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
	private const META_BASE     = '_pwt_addon_coupon_base';
	private const META_MODE     = '_pwt_addon_coupon_mode';
	private const META_AMOUNT   = '_pwt_addon_coupon_amount';
	private const META_PHONE    = '_pwt_addon_coupon_phone';
	private const META_MEETINGS = '_pwt_addon_coupon_meetings';

	/**
	 * Supported add-ons and fallback unit prices.
	 *
	 * @var array<string, float>
	 */
	public const PRODUCT_ID = 19;

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

		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'get_native_coupon_amount' ), 10, 2 );
		add_filter( 'woocommerce_coupon_get_discount_type', array( $this, 'force_fixed_cart_discount_type' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'filter_coupon_label' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_coupon' ), 10, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'filter_coupon_error' ), 10, 3 );

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
				'label'       => __( 'Cupom promocional Pontus', 'pontus-woocommerce-tools' ),
				'description' => __( 'Limita este cupom aos componentes selecionados abaixo.', 'pontus-woocommerce-tools' ),
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
				'id'          => self::META_BASE,
				'label'       => __( 'Plano principal', 'pontus-woocommerce-tools' ),
				'description' => __( 'Aplica o desconto ao valor-base do Escritório Inteligente.', 'pontus-woocommerce-tools' ),
				'value'       => get_post_meta( $coupon_id, self::META_BASE, true ),
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
		$base     = isset( $_POST[ self::META_BASE ] ) ? 'yes' : 'no';
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
		update_post_meta( $coupon_id, self::META_BASE, $base );
		update_post_meta( $coupon_id, self::META_MODE, $mode );
		update_post_meta( $coupon_id, self::META_AMOUNT, max( 0, (float) $amount ) );
		update_post_meta( $coupon_id, self::META_PHONE, $phone );
		update_post_meta( $coupon_id, self::META_MEETINGS, $meetings );

		if ( 'yes' === $enabled && $coupon instanceof \WC_Coupon ) {
			$coupon->set_discount_type( 'fixed_cart' );
			$coupon->set_amount( 0 );
			$coupon->save();
		}
	}

	/**
	 * Returns the discount amount calculated only over eligible add-ons.
	 *
	 * @param float     $amount Coupon amount.
	 * @param WC_Coupon $coupon Coupon object.
	 * @return float
	 */
	public function get_native_coupon_amount( $amount, $coupon ) {
		if ( ! $this->is_addon_coupon( $coupon ) ) {
			return $amount;
		}

		$eligible = $this->get_coupon_targets( $coupon );
		$base     = $this->sum_selected_targets( $this->get_cart_target_totals(), $eligible );

		return $this->calculate_discount( $coupon, $base );
	}

	/**
	 * Forces Pontus coupons to use the native fixed-cart presentation.
	 *
	 * @param string    $discount_type Current discount type.
	 * @param WC_Coupon $coupon        Coupon object.
	 * @return string
	 */
	public function force_fixed_cart_discount_type( $discount_type, $coupon ) {
		if ( $this->is_addon_coupon( $coupon ) ) {
			return 'fixed_cart';
		}

		return $discount_type;
	}

	/**
	 * Identifies the discounted add-ons in the native coupon label.
	 *
	 * @param string    $label  Current coupon label.
	 * @param WC_Coupon $coupon Coupon object.
	 * @return string
	 */
	public function filter_coupon_label( $label, $coupon ) {
		if ( ! $this->is_addon_coupon( $coupon ) ) {
			return $label;
		}

		$addon_labels = array(
			'base'     => __( 'Escritório Inteligente', 'pontus-woocommerce-tools' ),
			'phone'    => __( 'Atendimento Telefônico', 'pontus-woocommerce-tools' ),
			'meetings' => __( 'Pacote Mais Reuniões', 'pontus-woocommerce-tools' ),
		);

		$selected_labels = array();
		foreach ( $this->get_coupon_targets( $coupon ) as $addon_key ) {
			if ( isset( $addon_labels[ $addon_key ] ) ) {
				$selected_labels[] = $addon_labels[ $addon_key ];
			}
		}

		return sprintf(
			/* translators: 1: coupon code, 2: eligible add-on names. */
			__( 'Cupom %1$s: %2$s', 'pontus-woocommerce-tools' ),
			$coupon->get_code(),
			implode( ' + ', $selected_labels )
		);
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

		return $this->cart_has_eligible_target( $coupon );
	}

	/**
	 * Checks whether the cart contains a component targeted by the coupon.
	 *
	 * This is the shared eligibility source for manual validation and
	 * automatic campaign application, including compact YITH option data.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return bool
	 */
	public function cart_has_eligible_target( $coupon ) {
		if ( ! $this->is_addon_coupon( $coupon ) ) {
			return false;
		}

		$eligible = $this->get_coupon_targets( $coupon );
		if ( empty( $eligible ) ) {
			return false;
		}

		return 0 < $this->sum_selected_targets( $this->get_cart_target_totals(), $eligible );
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
			return __( 'Este cupom é válido apenas quando um componente Pontus elegível está no carrinho.', 'pontus-woocommerce-tools' );
		}

		return $message;
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
	private function get_coupon_targets( $coupon ) {
		$addons = array();

		if ( 'yes' === $coupon->get_meta( self::META_BASE, true ) ) {
			$addons[] = 'base';
		}

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
	private function get_cart_target_totals() {
		$totals = array(
			'base'     => 0.0,
			'phone'    => 0.0,
			'meetings' => 0.0,
		);

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $totals;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$quantity   = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;

			if ( self::PRODUCT_ID === $product_id ) {
				$base_price = isset( $cart_item['yith_wapo_item_price'] )
					? (float) wc_format_decimal( $cart_item['yith_wapo_item_price'] )
					: 0.0;

				if ( $base_price <= 0 && isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ) {
					$base_price = (float) $cart_item['data']->get_price();
				}

				$totals['base'] += $base_price * $quantity;
			}

			if ( empty( $cart_item['yith_wapo_options'] ) || ! is_array( $cart_item['yith_wapo_options'] ) ) {
				continue;
			}

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

			foreach ( $option as $option_key => $option_value ) {
				if ( is_scalar( $option_value ) && preg_match( '/^(\\d+)-(\\d+)$/', (string) $option_key, $matches ) ) {
					$records[] = array(
						'addon_id'  => $matches[1],
						'option_id' => (string) $option_key,
						'value'     => (string) $option_value,
					);
				}
			}

			if ( $this->looks_like_option_record( $option ) ) {
				$records[] = $option;
			}

			foreach ( $option as $nested_value ) {
				if ( is_array( $nested_value ) ) {
					$records = array_merge( $records, $this->flatten_option_records( $nested_value ) );
				}
			}
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
	private function sum_selected_targets( $totals, $eligible ) {
		$total = 0.0;

		foreach ( $eligible as $addon_key ) {
			$total += isset( $totals[ $addon_key ] ) ? (float) $totals[ $addon_key ] : 0.0;
		}

		return $total;
	}

}
