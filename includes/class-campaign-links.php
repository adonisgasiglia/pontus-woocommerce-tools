<?php
/**
 * Promotional links and sale-price presentation.
 *
 * @package PontusWooCommerceTools
 */

namespace Pontus\WooCommerceTools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects campaign URLs to WooCommerce coupons.
 */
final class Campaign_Links {

	private const QUERY_ARG   = 'pwt_coupon';
	private const SESSION_KEY = 'pwt_campaign_coupon';
	private const COOKIE_KEY  = 'pwt_campaign_coupon';

	private const META_ENABLED  = '_pwt_addon_coupon_enabled';
	private const META_BASE     = '_pwt_addon_coupon_base';
	private const META_MODE     = '_pwt_addon_coupon_mode';
	private const META_AMOUNT   = '_pwt_addon_coupon_amount';
	private const META_PHONE    = '_pwt_addon_coupon_phone';
	private const META_MEETINGS = '_pwt_addon_coupon_meetings';

	/**
	 * Singleton instance.
	 *
	 * @var Campaign_Links|null
	 */
	private static $instance = null;

	/**
	 * Whether the current coupon application was initiated by the campaign.
	 *
	 * @var bool
	 */
	private $is_applying_campaign_coupon = false;

	/**
	 * Returns the shared module instance.
	 *
	 * @return Campaign_Links
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
		add_action( 'wp_loaded', array( $this, 'capture_campaign' ), 25 );
		add_action( 'template_redirect', array( $this, 'apply_on_cart_or_checkout' ), 5 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'apply_pending_coupon' ), 25 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'apply_pending_coupon' ), 30 );
		add_action( 'woocommerce_before_cart', array( $this, 'apply_pending_coupon' ), 5 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'apply_pending_coupon' ), 5 );
		add_action( 'woocommerce_checkout_init', array( $this, 'apply_pending_coupon' ), 5 );
		add_action( 'woocommerce_removed_coupon', array( $this, 'clear_removed_campaign' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_campaign_assets' ) );

		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'add_campaign_to_checkout_redirect' ), 999, 2 );
		add_filter( 'woocommerce_add_to_cart_form_action', array( $this, 'add_campaign_to_cart_form_action' ), 999 );
		add_filter( 'woocommerce_coupon_message', array( $this, 'filter_automatic_coupon_message' ), 10, 3 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_main_product_price_html' ), 20, 2 );
		add_shortcode( 'pontus_preco_plano', array( $this, 'render_plan_price_shortcode' ) );
	}

	/**
	 * Stores a valid campaign coupon from the URL.
	 */
	public function capture_campaign() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$from_query = isset( $_GET[ self::QUERY_ARG ] );
		$raw_code   = $from_query
			? wp_unslash( $_GET[ self::QUERY_ARG ] )
			: ( isset( $_COOKIE[ self::COOKIE_KEY ] ) ? wp_unslash( $_COOKIE[ self::COOKIE_KEY ] ) : '' );
		$code       = wc_format_coupon_code( $raw_code );

		if ( '' === $code ) {
			return;
		}

		$coupon = new \WC_Coupon( $code );

		if ( $this->is_campaign_coupon( $coupon ) ) {
			WC()->session->set( self::SESSION_KEY, $coupon->get_code() );
			wc_setcookie( self::COOKIE_KEY, $coupon->get_code(), time() + DAY_IN_SECONDS );
			return;
		}

