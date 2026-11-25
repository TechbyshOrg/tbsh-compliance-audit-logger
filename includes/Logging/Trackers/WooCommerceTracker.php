<?php
namespace TBSHComplianceAuditLogger\Logging\Trackers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce store changes (setting: log_woocommerce_events). Loaded only when WooCommerce is active.
 *
 * Logged: order status changes and edits made by staff, refunds, order trash/deletion,
 * product price/stock/SKU/tax/download changes, coupons and store settings (setting
 * names only, never values, because gateway settings hold credentials). Status
 * changes made by payment gateways or customers at checkout are routine and skipped.
 * Customer names, addresses and payment details are never logged.
 */
class WooCommerceTracker extends Tracker {

	/**
	 * Setting that switches this tracker on.
	 */
	const SETTING = 'log_woocommerce_events';

	/**
	 * Product properties whose change is logged.
	 */
	const PRODUCT_PROPS = array(
		'regular_price',
		'sale_price',
		'date_on_sale_from',
		'date_on_sale_to',
		'stock_quantity',
		'manage_stock',
		'stock_status',
		'backorders',
		'sku',
		'tax_status',
		'tax_class',
		'downloadable',
		'downloads',
		'virtual',
		'product_type',
	);

	/**
	 * WooCommerce options changed in this request.
	 *
	 * @var string[]
	 */
	private static $changed_options = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'track_order_status' ), 10, 3 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'track_order_edited' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'track_order_refunded' ), 10, 2 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'track_order_trashed' ) );
		add_action( 'woocommerce_delete_order', array( __CLASS__, 'track_order_deleted' ) );

		add_action( 'woocommerce_product_object_updated_props', array( __CLASS__, 'track_product_props' ), 10, 2 );

		add_action( 'woocommerce_new_coupon', array( __CLASS__, 'track_coupon_created' ), 10, 2 );
		add_action( 'woocommerce_update_coupon', array( __CLASS__, 'track_coupon_updated' ), 10, 2 );
		add_action( 'woocommerce_trash_coupon', array( __CLASS__, 'track_coupon_trashed' ) );
		add_action( 'woocommerce_delete_coupon', array( __CLASS__, 'track_coupon_deleted' ) );

		add_action( 'updated_option', array( __CLASS__, 'collect_option' ) );
		add_action( 'added_option', array( __CLASS__, 'collect_option' ) );
		add_action( 'woocommerce_update_options', array( __CLASS__, 'note_settings_saved' ) );
	}

	/**
	 * Whether the current user is store staff acting in the admin, REST or CLI.
	 *
	 * @return bool
	 */
	private static function staff_action() {
		return get_current_user_id() > 0 && ( current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Orders
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Order status changed by staff.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public static function track_order_status( $order_id, $from, $to ) {
		if ( ! self::enabled( self::SETTING ) || ! self::staff_action() ) {
			return;
		}
		$from = sanitize_key( $from );
		$to   = sanitize_key( $to );

		self::batch(
			'wc_order_status:' . $to,
			self::entry(
				'wc_order_status_changed',
				'Change Management',
				in_array( $to, array( 'refunded', 'cancelled' ), true ) ? 'notice' : 'info',
				__( 'Order status changed', 'tbsh-compliance-audit-logger' ),
				/* translators: 1: order number, 2: old status, 3: new status */
				sprintf( __( 'Order #%1$s changed from %2$s to %3$s.', 'tbsh-compliance-audit-logger' ), self::order_number( $order_id ), $from, $to ),
				array(
					'object_type'     => 'shop_order',
					'object_id'       => (int) $order_id,
					'compliance_tags' => 'Change Management, PCI DSS 10.2',
					'metadata'        => array(
						'from' => $from,
						'to'   => $to,
					),
				)
			),
			function ( $entries ) use ( $to ) {
				return self::entry(
					'wc_order_status_changed',
					'Change Management',
					'notice',
					__( 'Order statuses changed', 'tbsh-compliance-audit-logger' ),
					/* translators: 1: number of orders, 2: new status */
					sprintf( _n( '%1$d order was changed to %2$s.', '%1$d orders were changed to %2$s.', count( $entries ), 'tbsh-compliance-audit-logger' ), count( $entries ), $to ),
					array(
						'object_type'     => 'shop_order',
						'compliance_tags' => 'Change Management, PCI DSS 10.2',
						'metadata'        => array(
							'count'     => count( $entries ),
							'to'        => $to,
							'order_ids' => self::batch_ids( $entries ),
						),
					)
				);
			}
		);
	}

	/**
	 * Order saved from the order edit screen.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function track_order_edited( $order_id ) {
		if ( ! self::enabled( self::SETTING ) || ! self::staff_action() ) {
			return;
		}
		self::write( self::entry(
			'wc_order_edited',
			'Change Management',
			'info',
			__( 'Order edited', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: order number */
			sprintf( __( 'Order #%s was saved from the order edit screen.', 'tbsh-compliance-audit-logger' ), self::order_number( $order_id ) ),
			array(
				'object_type'     => 'shop_order',
				'object_id'       => (int) $order_id,
				'compliance_tags' => 'Change Management, PCI DSS 10.2',
			)
		) );
	}

	/**
	 * Refund created (manual or through the gateway).
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public static function track_order_refunded( $order_id, $refund_id ) {
		if ( ! self::enabled( self::SETTING ) ) {
			return;
		}
		$refund   = function_exists( 'wc_get_order' ) ? wc_get_order( $refund_id ) : null;
		$amount   = $refund ? (string) $refund->get_amount() : '';
		$currency = $refund ? (string) $refund->get_currency() : '';

		self::write( self::entry(
			'wc_order_refunded',
			'Change Management',
			'warning',
			__( 'Order refunded', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: amount, 2: currency, 3: order number */
			sprintf( __( 'A refund of %1$s %2$s was issued for order #%3$s.', 'tbsh-compliance-audit-logger' ), $amount, $currency, self::order_number( $order_id ) ),
			array(
				'object_type'     => 'shop_order',
				'object_id'       => (int) $order_id,
				'compliance_tags' => 'Change Management, PCI DSS 10.2',
				'metadata'        => array(
					'refund_id' => (int) $refund_id,
					'amount'    => $amount,
					'currency'  => $currency,
					'gateway'   => $refund && $refund->get_refunded_payment() ? 'yes' : 'no',
				),
			)
		) );
	}

	/**
	 * Order moved to the trash.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function track_order_trashed( $order_id ) {
		self::order_removed(
			'wc_order_trashed',
			$order_id,
			__( 'Order trashed', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: order number */
			__( 'Order #%s was moved to the trash.', 'tbsh-compliance-audit-logger' )
		);
	}

	/**
	 * Order permanently deleted.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function track_order_deleted( $order_id ) {
		self::order_removed(
			'wc_order_deleted',
			$order_id,
			__( 'Order deleted', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: order number */
			__( 'Order #%s was permanently deleted.', 'tbsh-compliance-audit-logger' )
		);
	}

	/**
	 * Batched order trash/delete entry.
	 *
	 * @param string $type    Event type.
	 * @param int    $order_id Order ID.
	 * @param string $title   Title.
	 * @param string $format  Message format with %s for the order number.
	 */
	private static function order_removed( $type, $order_id, $title, $format ) {
		if ( ! self::enabled( self::SETTING ) ) {
			return;
		}
		self::batch(
			$type,
			self::entry(
				$type,
				'Change Management',
				'warning',
				$title,
				sprintf( $format, self::order_number( $order_id ) ),
				array(
					'object_type'     => 'shop_order',
					'object_id'       => (int) $order_id,
					'compliance_tags' => 'Change Management, PCI DSS 10.2',
				)
			),
			function ( $entries ) use ( $type, $title ) {
				return self::entry(
					$type,
					'Change Management',
					'warning',
					$title,
					'wc_order_trashed' === $type
						/* translators: %d: number of orders */
						? sprintf( _n( '%d order was moved to the trash.', '%d orders were moved to the trash.', count( $entries ), 'tbsh-compliance-audit-logger' ), count( $entries ) )
						/* translators: %d: number of orders */
						: sprintf( _n( '%d order was permanently deleted.', '%d orders were permanently deleted.', count( $entries ), 'tbsh-compliance-audit-logger' ), count( $entries ) ),
					array(
						'object_type'     => 'shop_order',
						'compliance_tags' => 'Change Management, PCI DSS 10.2',
						'metadata'        => array(
							'count'     => count( $entries ),
							'order_ids' => self::batch_ids( $entries ),
						),
					)
				);
			}
		);
	}

	/**
	 * Display number of an order (custom order numbers supported).
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function order_number( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		return $order ? sanitize_text_field( (string) $order->get_order_number() ) : (string) (int) $order_id;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Products
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Commercially relevant product properties changed by staff.
	 *
	 * @param \WC_Product $product       Product.
	 * @param string[]    $updated_props Changed property names.
	 */
	public static function track_product_props( $product, $updated_props ) {
		if ( ! self::enabled( self::SETTING ) || ! is_object( $product ) || get_current_user_id() <= 0 || ! current_user_can( 'edit_products' ) ) {
			return;
		}
		$props = array_values( array_intersect( self::PRODUCT_PROPS, (array) $updated_props ) );
		if ( ! $props ) {
			return;
		}

		$metadata = array( 'props' => $props );
		foreach ( array( 'regular_price', 'sale_price', 'stock_quantity', 'stock_status', 'sku' ) as $prop ) {
			if ( in_array( $prop, $props, true ) && is_callable( array( $product, 'get_' . $prop ) ) ) {
				$metadata['new'][ $prop ] = self::display( call_user_func( array( $product, 'get_' . $prop ) ) );
			}
		}
		$severity = in_array( 'downloads', $props, true ) ? 'warning' : 'info';

		self::batch(
			'wc_product_updated',
			self::entry(
				'wc_product_updated',
				'Change Management',
				$severity,
				__( 'Product commerce data changed', 'tbsh-compliance-audit-logger' ),
				/* translators: 1: product name, 2: product ID, 3: comma-separated properties */
				sprintf( __( 'Product "%1$s" (ID %2$d) changed: %3$s.', 'tbsh-compliance-audit-logger' ), self::display( $product->get_name(), 120 ), (int) $product->get_id(), implode( ', ', $props ) ),
				array(
					'object_type' => 'product',
					'object_id'   => (int) $product->get_id(),
					'metadata'    => $metadata,
				)
			),
			function ( $entries ) {
				return self::entry(
					'wc_product_updated',
					'Change Management',
					'info',
					__( 'Products changed', 'tbsh-compliance-audit-logger' ),
					/* translators: %d: number of products */
					sprintf( _n( 'Commerce data of %d product changed.', 'Commerce data of %d products changed.', count( $entries ), 'tbsh-compliance-audit-logger' ), count( $entries ) ),
					array(
						'object_type' => 'product',
						'metadata'    => array(
							'count'       => count( $entries ),
							'product_ids' => self::batch_ids( $entries ),
						),
					)
				);
			}
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Coupons
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Coupon created.
	 *
	 * @param int        $coupon_id Coupon ID.
	 * @param \WC_Coupon $coupon    Coupon.
	 */
	public static function track_coupon_created( $coupon_id, $coupon = null ) {
		self::coupon_event(
			'wc_coupon_created',
			'notice',
			$coupon_id,
			$coupon,
			__( 'Coupon created', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: coupon code, 2: amount, 3: discount type */
			__( 'Coupon "%1$s" (%2$s %3$s) was created.', 'tbsh-compliance-audit-logger' )
		);
	}

	/**
	 * Coupon updated.
	 *
	 * @param int        $coupon_id Coupon ID.
	 * @param \WC_Coupon $coupon    Coupon.
	 */
	public static function track_coupon_updated( $coupon_id, $coupon = null ) {
		self::coupon_event(
			'wc_coupon_updated',
			'notice',
			$coupon_id,
			$coupon,
			__( 'Coupon updated', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: coupon code, 2: amount, 3: discount type */
			__( 'Coupon "%1$s" (%2$s %3$s) was updated.', 'tbsh-compliance-audit-logger' )
		);
	}

	/**
	 * Coupon trashed.
	 *
	 * @param int $coupon_id Coupon ID.
	 */
	public static function track_coupon_trashed( $coupon_id ) {
		self::coupon_event(
			'wc_coupon_trashed',
			'warning',
			$coupon_id,
			null,
			__( 'Coupon trashed', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: coupon code, 2: amount, 3: discount type */
			__( 'Coupon "%1$s" (%2$s %3$s) was moved to the trash.', 'tbsh-compliance-audit-logger' )
		);
	}

	/**
	 * Coupon deleted.
	 *
	 * @param int $coupon_id Coupon ID.
	 */
	public static function track_coupon_deleted( $coupon_id ) {
		self::coupon_event(
			'wc_coupon_deleted',
			'warning',
			$coupon_id,
			null,
			__( 'Coupon deleted', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: coupon code, 2: amount, 3: discount type */
			__( 'Coupon "%1$s" (%2$s %3$s) was permanently deleted.', 'tbsh-compliance-audit-logger' )
		);
	}

	/**
	 * Write a coupon event.
	 *
	 * @param string          $type      Event type.
	 * @param string          $severity  Severity.
	 * @param int             $coupon_id Coupon ID.
	 * @param \WC_Coupon|null $coupon    Coupon.
	 * @param string          $title     Title.
	 * @param string          $format    Message format (%1$s code, %2$s amount, %3$s discount type).
	 */
	private static function coupon_event( $type, $severity, $coupon_id, $coupon, $title, $format ) {
		if ( ! self::enabled( self::SETTING ) || get_current_user_id() <= 0 ) {
			return;
		}
		if ( ! is_object( $coupon ) && class_exists( 'WC_Coupon' ) ) {
			$coupon = new \WC_Coupon( (int) $coupon_id );
		}
		$code   = is_object( $coupon ) ? self::display( $coupon->get_code(), 60 ) : '#' . (int) $coupon_id;
		$amount = is_object( $coupon ) ? self::display( $coupon->get_amount() ) : '';
		$kind   = is_object( $coupon ) ? sanitize_key( $coupon->get_discount_type() ) : '';

		self::write( self::entry(
			$type,
			'Change Management',
			$severity,
			$title,
			sprintf( $format, $code, $amount, $kind ),
			array(
				'object_type' => 'shop_coupon',
				'object_id'   => (int) $coupon_id,
				'metadata'    => array(
					'code'          => $code,
					'amount'        => $amount,
					'discount_type' => $kind,
				),
			)
		) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Store settings
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Remember WooCommerce option names changed by staff in this request.
	 *
	 * @param string $option Option name.
	 */
	public static function collect_option( $option ) {
		if ( 0 !== strpos( (string) $option, 'woocommerce_' ) || ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		self::$changed_options[ sanitize_key( $option ) ] = true;
	}

	/**
	 * A settings screen or settings AJAX action was saved: log at shutdown.
	 */
	public static function note_settings_saved() {
		if ( ! self::enabled( self::SETTING ) ) {
			return;
		}
		if ( doing_action( 'shutdown' ) ) {
			self::track_settings_saved();
			return;
		}
		self::defer( array( __CLASS__, 'track_settings_saved' ) );
	}

	/**
	 * Log the changed WooCommerce settings (names only).
	 */
	public static function track_settings_saved() {
		$options               = array_keys( self::$changed_options );
		self::$changed_options = array();
		if ( ! $options ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only context for the log entry; WooCommerce verified the save.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$sensitive = (bool) preg_grep( '/_settings$|payment|gateway|checkout|api|webhook/', $options );

		self::write( self::entry(
			'wc_settings_updated',
			'Configuration Management',
			$sensitive ? 'warning' : 'notice',
			__( 'Store settings changed', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: comma-separated option names */
			sprintf( __( 'WooCommerce settings were changed: %s.', 'tbsh-compliance-audit-logger' ), implode( ', ', array_slice( $options, 0, 20 ) ) ),
			array(
				'object_type'     => 'woocommerce_settings',
				'object_id'       => '' !== $section ? $tab . '/' . $section : $tab,
				'compliance_tags' => 'Configuration Management, PCI DSS 10.2',
				'metadata'        => array(
					'tab'     => $tab,
					'section' => $section,
					'options' => array_slice( $options, 0, 100 ),
				),
			)
		) );
	}
}
