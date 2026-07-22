<?php
/**
 * Coupons restricted to Pontus YITH add-ons.
 *
 * @package PontusWooCommerceTools
 */

namespace Pontus\WooCommerceTools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds coupon controls and calculates discounts only over eligible add-ons.
 */
final class Coupon_Addons {

	/**
	 * Coupon meta keys.
	 */
	private const META_ENABLED  = '_pwt_addon_coupon_enabled';
	private const META_MODE     = '_pwt_addon_coupon_mode';
	private const META_AMOUNT   = '_pwt_addon_coupon_amount';
	private const META_PHONE    = '_pwt_addon_coupon_phone';
	private const META_MEETINGS = '_pwt_addon_coupon_meetings';

	/**
	 * Supported add-ons and fallback unit prices.
	 *
	 * @var array<string, float>
	 */
	private const ADDON_FALLBACK_PRICES = array(
		'phone'    => 50.0,
		'meetings' => 350.0,
	);

	/**
	 * Singleton instance.
	 *
	 * @var Coupon_Addons|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared module instance.
	 *
	 * @return Coupon_Addons
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers WooCommerce hooks.
	 */
	private function __construct() {
		add_action( 'woocommerce_coupon_options', array( $this, 'render_coupon_options' ) );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon_options' ), 10, 2 );

		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'zero_native_coupon_amount' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_coupon' ), 10, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'filter_coupon_error' ), 10, 3 );

		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_addon_discounts' ), 30 );
	}

