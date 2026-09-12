<?php

namespace NPS\Core\Admin;

use NPS\Core\Registry;

if (!defined('ABSPATH')) exit;

/**
 * Persistent health state and settings UI for missing Singles product images.
 *
 * Normal game pages only check the active game while no unresolved image issue
 * is already known for that game. The Settings page refreshes every registered
 * game and is the canonical place for repair actions.
 */
final class ImageRepairHealth
{
    private const NONCE_ACTION = 'nps_image_repair';
    private const STATE_OPTION = 'nps_image_health_state';
    private const DEFAULT_BATCH_SIZE = 10;

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;

        // ImageRepairTool still owns the repair backend, but its old notice and
        // page-specific script are replaced by this persistent health UI.
        remove_action('admin_notices', [ImageRepairTool::class, 'renderPanel']);
        remove_action('admin_enqueue_scripts', [ImageRepairTool::class, 'enqueue']);

        add_action('wp_ajax_nps_image_health_count', [self::class, 'ajaxCount']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue'], 25);
    }

    public static function hasMissingImages(): bool
    {
        $state = self::state();
        foreach (Registry::instance()->all() as $gameId => $provider) {
            if ((int)($state[sanitize_key((string)$gameId)]['total'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    public static function renderSettingsPanel(): void
    {
        if (!current_user_can('manage_woocommerce')) return;

        $games = Registry::instance()->all();
        if (!$games) return;

        $state = self::state();

        echo '<div class="card nps-image-health" style="max-width:none;margin-top:16px;">';
        echo '<h2>Manglende produktbilleder</h2>';
        echo '<p>Kontrollen opdateres automatisk for alle spil, når Indstillinger åbnes. Værktøjet ændrer kun produktets featured image; pris, lager, status og øvrige produktdata ændres ikke.</p>';
        echo '<table class="widefat striped" style="max-width:1000px;">';
        echo '<thead><tr><th>Spil</th><th style="width:260px;">Status</th><th style="width:220px;">Handling</th></tr></thead><tbody>';

        foreach ($games as $gameId => $provider) {
            $gameId = sanitize_key((string)$gameId);
            $counts = isset($state[$gameId]) && is_array($state[$gameId]) ? $state[$gameId] : null;
            $total = $counts === null ? null : (int)($counts['total'] ?? 0);
            $published = $counts === null ? 0 : (int)($counts['published'] ?? 0);

            if ($total === null) {
                $status = 'Ikke kontrolleret';
            } elseif ($total > 0) {
                $status = sprintf('%d mangler, heraf %d publiceret', $total, $published);
            } else {
                $status = 'OK';
            }

            printf(
                '<tr data-image-health-game="%1$s"><td><strong>%2$s</strong></td><td class="nps-image-health__game-status">%3$s</td><td><button type="button" class="button button-primary nps-image-health__repair" data-game="%1$s" %4$s>Reparer næste %5$d</button> <span class="nps-image-health__repair-status" aria-live="polite"></span></td></tr>',
                esc_attr($gameId),
                esc_html($provider->name()),
                esc_html($status),
                $total !== null && $total > 0 ? '' : 'disabled',
                self::DEFAULT_BATCH_SIZE
            );
        }

        echo '</tbody></table>';
        echo '<p style="margin-top:12px;"><button type="button" class="button nps-image-health__check-all">Kontrollér igen</button> <span class="nps-image-health__overall-status" aria-live="polite"></span></p>';
        echo '<div class="nps-image-health__failures"></div>';
        echo '</div>';
    }

    public static function enqueue(string $hook): void
    {
        [$section, $gameId] = self::requestContext();
        if ($section === '') return;

        $path = NPS_CORE_DIR . 'assets/image-repair-health.js';
        if (!is_file($path)) return;

        $state = self::state();
        $games = [];
        foreach (Registry::instance()->all() as $id => $provider) {
            $id = sanitize_key((string)$id);
            $counts = isset($state[$id]) && is_array($state[$id]) ? $state[$id] : null;
            $games[] = [
                'id' => $id,
                'name' => (string)$provider->name(),
                'counts' => $counts === null ? null : [
                    'total' => (int)($counts['total'] ?? 0),
                    'published' => (int)($counts['published'] ?? 0),
                ],
            ];
        }

        if ($section === 'bulk') {
            $knownMissing = (int)($state[$gameId]['total'] ?? 0) > 0;
            if ($knownMissing) return;
        }

        wp_enqueue_script(
            'nps-image-repair-health',
            NPS_CORE_URL . 'assets/image-repair-health.js',
            [],
            filemtime($path),
            true
        );
        wp_localize_script('nps-image-repair-health', 'NPS_IMAGE_HEALTH', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'mode' => $section,
            'gameId' => $gameId,
            'games' => $games,
            'batchSize' => self::DEFAULT_BATCH_SIZE,
        ]);
    }

    public static function ajaxCount(): void
    {
        self::assertAjaxAccess();
        $gameId = self::postedGameId();
        $provider = Registry::instance()->get($gameId);
        if ($provider === null) {
            wp_send_json_error(['message' => 'Singles-integration blev ikke fundet.'], 404);
        }

        $keys = $provider->metaKeys();
        $cardMetaKey = trim((string)($keys['card_id'] ?? ''));
        if ($cardMetaKey === '') {
            wp_send_json_error(['message' => 'Spil-pluginet har ikke registreret et card_id-meta-felt.'], 500);
        }

        $counts = self::missingCounts($cardMetaKey);
        self::saveCounts($gameId, $counts);
        wp_send_json_success($counts);
    }

    private static function saveCounts(string $gameId, array $counts): void
    {
        $gameId = sanitize_key($gameId);
        if ($gameId === '') return;

        $state = self::state();
        $state[$gameId] = [
            'total' => max(0, (int)($counts['total'] ?? 0)),
            'published' => max(0, (int)($counts['published'] ?? 0)),
        ];
        update_option(self::STATE_OPTION, $state, false);
    }

    private static function state(): array
    {
        $state = get_option(self::STATE_OPTION, []);
        return is_array($state) ? $state : [];
    }

    private static function missingCounts(string $cardMetaKey): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) AS total,
                    COUNT(DISTINCT CASE WHEN p.post_status = 'publish' THEN p.ID END) AS published
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} card ON card.post_id = p.ID AND card.meta_key = %s AND card.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} thumb ON thumb.post_id = p.ID AND thumb.meta_key = '_thumbnail_id'
             LEFT JOIN {$wpdb->posts} attachment ON attachment.ID = CAST(thumb.meta_value AS UNSIGNED) AND attachment.post_type = 'attachment'
             WHERE p.post_type = 'product'
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND (thumb.post_id IS NULL OR thumb.meta_value = '' OR attachment.ID IS NULL)",
            $cardMetaKey
        ), ARRAY_A);

        return [
            'total' => (int)($row['total'] ?? 0),
            'published' => (int)($row['published'] ?? 0),
        ];
    }

    /** @return array{0:string,1:string} */
    private static function requestContext(): array
    {
        if (!is_admin()) return ['', ''];

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string)$_GET['page'])) : '';
        if ($page !== AdminHub::PAGE_SLUG) return ['', ''];

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string)$_GET['tab'])) : '';
        if ($tab === 'settings') return ['settings', ''];
        if ($tab === 'history') return ['', ''];
        if ($tab !== '' && Registry::instance()->has($tab)) return ['bulk', $tab];

        $gameId = self::rememberedGameId();
        return $gameId !== '' ? ['bulk', $gameId] : ['', ''];
    }

    private static function rememberedGameId(): string
    {
        $userId = get_current_user_id();
        if ($userId > 0) {
            $remembered = sanitize_key((string)get_user_meta($userId, '_nps_singles_last_game', true));
            if ($remembered !== '' && Registry::instance()->has($remembered)) {
                return $remembered;
            }
        }

        foreach (Registry::instance()->all() as $gameId => $provider) {
            return sanitize_key((string)$gameId);
        }
        return '';
    }

    private static function assertAjaxAccess(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Ingen adgang.'], 403);
        }
    }

    private static function postedGameId(): string
    {
        $gameId = isset($_POST['gameId']) ? sanitize_key(wp_unslash((string)$_POST['gameId'])) : '';
        if ($gameId === '') {
            wp_send_json_error(['message' => 'Spil-id mangler.'], 400);
        }
        return $gameId;
    }
}
