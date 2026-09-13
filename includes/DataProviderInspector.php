<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class DataProviderInspector
{
    /** @return array<string,mixed>|null */
    public function latestRequest(string $providerId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . DataProviderStore::requestsTable() . ' WHERE provider_id = %s ORDER BY id DESC LIMIT 1',
            sanitize_key($providerId)
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function jobs(string $providerId, int $limit = 5): array
    {
        global $wpdb;
        $limit = min(50, max(1, $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . DataProviderStore::jobsTable() . ' WHERE provider_id = %s ORDER BY updated_at DESC LIMIT %d',
            sanitize_key($providerId),
            $limit
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,int> */
    public function resourceCounts(string $providerId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT state, COUNT(*) AS qty FROM ' . DataProviderStore::resourcesTable() . ' WHERE provider_id = %s GROUP BY state',
            sanitize_key($providerId)
        ), ARRAY_A);

        $counts = ['missing' => 0, 'incomplete' => 0, 'complete' => 0];
        foreach ((array)$rows as $row) {
            $state = sanitize_key((string)($row['state'] ?? ''));
            if (array_key_exists($state, $counts)) $counts[$state] = (int)($row['qty'] ?? 0);
        }
        return $counts;
    }

    public function rawCount(string $providerId): int
    {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . DataProviderStore::rawTable() . ' WHERE provider_id = %s',
            sanitize_key($providerId)
        ));
    }
}
