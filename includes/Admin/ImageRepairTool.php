<?php

namespace NPS\Core\Admin;

use NPS\Core\Registry;

if (!defined('ABSPATH')) exit;

/**
 * Shared admin tool for repairing WooCommerce products whose featured image is missing.
 *
 * The tool deliberately changes only the product thumbnail. Product data such as price,
 * stock, status, SKU and taxonomy terms are left untouched.
 */
final class ImageRepairTool
{
    private const NONCE_ACTION = 'nps_image_repair';
    private const DEFAULT_BATCH_SIZE = 10;
    private const MAX_BATCH_SIZE = 25;
    private const ATTEMPT_META = '_nps_image_repair_attempt_at';
    private const ERROR_META = '_nps_image_repair_last_error';
    private const SOURCE_META = '_nps_image_source_url';

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;

        add_action('wp_ajax_nps_image_repair_count', [self::class, 'ajaxCount']);
        add_action('wp_ajax_nps_image_repair_batch', [self::class, 'ajaxBatch']);
        add_action('admin_notices', [self::class, 'renderPanel']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function renderPanel(): void
    {
        if (!current_user_can('manage_woocommerce')) return;

        $gameId = self::currentGameId();
        if ($gameId === '') return;

        $provider = Registry::instance()->get($gameId);
        if ($provider === null) return;

        printf(
            '<div class="notice notice-info nps-image-repair" data-game="%1$s"><p><strong>Manglende produktbilleder - %2$s</strong></p><p class="nps-image-repair__summary">Tæller produkter uden billede...</p><p>Værktøjet ændrer kun produktets featured image. Publicerede produkter prioriteres, og der behandles højst %3$d billeder pr. klik.</p><p><button type="button" class="button button-primary nps-image-repair__run" disabled>Reparer næste %3$d</button> <span class="nps-image-repair__status" aria-live="polite"></span></p></div>',
            esc_attr($gameId),
            esc_html($provider->name()),
            self::DEFAULT_BATCH_SIZE
        );
    }

    public static function enqueue(string $hook): void
    {
        $gameId = self::currentGameId();
        if ($gameId === '') return;

        $path = NPS_CORE_DIR . 'assets/image-repair.js';
        if (!is_file($path)) return;

        wp_enqueue_script(
            'nps-image-repair',
            NPS_CORE_URL . 'assets/image-repair.js',
            [],
            filemtime($path),
            true
        );
        wp_localize_script('nps-image-repair', 'NPS_IMAGE_REPAIR', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'gameId' => $gameId,
            'batchSize' => self::DEFAULT_BATCH_SIZE,
        ]);
    }

    public static function ajaxCount(): void
    {
        self::assertAjaxAccess();
        $gameId = self::postedGameId();
        $provider = self::providerOrFail($gameId);
        $cardMetaKey = self::cardMetaKeyOrFail($provider);

        wp_send_json_success(self::missingCounts($cardMetaKey));
    }

    public static function ajaxBatch(): void
    {
        self::assertAjaxAccess();
        $gameId = self::postedGameId();
        $provider = self::providerOrFail($gameId);
        $metaKeys = $provider->metaKeys();
        $cardMetaKey = self::cardMetaKeyOrFail($provider);

        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : self::DEFAULT_BATCH_SIZE;
        $limit = max(1, min(self::MAX_BATCH_SIZE, $limit));

        $ids = self::missingProductIds($cardMetaKey, $limit);
        if (!$ids) {
            wp_send_json_success([
                'attempted' => 0,
                'repaired' => 0,
                'failed' => 0,
                'failures' => [],
                'counts' => self::missingCounts($cardMetaKey),
            ]);
        }

        $runtime = self::createRuntime($provider);
        if (is_wp_error($runtime)) {
            wp_send_json_error(['message' => $runtime->get_error_message()], 500);
        }

        $setSnapshots = [];
        $repaired = 0;
        $failed = 0;
        $failures = [];

        foreach ($ids as $postId) {
            $postId = (int)$postId;
            if ($postId <= 0 || has_post_thumbnail($postId)) continue;

            $result = self::repairProduct($postId, $metaKeys, $runtime, $setSnapshots);
            if ($result['ok']) {
                $repaired++;
                delete_post_meta($postId, self::ATTEMPT_META);
                delete_post_meta($postId, self::ERROR_META);
            } else {
                $failed++;
                update_post_meta($postId, self::ATTEMPT_META, (string)time());
                update_post_meta($postId, self::ERROR_META, (string)$result['reason']);
                if (count($failures) < 10) {
                    $failures[] = [
                        'post_id' => $postId,
                        'title' => (string)get_the_title($postId),
                        'reason' => (string)$result['reason'],
                    ];
                }
            }
        }

        wp_send_json_success([
            'attempted' => count($ids),
            'repaired' => $repaired,
            'failed' => $failed,
            'failures' => $failures,
            'counts' => self::missingCounts($cardMetaKey),
        ]);
    }

