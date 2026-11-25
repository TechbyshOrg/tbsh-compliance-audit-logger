<?php
namespace TBSHComplianceAuditLogger\Vendors;

use TBSHComplianceAuditLogger\Alerts\Channels;
use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Software supply-chain inventory built from real site data: installed plugins and
 * themes with pending updates, vendor-register mapping, and configured outbound
 * integrations. Cached for 12 hours, flushed when the inventory changes, and rebuilt
 * when WordPress has fetched new update information (see update_fingerprint()).
 */
class SupplyChain {

	const CACHE_KEY = 'tbsh_cal_supply_chain';

	/**
	 * Register cache invalidation hooks.
	 */
	public static function init() {
		foreach ( array( 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'switch_theme' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Fingerprint of the update information WordPress last fetched.
	 *
	 * Reads WordPress's own update data (it is never modified): when WordPress
	 * finds new or different pending updates, the cached inventory is rebuilt.
	 *
	 * @return string
	 */
	private static function update_fingerprint() {
		$parts = array();
		foreach ( array( 'update_plugins', 'update_themes' ) as $type ) {
			$data    = get_site_transient( $type );
			$pending = array();
			foreach ( (array) ( is_object( $data ) && isset( $data->response ) ? $data->response : array() ) as $key => $update ) {
				$update          = (array) $update;
				$pending[ $key ] = (string) ( $update['new_version'] ?? '' );
			}
			ksort( $pending );
			$parts[] = ( is_object( $data ) && isset( $data->last_checked ) ? (int) $data->last_checked : 0 ) . ':' . wp_json_encode( $pending );
		}
		return md5( implode( '|', $parts ) );
	}

	/**
	 * Drop the cached inventory.
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Inventory.
	 *
	 * @param bool $force Rebuild.
	 * @return array
	 */
	public static function inventory( $force = false ) {
		$fingerprint = self::update_fingerprint();
		$cached      = $force ? false : get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ( $cached['fingerprint'] ?? '' ) === $fingerprint ) {
			return $cached;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$vendors        = VendorRegister::linked_map();
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );
		$active         = (array) get_option( 'active_plugins', array() );
		$network_active = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$update = isset( $plugin_updates->response[ $file ]->new_version ) ? (string) $plugin_updates->response[ $file ]->new_version : '';
			$slug   = dirname( $file ) === '.' ? $file : dirname( $file );
			$item   = array(
				'type'             => 'plugin',
				'slug'             => $slug,
				'file'             => $file,
				'name'             => wp_strip_all_tags( $data['Name'] ),
				'version'          => $data['Version'],
				'author'           => wp_strip_all_tags( $data['AuthorName'] ?? $data['Author'] ),
				'active'           => in_array( $file, $active, true ) || in_array( $file, $network_active, true ),
				'network_active'   => in_array( $file, $network_active, true ),
				'update_available' => $update,
				'vendor'           => $vendors[ $slug ] ?? null,
			);
			$plugins[] = self::assess( $item );
		}

		$themes     = array();
		$stylesheet = get_stylesheet();
		$template   = get_template();
		foreach ( array_unique( array( $stylesheet, $template ) ) as $slug ) {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				continue;
			}
			$themes[] = self::assess( array(
				'type'             => 'theme',
				'slug'             => $slug,
				'file'             => $slug,
				'name'             => $theme->get( 'Name' ),
				'version'          => $theme->get( 'Version' ),
				'author'           => wp_strip_all_tags( $theme->get( 'Author' ) ),
				'active'           => true,
				'role'             => $slug === $stylesheet ? ( $stylesheet === $template ? 'active' : 'child' ) : 'parent',
				'network_active'   => false,
				'update_available' => isset( $theme_updates->response[ $slug ]['new_version'] ) ? (string) $theme_updates->response[ $slug ]['new_version'] : '',
				'vendor'           => $vendors[ $slug ] ?? null,
			) );
		}

		$inventory = array(
			'plugins'      => $plugins,
			'themes'       => $themes,
			'integrations' => self::integrations(),
			'summary'      => array(
				'plugins'          => count( $plugins ),
				'active_plugins'   => count( array_filter( $plugins, function ( $p ) { return $p['active']; } ) ),
				'updates_pending'  => count( array_filter( array_merge( $plugins, $themes ), function ( $p ) { return '' !== $p['update_available']; } ) ),
				'inactive_plugins' => count( array_filter( $plugins, function ( $p ) { return ! $p['active']; } ) ),
				'high_risk'        => count( array_filter( array_merge( $plugins, $themes ), function ( $p ) { return 'high' === $p['risk']; } ) ),
				'unmapped_active'  => count( array_filter( $plugins, function ( $p ) { return $p['active'] && empty( $p['vendor'] ); } ) ),
			),
			'generated_at' => current_time( 'mysql' ),
		);

		$inventory['fingerprint'] = $fingerprint;
		set_transient( self::CACHE_KEY, $inventory, 12 * HOUR_IN_SECONDS );
		return $inventory;
	}

