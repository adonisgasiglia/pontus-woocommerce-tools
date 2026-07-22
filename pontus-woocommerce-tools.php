<?php
/**
 * Plugin Name: Pontus WooCommerce Tools
 * Plugin URI: https://pontusescritorios.com.br
 * Description: Ferramentas personalizadas da Pontus para WooCommerce.
 * Version: 1.2.2
 * GitHub Plugin URI: https://github.com/adonisgasiglia/pontus-woocommerce-tools
 * Primary Branch: main
 * Author: Pontus Escritórios Inteligentes
 * License: GPL2
 * Text Domain: pontus-woocommerce-tools
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PWT_VERSION', '1.2.2');
define('PWT_PLUGIN_FILE', __FILE__);
define('PWT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PWT_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once PWT_PLUGIN_PATH . 'includes/class-plugin.php';

Pontus\WooCommerceTools\Plugin::instance();