    private static function repairProduct(int $postId, array $metaKeys, array $runtime, array &$setSnapshots): array
    {
        if (get_post_type($postId) !== 'product') {
            return ['ok' => false, 'reason' => 'Ikke et WooCommerce-produkt.'];
        }
        if (has_post_thumbnail($postId)) {
            return ['ok' => true, 'reason' => ''];
        }

        $cardId = self::productMeta($postId, $metaKeys, 'card_id');
        if ($cardId === '') {
            return ['ok' => false, 'reason' => 'Kort-id mangler.'];
        }

        $setId = self::productMeta($postId, $metaKeys, 'set_id');
        $finish = self::productMeta($postId, $metaKeys, 'finish');
        $lang = strtoupper(self::productMeta($postId, $metaKeys, 'language'));
        if ($finish === '') $finish = 'normal';
        if ($lang === '') $lang = 'EN';

        $setInfo = [
            'id' => $setId,
            'name' => self::productMeta($postId, $metaKeys, 'set_name'),
            'ptcgoCode' => self::productMeta($postId, $metaKeys, 'set_code'),
            'series' => self::productMeta($postId, $metaKeys, 'series_name'),
        ];

        $snap = self::findSnapshot($cardId, $setId, $runtime, $setSnapshots);
        if (!$snap) {
            $snap = ['id' => $cardId];
        } elseif (empty($snap['id'])) {
            $snap['id'] = $cardId;
        }

        $builder = $runtime['builder'] ?? null;
        if (is_object($builder) && method_exists($builder, 'ensure_featured_image_from_card_snapshot')) {
            try {
                $ok = (bool)$builder->ensure_featured_image_from_card_snapshot(
                    $postId,
                    $snap,
                    $setInfo,
                    $finish,
                    $lang,
                    (string)get_the_title($postId)
                );
                if ($ok && has_post_thumbnail($postId)) {
                    return ['ok' => true, 'reason' => ''];
                }
            } catch (\Throwable $e) {
                // Fall through to the generic image URL resolver before declaring failure.
            }
        }

        $url = self::firstImageUrl($snap);
        if ($url === '') {
            $url = self::liveCardImageUrl($cardId, $runtime['api'] ?? null);
        }
        if ($url === '') {
            return ['ok' => false, 'reason' => 'Ingen billed-URL kunne findes for kortet.'];
        }

        $desc = trim((string)get_the_title($postId));
        if ($desc === '') $desc = 'Card image';

        $attachmentId = self::getOrSideloadAttachment($url, $postId, $desc);
        if ($attachmentId <= 0) {
            return ['ok' => false, 'reason' => 'Billedet kunne ikke downloades eller gemmes i mediebiblioteket.'];
        }

        set_post_thumbnail($postId, $attachmentId);
        self::flushProductImageCache($postId);

        return has_post_thumbnail($postId)
            ? ['ok' => true, 'reason' => '']
            : ['ok' => false, 'reason' => 'Billedet blev gemt, men kunne ikke sættes som produktbillede.'];
    }

