<?php

namespace NPS\Core\Admin;

if (!defined('ABSPATH')) exit;

final class AdminRouter
{
    private AdminAssets $assets;
    private object $adminPage;
    private object $settingsPage;
    private InventoryHistoryPage $historyPage;
    private array $config;
    /** @var array<string,callable> */
    private array $pageAssetCallbacks = [];

    public function __construct(AdminAssets $assets, object $adminPage, object $settingsPage, InventoryHistoryPage $historyPage, array $config)
    {
        $this->assets = $assets;
        $this->adminPage = $adminPage;
        $this->settingsPage = $settingsPage;
        $this->historyPage = $historyPage;
        $this->config = $config;
    }

    public function init(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueuePageAssets'], 20);
    }

    public function menu(): void
    {
        $bulkLabel = (string)$this->config['bulk_label'];
        $bulkSlug = (string)$this->config['bulk_slug'];
        $historyTitle = (string)$this->config['history_title'];
        $historyLabel = (string)$this->config['history_label'];
        $historySlug = (string)$this->config['history_slug'];
        $settingsTitle = (string)$this->config['settings_title'];
        $settingsLabel = (string)$this->config['settings_label'];
        $settingsSlug = (string)$this->config['settings_slug'];
        $nonceAction = (string)$this->config['nonce_action'];
        $globalName = (string)$this->config['global_name'];

        $bulkHook = add_submenu_page(
            'edit.php?post_type=product',
            $bulkLabel,
            $bulkLabel,
            'manage_woocommerce',
            $bulkSlug,
            [$this->adminPage, 'render']
        );
        $this->assets->registerHook($bulkHook);
        $this->pageAssetCallbacks[$bulkHook] = function () use ($globalName, $nonceAction, $historySlug): void {
            wp_localize_script($this->assets->handle(), $globalName, [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce($nonceAction),
                'historyUrl' => admin_url('edit.php?post_type=product&page=' . rawurlencode($historySlug)),
            ]);
        };

        $historyHook = add_submenu_page(
            'edit.php?post_type=product',
            $historyTitle,
            $historyLabel,
            'manage_woocommerce',
            $historySlug,
            [$this->historyPage, 'render']
        );
        $this->assets->registerHook($historyHook);
        $this->pageAssetCallbacks[$historyHook] = function () use ($globalName, $nonceAction, $historySlug): void {
            wp_localize_script($this->assets->handle(), $globalName, [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce($nonceAction),
                'historyUrl' => admin_url('edit.php?post_type=product&page=' . rawurlencode($historySlug)),
            ]);
        };

        $settingsHook = add_options_page(
            $settingsTitle,
            $settingsLabel,
            'manage_woocommerce',
            $settingsSlug,
            [$this->settingsPage, 'render']
        );
        $this->assets->registerHook($settingsHook);
        $this->pageAssetCallbacks[$settingsHook] = function () use ($nonceAction): void {
            $settingsHandle = (string)$this->config['settings_script_handle'];
            $settingsFile = ltrim((string)$this->config['settings_script_file'], '/');
            $pluginDir = rtrim((string)$this->config['plugin_dir'], '/\\') . '/';
            $pluginUrl = rtrim((string)$this->config['plugin_url'], '/') . '/';
            $settingsGlobal = (string)$this->config['settings_global_name'];
            $path = $pluginDir . $settingsFile;

            if (is_file($path)) {
                wp_enqueue_script($settingsHandle, $pluginUrl . $settingsFile, ['jquery'], filemtime($path), true);
                wp_localize_script($settingsHandle, $settingsGlobal, [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce($nonceAction),
                ]);
            }
        };
    }

    public function enqueuePageAssets(string $hook): void
    {
        $debug = $this->config['debug'] ?? null;
        if (is_callable($debug)) {
            $debug('enqueue_page_assets', ['hook' => $hook]);
        }

        if (isset($this->pageAssetCallbacks[$hook])) {
            ($this->pageAssetCallbacks[$hook])();
        }
    }
}
