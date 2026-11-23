<?php
namespace TBSHComplianceAuditLogger\AI;

use TBSHComplianceAuditLogger\Helpers\Secrets;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Incidents\IncidentRepository;
use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional AI-assisted incident analysis (off by default).
 *
 * Sends only incident fields (title, type, severity, status, description, affected
 * systems, impact, root cause) — never log rows, IP hashes or user data — to the
 * provider the administrator configured. The API key is stored encrypted and is
 * never sent to the browser.
 */
class AIAdapter {

	/**
	 * Providers: label, host, default model ('' = the administrator must enter one).
	 */
	const PROVIDERS = array(
		'anthropic' => array( 'label' => 'Anthropic Claude', 'host' => 'api.anthropic.com', 'model' => 'claude-opus-5-5' ),
		'openai'    => array( 'label' => 'OpenAI', 'host' => 'api.openai.com', 'model' => '' ),
		'gemini'    => array( 'label' => 'Google Gemini', 'host' => 'generativelanguage.googleapis.com', 'model' => '' ),
		'ollama'    => array( 'label' => 'OpenAI-compatible endpoint (Ollama, LM Studio, vLLM…)', 'host' => '', 'model' => '' ),
	);

	/**
	 * Host for a provider (for disclosure in the supply-chain inventory).
	 *
	 * @param string $provider Provider.
	 * @return string
	 */
	public static function endpoint_host( $provider ) {
		if ( 'ollama' === $provider ) {
			return (string) wp_parse_url( Secrets::get( 'ai_endpoint' ), PHP_URL_HOST );
		}
		return self::PROVIDERS[ $provider ]['host'] ?? '';
	}

