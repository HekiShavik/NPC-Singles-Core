<?php
/**
 * Plugin Name: NP Singles Core
 * Description: Fælles kerne og registry for NP Singles-integrationer til WooCommerce.
 * Version: 0.8.1
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('NPS_CORE_VERSION', '0.8.1');
define('NPS_CORE_DIR', plugin_dir_path(__FILE__));
define('NPS_CORE_URL', plugin_dir_url(__FILE__));
require_once NPS_CORE_DIR . 'includes/PublicApi.php';

spl_autoload_register(function ($class) {
    $prefix = 'NPS\\Core\\';
    if (strpos($class, $prefix) !== 0) return;

    $rel = substr($class, strlen($prefix));
    $path = NPS_CORE_DIR . 'includes/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) require_once $path;
});

register_activation_hook(__FILE__, static function (): void {
    \NPS\Core\LocalCardIndex::install();
    \NPS\Core\SetStatus::install();
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) return;

    \NPS\Core\LocalCardIndex::install();
    \NPS\Core\SetStatus::boot();

    /**
     * Fires when NP Singles Core is ready and integrations may register.
     */
    do_action('nps_core_ready', \NPS\Core\Registry::instance());
}, 5);
