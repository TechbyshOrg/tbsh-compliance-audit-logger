<?php
namespace TBSHComplianceAuditLogger\Alerts;

use TBSHComplianceAuditLogger\Helpers\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivery channels: payload building and sending.
 *
 * Every send returns array{ ok: bool, code: int, message: string }.
 */
class Channels {

	/**
	 * Supported channels.
	 */
	const ALL = array( 'email', 'slack', 'discord', 'teams', 'telegram', 'webhook' );

	/**
	 * Secret holding each webhook-style channel's endpoint.
	 */
	const SECRET_FOR = array(
		'slack'    => 'slack_webhook',
		'discord'  => 'discord_webhook',
		'teams'    => 'teams_webhook',
		'telegram' => 'telegram_bot_token',
		'webhook'  => 'webhook_url',
	);

	/**
	 * Whether a channel is enabled and fully configured.
	 *
	 * @param string $channel  Channel.
	 * @param array  $settings Plugin settings.
	 * @return bool
	 */
	public static function is_ready( $channel, array $settings ) {
		switch ( $channel ) {
			case 'email':
				return ! empty( $settings['email_alerts'] ) && ! empty( self::email_recipients( $settings ) );
			case 'telegram':
				return ! empty( $settings['telegram_enabled'] ) && Secrets::has( 'telegram_bot_token' ) && ! empty( $settings['telegram_chat_id'] );
			default:
				return ! empty( $settings[ $channel . '_enabled' ] ) && isset( self::SECRET_FOR[ $channel ] ) && Secrets::has( self::SECRET_FOR[ $channel ] );
		}
	}

	/**
	 * Masked recipient description for the delivery log.
	 *
	 * @param string $channel  Channel.
	 * @param array  $settings Settings.
	 * @return string
	 */
	public static function recipient_hint( $channel, array $settings ) {
		if ( 'email' === $channel ) {
			return implode( ', ', array_map( array( __CLASS__, 'mask_email' ), self::email_recipients( $settings ) ) );
		}
		if ( 'telegram' === $channel ) {
			return 'chat ' . substr( (string) ( $settings['telegram_chat_id'] ?? '' ), 0, 4 ) . '••••';
		}
		$url  = Secrets::get( self::SECRET_FOR[ $channel ] ?? '' );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host ? $host . '/••••' : '••••';
	}

	/**
	 * Send a payload over a channel.
	 *
	 * @param string $channel  Channel.
	 * @param array  $payload  Alert payload (see AlertManager::build_payload()).
	 * @param array  $settings Settings.
	 * @return array{ok: bool, code: int, message: string}
	 */
	public static function send( $channel, array $payload, array $settings ) {
		switch ( $channel ) {
			case 'email':
				return self::send_email( $payload, $settings );
			case 'slack':
				return self::post_json( Secrets::get( 'slack_webhook' ), self::slack_body( $payload ) );
			case 'discord':
				return self::post_json( Secrets::get( 'discord_webhook' ), self::discord_body( $payload ) );
			case 'teams':
				return self::post_json( Secrets::get( 'teams_webhook' ), self::teams_body( $payload ) );
			case 'telegram':
				$url = 'https://api.telegram.org/bot' . rawurlencode( Secrets::get( 'telegram_bot_token' ) ) . '/sendMessage';
				return self::post_json(
					$url,
					array(
						'chat_id'                  => (string) ( $settings['telegram_chat_id'] ?? '' ),
						'text'                     => self::plain_text( $payload ),
						'disable_web_page_preview' => true,
					)
				);
			case 'webhook':
				return self::send_webhook( $payload );
		}
		return array( 'ok' => false, 'code' => 0, 'message' => 'Unknown channel.' );
	}