	/**
	 * Whether analysis is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = get_option( 'tbsh_cal_settings', array() );
		$provider = $settings['ai_provider'] ?? 'none';
		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			return false;
		}
		if ( '' === self::model( $settings ) ) {
			return false;
		}
		return 'ollama' === $provider ? Secrets::has( 'ai_endpoint' ) : Secrets::has( 'ai_api_key' );
	}

	/**
	 * Analyse an incident and store the result on it.
	 *
	 * @param int $incident_id Incident.
	 * @return array|\WP_Error Updated incident.
	 */
	public static function analyze( $incident_id ) {
		$incident = IncidentRepository::get( $incident_id );
		if ( ! $incident ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Incident not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}

		$settings = get_option( 'tbsh_cal_settings', array() );
		$provider = $settings['ai_provider'] ?? 'none';
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'tbsh_cal_ai_disabled', __( 'AI analysis is not configured. Choose a provider, model and credentials in Settings.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}

		$model  = self::model( $settings );
		$prompt = self::prompt( $incident );

		switch ( $provider ) {
			case 'anthropic':
				$text = self::call_anthropic( $model, $prompt );
				break;
			case 'openai':
				$text = self::call_openai_compatible( 'https://api.openai.com/v1/chat/completions', Secrets::get( 'ai_api_key' ), $model, $prompt );
				break;
			case 'gemini':
				$text = self::call_gemini( $model, $prompt );
				break;
			default:
				$text = self::call_openai_compatible( Secrets::get( 'ai_endpoint' ), Secrets::get( 'ai_api_key' ), $model, $prompt );
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$analysis = array(
			'provider'     => $provider,
			'model'        => $model,
			'generated_at' => Time::now(),
			'result'       => self::parse( $text ),
		);

		Logger::log(
			'incident_ai_analysis',
			'Incident Detection',
			'info',
			__( 'AI incident analysis generated', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: incident id, 2: provider, 3: model */
			sprintf( __( 'Incident #%1$d analysed with %2$s (%3$s).', 'tbsh-compliance-audit-logger' ), $incident_id, $provider, $model ),
			array( 'object_type' => 'incident', 'object_id' => $incident_id )
		);

		return IncidentRepository::update( $incident_id, array( 'ai_analysis_json' => $analysis ) );
	}

	/**
	 * Configured model or provider default.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	private static function model( array $settings ) {
		$provider = $settings['ai_provider'] ?? 'none';
		$model    = trim( (string) ( $settings['ai_model'] ?? '' ) );
		if ( '' === $model && isset( self::PROVIDERS[ $provider ] ) ) {
			$model = self::PROVIDERS[ $provider ]['model'];
		}
		return $model;
	}

	/**
	 * Prompt built from incident fields only.
	 *
	 * @param array $incident Incident.
	 * @return string
	 */
	private static function prompt( array $incident ) {
		$fields = array(
			'title'             => $incident['event_title'],
			'type'              => $incident['incident_type'],
			'severity'          => $incident['severity'],
			'status'            => $incident['status'],
			'detected_at'       => $incident['detected_at'],
			'description'       => $incident['description'],
			'affected_systems'  => $incident['affected_systems'],
			'impact_assessment' => $incident['impact_assessment'],
			'root_cause_notes'  => $incident['root_cause'],
			'compliance_tags'   => $incident['compliance_mappings'],
		);

		return "You are assisting a security team that runs a WordPress website. Analyse the incident below and respond with JSON only, using exactly these keys:\n"
			. "{\"summary\": string, \"likely_causes\": string[], \"recommended_actions\": string[], \"risk_level\": \"low\"|\"medium\"|\"high\"|\"critical\", \"nis2_reportable\": boolean, \"notes\": string}\n"
			. "Base the answer only on the incident data. If information is missing, say what should be collected.\n\nIncident:\n"
			. wp_json_encode( $fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Anthropic Messages API.
	 *
	 * @param string $model  Model.
	 * @param string $prompt Prompt.
	 * @return string|\WP_Error
	 */
	private static function call_anthropic( $model, $prompt ) {
		$body = array(
			'model'         => $model,
			'max_tokens'    => 4000,
			'output_config' => array( 'effort' => 'low' ),
			'messages'      => array( array( 'role' => 'user', 'content' => $prompt ) ),
		);
		$headers = array(
			'x-api-key'         => Secrets::get( 'ai_api_key' ),
			'anthropic-version' => '2023-06-01',
		);
		// Server-side refusal fallback on the models that support it.
		if ( in_array( $model, array( 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-fable-5-2', 'claude-fable-5-1' ), true ) ) {
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
			$body['fallbacks']         = 'default';
		}

		$data = self::post( 'https://api.anthropic.com/v1/messages', $headers, $body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( 'refusal' === ( $data['stop_reason'] ?? '' ) ) {
			return new \WP_Error( 'tbsh_cal_ai_refusal', __( 'The AI provider declined to analyse this incident.', 'tbsh-compliance-audit-logger' ), array( 'status' => 502 ) );
		}

		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) $block['text'];
			}
		}
		return self::non_empty( $text );
	}

	/**
	 * OpenAI Chat Completions or a compatible endpoint.
	 *
	 * @param string $url    Endpoint URL.
	 * @param string $key    API key (may be empty for local endpoints).
	 * @param string $model  Model.
	 * @param string $prompt Prompt.
	 * @return string|\WP_Error
	 */
	private static function call_openai_compatible( $url, $key, $model, $prompt ) {
		$headers = array();
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		$data = self::post(
			$url,
			$headers,
			array(
				'model'    => $model,
				'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ),
			),
			'ollama' === ( get_option( 'tbsh_cal_settings', array() )['ai_provider'] ?? '' )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::non_empty( (string) ( $data['choices'][0]['message']['content'] ?? '' ) );
	}

	/**
	 * Google Gemini generateContent.
	 *
	 * @param string $model  Model.
	 * @param string $prompt Prompt.
	 * @return string|\WP_Error
	 */
	private static function call_gemini( $model, $prompt ) {
		$url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
		$data = self::post(
			$url,
			array( 'x-goog-api-key' => Secrets::get( 'ai_api_key' ) ),
			array(
				'contents' => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => $prompt ) ),
					),
				),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$text = '';
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			$text .= (string) ( $part['text'] ?? '' );
		}
		return self::non_empty( $text );
	}

	/**
	 * POST JSON and decode, surfacing provider errors.
	 *
	 * @param string $url        URL.
	 * @param array  $headers    Headers.
	 * @param array  $body       Body.
	 * @param bool   $allow_local Allow private/local hosts (self-hosted endpoints chosen by an administrator).
	 * @return array|\WP_Error
	 */
	private static function post( $url, array $headers, array $body, $allow_local = false ) {
		if ( ! wp_http_validate_url( $url ) && ! $allow_local ) {
			return new \WP_Error( 'tbsh_cal_ai_url', __( 'The AI endpoint URL is not valid.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}
		$args = array(
			'timeout'     => 60,
			'redirection' => 0,
			'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'body'        => wp_json_encode( $body ),
		);
		$response = $allow_local ? wp_remote_post( esc_url_raw( $url ), $args ) : wp_safe_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error */
			return new \WP_Error( 'tbsh_cal_ai_http', sprintf( __( 'Could not reach the AI provider: %s', 'tbsh-compliance-audit-logger' ), $response->get_error_message() ), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) ? ( $data['error']['message'] ?? ( $data['error'] ?? '' ) ) : '';
			$message = is_string( $message ) && '' !== $message ? $message : wp_remote_retrieve_response_message( $response );
			/* translators: 1: HTTP status, 2: provider message */
			return new \WP_Error( 'tbsh_cal_ai_http', sprintf( __( 'The AI provider returned HTTP %1$d: %2$s', 'tbsh-compliance-audit-logger' ), $code, mb_substr( wp_strip_all_tags( $message ), 0, 300 ) ), array( 'status' => 502 ) );
		}

		return is_array( $data ) ? $data : new \WP_Error( 'tbsh_cal_ai_http', __( 'The AI provider returned an unreadable response.', 'tbsh-compliance-audit-logger' ), array( 'status' => 502 ) );
	}

	/**
	 * Reject empty output.
	 *
	 * @param string $text Text.
	 * @return string|\WP_Error
	 */
	private static function non_empty( $text ) {
		$text = trim( (string) $text );
		return '' === $text
			? new \WP_Error( 'tbsh_cal_ai_empty', __( 'The AI provider returned an empty answer.', 'tbsh-compliance-audit-logger' ), array( 'status' => 502 ) )
			: $text;
	}

	/**
	 * Parse the JSON answer; keep raw text when it is not JSON.
	 *
	 * @param string $text Model output.
	 * @return array
	 */
	private static function parse( $text ) {
		$clean = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', trim( $text ) );
		$data  = json_decode( (string) $clean, true );
		if ( ! is_array( $data ) ) {
			return array( 'summary' => wp_strip_all_tags( $text ), 'likely_causes' => array(), 'recommended_actions' => array(), 'risk_level' => '', 'nis2_reportable' => null, 'notes' => '' );
		}
		$list = function ( $v ) {
			return array_values( array_map( function ( $s ) {
				return wp_strip_all_tags( (string) $s );
			}, is_array( $v ) ? $v : array() ) );
		};
		return array(
			'summary'             => wp_strip_all_tags( (string) ( $data['summary'] ?? '' ) ),
			'likely_causes'       => $list( $data['likely_causes'] ?? array() ),
			'recommended_actions' => $list( $data['recommended_actions'] ?? array() ),
			'risk_level'          => in_array( $data['risk_level'] ?? '', array( 'low', 'medium', 'high', 'critical' ), true ) ? $data['risk_level'] : '',
			'nis2_reportable'     => isset( $data['nis2_reportable'] ) ? (bool) $data['nis2_reportable'] : null,
			'notes'               => wp_strip_all_tags( (string) ( $data['notes'] ?? '' ) ),
		);
	}
}
