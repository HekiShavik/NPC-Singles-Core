<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class SetStatus
{
    private const DB_VERSION = 2;
    private const OPT_DB_VERSION = 'nps_set_status_db_version';
    private const BUILD_PREFIX = 'nps_set_status_built_v2_';

    private static ?self $instance = null;
    private string $presenceTable;
    private string $summaryTable;

    private function __construct()
    {
        global $wpdb;
        $this->presenceTable = $wpdb->prefix . 'nps_set_card_presence';
        $this->summaryTable = $wpdb->prefix . 'nps_set_status';
    }

    public static function instance(): self
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public static function install(): void
    {
        global $wpdb;
        $current = (int)get_option(self::OPT_DB_VERSION, 0);
        if ($current >= self::DB_VERSION) return;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $presence = $wpdb->prefix . 'nps_set_card_presence';
        $summary = $wpdb->prefix . 'nps_set_status';

        dbDelta("CREATE TABLE {$presence} (
            game_id varchar(80) NOT NULL,
            set_id varchar(191) NOT NULL,
            card_key varchar(191) NOT NULL,
            unpublished tinyint(1) unsigned NOT NULL DEFAULT 0,
            unpriced tinyint(1) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (game_id,set_id,card_key),
            KEY game_set (game_id,set_id),
            KEY game_set_unpublished (game_id,set_id,unpublished),
            KEY game_set_unpriced (game_id,set_id,unpriced)
        ) {$charset};");

        dbDelta("CREATE TABLE {$summary} (
            game_id varchar(80) NOT NULL,
            set_id varchar(191) NOT NULL,
            registered_count int unsigned NOT NULL DEFAULT 0,
            unpublished_count int unsigned NOT NULL DEFAULT 0,
            unpriced_count int unsigned NOT NULL DEFAULT 0,
            total_count int unsigned NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (game_id,set_id),
            KEY game_id (game_id)
        ) {$charset};");

        update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
    }

    public static function boot(): void
    {
        self::install();
        $self = self::instance();
        add_action('save_post_product', [$self, 'onProductSaved'], 30, 3);
        add_action('before_delete_post', [$self, 'onBeforeDeletePost'], 30, 2);
        add_action('trashed_post', [$self, 'onProductTrashed'], 30, 1);
        add_action('untrashed_post', [$self, 'onProductUntrashed'], 30, 1);
    }

    public function syncProduct(int $postId): void
    {
        if ($postId <= 0 || get_post_type($postId) !== 'product') return;
        foreach (Registry::instance()->all() as $provider) {
            $identity = $this->productIdentity($postId, $provider);
            if ($identity !== null) $this->refreshLogicalCard($provider, $identity);
        }
    }

    public function onProductSaved(int $postId, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($postId) || $post->post_type !== 'product') return;
        if (in_array($post->post_status, ['trash', 'auto-draft'], true)) return;
        $this->syncProduct($postId);
    }

    public function onProductTrashed(int $postId): void
    {
        if ($postId <= 0 || get_post_type($postId) !== 'product') return;
        foreach (Registry::instance()->all() as $provider) {
            $identity = $this->productIdentity($postId, $provider);
            if ($identity !== null) $this->refreshLogicalCard($provider, $identity);
        }
    }

    public function onProductUntrashed(int $postId): void
    {
        $this->syncProduct($postId);
    }

    public function onBeforeDeletePost(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== 'product') return;

        foreach (Registry::instance()->all() as $provider) {
            $identity = $this->productIdentity($postId, $provider);
            if ($identity === null) continue;
            $this->refreshLogicalCard($provider, $identity, $postId);
        }
    }

    public function decorateSets(string $gameId, array $sets): array
    {
        $gameId = sanitize_key($gameId);
        if ($gameId === '') return $sets;

        $provider = Registry::instance()->get($gameId);
        if ($provider) $this->ensureGameBuilt($provider);

        $status = $this->summaryMap($gameId);
        foreach ($sets as &$set) {
            if (!is_array($set)) continue;
            $setId = trim((string)($set['id'] ?? ''));
            if ($setId === '') continue;

            $knownTotal = $this->extractTotal($set);
            $row = $status[$setId] ?? [
                'registered_count' => 0,
                'unpublished_count' => 0,
                'unpriced_count' => 0,
                'total_count' => null,
            ];
            if ($knownTotal !== null && (int)($row['total_count'] ?? 0) !== $knownTotal) {
                $this->setTotal($gameId, $setId, $knownTotal);
                $row['total_count'] = $knownTotal;
            }

            $set['registeredCount'] = (int)($row['registered_count'] ?? 0);
            $set['unpublishedCount'] = (int)($row['unpublished_count'] ?? 0);
            $set['unpricedCount'] = (int)($row['unpriced_count'] ?? 0);
            $set['totalCount'] = isset($row['total_count']) && $row['total_count'] !== null
                ? (int)$row['total_count']
                : null;
        }
        unset($set);
        return $sets;
    }

    public function observeSetCards(string $gameId, string $setId, array $cards): void
    {
        $gameId = sanitize_key($gameId);
        $setId = trim($setId);
        if ($gameId === '' || $setId === '') return;

        $keys = [];
        foreach ($cards as $card) {
            if (!is_array($card)) continue;
            $key = trim((string)($card['number'] ?? ''));
            if ($key === '') $key = trim((string)($card['id'] ?? ''));
            if ($key !== '') $keys[$key] = true;
        }
        if ($keys) $this->setTotal($gameId, $setId, count($keys));
    }

    /**
     * Reconciles the status for one opened set only. This is intentionally set-local:
     * it never scans the full WooCommerce catalogue.
     */
    public function reconcileOpenedSet(string $gameId, string $setId): array
    {
        $provider = Registry::instance()->get($gameId);
        if (!$provider) return $this->getSetStatus($gameId, $setId);

        $gameId = sanitize_key($provider->id());
        $setId = trim($setId);
        if ($gameId === '' || $setId === '') return $this->emptyStatus();

        global $wpdb;
        $wpdb->delete($this->presenceTable, [
            'game_id' => $gameId,
            'set_id' => $setId,
        ], ['%s', '%s']);

        $meta = $provider->metaKeys();
        $setMeta = (string)($meta['set_id'] ?? '');
        if ($setMeta === '') {
            $this->recomputeSummary($gameId, $setId);
            return $this->getSetStatus($gameId, $setId);
        }

        $q = new \WP_Query([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'meta_query' => [[
                'key' => $setMeta,
                'value' => $setId,
                'compare' => '=',
            ]],
        ]);

        $states = [];
        foreach ((array)$q->posts as $postId) {
            $postId = (int)$postId;
            $identity = $this->productIdentity($postId, $provider);
            if ($identity === null || $identity['set_id'] !== $setId) continue;
            $key = $identity['card_key'];
            if (!isset($states[$key])) {
                $states[$key] = ['has_publish' => false, 'has_price' => false];
            }
            if ((string)get_post_status($postId) === 'publish') $states[$key]['has_publish'] = true;
            if ($this->productHasPrice($postId)) $states[$key]['has_price'] = true;
        }
        wp_reset_postdata();

        foreach ($states as $cardKey => $state) {
            $this->upsertPresence(
                $gameId,
                $setId,
                (string)$cardKey,
                !$state['has_publish'],
                !$state['has_price']
            );
        }
        $this->recomputeSummary($gameId, $setId);
        return $this->getSetStatus($gameId, $setId);
    }

    public function getSetStatus(string $gameId, string $setId): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT registered_count, unpublished_count, unpriced_count, total_count
             FROM {$this->summaryTable}
             WHERE game_id = %s AND set_id = %s",
            sanitize_key($gameId), trim($setId)
        ), ARRAY_A);

        if (!is_array($row)) return $this->emptyStatus();
        return [
            'registeredCount' => (int)($row['registered_count'] ?? 0),
            'unpublishedCount' => (int)($row['unpublished_count'] ?? 0),
            'unpricedCount' => (int)($row['unpriced_count'] ?? 0),
            'totalCount' => $row['total_count'] === null ? null : (int)$row['total_count'],
        ];
    }

    public function ensureGameBuilt(GameProvider $provider): void
    {
        $gameId = sanitize_key($provider->id());
        if ($gameId === '') return;
        $flag = self::BUILD_PREFIX . $gameId;
        if ((int)get_option($flag, 0) === self::DB_VERSION) return;

        $this->rebuildGame($provider);
        update_option($flag, self::DB_VERSION, false);
    }

    public function rebuildGame(GameProvider $provider): void
    {
        global $wpdb;
        $gameId = sanitize_key($provider->id());
        $meta = $provider->metaKeys();
        $cardMeta = (string)($meta['card_id'] ?? '');
        $setMeta = (string)($meta['set_id'] ?? '');
        if ($gameId === '' || $cardMeta === '' || $setMeta === '') return;

        $wpdb->delete($this->presenceTable, ['game_id' => $gameId], ['%s']);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->summaryTable}
             SET registered_count = 0, unpublished_count = 0, unpriced_count = 0, updated_at = %s
             WHERE game_id = %s",
            current_time('mysql'), $gameId
        ));

        $q = new \WP_Query([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'meta_query' => [
                ['key' => $cardMeta, 'compare' => 'EXISTS'],
                ['key' => $setMeta, 'compare' => 'EXISTS'],
            ],
        ]);

        $states = [];
        foreach ((array)$q->posts as $postId) {
            $postId = (int)$postId;
            $identity = $this->productIdentity($postId, $provider);
            if ($identity === null) continue;
            $setId = $identity['set_id'];
            $cardKey = $identity['card_key'];
            if (!isset($states[$setId][$cardKey])) {
                $states[$setId][$cardKey] = ['has_publish' => false, 'has_price' => false];
            }
            if ((string)get_post_status($postId) === 'publish') $states[$setId][$cardKey]['has_publish'] = true;
            if ($this->productHasPrice($postId)) $states[$setId][$cardKey]['has_price'] = true;
        }
        wp_reset_postdata();

        foreach ($states as $setId => $cards) {
            foreach ($cards as $cardKey => $state) {
                $this->upsertPresence(
                    $gameId,
                    (string)$setId,
                    (string)$cardKey,
                    !$state['has_publish'],
                    !$state['has_price']
                );
            }
            $this->recomputeSummary($gameId, (string)$setId);
        }
    }

    private function refreshLogicalCard(GameProvider $provider, array $identity, int $excludePostId = 0): void
    {
        $gameId = sanitize_key($provider->id());
        if ($gameId === '') return;

        $qArgs = [
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'meta_query' => [
                ['key' => $identity['set_meta'], 'value' => $identity['set_id'], 'compare' => '='],
                ['key' => $identity['card_meta'], 'value' => $identity['card_value'], 'compare' => '='],
            ],
        ];
        if ($excludePostId > 0) $qArgs['post__not_in'] = [$excludePostId];

        $q = new \WP_Query($qArgs);
        $hasAny = false;
        $hasPublish = false;
        $hasPrice = false;
        foreach ((array)$q->posts as $postId) {
            $postId = (int)$postId;
            $hasAny = true;
            if ((string)get_post_status($postId) === 'publish') $hasPublish = true;
            if ($this->productHasPrice($postId)) $hasPrice = true;
            if ($hasPublish && $hasPrice) break;
        }
        wp_reset_postdata();

        if (!$hasAny) {
            $this->removePresence($gameId, $identity['set_id'], $identity['card_key']);
            return;
        }

        $this->upsertPresence(
            $gameId,
            $identity['set_id'],
            $identity['card_key'],
            !$hasPublish,
            !$hasPrice
        );
        $this->recomputeSummary($gameId, $identity['set_id']);
    }

    private function productIdentity(int $postId, GameProvider $provider): ?array
    {
        $meta = $provider->metaKeys();
        $cardMeta = (string)($meta['card_id'] ?? '');
        $setMeta = (string)($meta['set_id'] ?? '');
        if ($cardMeta === '' || $setMeta === '') return null;

        $cardId = trim((string)get_post_meta($postId, $cardMeta, true));
        $setId = trim((string)get_post_meta($postId, $setMeta, true));
        if ($cardId === '' || $setId === '') return null;

        $numberMeta = (string)($meta['card_number'] ?? '');
        $number = $numberMeta !== '' ? trim((string)get_post_meta($postId, $numberMeta, true)) : '';
        return [
            'set_id' => $setId,
            'card_key' => $number !== '' ? $number : $cardId,
            'card_meta' => $number !== '' ? $numberMeta : $cardMeta,
            'card_value' => $number !== '' ? $number : $cardId,
            'set_meta' => $setMeta,
        ];
    }

    private function productHasPrice(int $postId): bool
    {
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($postId);
            if ($product) return $product->get_price() !== '';
        }
        return get_post_meta($postId, '_price', true) !== ''
            || get_post_meta($postId, '_regular_price', true) !== ''
            || get_post_meta($postId, '_sale_price', true) !== '';
    }

    private function upsertPresence(string $gameId, string $setId, string $cardKey, bool $unpublished, bool $unpriced): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->presenceTable} (game_id,set_id,card_key,unpublished,unpriced,updated_at)
             VALUES (%s,%s,%s,%d,%d,%s)
             ON DUPLICATE KEY UPDATE unpublished = VALUES(unpublished), unpriced = VALUES(unpriced), updated_at = VALUES(updated_at)",
            sanitize_key($gameId), $setId, $cardKey, $unpublished ? 1 : 0, $unpriced ? 1 : 0, current_time('mysql')
        ));
    }

    private function removePresence(string $gameId, string $setId, string $cardKey): void
    {
        global $wpdb;
        $deleted = $wpdb->delete($this->presenceTable, [
            'game_id' => sanitize_key($gameId),
            'set_id' => $setId,
            'card_key' => $cardKey,
        ], ['%s', '%s', '%s']);
        if ((int)$deleted === 1) $this->recomputeSummary($gameId, $setId);
    }

    private function recomputeSummary(string $gameId, string $setId): void
    {
        global $wpdb;
        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS registered_count,
                    COALESCE(SUM(unpublished), 0) AS unpublished_count,
                    COALESCE(SUM(unpriced), 0) AS unpriced_count
             FROM {$this->presenceTable}
             WHERE game_id = %s AND set_id = %s",
            sanitize_key($gameId), $setId
        ), ARRAY_A);

        $registered = (int)($counts['registered_count'] ?? 0);
        $unpublished = (int)($counts['unpublished_count'] ?? 0);
        $unpriced = (int)($counts['unpriced_count'] ?? 0);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->summaryTable}
                (game_id,set_id,registered_count,unpublished_count,unpriced_count,total_count,updated_at)
             VALUES (%s,%s,%d,%d,%d,NULL,%s)
             ON DUPLICATE KEY UPDATE
                registered_count = VALUES(registered_count),
                unpublished_count = VALUES(unpublished_count),
                unpriced_count = VALUES(unpriced_count),
                updated_at = VALUES(updated_at)",
            sanitize_key($gameId), $setId, $registered, $unpublished, $unpriced, current_time('mysql')
        ));
    }

    private function summaryMap(string $gameId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT set_id, registered_count, unpublished_count, unpriced_count, total_count
             FROM {$this->summaryTable} WHERE game_id = %s",
            sanitize_key($gameId)
        ), ARRAY_A);
        $map = [];
        foreach ((array)$rows as $row) {
            $map[(string)$row['set_id']] = [
                'registered_count' => (int)$row['registered_count'],
                'unpublished_count' => (int)($row['unpublished_count'] ?? 0),
                'unpriced_count' => (int)($row['unpriced_count'] ?? 0),
                'total_count' => $row['total_count'] === null ? null : (int)$row['total_count'],
            ];
        }
        return $map;
    }

    private function setTotal(string $gameId, string $setId, int $total): void
    {
        global $wpdb;
        if ($total < 0) return;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->summaryTable}
                (game_id,set_id,registered_count,unpublished_count,unpriced_count,total_count,updated_at)
             VALUES (%s,%s,0,0,0,%d,%s)
             ON DUPLICATE KEY UPDATE total_count = VALUES(total_count), updated_at = VALUES(updated_at)",
            sanitize_key($gameId), $setId, $total, current_time('mysql')
        ));
    }

    private function extractTotal(array $set): ?int
    {
        foreach (['cardCount', 'totalCount', 'printedTotal', 'total', 'card_count', 'total_cards'] as $key) {
            if (isset($set[$key]) && is_numeric($set[$key]) && (int)$set[$key] >= 0) return (int)$set[$key];
        }
        return null;
    }

    private function emptyStatus(): array
    {
        return [
            'registeredCount' => 0,
            'unpublishedCount' => 0,
            'unpricedCount' => 0,
            'totalCount' => null,
        ];
    }
}