	/**
	 * Email recipients (validated), defaulting to the site admin email.
	 *
	 * @param array $settings Settings.
	 * @return string[]
	 */
	public static function email_recipients( array $settings ) {
		$raw  = isset( $settings['alert_emails'] ) ? (string) $settings['alert_emails'] : '';
		$list = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ) );
		$list = array_values( array_filter( $list, 'is_email' ) );
		if ( empty( $list ) && is_email( get_option( 'admin_email' ) ) ) {
			$list = array( get_option( 'admin_email' ) );
		}
		return array_slice( array_unique( $list ), 0, 20 );
	}

	/**
	 * Mask an email address.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function mask_email( $email ) {
		$parts = explode( '@', (string) $email, 2 );
		return 2 === count( $parts ) ? substr( $parts[0], 0, 1 ) . '•••@' . $parts[1] : '••••';
	}

	/**
	 * Email via wp_mail (first recipient in To, the rest in Bcc).
	 *
	 * @param array $payload  Payload.
	 * @param array $settings Settings.
	 * @return array
	 */
	private static function send_email( array $payload, array $settings ) {
		$recipients = self::email_recipients( $settings );
		if ( empty( $recipients ) ) {
			return array( 'ok' => false, 'code' => 0, 'message' => 'No valid recipients.' );
		}

		$subject = sprintf( '[%s] %s: %s', $payload['site_name'], strtoupper( $payload['severity'] ), $payload['subject'] );
		$headers = array();
		foreach ( array_slice( $recipients, 1 ) as $bcc ) {
			$headers[] = 'Bcc: ' . $bcc;
		}

		$sent = wp_mail( $recipients[0], $subject, self::plain_text( $payload ), $headers );

		return array(
			'ok'      => (bool) $sent,
			'code'    => $sent ? 250 : 0,
			'message' => $sent ? 'Accepted by mailer.' : 'wp_mail() reported a failure.',
		);
	}

	/**
	 * Generic signed JSON webhook.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	private static function send_webhook( array $payload ) {
		$body      = wp_json_encode( $payload );
		$timestamp = (string) time();
		$headers   = array(
			'X-TBSH-Event'     => $payload['type'],
			'X-TBSH-Timestamp' => $timestamp,
		);
		$secret = Secrets::get( 'webhook_signing_secret' );
		if ( '' !== $secret ) {
			$headers['X-TBSH-Signature'] = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		}
		return self::post_raw( Secrets::get( 'webhook_url' ), $body, $headers );
	}

	/**
	 * Slack incoming-webhook body.
	 *
	 * @param array $p Payload.
	 * @return array
	 */
	private static function slack_body( array $p ) {
		$esc = function ( $s ) {
			return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $s );
		};
		return array(
			'text' => sprintf(
				"*[%s] %s*\n%s\n<%s|%s> · %s",
				strtoupper( $p['severity'] ),
				$esc( $p['subject'] ),
				$esc( $p['message'] ),
				esc_url_raw( $p['link'] ),
				$esc( __( 'Open in WordPress', 'tbsh-compliance-audit-logger' ) ),
				$esc( $p['site_name'] )
			),
		);
	}

	/**
	 * Discord webhook body.
	 *
	 * @param array $p Payload.
	 * @return array
	 */
	private static function discord_body( array $p ) {
		$colors = array( 'critical' => 0xDC2626, 'error' => 0xEA580C, 'warning' => 0xD97706, 'notice' => 0x2563EB, 'info' => 0x64748B );
		return array(
			'content'          => '',
			'allowed_mentions' => array( 'parse' => array() ),
			'embeds'           => array(
				array(
					'title'       => mb_substr( '[' . strtoupper( $p['severity'] ) . '] ' . $p['subject'], 0, 256 ),
					'description' => mb_substr( $p['message'], 0, 3500 ),
					'url'         => esc_url_raw( $p['link'] ),
					'color'       => $colors[ $p['severity'] ] ?? 0x64748B,
					'footer'      => array( 'text' => mb_substr( $p['site_name'], 0, 200 ) ),
					'timestamp'   => gmdate( 'c' ),
				),
			),
		);
	}

	/**
	 * Microsoft Teams Workflows webhook body (Adaptive Card).
	 *
	 * @param array $p Payload.
	 * @return array
	 */
	private static function teams_body( array $p ) {
		return array(
			'type'        => 'message',
			'attachments' => array(
				array(
					'contentType' => 'application/vnd.microsoft.card.adaptive',
					'contentUrl'  => null,
					'content'     => array(
						'$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
						'type'    => 'AdaptiveCard',
						'version' => '1.4',
						'body'    => array(
							array( 'type' => 'TextBlock', 'size' => 'Medium', 'weight' => 'Bolder', 'wrap' => true, 'text' => '[' . strtoupper( $p['severity'] ) . '] ' . $p['subject'] ),
							array( 'type' => 'TextBlock', 'wrap' => true, 'text' => $p['message'] ),
							array(
								'type'  => 'FactSet',
								'facts' => array(
									array( 'title' => __( 'Site', 'tbsh-compliance-audit-logger' ), 'value' => $p['site_name'] ),
									array( 'title' => __( 'Time', 'tbsh-compliance-audit-logger' ), 'value' => $p['time'] ),
								),
							),
						),
						'actions' => array(
							array( 'type' => 'Action.OpenUrl', 'title' => __( 'Open in WordPress', 'tbsh-compliance-audit-logger' ), 'url' => esc_url_raw( $p['link'] ) ),
						),
					),
				),
			),
		);
	}

	/**
	 * Plain-text rendering (email, Telegram).
	 *
	 * @param array $p Payload.
	 * @return string
	 */
	private static function plain_text( array $p ) {
		return sprintf(
			"[%s] %s\n\n%s\n\n%s: %s\n%s: %s\n%s",
			strtoupper( $p['severity'] ),
			$p['subject'],
			$p['message'],
			__( 'Site', 'tbsh-compliance-audit-logger' ),
			$p['site_name'],
			__( 'Time', 'tbsh-compliance-audit-logger' ),
			$p['time'],
			$p['link']
		);
	}

	/**
	 * POST JSON.
	 *
	 * @param string $url  URL.
	 * @param array  $body Body.
	 * @return array
	 */
	private static function post_json( $url, array $body ) {
		return self::post_raw( $url, wp_json_encode( $body ), array() );
	}

	/**
	 * POST a raw JSON body with SSRF-safe transport and a short timeout.
	 *
	 * @param string $url     URL.
	 * @param string $body    Body.
	 * @param array  $headers Extra headers.
	 * @return array
	 */
	private static function post_raw( $url, $body, array $headers ) {
		if ( ! wp_http_validate_url( $url ) || 0 !== strpos( $url, 'https://' ) ) {
			return array( 'ok' => false, 'code' => 0, 'message' => 'Endpoint is not a valid public https URL.' );
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array_merge( array( 'Content-Type' => 'application/json; charset=utf-8' ), $headers ),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'code' => 0, 'message' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'ok'      => $code >= 200 && $code < 300,
			'code'    => $code,
			'message' => mb_substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), 0, 500 ),
		);
	}
}
