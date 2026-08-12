<?php
/**
 * Plugin Name: NP Singles Core
 * Description: Fælles kerne og registry for NP Singles-integrationer til WooCommerce.
 * Version: 0.1.0
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('NPS_CORE_VERSION', '0.1.0');
define('NPS_CORE_DIR', plugin_dir_path(__FILE__));
define('NPS_CORE_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function ($class) {
    $prefix = 'NPS\\Core\\';
    if (strpos($class, $prefix) !== 0) return;

    $rel = substr($class, strlen($prefix));
    $path = NPS_CORE_DIR . 'includes/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) require_once $path;
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) return;

    /**
     * Fires when NP Singles Core is ready and integrations may register.
     */
    do_action('nps_core_ready', \NPS\Core\Registry::instance());
}, 5);