	/**
	 * Renders Pontus controls in the coupon editor.
	 *
	 * @param int $coupon_id Coupon post ID.
	 */
	public function render_coupon_options( $coupon_id ) {
		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_ENABLED,
				'label'       => __( 'Desconto em adicionais Pontus', 'pontus-woocommerce-tools' ),
				'description' => __( 'Limita este cupom aos adicionais selecionados abaixo. O valor-base do produto não recebe desconto.', 'pontus-woocommerce-tools' ),
				'value'       => get_post_meta( $coupon_id, self::META_ENABLED, true ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'          => self::META_MODE,
				'label'       => __( 'Modalidade do desconto', 'pontus-woocommerce-tools' ),
				'description' => __( 'Percentual, valor fixo ou gratuidade integral dos adicionais elegíveis.', 'pontus-woocommerce-tools' ),
				'desc_tip'    => true,
				'value'       => get_post_meta( $coupon_id, self::META_MODE, true ) ?: 'percent',
				'options'     => array(
					'percent' => __( 'Percentual', 'pontus-woocommerce-tools' ),
					'fixed'   => __( 'Valor fixo', 'pontus-woocommerce-tools' ),
					'free'    => __( 'Gratuito', 'pontus-woocommerce-tools' ),
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => self::META_AMOUNT,
				'label'             => __( 'Valor do desconto', 'pontus-woocommerce-tools' ),
				'description'       => __( 'Informe a porcentagem ou o valor em reais. No modo gratuito, este campo é ignorado.', 'pontus-woocommerce-tools' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'value'             => get_post_meta( $coupon_id, self::META_AMOUNT, true ),
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'    => self::META_PHONE,
				'label' => __( 'Atendimento Telefônico', 'pontus-woocommerce-tools' ),
				'value' => get_post_meta( $coupon_id, self::META_PHONE, true ),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'    => self::META_MEETINGS,
				'label' => __( 'Pacote Mais Reuniões', 'pontus-woocommerce-tools' ),
				'value' => get_post_meta( $coupon_id, self::META_MEETINGS, true ),
			)
		);

		echo '</div>';
	}

	/**
	 * Persists Pontus coupon settings.
	 *
	 * @param int       $coupon_id Coupon post ID.
	 * @param WC_Coupon $coupon    Coupon object.
	 */
	public function save_coupon_options( $coupon_id, $coupon ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$enabled  = isset( $_POST[ self::META_ENABLED ] ) ? 'yes' : 'no';
		$phone    = isset( $_POST[ self::META_PHONE ] ) ? 'yes' : 'no';
		$meetings = isset( $_POST[ self::META_MEETINGS ] ) ? 'yes' : 'no';

		$mode = isset( $_POST[ self::META_MODE ] )
			? sanitize_key( wp_unslash( $_POST[ self::META_MODE ] ) )
			: 'percent';

		if ( ! in_array( $mode, array( 'percent', 'fixed', 'free' ), true ) ) {
			$mode = 'percent';
		}

		$amount = isset( $_POST[ self::META_AMOUNT ] )
			? wc_format_decimal( wp_unslash( $_POST[ self::META_AMOUNT ] ) )
			: '0';

		update_post_meta( $coupon_id, self::META_ENABLED, $enabled );
		update_post_meta( $coupon_id, self::META_MODE, $mode );
		update_post_meta( $coupon_id, self::META_AMOUNT, max( 0, (float) $amount ) );
		update_post_meta( $coupon_id, self::META_PHONE, $phone );
		update_post_meta( $coupon_id, self::META_MEETINGS, $meetings );

		if ( 'yes' === $enabled && $coupon instanceof \WC_Coupon ) {
			$coupon->set_amount( 0 );
			$coupon->save();
		}
	}

	/**
	 * Prevents WooCommerce from discounting the complete product line.
	 *
	 * @param float     $amount Coupon amount.
	 * @param WC_Coupon $coupon Coupon object.
	 * @return float
	 */
	public function zero_native_coupon_amount( $amount, $coupon ) {
		if ( $this->is_addon_coupon( $coupon ) ) {
			return 0.0;
		}

		return $amount;
	}

	/**
	 * Requires an eligible add-on in the cart.
	 *
	 * @param bool         $valid     Current validity.
	 * @param WC_Coupon    $coupon    Coupon object.
	 * @param WC_Discounts $discounts Discounts object.
	 * @return bool
	 */
	public function validate_coupon( $valid, $coupon, $discounts ) {
		unset( $discounts );

		if ( ! $valid || ! $this->is_addon_coupon( $coupon ) ) {
			return $valid;
		}

		$eligible = $this->get_coupon_addons( $coupon );
		if ( empty( $eligible ) ) {
			return false;
		}

		$totals = $this->get_cart_addon_totals();

		return 0 < $this->sum_selected_addons( $totals, $eligible );
	}

	/**
	 * Returns a clearer error for Pontus coupons without eligible add-ons.
	 *
	 * @param string    $message    Existing message.
	 * @param int       $error_code Coupon error code.
	 * @param WC_Coupon $coupon     Coupon object.
	 * @return string
	 */
	public function filter_coupon_error( $message, $error_code, $coupon ) {
		unset( $error_code );

		if ( $this->is_addon_coupon( $coupon ) ) {
			return __( 'Este cupom é válido apenas quando um adicional Pontus elegível está selecionado.', 'pontus-woocommerce-tools' );
		}

		return $message;
	}

	/**
	 * Adds negative fees representing each applied Pontus coupon.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function apply_addon_discounts( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$remaining = $this->get_cart_addon_totals();

		if ( empty( array_filter( $remaining ) ) ) {
			return;
		}

		foreach ( $cart->get_applied_coupons() as $coupon_code ) {
			$coupon = new \WC_Coupon( $coupon_code );

			if ( ! $this->is_addon_coupon( $coupon ) ) {
				continue;
			}

			$eligible = $this->get_coupon_addons( $coupon );
			$base     = $this->sum_selected_addons( $remaining, $eligible );

			if ( $base <= 0 ) {
				continue;
			}

			$discount = $this->calculate_discount( $coupon, $base );
			if ( $discount <= 0 ) {
				continue;
			}

			$discount = min( $discount, $base );

			$cart->add_fee(
				sprintf(
					/* translators: %s: coupon code. */
					__( 'Cupom %s: adicionais', 'pontus-woocommerce-tools' ),
					wc_format_coupon_code( $coupon_code )
				),
				-1 * $discount,
				false
			);

			$this->consume_discount( $remaining, $eligible, $discount, $base );
		}
	}

	/**
	 * Calculates the discount according to the configured mode.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @param float     $base   Eligible add-on total.
	 * @return float
	 */
	private function calculate_discount( $coupon, $base ) {
		$mode   = (string) $coupon->get_meta( self::META_MODE, true );
		$amount = max( 0, (float) $coupon->get_meta( self::META_AMOUNT, true ) );

		if ( 'free' === $mode ) {
			return $base;
		}

		if ( 'fixed' === $mode ) {
			return min( $amount, $base );
		}

		return min( $base * min( $amount, 100 ) / 100, $base );
	}

	/**
	 * Checks whether a coupon is managed by this module.
	 *
	 * @param mixed $coupon Coupon object.
	 * @return bool
	 */
	private function is_addon_coupon( $coupon ) {
		return $coupon instanceof \WC_Coupon
			&& 'yes' === $coupon->get_meta( self::META_ENABLED, true );
	}

	/**
	 * Returns the add-on keys enabled for a coupon.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return string[]
	 */
	private function get_coupon_addons( $coupon ) {
		$addons = array();

		if ( 'yes' === $coupon->get_meta( self::META_PHONE, true ) ) {
			$addons[] = 'phone';
		}

		if ( 'yes' === $coupon->get_meta( self::META_MEETINGS, true ) ) {
			$addons[] = 'meetings';
		}

		return $addons;
	}

	/**
	 * Totals eligible YITH add-ons currently present in the cart.
	 *
	 * @return array<string, float>
	 */
	private function get_cart_addon_totals() {
		$totals = array(
			'phone'    => 0.0,
			'meetings' => 0.0,
		);

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $totals;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['yith_wapo_options'] ) || ! is_array( $cart_item['yith_wapo_options'] ) ) {
				continue;
			}

			$quantity = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;

			foreach ( $this->flatten_option_records( $cart_item['yith_wapo_options'] ) as $option ) {
				$addon_key = $this->identify_addon( $option );

				if ( ! $addon_key ) {
					continue;
				}

				$price = $this->extract_option_price( $option, $addon_key );
				$totals[ $addon_key ] += $price * $quantity;
			}
		}

		return $totals;
	}

	/**
	 * Flattens nested YITH option data while preserving record arrays.
	 *
	 * @param array $options Raw options.
	 * @return array<int, array>
	 */
	private function flatten_option_records( $options ) {
		$records = array();

		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			if ( $this->looks_like_option_record( $option ) ) {
				$records[] = $option;
				continue;
			}

			$records = array_merge( $records, $this->flatten_option_records( $option ) );
		}

		return $records;
	}

	/**
	 * Detects arrays that represent one selected YITH option.
	 *
	 * @param array $option Candidate record.
	 * @return bool
	 */
	private function looks_like_option_record( $option ) {
		$known_keys = array(
			'value',
			'label',
			'name',
			'option_label',
			'addon_label',
			'option_id',
			'addon_id',
			'price',
			'option_price',
			'addon_price',
		);

		return (bool) array_intersect( $known_keys, array_keys( $option ) );
	}

	/**
	 * Maps a YITH option record to a Pontus add-on.
	 *
	 * @param array $option YITH option record.
	 * @return string
	 */
	private function identify_addon( $option ) {
		$label = $this->extract_option_label( $option );
		$slug  = remove_accents( strtolower( $label ) );

		if ( false !== strpos( $slug, 'atendimento telefonico' ) ) {
			return 'phone';
		}

		if ( false !== strpos( $slug, 'mais reunioes' ) ) {
			return 'meetings';
		}

		$addon_id  = isset( $option['addon_id'] ) ? (string) $option['addon_id'] : '';
		$option_id = isset( $option['option_id'] ) ? (string) $option['option_id'] : '';

		if ( '1' === $addon_id && in_array( $option_id, array( '0', '1-0' ), true ) ) {
			return 'phone';
		}

		if ( '1' === $addon_id && in_array( $option_id, array( '1', '1-1' ), true ) ) {
			return 'meetings';
		}

		return '';
	}

	/**
	 * Gets a readable option label.
	 *
	 * @param array $option YITH option record.
	 * @return string
	 */
	private function extract_option_label( $option ) {
		foreach ( array( 'value', 'label', 'name', 'option_label', 'addon_label' ) as $key ) {
			if ( isset( $option[ $key ] ) && is_scalar( $option[ $key ] ) ) {
				return wp_strip_all_tags( (string) $option[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Extracts the real YITH option price or uses the current safe fallback.
	 *
	 * @param array  $option    YITH option record.
	 * @param string $addon_key Pontus add-on key.
	 * @return float
	 */
	private function extract_option_price( $option, $addon_key ) {
		foreach ( array( 'price', 'option_price', 'addon_price', 'calculated_price' ) as $key ) {
			if ( ! isset( $option[ $key ] ) || ! is_scalar( $option[ $key ] ) ) {
				continue;
			}

			$price = (float) wc_format_decimal( $option[ $key ] );
			if ( $price > 0 ) {
				return $price;
			}
		}

		return self::ADDON_FALLBACK_PRICES[ $addon_key ];
	}

	/**
	 * Sums selected add-on totals.
	 *
	 * @param array<string, float> $totals   Totals by add-on.
	 * @param string[]             $eligible Eligible add-on keys.
	 * @return float
	 */
	private function sum_selected_addons( $totals, $eligible ) {
		$total = 0.0;

		foreach ( $eligible as $addon_key ) {
			$total += isset( $totals[ $addon_key ] ) ? (float) $totals[ $addon_key ] : 0.0;
		}

		return $total;
	}

	/**
	 * Consumes the discounted portion so stacked coupons cannot exceed the add-on total.
	 *
	 * @param array<string, float> $remaining Remaining totals by add-on.
	 * @param string[]             $eligible  Eligible add-on keys.
	 * @param float                $discount  Discount applied.
	 * @param float                $base      Eligible base before this coupon.
	 */
	private function consume_discount( &$remaining, $eligible, $discount, $base ) {
		foreach ( $eligible as $addon_key ) {
			if ( empty( $remaining[ $addon_key ] ) ) {
				continue;
			}

			$share = $remaining[ $addon_key ] / $base;
			$remaining[ $addon_key ] = max( 0, $remaining[ $addon_key ] - ( $discount * $share ) );
		}
	}
}
