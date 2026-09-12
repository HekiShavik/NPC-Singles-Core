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
        AdminHub::instance()->register($this);
        add_action('admin_init', [$this, 'legacyRedirect']);
    }

    public function gameId(): string
    {
        return sanitize_key((string)($this->config['game_id'] ?? ''));
    }

    public function gameLabel(): string
    {
        return (string)($this->config['game_label'] ?? $this->config['bulk_label'] ?? $this->gameId());
    }

    public function gameOrder(): int
    {
        return (int)($this->config['game_order'] ?? 100);
    }

    public function uiPrefix(): string
    {
        $globalName = strtolower(trim((string)($this->config['global_name'] ?? '')));
        return sanitize_key((string)($this->config['ui_prefix'] ?? $this->config['ajax_prefix'] ?? $globalName));
    }

    public function renderBulk(): void { $this->adminPage->render(); }
    public function renderSettings(): void { $this->settingsPage->render(); }
    public function renderHistory(): void { $this->historyPage->render(); }

    public function enqueueForHub(string $section): void
    {
        $debug = $this->config['debug'] ?? null;
        if (is_callable($debug)) {
            $debug('enqueue_hub_assets', ['section' => $section, 'game' => $this->gameId()]);
        }

        $this->assets->enqueueFiles($section === 'bulk');

        $nonceAction = (string)$this->config['nonce_action'];
        $globalName = (string)$this->config['global_name'];

        if ($section === 'bulk') {
            wp_localize_script($this->assets->handle(), $globalName, [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce($nonceAction),
                'historyUrl' => AdminHub::historyUrl($this->gameId()),
            ]);
            $this->enqueueStockAutocreate($nonceAction, $globalName);
            return;
        }

        if ($section === 'settings') {
            $this->enqueueSettingsScript($nonceAction);
        }
    }

    /** Keep old bookmarks usable without duplicate menu items. */
    public function legacyRedirect(): void
    {
        if (!is_admin() || !current_user_can('manage_woocommerce')) return;

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string)$_GET['page'])) : '';
        if ($page === '') return;

        $bulkSlug = sanitize_key((string)($this->config['bulk_slug'] ?? ''));
        $historySlug = sanitize_key((string)($this->config['history_slug'] ?? ''));
        $settingsSlug = sanitize_key((string)($this->config['settings_slug'] ?? ''));

        if ($page === $bulkSlug && $bulkSlug !== '') {
            wp_safe_redirect(AdminHub::gameUrl($this->gameId()));
            exit;
        }

        if ($page === $historySlug && $historySlug !== '') {
            $url = AdminHub::historyUrl($this->gameId());
            foreach (['setId', 'paged', 'perPage'] as $key) {
                if (isset($_GET[$key])) {
                    $url = add_query_arg($key, sanitize_text_field(wp_unslash((string)$_GET[$key])), $url);
                }
            }
            wp_safe_redirect($url);
            exit;
        }

        if ($page === $settingsSlug && $settingsSlug !== '') {
            wp_safe_redirect(AdminHub::settingsUrl($this->gameId()));
            exit;
        }
    }

    private function enqueueStockAutocreate(string $nonceAction, string $globalName): void
    {
        $path = NPS_CORE_DIR . 'assets/stock-autocreate.js';
        if (!is_file($path)) return;

        $prefix = sanitize_key((string)($this->config['ajax_prefix'] ?? strtolower($globalName)));
        if ($prefix === '') return;

        wp_enqueue_script(
            'nps-stock-autocreate',
            NPS_CORE_URL . 'assets/stock-autocreate.js',
            [$this->assets->handle()],
            filemtime($path),
            true
        );
        wp_localize_script('nps-stock-autocreate', 'NPS_STOCK_AUTOCREATE', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce($nonceAction),
            'createAction' => $prefix . '_create',
            'setStockAction' => $prefix . '_set_stock_absolute',
            'deleteAction' => $prefix . '_delete_product',
            'setIdSelector' => '#' . $prefix . '_set_id',
        ]);
    }

    private function enqueueSettingsScript(string $nonceAction): void
    {
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
    }
}
