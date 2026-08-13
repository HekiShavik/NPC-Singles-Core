<?php

namespace NPS\Core\Admin;

if (!defined('ABSPATH')) exit;

final class AdminHub
{
    public const PAGE_SLUG = 'nps-singles';

    private static ?self $instance = null;

    /** @var array<string,AdminRouter> */
    private array $routers = [];
    private string $hook = '';

    private function __construct()
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue'], 20);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function gameUrl(string $gameId): string
    {
        return add_query_arg([
            'post_type' => 'product',
            'page' => self::PAGE_SLUG,
            'tab' => sanitize_key($gameId),
        ], admin_url('edit.php'));
    }

    public static function settingsUrl(string $gameId): string
    {
        return add_query_arg([
            'post_type' => 'product',
            'page' => self::PAGE_SLUG,
            'tab' => 'settings',
            'game' => sanitize_key($gameId),
        ], admin_url('edit.php'));
    }

    public static function historyUrl(string $gameId): string
    {
        return add_query_arg([
            'post_type' => 'product',
            'page' => self::PAGE_SLUG,
            'tab' => 'history',
            'game' => sanitize_key($gameId),
        ], admin_url('edit.php'));
    }

    public function register(AdminRouter $router): void
    {
        $id = sanitize_key($router->gameId());
        if ($id === '') {
            throw new \InvalidArgumentException('A Singles admin router must have a game_id.');
        }
        $this->routers[$id] = $router;
    }

    public function menu(): void
    {
        $this->hook = (string)add_submenu_page(
            'edit.php?post_type=product',
            'Singles',
            'Singles',
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function enqueue(string $hook): void
    {
        if ($this->hook === '' || $hook !== $this->hook) {
            return;
        }

        [$section, $gameId] = $this->resolveRequest();
        $router = $this->routers[$gameId] ?? null;
        if ($router === null) {
            return;
        }

        $router->enqueueForHub($section);
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) return;

        if (!$this->routers) {
            echo '<div class="wrap"><h1>Singles</h1><p>Ingen Singles-integrationer er registreret.</p></div>';
            return;
        }

        [$section, $gameId] = $this->resolveRequest();
        $router = $this->routers[$gameId] ?? reset($this->routers);
        if (!$router instanceof AdminRouter) {
            return;
        }

        echo '<div class="wrap nps-singles-hub">';
        echo '<h1>Singles</h1>';
        echo '<nav class="nav-tab-wrapper" aria-label="Singles">';
        foreach ($this->sortedRouters() as $id => $candidate) {
            $active = $section === 'bulk' && $id === $gameId ? ' nav-tab-active' : '';
            printf(
                '<a class="nav-tab%s" href="%s">%s</a>',
                esc_attr($active),
                esc_url(self::gameUrl($id)),
                esc_html($candidate->gameLabel())
            );
        }

        printf(
            '<a class="nav-tab%s" href="%s">Indstillinger</a>',
            esc_attr($section === 'settings' ? ' nav-tab-active' : ''),
            esc_url(self::settingsUrl($gameId))
        );
        printf(
            '<a class="nav-tab%s" href="%s">Historik</a>',
            esc_attr($section === 'history' ? ' nav-tab-active' : ''),
            esc_url(self::historyUrl($gameId))
        );
        echo '</nav>';

        if ($section === 'settings' || $section === 'history') {
            $this->renderGameSubtabs($section, $gameId);
        }
        echo '</div>';

        if ($section === 'settings') {
            $router->renderSettings();
        } elseif ($section === 'history') {
            $router->renderHistory();
        } else {
            $router->renderBulk();
        }
    }

    private function renderGameSubtabs(string $section, string $gameId): void
    {
        echo '<ul class="subsubsub" style="float:none;margin:12px 0 8px;">';
        $links = [];
        foreach ($this->sortedRouters() as $id => $router) {
            $url = $section === 'history' ? self::historyUrl($id) : self::settingsUrl($id);
            $class = $id === $gameId ? ' class="current" aria-current="page"' : '';
            $links[] = sprintf('<li><a%s href="%s">%s</a></li>', $class, esc_url($url), esc_html($router->gameLabel()));
        }
        echo implode(' | ', $links);
        echo '</ul>';
    }

    /** @return array<string,AdminRouter> */
    private function sortedRouters(): array
    {
        $routers = $this->routers;
        uasort($routers, static function (AdminRouter $a, AdminRouter $b): int {
            $order = $a->gameOrder() <=> $b->gameOrder();
            return $order !== 0 ? $order : strcasecmp($a->gameLabel(), $b->gameLabel());
        });
        return $routers;
    }

    /** @return array{0:string,1:string} */
    private function resolveRequest(): array
    {
        $ids = array_keys($this->sortedRouters());
        $first = $ids[0] ?? '';
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string)$_GET['tab'])) : '';

        if ($tab === 'settings' || $tab === 'history') {
            $gameId = isset($_GET['game']) ? sanitize_key(wp_unslash((string)$_GET['game'])) : $first;
            if (!isset($this->routers[$gameId])) {
                $gameId = $first;
            }
            return [$tab, $gameId];
        }

        if ($tab !== '' && isset($this->routers[$tab])) {
            return ['bulk', $tab];
        }

        return ['bulk', $first];
    }
}
