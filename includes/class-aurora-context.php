<?php
/**
 * Aurora recommendation context for the product and checkout journey.
 *
 * @package PontusWooCommerceTools
 */

namespace Pontus\WooCommerceTools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries Aurora recommendations from the product URL to the WooCommerce order.
 */
final class Aurora_Context {

	private const SESSION_KEY = 'pwt_aurora_context';
	private const PRODUCT_ID  = 19;

	/**
	 * Singleton instance.
	 *
	 * @var Aurora_Context|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared module instance.
	 *
	 * @return Aurora_Context
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers WordPress and WooCommerce hooks.
	 */
	private function __construct() {
		add_action( 'wp_loaded', array( $this, 'capture_context' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_order_context' ), 8, 2 );
		add_action( 'woocommerce_thankyou', array( $this, 'clear_context' ), 20 );

		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'store_cart_context' ), 10, 3 );
		add_filter( 'pwt_checkout_tracking_config', array( $this, 'add_tracking_context' ) );
	}

	/**
	 * Captures a new Aurora context only when the link explicitly declares it.
	 */
	public function capture_context() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$origin = isset( $_GET['origem'] ) ? sanitize_key( wp_unslash( $_GET['origem'] ) ) : '';
		if ( 'aurora' !== $origin ) {
			return;
		}

		$context = $this->context_from_request( $_GET );
		if ( empty( $context['aurora_lead_id'] ) ) {
			return;
		}

		WC()->session->set( self::SESSION_KEY, $context );
	}

	/**
	 * Loads the browser behavior only on the target product and checkout.
	 */
	public function enqueue_assets() {
		if ( ! is_product( self::PRODUCT_ID ) && ! is_checkout() ) {
			return;
		}

		$context = $this->get_context();
		if ( empty( $context['aurora_lead_id'] ) ) {
			return;
		}

		wp_enqueue_script(
			'pwt-aurora-context',
			PWT_PLUGIN_URL . 'assets/js/aurora-context.js',
			array(),
			PWT_VERSION,
			true
		);

		wp_localize_script(
			'pwt-aurora-context',
			'pwtAuroraContext',
			$context
		);
	}

	/**
	 * Persists the recommendation alongside the eligible cart item.
	 *
	 * @param array $cart_item_data Existing cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public function store_cart_context( $cart_item_data, $product_id, $variation_id ) {
		unset( $variation_id );

		if ( self::PRODUCT_ID !== (int) $product_id ) {
			return $cart_item_data;
		}

		$context = $this->context_from_request( $_POST );
		if ( empty( $context['aurora_lead_id'] ) ) {
			$context = $this->get_context();
		}

		if ( ! empty( $context['aurora_lead_id'] ) ) {
			$cart_item_data['pwt_aurora_context'] = $context;
		}

		return $cart_item_data;
	}

	/**
	 * Stores protected order metadata before the order is first saved.
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted checkout data.
	 */
	public function store_order_context( $order, $data ) {
		unset( $data );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$context = $this->get_context_from_cart();
		if ( empty( $context['aurora_lead_id'] ) ) {
			$context = $this->get_context();
		}

		if ( empty( $context['aurora_lead_id'] ) ) {
			return;
		}

		$order->update_meta_data( '_pwt_aurora_lead_id', $context['aurora_lead_id'] );
		$order->update_meta_data( '_pwt_aurora_origin', $context['aurora_origin'] );
		$order->update_meta_data( '_pwt_aurora_plan_recommended', $context['aurora_plan_recommended'] );
		$order->update_meta_data( '_pwt_aurora_phone_recommended', $context['aurora_phone_recommended'] );
	}

	/**
	 * Adds the context to the existing same-origin checkout tracking payload.
	 *
	 * @param array $config Tracking configuration.
	 * @return array
	 */
	public function add_tracking_context( $config ) {
		$context = $this->get_context_from_cart();
		if ( empty( $context['aurora_lead_id'] ) ) {
			$context = $this->get_context();
		}

		if ( ! empty( $context['aurora_lead_id'] ) ) {
			$config['auroraContext'] = $context;
		}

		return $config;
	}

	/**
	 * Clears the context only after an order reaches the thank-you page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function clear_context( $order_id ) {
		if ( $order_id && function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( self::SESSION_KEY );
		}
	}

	/**
	 * Returns the normalized context currently held in the WooCommerce session.
	 *
	 * @return array
	 */
	private function get_context() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}

		$context = WC()->session->get( self::SESSION_KEY );
		return is_array( $context ) ? $this->sanitize_context( $context ) : array();
	}

	/**
	 * Prefers the context attached to the Pontus cart item.
	 *
	 * @return array
	 */
	private function get_context_from_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if (
				self::PRODUCT_ID === (int) $cart_item['product_id']
				&& ! empty( $cart_item['pwt_aurora_context'] )
				&& is_array( $cart_item['pwt_aurora_context'] )
			) {
				return $this->sanitize_context( $cart_item['pwt_aurora_context'] );
			}
		}

		return array();
	}

	/**
	 * Normalizes URL or form names into the webhook field names.
	 *
	 * @param array $request Request data.
	 * @return array
	 */
	private function context_from_request( $request ) {
		$request = is_array( $request ) ? $request : array();

		return $this->sanitize_context(
			array(
				'aurora_lead_id'           => isset( $request['lead_id'] ) ? wp_unslash( $request['lead_id'] ) : ( isset( $request['aurora_lead_id'] ) ? wp_unslash( $request['aurora_lead_id'] ) : '' ),
				'aurora_origin'            => isset( $request['origem'] ) ? wp_unslash( $request['origem'] ) : ( isset( $request['aurora_origin'] ) ? wp_unslash( $request['aurora_origin'] ) : '' ),
				'aurora_plan_recommended'  => isset( $request['plano_recomendado'] ) ? wp_unslash( $request['plano_recomendado'] ) : ( isset( $request['aurora_plan_recommended'] ) ? wp_unslash( $request['aurora_plan_recommended'] ) : '' ),
				'aurora_phone_recommended' => isset( $request['atendimento_telefonico'] ) ? wp_unslash( $request['atendimento_telefonico'] ) : ( isset( $request['aurora_phone_recommended'] ) ? wp_unslash( $request['aurora_phone_recommended'] ) : '' ),
			)
		);
	}

	/**
	 * Applies strict allowlists to values that control the product UI.
	 *
	 * @param array $context Raw context.
	 * @return array
	 */
	private function sanitize_context( $context ) {
		$lead_id = isset( $context['aurora_lead_id'] ) ? sanitize_text_field( (string) $context['aurora_lead_id'] ) : '';
		$lead_id = preg_match( '/^[a-zA-Z0-9_-]{1,128}$/', $lead_id ) ? $lead_id : '';

		$origin = isset( $context['aurora_origin'] ) ? sanitize_key( (string) $context['aurora_origin'] ) : '';
		$origin = 'aurora' === $origin ? 'aurora' : '';

		$plan = isset( $context['aurora_plan_recommended'] ) ? sanitize_key( (string) $context['aurora_plan_recommended'] ) : '';
		$plan = in_array( $plan, array( 'basico', 'mais_horas' ), true ) ? $plan : '';

		$phone = isset( $context['aurora_phone_recommended'] ) ? sanitize_key( (string) $context['aurora_phone_recommended'] ) : '';
		$phone = in_array( $phone, array( 'sim', 'nao' ), true ) ? $phone : '';

		return array(
			'aurora_lead_id'           => $lead_id,
			'aurora_origin'            => $origin,
			'aurora_plan_recommended'  => $plan,
			'aurora_phone_recommended' => $phone,
		);
	}
}