    private static function findSnapshot(string $cardId, string $setId, array $runtime, array &$setSnapshots): array
    {
        $setCache = $runtime['set_cache'] ?? null;
        if ($setId === '' || !is_object($setCache) || !method_exists($setCache, 'get')) {
            return [];
        }

        if (!array_key_exists($setId, $setSnapshots)) {
            $payload = [];
            try {
                $payload = (array)$setCache->get($setId, false);
                if (empty($payload['data']['cards'])) {
                    $payload = (array)$setCache->get($setId, true);
                }
            } catch (\Throwable $e) {
                $payload = [];
            }

            $byId = [];
            foreach ((array)($payload['data']['cards'] ?? []) as $card) {
                if (!is_array($card)) continue;
                $id = trim((string)($card['id'] ?? ''));
                if ($id !== '') $byId[$id] = $card;
            }
            $setSnapshots[$setId] = $byId;
        }

        return isset($setSnapshots[$setId][$cardId]) && is_array($setSnapshots[$setId][$cardId])
            ? $setSnapshots[$setId][$cardId]
            : [];
    }

    private static function liveCardImageUrl(string $cardId, $api): string
    {
        if ($cardId === '' || !is_object($api) || !method_exists($api, 'get')) return '';

        try {
            $result = (array)$api->get('/cards/' . rawurlencode($cardId), [], 20);
        } catch (\Throwable $e) {
            return '';
        }

        if (!($result['ok'] ?? false)) return '';
        $data = $result['data']['data'] ?? ($result['data'] ?? []);
        return is_array($data) ? self::firstImageUrl($data) : '';
    }

    private static function firstImageUrl(array $data): string
    {
        $candidates = [
            $data['image'] ?? '',
            $data['imageUrl'] ?? '',
            $data['thumbnail'] ?? '',
        ];

        $images = isset($data['images']) && is_array($data['images']) ? $data['images'] : [];
        foreach (['full', 'large', 'normal', 'small'] as $key) {
            $candidates[] = $images[$key] ?? '';
        }

        foreach ($candidates as $candidate) {
            $url = trim((string)$candidate);
            if ($url !== '' && wp_http_validate_url($url)) return $url;
        }
        return '';
    }

    private static function createRuntime($provider)
    {
        $config = $provider->config();
        $namespace = trim((string)($config['namespace'] ?? ''), '\\');
        if ($namespace === '') {
            return new \WP_Error('nps_image_repair_namespace', 'Spil-pluginet oplyser ikke sit namespace.');
        }

        $apiClass = (string)($config['api_client_class'] ?? ($namespace . '\\ApiClient'));
        $cacheClass = (string)($config['cache_class'] ?? ($namespace . '\\Cache'));
        $setCacheClass = (string)($config['set_cards_cache_class'] ?? ($namespace . '\\SetCardsCache'));
        $builderClass = (string)($config['product_builder_class'] ?? ($namespace . '\\ProductBuilder'));

        try {
            if (!class_exists($apiClass) || !class_exists($cacheClass)) {
                return new \WP_Error('nps_image_repair_runtime', 'Spil-pluginets API/cache-klasser kunne ikke indlæses.');
            }

            $api = new $apiClass();
            $cache = new $cacheClass();
            $setCache = class_exists($setCacheClass) ? new $setCacheClass($cache, $api) : null;
            $builder = class_exists($builderClass) ? new $builderClass($cache, $api) : null;

            return [
                'api' => $api,
                'cache' => $cache,
                'set_cache' => $setCache,
                'builder' => $builder,
            ];
        } catch (\Throwable $e) {
            return new \WP_Error('nps_image_repair_runtime', 'Kunne ikke initialisere billedreparation: ' . $e->getMessage());
        }
    }

    private static function getOrSideloadAttachment(string $url, int $postId, string $desc): int
    {
        $existing = self::findAttachmentBySourceUrl($url);
        if ($existing > 0) return $existing;

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam($url);
        if (!$tmp) return 0;

        $response = wp_remote_get($url, [
            'timeout' => 25,
            'redirection' => 5,
            'stream' => true,
            'filename' => $tmp,
            'user-agent' => 'Mozilla/5.0 (WordPress; NP Singles Core)',
        ]);

        if (is_wp_error($response)) {
            @unlink($tmp);
            return 0;
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            @unlink($tmp);
            return 0;
        }

        $ext = self::imageExtension($url, $response);
        $safe = sanitize_file_name($desc);
        if ($safe === '') $safe = 'card-image';

        $attachmentId = media_handle_sideload([
            'name' => $safe . '.' . $ext,
            'tmp_name' => $tmp,
        ], $postId, $desc);

        if (is_wp_error($attachmentId) || !$attachmentId) {
            @unlink($tmp);
            return 0;
        }

        $attachmentId = (int)$attachmentId;
        update_post_meta($attachmentId, self::SOURCE_META, $url);
        wp_update_post([
            'ID' => $attachmentId,
            'post_parent' => 0,
        ]);

        return $attachmentId;
    }

