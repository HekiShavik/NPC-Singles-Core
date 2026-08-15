<?php

namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class LocalCardIndex
{
    public const DB_VERSION = '1';
    public const DB_OPTION = 'nps_card_index_db_version';

    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'nps_card_index';
    }

    public static function install(): void
    {
        if ((string)get_option(self::DB_OPTION, '') === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::tableName();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            game_id varchar(40) NOT NULL,
            card_id varchar(80) NOT NULL,
            card_name varchar(255) NOT NULL,
            set_id varchar(80) NOT NULL DEFAULT '',
            set_name varchar(255) NOT NULL DEFAULT '',
            collector_number varchar(80) NOT NULL DEFAULT '',
            language varchar(16) NOT NULL DEFAULT '',
            rarity varchar(40) NOT NULL DEFAULT '',
            image_url text NULL,
            image_back_url text NULL,
            finishes text NULL,
            release_date varchar(10) NOT NULL DEFAULT '',
            search_text text NULL,
            payload longtext NULL,
            sync_token varchar(40) NOT NULL DEFAULT '',
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY game_card (game_id, card_id),
            KEY game_name (game_id, card_name(191)),
            KEY game_set (game_id, set_id),
            KEY game_collector (game_id, collector_number),
            KEY game_sync (game_id, sync_token)
        ) {$charset};";

        dbDelta($sql);
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public function count(string $gameId): int
    {
        global $wpdb;
        $table = self::tableName();
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE game_id = %s",
            sanitize_key($gameId)
        ));
    }

    public function clear(string $gameId): int
    {
        global $wpdb;
        $table = self::tableName();
        return (int)$wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE game_id = %s",
            sanitize_key($gameId)
        ));
    }

    public function pruneOtherSyncs(string $gameId, string $syncToken): int
    {
        global $wpdb;
        $table = self::tableName();
        return (int)$wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE game_id = %s AND sync_token <> %s",
            sanitize_key($gameId),
            $syncToken
        ));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function upsertBatch(string $gameId, array $rows, string $syncToken): int
    {
        if (!$rows) return 0;

        global $wpdb;
        $table = self::tableName();
        $gameId = sanitize_key($gameId);
        $now = current_time('mysql', true);

        $columns = [
            'game_id', 'card_id', 'card_name', 'set_id', 'set_name', 'collector_number',
            'language', 'rarity', 'image_url', 'image_back_url', 'finishes', 'release_date',
            'search_text', 'payload', 'sync_token', 'updated_at',
        ];

        $values = [];
        $placeholders = [];
        foreach ($rows as $row) {
            $cardId = trim((string)($row['card_id'] ?? ''));
            $name = trim((string)($row['card_name'] ?? ''));
            if ($cardId === '' || $name === '') continue;

            $finishes = $row['finishes'] ?? [];
            if (is_array($finishes)) {
                $finishes = wp_json_encode(array_values($finishes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $payload = $row['payload'] ?? [];
            if (is_array($payload) || is_object($payload)) {
                $payload = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $releaseDate = trim((string)($row['release_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $releaseDate)) {
                $releaseDate = '';
            }

            $placeholders[] = '(' . implode(',', array_fill(0, count($columns), '%s')) . ')';
            array_push(
                $values,
                $gameId,
                $cardId,
                $name,
                trim((string)($row['set_id'] ?? '')),
                trim((string)($row['set_name'] ?? '')),
                trim((string)($row['collector_number'] ?? '')),
                strtoupper(trim((string)($row['language'] ?? ''))),
                trim((string)($row['rarity'] ?? '')),
                trim((string)($row['image_url'] ?? '')),
                trim((string)($row['image_back_url'] ?? '')),
                (string)$finishes,
                $releaseDate,
                trim((string)($row['search_text'] ?? '')),
                (string)$payload,
                $syncToken,
                $now
            );
        }

        if (!$placeholders) return 0;

        $updates = [
            'card_name=VALUES(card_name)',
            'set_id=VALUES(set_id)',
            'set_name=VALUES(set_name)',
            'collector_number=VALUES(collector_number)',
            'language=VALUES(language)',
            'rarity=VALUES(rarity)',
            'image_url=VALUES(image_url)',
            'image_back_url=VALUES(image_back_url)',
            'finishes=VALUES(finishes)',
            'release_date=VALUES(release_date)',
            'search_text=VALUES(search_text)',
            'payload=VALUES(payload)',
            'sync_token=VALUES(sync_token)',
            'updated_at=VALUES(updated_at)',
        ];

        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES '
            . implode(',', $placeholders)
            . ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);

        $prepared = $wpdb->prepare($sql, $values);
        $result = $wpdb->query($prepared);
        return $result === false ? 0 : count($placeholders);
    }

    /** @return array<int,array<string,mixed>> */
    public function search(string $gameId, string $term, int $limit = 60): array
    {
        global $wpdb;
        $table = self::tableName();
        $gameId = sanitize_key($gameId);
        $term = trim($term);
        $termLength = function_exists('mb_strlen') ? mb_strlen($term) : strlen($term);
        if ($gameId === '' || $termLength < 2) return [];

        $limit = max(1, min(100, $limit));
        $like = '%' . $wpdb->esc_like($term) . '%';
        $prefix = $wpdb->esc_like($term) . '%';
        $exact = $term;

        $sql = $wpdb->prepare(
            "SELECT card_id, card_name, set_id, set_name, collector_number, language, rarity,
                    image_url, image_back_url, finishes, release_date, payload
             FROM {$table}
             WHERE game_id = %s
               AND (card_name LIKE %s OR set_name LIKE %s OR collector_number LIKE %s OR search_text LIKE %s)
             ORDER BY
               CASE
                 WHEN LOWER(card_name) = LOWER(%s) THEN 0
                 WHEN card_name LIKE %s THEN 1
                 ELSE 2
               END,
               card_name ASC,
               release_date DESC,
               set_name ASC,
               collector_number ASC
             LIMIT %d",
            $gameId, $like, $like, $like, $like, $exact, $prefix, $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) return [];

        foreach ($rows as &$row) {
            $finishes = json_decode((string)($row['finishes'] ?? ''), true);
            $payload = json_decode((string)($row['payload'] ?? ''), true);
            $row['finishes'] = is_array($finishes) ? $finishes : [];
            $row['payload'] = is_array($payload) ? $payload : [];
        }
        unset($row);
        return $rows;
    }
    /** @return array<string,mixed>|null */
    public function get(string $gameId, string $cardId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT card_id, card_name, set_id, set_name, collector_number, language, rarity,
                    image_url, image_back_url, finishes, release_date, payload
             FROM " . self::tableName() . " WHERE game_id = %s AND card_id = %s LIMIT 1",
            sanitize_key($gameId), sanitize_text_field($cardId)
        ), ARRAY_A);
        return is_array($row) ? $this->hydrateRow(sanitize_key($gameId), $row) : null;
    }

    /**
     * Structured, game-neutral card lookup.
     * Criteria: card_id, name/card_name, set_id, set_name, card_number/collector_number, language.
     * @param array<string,mixed> $criteria
     * @return array<int,array<string,mixed>>
     */
    public function searchCriteria(string $gameId, array $criteria, int $limit = 25): array
    {
        global $wpdb;
        $gameId = sanitize_key($gameId);
        if ($gameId === '') return [];
        $limit = max(1, min(100, $limit));

        $cardId = trim((string)($criteria['card_id'] ?? ''));
        if ($cardId !== '') {
            $row = $this->get($gameId, $cardId);
            return $row ? [$row] : [];
        }

        $name = trim((string)($criteria['name'] ?? $criteria['card_name'] ?? ''));
        $setId = trim((string)($criteria['set_id'] ?? ''));
        $setName = trim((string)($criteria['set_name'] ?? $criteria['set'] ?? ''));
        $number = trim((string)($criteria['card_number'] ?? $criteria['collector_number'] ?? $criteria['number'] ?? ''));
        $language = strtoupper(trim((string)($criteria['language'] ?? '')));

        $where = ['game_id = %s'];
        $args = [$gameId];
        if ($setId !== '') { $where[] = 'set_id = %s'; $args[] = $setId; }
        if ($number !== '') {
            $numerator = trim(explode('/', $number, 2)[0]);
            $where[] = '(collector_number = %s OR collector_number LIKE %s)';
            $args[] = $number; $args[] = $numerator . '/%';
        }
        // Collector number is the strongest cross-provider discriminator. When it is
        // available, keep name/set as scoring signals rather than hard SQL filters;
        // older index snapshots may not contain every descriptive field.
        if ($number === '') {
            if ($setName !== '') { $where[] = 'set_name LIKE %s'; $args[] = '%' . $wpdb->esc_like($setName) . '%'; }
            if ($name !== '') { $where[] = 'card_name LIKE %s'; $args[] = '%' . $wpdb->esc_like($name) . '%'; }
        }
        if ($language !== '') { $where[] = '(language = %s OR language = \'\')'; $args[] = $language; }

        if (count($where) === 1) return [];
        $sql = "SELECT card_id, card_name, set_id, set_name, collector_number, language, rarity,
                       image_url, image_back_url, finishes, release_date, payload
                FROM " . self::tableName() . " WHERE " . implode(' AND ', $where) . " LIMIT %d";
        $args[] = $limit * 4;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $row = $this->hydrateRow($gameId, $row);
            $score = 0.0;
            if ($number !== '' && $this->normNumber($number) === $this->normNumber((string)$row['card_number'])) $score += 0.45;
            if ($setName !== '' && $this->norm($setName) === $this->norm((string)$row['set_name'])) $score += 0.30;
            if ($name !== '' && $this->norm($name) === $this->norm((string)$row['name'])) $score += 0.25;
            if ($setId !== '' && $setId === (string)$row['set_id']) $score += 0.30;
            $row['match_score'] = min(1.0, $score);
            $out[] = $row;
        }
        usort($out, static fn(array $a, array $b): int => (($b['match_score'] ?? 0) <=> ($a['match_score'] ?? 0)) ?: strnatcasecmp((string)$a['card_number'], (string)$b['card_number']));
        return array_slice($out, 0, $limit);
    }

    public function deleteSet(string $gameId, string $setId): int
    {
        global $wpdb;
        return (int)$wpdb->delete(self::tableName(), ['game_id'=>sanitize_key($gameId), 'set_id'=>sanitize_text_field($setId)], ['%s','%s']);
    }

    /** @return array<string,mixed> */
    private function hydrateRow(string $gameId, array $row): array
    {
        $finishes = json_decode((string)($row['finishes'] ?? ''), true);
        $payload = json_decode((string)($row['payload'] ?? ''), true);
        return [
            'game_id'=>$gameId,
            'card_id'=>(string)($row['card_id'] ?? ''),
            'name'=>(string)($row['card_name'] ?? ''),
            'set_id'=>(string)($row['set_id'] ?? ''),
            'set_name'=>(string)($row['set_name'] ?? ''),
            'card_number'=>(string)($row['collector_number'] ?? ''),
            'language'=>(string)($row['language'] ?? ''),
            'rarity'=>(string)($row['rarity'] ?? ''),
            'image_url'=>(string)($row['image_url'] ?? ''),
            'image_back_url'=>(string)($row['image_back_url'] ?? ''),
            'finishes'=>is_array($finishes) ? $finishes : [],
            'release_date'=>(string)($row['release_date'] ?? ''),
            'payload'=>is_array($payload) ? $payload : [],
        ];
    }

    private function norm(string $value): string
    {
        return (string)preg_replace('/[^a-z0-9]+/u', '', strtolower(remove_accents(trim($value))));
    }

    private function normNumber(string $value): string
    {
        return $this->norm(explode('/', trim($value), 2)[0]);
    }

}