		WC()->session->__unset( self::SESSION_KEY );
		wc_setcookie( self::COOKIE_KEY, '', time() - HOUR_IN_SECONDS );
	}

	/**
	 * Sends the product form directly to checkout with the campaign code.
	 *
	 * This runs before Elementor or YITH can bypass the standard WooCommerce
	 * post-add-to-cart redirect.
	 *
	 * @param string $url Original form action.
	 * @return string
	 */
	public function add_campaign_to_cart_form_action( $url ) {
		$coupon = $this->get_campaign_coupon();
		if ( ! $coupon ) {
			return $url;
		}

		global $product;
		if ( $product instanceof \WC_Product && Coupon_Addons::PRODUCT_ID !== $product->get_id() ) {
			return $url;
		}

		return add_query_arg( self::QUERY_ARG, $coupon->get_code(), wc_get_checkout_url() );
	}

	/**
	 * Carries the campaign code in the checkout redirect URL.
	 *
	 * @param string           $url     Redirect URL.
	 * @param WC_Product|mixed $product Product added to the cart.
	 * @return string
	 */
	public function add_campaign_to_checkout_redirect( $url, $product = null ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return $url;
		}

		if ( $product instanceof \WC_Product && Coupon_Addons::PRODUCT_ID !== $product->get_id() ) {
			return $url;
		}

		$code   = (string) WC()->session->get( self::SESSION_KEY );
		$coupon = new \WC_Coupon( $code );

		if ( '' === $code || ! $this->is_campaign_coupon( $coupon ) ) {
			return $url;
		}

		$redirect_url = $url ? $url : wc_get_checkout_url();

		return add_query_arg( self::QUERY_ARG, rawurlencode( $coupon->get_code() ), $redirect_url );
	}

	/**
	 * Applies a pending campaign on cart or checkout requests.
	 */
	public function apply_on_cart_or_checkout() {
		if ( ( is_cart() || is_checkout() ) && function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			$this->apply_pending_coupon();
		}
	}

	/**
	 * Applies the campaign coupon after an eligible item enters the cart.
	 */
	public function apply_pending_coupon() {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			return;
		}

		$code = (string) WC()->session->get( self::SESSION_KEY );
		if ( '' === $code || WC()->cart->has_discount( $code ) ) {
			return;
		}

		$coupon = new \WC_Coupon( $code );
		if ( ! $this->is_campaign_coupon( $coupon ) || ! $this->cart_contains_campaign_target( $coupon ) ) {
			return;
		}

		$this->is_applying_campaign_coupon = true;

		try {
			WC()->cart->apply_coupon( $code );
		} finally {
			$this->is_applying_campaign_coupon = false;
		}
	}

	/**
	 * Hides only the success notice created by automatic campaign application.
	 *
	 * Manual coupon messages remain unchanged.
	 *
	 * @param string    $message      Coupon message.
	 * @param int       $message_code WooCommerce message code.
	 * @param WC_Coupon $coupon       Coupon object.
	 * @return string
	 */
	public function filter_automatic_coupon_message( $message, $message_code, $coupon ) {
		if (
			! $this->is_applying_campaign_coupon
			|| \WC_Coupon::WC_COUPON_SUCCESS !== $message_code
			|| ! $coupon instanceof \WC_Coupon
		) {
			return $message;
		}

		$campaign_code = function_exists( 'WC' ) && WC()->session
			? (string) WC()->session->get( self::SESSION_KEY )
			: '';

		if ( wc_format_coupon_code( $coupon->get_code() ) !== wc_format_coupon_code( $campaign_code ) ) {
			return $message;
		}

		return '';
	}

	/**
	 * Checks whether the cart already contains a target of the campaign.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return bool
	 */
	private function cart_contains_campaign_target( $coupon ) {
		return Coupon_Addons::instance()->cart_has_eligible_target( $coupon );
	}

	/**
	 * Clears campaign state when the visitor removes its coupon.
	 *
	 * @param string $coupon_code Removed coupon code.
	 */
	public function clear_removed_campaign( $coupon_code ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$campaign_code = (string) WC()->session->get( self::SESSION_KEY );
		if ( wc_format_coupon_code( $coupon_code ) === wc_format_coupon_code( $campaign_code ) ) {
			WC()->session->__unset( self::SESSION_KEY );
			wc_setcookie( self::COOKIE_KEY, '', time() - HOUR_IN_SECONDS );
		}
	}

	/**
	 * Displays a sale price for the main plan on campaign product pages.
	 *
	 * @param string     $price_html Existing price HTML.
	 * @param WC_Product $product    Product object.
	 * @return string
	 */
	public function filter_main_product_price_html( $price_html, $product ) {
		if ( ! is_product() || ! $product instanceof \WC_Product || Coupon_Addons::PRODUCT_ID !== $product->get_id() ) {
			return $price_html;
		}

		$coupon = $this->get_campaign_coupon();
		if ( ! $coupon || ! in_array( 'base', $this->get_coupon_targets( $coupon ), true ) ) {
			return $price_html;
		}

		$original = (float) $product->get_price();
		$sale     = $this->get_target_sale_price( $coupon, 'base', $original );

		if ( $sale >= $original ) {
			return $price_html;
		}

		return wc_format_sale_price( $original, $sale ) . $product->get_price_suffix();
	}

	/**
	 * Loads the YITH add-on sale-price presentation.
	 */
	public function enqueue_campaign_assets() {
		$coupon  = $this->get_campaign_coupon();
		$targets = $coupon ? $this->get_coupon_targets( $coupon ) : array();

		wp_enqueue_style(
			'pwt-campaign-prices',
			PWT_PLUGIN_URL . 'assets/css/campaign-prices.css',
			array(),
			PWT_VERSION
		);

		wp_enqueue_script(
			'pwt-campaign-prices',
			PWT_PLUGIN_URL . 'assets/js/campaign-prices.js',
			array(),
			PWT_VERSION,
			true
		);

		$prices = array();
		foreach ( array( 'phone' => 50.0, 'meetings' => 350.0 ) as $target => $original ) {
			if ( $coupon && in_array( $target, $targets, true ) ) {
				$prices[ $target ] = array(
					'original' => $original,
					'sale'     => $this->get_target_sale_price( $coupon, $target, $original ),
				);
			}
		}

		$base_product  = wc_get_product( Coupon_Addons::PRODUCT_ID );
		$base_original = $base_product instanceof \WC_Product ? (float) $base_product->get_price() : 189.0;
		$base_sale     = $coupon && in_array( 'base', $targets, true )
			? $this->get_target_sale_price( $coupon, 'base', $base_original )
			: $base_original;

		wp_localize_script(
			'pwt-campaign-prices',
			'pwtCampaignPrices',
			array(
				'currency'    => get_woocommerce_currency(),
				'locale'      => str_replace( '_', '-', get_locale() ),
				'period'      => __( '/mês', 'pontus-woocommerce-tools' ),
				'couponCode'  => $coupon ? $coupon->get_code() : '',
				'queryArg'    => self::QUERY_ARG,
				'isCheckout'  => is_checkout(),
				'mode'        => $coupon ? (string) $coupon->get_meta( self::META_MODE, true ) : '',
				'amount'      => $coupon ? (float) $coupon->get_meta( self::META_AMOUNT, true ) : 0,
				'targetCount' => count( $targets ),
				'basePrice'   => array(
					'original' => $base_original,
					'sale'     => $base_sale,
				),
				'prices'      => $prices,
			)
		);
	}

	/**
	 * Renders an isolated dynamic total for Elementor.
	 *
	 * @return string
	 */
	public function render_plan_price_shortcode() {
		$product = wc_get_product( Coupon_Addons::PRODUCT_ID );
		$price   = $product instanceof \WC_Product ? (float) $product->get_price() : 189.0;

		return sprintf(
			'<span class="pwt-plan-price-shortcode" data-pwt-plan-price>%1$s<span class="pwt-plan-price-period">%2$s</span></span>',
			wp_kses_post( wc_price( $price ) ),
			esc_html__( '/mês', 'pontus-woocommerce-tools' )
		);
	}

	/**
	 * Returns the active campaign coupon.
	 *
	 * @return WC_Coupon|null
	 */
	private function get_campaign_coupon() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		$code = (string) WC()->session->get( self::SESSION_KEY );
		if ( '' === $code ) {
			return null;
		}

		$coupon = new \WC_Coupon( $code );

		return $this->is_campaign_coupon( $coupon ) ? $coupon : null;
	}

	/**
	 * Checks whether the coupon can be used as a campaign.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return bool
	 */
	private function is_campaign_coupon( $coupon ) {
		return $coupon instanceof \WC_Coupon
			&& 0 < $coupon->get_id()
			&& 'yes' === $coupon->get_meta( self::META_ENABLED, true )
			&& ! empty( $this->get_coupon_targets( $coupon ) );
	}

	/**
	 * Returns the configured campaign targets.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return string[]
	 */
	private function get_coupon_targets( $coupon ) {
		$targets = array();

		$meta_targets = array(
			'base'     => self::META_BASE,
			'phone'    => self::META_PHONE,
			'meetings' => self::META_MEETINGS,
		);

		foreach ( $meta_targets as $target => $meta_key ) {
			if ( 'yes' === $coupon->get_meta( $meta_key, true ) ) {
				$targets[] = $target;
			}
		}

		return $targets;
	}

	/**
	 * Calculates the displayed campaign price for one target.
	 *
	 * @param WC_Coupon $coupon   Coupon object.
	 * @param string    $target   Target key.
	 * @param float     $original Original component price.
	 * @return float
	 */
	private function get_target_sale_price( $coupon, $target, $original ) {
		$mode   = (string) $coupon->get_meta( self::META_MODE, true );
		$amount = max( 0, (float) $coupon->get_meta( self::META_AMOUNT, true ) );

		if ( 'free' === $mode ) {
			return 0.0;
		}

		if ( 'percent' === $mode ) {
			return max( 0, $original * ( 1 - min( $amount, 100 ) / 100 ) );
		}

		$target_prices = array(
			'base'     => 189.0,
			'phone'    => 50.0,
			'meetings' => 350.0,
		);
		$target_prices[ $target ] = $original;

		$total = 0.0;
		foreach ( $this->get_coupon_targets( $coupon ) as $target_key ) {
			$total += isset( $target_prices[ $target_key ] ) ? $target_prices[ $target_key ] : 0.0;
		}

		if ( $total <= 0 ) {
			return $original;
		}

		$allocated_discount = min( $amount, $total ) * ( $original / $total );

		return max( 0, $original - $allocated_discount );
	}
}
