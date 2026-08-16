<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class SetStatus
{
    private const DB_VERSION = 1;
    private const OPT_DB_VERSION = 'nps_set_status_db_version';
    private const BUILD_PREFIX = 'nps_set_status_built_v1_';

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
            updated_at datetime NOT NULL,
            PRIMARY KEY  (game_id,set_id,card_key),
            KEY game_set (game_id,set_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$summary} (
            game_id varchar(80) NOT NULL,
            set_id varchar(191) NOT NULL,
            registered_count int unsigned NOT NULL DEFAULT 0,
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
    }

    public function syncProduct(int $postId): void
    {
        if ($postId <= 0 || get_post_type($postId) !== 'product') return;
        foreach (Registry::instance()->all() as $provider) {
            $identity = $this->productIdentity($postId, $provider);
            if ($identity !== null) $this->ensurePresence($provider->id(), $identity['set_id'], $identity['card_key']);
        }
    }

    public function onProductSaved(int $postId, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($postId) || $post->post_type !== 'product') return;
        if (in_array($post->post_status, ['trash', 'auto-draft'], true)) return;

        foreach (Registry::instance()->all() as $provider) {
            $identity = $this->productIdentity($postId, $provider);
            if ($identity === null) continue;
            $this->ensurePresence($provider->id(), $identity['set_id'], $identity['card_key']);
        }
    }

    public function onBeforeDeletePost(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== 'product') return;

        foreach (Registry::instance()->all() as $provider) {
            $identity = $this->productIdentity($postId, $provider);
            if ($identity === null) continue;
            if (!$this->hasOtherProduct($postId, $provider, $identity)) {
                $this->removePresence($provider->id(), $identity['set_id'], $identity['card_key']);
            }
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
            $row = $status[$setId] ?? ['registered_count' => 0, 'total_count' => null];
            if ($knownTotal !== null && (int)($row['total_count'] ?? 0) !== $knownTotal) {
                $this->setTotal($gameId, $setId, $knownTotal);
                $row['total_count'] = $knownTotal;
            }

            $set['registeredCount'] = (int)($row['registered_count'] ?? 0);
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
        $wpdb->query($wpdb->prepare("UPDATE {$this->summaryTable} SET registered_count = 0, updated_at = %s WHERE game_id = %s", current_time('mysql'), $gameId));

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

        foreach ((array)$q->posts as $postId) {
            $identity = $this->productIdentity((int)$postId, $provider);
            if ($identity !== null) $this->ensurePresence($gameId, $identity['set_id'], $identity['card_key']);
        }
        wp_reset_postdata();
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

    private function ensurePresence(string $gameId, string $setId, string $cardKey): void
    {
        global $wpdb;
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->presenceTable} (game_id,set_id,card_key,updated_at) VALUES (%s,%s,%s,%s)",
            sanitize_key($gameId), $setId, $cardKey, current_time('mysql')
        ));
        if ((int)$inserted !== 1) return;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->summaryTable} (game_id,set_id,registered_count,total_count,updated_at)
             VALUES (%s,%s,1,NULL,%s)
             ON DUPLICATE KEY UPDATE registered_count = registered_count + 1, updated_at = VALUES(updated_at)",
            sanitize_key($gameId), $setId, current_time('mysql')
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
        if ((int)$deleted !== 1) return;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->summaryTable}
             SET registered_count = GREATEST(registered_count - 1, 0), updated_at = %s
             WHERE game_id = %s AND set_id = %s",
            current_time('mysql'), sanitize_key($gameId), $setId
        ));
    }

    private function hasOtherProduct(int $postId, GameProvider $provider, array $identity): bool
    {
        $q = new \WP_Query([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post__not_in' => [$postId],
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query' => [
                ['key' => $identity['set_meta'], 'value' => $identity['set_id'], 'compare' => '='],
                ['key' => $identity['card_meta'], 'value' => $identity['card_value'], 'compare' => '='],
            ],
        ]);
        $found = !empty($q->posts);
        wp_reset_postdata();
        return $found;
    }

    private function summaryMap(string $gameId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT set_id, registered_count, total_count FROM {$this->summaryTable} WHERE game_id = %s",
            sanitize_key($gameId)
        ), ARRAY_A);
        $map = [];
        foreach ((array)$rows as $row) {
            $map[(string)$row['set_id']] = [
                'registered_count' => (int)$row['registered_count'],
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
            "INSERT INTO {$this->summaryTable} (game_id,set_id,registered_count,total_count,updated_at)
             VALUES (%s,%s,0,%d,%s)
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
}
