<?php
/**
 * Compatibility with the official Efí WooCommerce gateways.
 *
 * @package PontusWooCommerceTools
 */

namespace Pontus\WooCommerceTools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes Efí read the gross order-item price that already includes YITH add-ons.
 */
final class Efi_Compatibility {

	/**
	 * Efí gateways supported by this compatibility layer.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_GATEWAYS = array(
		'wc_gerencianet_pix',
		'wc_gerencianet_cartao',
	);

	/**
	 * Effective gross prices keyed by product ID for the current request.
	 *
	 * @var array<int, string>
	 */
	private $order_item_prices = array();

	/**
	 * Singleton instance.
	 *
	 * @var Efi_Compatibility|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared module instance.
	 *
	 * @return Efi_Compatibility
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
		add_action( 'woocommerce_checkout_order_created', array( $this, 'prepare_order_prices' ), 999 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'prepare_processed_order_prices' ), 999, 3 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'prepare_order_prices' ), 999 );

		add_filter( 'woocommerce_product_get_price', array( $this, 'filter_product_price' ), 9999, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_product_price' ), 9999, 2 );
	}

	/**
	 * Prepares prices from the order passed after checkout creation.
	 *
	 * @param int            $order_id    Order ID.
	 * @param array          $posted_data Checkout data.
	 * @param WC_Order|mixed $order       Order object.
	 */
	public function prepare_processed_order_prices( $order_id, $posted_data, $order ) {
		unset( $posted_data );

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		$this->prepare_order_prices( $order );
	}

	/**
	 * Stores each Pontus item's gross unit price for the current payment request.
	 *
	 * Efí rebuilds Pix and card charges from the product catalog price instead
	 * of the order-item subtotal. YITH includes add-ons in the order item, so
	 * returning its gross unit subtotal prevents the gateway from dropping them.
	 *
	 * @param WC_Order|mixed $order Order object.
	 */
	public function prepare_order_prices( $order ) {
		$this->order_item_prices = array();

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! in_array( $order->get_payment_method(), self::SUPPORTED_GATEWAYS, true ) ) {
			return;
		}

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();
			$quantity   = (float) $item->get_quantity();

			if ( Coupon_Addons::PRODUCT_ID !== $product_id || $quantity <= 0 ) {
				continue;
			}

			$gross_unit_price = (float) $item->get_subtotal() / $quantity;

			if ( $gross_unit_price > 0 ) {
				$this->order_item_prices[ $product_id ] = wc_format_decimal( $gross_unit_price );
			}
		}
	}

	/**
	 * Supplies Efí with the order-item price while its payment is processed.
	 *
	 * @param string|float     $price   Current catalog price.
	 * @param WC_Product|mixed $product Product object.
	 * @return string|float
	 */
	public function filter_product_price( $price, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $price;
		}

		$product_id = (int) $product->get_id();

		return isset( $this->order_item_prices[ $product_id ] )
			? $this->order_item_prices[ $product_id ]
			: $price;
	}
}