	/**
	 * Derive a risk level with explicit reasons.
	 *
	 * @param array $item Component.
	 * @return array
	 */
	private static function assess( array $item ) {
		$reasons = array();
		$points  = 0;

		if ( '' !== $item['update_available'] ) {
			/* translators: %s: version */
			$reasons[] = sprintf( __( 'Update available (%s)', 'tbsh-compliance-audit-logger' ), $item['update_available'] );
			$points   += 2;
		}
		if ( ! $item['active'] ) {
			$reasons[] = __( 'Installed but inactive — remove if unused', 'tbsh-compliance-audit-logger' );
			$points   += 1;
		}
		if ( ! empty( $item['vendor'] ) && 'high' === $item['vendor']['criticality'] ) {
			$reasons[] = __( 'Mapped to a critical vendor', 'tbsh-compliance-audit-logger' );
			$points   += 1;
		}

		$item['risk']         = $points >= 3 ? 'high' : ( $points >= 1 ? 'medium' : 'low' );
		$item['risk_reasons'] = $reasons;
		return $item;
	}

	/**
	 * Outbound integrations configured on this site (hosts only, never secrets).
	 *
	 * @return array
	 */
	private static function integrations() {
		global $wpdb;
		$out      = array();
		$settings = get_option( 'tbsh_cal_settings', array() );

		foreach ( Channels::SECRET_FOR as $channel => $secret ) {
			if ( 'telegram' === $channel ) {
				if ( Secrets::has( $secret ) ) {
					$out[] = array( 'name' => 'Telegram', 'kind' => __( 'Alert channel', 'tbsh-compliance-audit-logger' ), 'endpoint' => 'api.telegram.org', 'enabled' => ! empty( $settings['telegram_enabled'] ) );
				}
				continue;
			}
			$host = wp_parse_url( Secrets::get( $secret ), PHP_URL_HOST );
			if ( $host ) {
				$out[] = array( 'name' => ucfirst( $channel ), 'kind' => __( 'Alert channel', 'tbsh-compliance-audit-logger' ), 'endpoint' => $host, 'enabled' => ! empty( $settings[ $channel . '_enabled' ] ) );
			}
		}

		$provider = $settings['ai_provider'] ?? 'none';
		if ( 'none' !== $provider ) {
			$out[] = array( 'name' => strtoupper( $provider ), 'kind' => __( 'AI analysis provider', 'tbsh-compliance-audit-logger' ), 'endpoint' => \TBSHComplianceAuditLogger\AI\AIAdapter::endpoint_host( $provider ), 'enabled' => true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, vault_type, label, is_active FROM %i', Schema::table( 'vaults' ) ) ) as $vault ) {
			$out[] = array(
				'name'     => $vault->label ? $vault->label : $vault->vault_type,
				'kind'     => __( 'Off-site evidence vault', 'tbsh-compliance-audit-logger' ),
				'endpoint' => \TBSHComplianceAuditLogger\Vaults\VaultManager::endpoint_host( (int) $vault->id ),
				'enabled'  => (bool) $vault->is_active,
			);
		}

		// WooCommerce webhooks.
		$wc_table = $wpdb->prefix . 'wc_webhooks';
		if ( class_exists( 'WooCommerce' ) && Schema::table_exists( $wc_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT name, status, delivery_url, topic FROM %i LIMIT 100', $wc_table ) ) as $hook ) {
				$out[] = array(
					/* translators: %s: webhook topic */
					'name'     => $hook->name ? $hook->name : sprintf( __( 'WooCommerce webhook (%s)', 'tbsh-compliance-audit-logger' ), $hook->topic ),
					'kind'     => __( 'WooCommerce webhook', 'tbsh-compliance-audit-logger' ),
					'endpoint' => (string) wp_parse_url( $hook->delivery_url, PHP_URL_HOST ),
					'enabled'  => 'active' === $hook->status,
				);
			}
		}

		$out[] = array( 'name' => 'WordPress.org', 'kind' => __( 'Core, plugin and theme updates; core checksums', 'tbsh-compliance-audit-logger' ), 'endpoint' => 'api.wordpress.org', 'enabled' => true );

		return $out;
	}
}
