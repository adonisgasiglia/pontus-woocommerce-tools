<?php
/**
 * Main plugin bootstrap.
 *
 * @package PontusWooCommerceTools
 */

namespace Pontus\WooCommerceTools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates the plugin lifecycle and dependencies.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Activation notice transient.
	 *
	 * @var string
	 */
	private const ACTIVATION_NOTICE_TRANSIENT = 'pwt_activation_notice';

	/**
	 * Returns the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers WordPress hooks.
	 */
	private function __construct() {
		register_activation_hook( PWT_PLUGIN_FILE, array( $this, 'activate' ) );

		add_action( 'plugins_loaded', array( $this, 'bootstrap' ) );
		add_action( 'admin_notices', array( $this, 'render_activation_notice' ) );
	}

	/**
	 * Runs when the plugin is activated.
	 */
	public function activate() {
		set_transient( self::ACTIVATION_NOTICE_TRANSIENT, '1', MINUTE_IN_SECONDS );
	}

	/**
	 * Starts plugin modules after all plugins have loaded.
	 */
	public function bootstrap() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
			return;
		}

		require_once PWT_PLUGIN_PATH . 'includes/class-coupon-addons.php';
		Coupon_Addons::instance();

		/**
		 * Fires when Pontus WooCommerce Tools is ready.
		 *
		 * @param Plugin $plugin Main plugin instance.
		 */
		do_action( 'pwt_loaded', $this );
	}

	/**
	 * Displays a success notice once after activation.
	 */
	public function render_activation_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( false === get_transient( self::ACTIVATION_NOTICE_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::ACTIVATION_NOTICE_TRANSIENT );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Pontus WooCommerce Tools foi ativado com sucesso.', 'pontus-woocommerce-tools' )
		);
	}

	/**
	 * Displays a warning when WooCommerce is unavailable.
	 */
	public function render_missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Pontus WooCommerce Tools requer que o WooCommerce esteja instalado e ativo.', 'pontus-woocommerce-tools' )
		);
	}
}