    private static function findAttachmentBySourceUrl(string $url): int
    {
        global $wpdb;

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
               AND pm.meta_value = %s
               AND (pm.meta_key = %s OR pm.meta_key LIKE %s)
             ORDER BY pm.post_id ASC
             LIMIT 1",
            $url,
            self::SOURCE_META,
            '%\\_source\\_url'
        ));

        return (int)$id;
    }

    private static function imageExtension(string $url, $response): string
    {
        $contentType = strtolower(trim((string)(explode(';', (string)wp_remote_retrieve_header($response, 'content-type'))[0] ?? '')));
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
        ];
        if (isset($map[$contentType])) return $map[$contentType];

        $path = (string)(wp_parse_url($url, PHP_URL_PATH) ?? '');
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        return in_array($ext, ['jpg', 'png', 'webp', 'gif', 'avif'], true) ? $ext : 'jpg';
    }

    private static function flushProductImageCache(int $postId): void
    {
        clean_post_cache($postId);
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($postId);
        }
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

    /** @return int[] */
    private static function missingProductIds(string $cardMetaKey, int $limit): array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} card ON card.post_id = p.ID AND card.meta_key = %s AND card.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} thumb ON thumb.post_id = p.ID AND thumb.meta_key = '_thumbnail_id'
             LEFT JOIN {$wpdb->posts} attachment ON attachment.ID = CAST(thumb.meta_value AS UNSIGNED) AND attachment.post_type = 'attachment'
             LEFT JOIN {$wpdb->postmeta} attempt ON attempt.post_id = p.ID AND attempt.meta_key = %s
             WHERE p.post_type = 'product'
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND (thumb.post_id IS NULL OR thumb.meta_value = '' OR attachment.ID IS NULL)
             ORDER BY
               CASE
                 WHEN (attempt.post_id IS NULL OR attempt.meta_value = '') AND p.post_status = 'publish' THEN 0
                 WHEN (attempt.post_id IS NULL OR attempt.meta_value = '') THEN 1
                 WHEN p.post_status = 'publish' THEN 2
                 ELSE 3
               END ASC,
               CAST(COALESCE(NULLIF(attempt.meta_value, ''), '0') AS UNSIGNED) ASC,
               p.ID ASC
             LIMIT %d",
            $cardMetaKey,
            self::ATTEMPT_META,
            $limit
        );

        return array_map('intval', (array)$wpdb->get_col($sql));
    }

    private static function productMeta(int $postId, array $metaKeys, string $logicalKey): string
    {
        $key = trim((string)($metaKeys[$logicalKey] ?? ''));
        return $key === '' ? '' : trim((string)get_post_meta($postId, $key, true));
    }

    private static function currentGameId(): string
    {
        if (!is_admin()) return '';
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string)$_GET['page'])) : '';
        if ($page !== AdminHub::PAGE_SLUG) return '';

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string)$_GET['tab'])) : '';
        if ($tab === '' || $tab === 'settings' || $tab === 'history') return '';

        return Registry::instance()->has($tab) ? $tab : '';
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

    private static function providerOrFail(string $gameId)
    {
        $provider = Registry::instance()->get($gameId);
        if ($provider === null) {
            wp_send_json_error(['message' => 'Singles-integration blev ikke fundet.'], 404);
        }
        return $provider;
    }

    private static function cardMetaKeyOrFail($provider): string
    {
        $keys = $provider->metaKeys();
        $key = trim((string)($keys['card_id'] ?? ''));
        if ($key === '') {
            wp_send_json_error(['message' => 'Spil-pluginet har ikke registreret et card_id-meta-felt.'], 500);
        }
        return $key;
    }
}
