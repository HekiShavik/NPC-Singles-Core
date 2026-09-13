<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class DataProviderStore
{
    public const DB_VERSION = '1';
    private const DB_OPTION = 'nps_data_provider_db_version';

    public static function requestsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'nps_provider_requests';
    }

    public static function rawTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'nps_provider_raw';
    }

    public static function jobsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'nps_provider_jobs';
    }

    public static function resourcesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'nps_provider_resources';
    }

    public static function install(): void
    {
        if ((string)get_option(self::DB_OPTION, '') === self::DB_VERSION) return;

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $requests = self::requestsTable();
        dbDelta("CREATE TABLE {$requests} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider_id varchar(64) NOT NULL,
            requested_at datetime NOT NULL,
            cost int(10) unsigned NOT NULL DEFAULT 1,
            status_code smallint(5) unsigned NOT NULL DEFAULT 0,
            outcome varchar(32) NOT NULL DEFAULT '',
            endpoint varchar(191) NOT NULL DEFAULT '',
            message text NULL,
            PRIMARY KEY  (id),
            KEY provider_requested (provider_id,requested_at),
            KEY provider_outcome (provider_id,outcome)
        ) {$charset};");

        $raw = self::rawTable();
        dbDelta("CREATE TABLE {$raw} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider_id varchar(64) NOT NULL,
            resource_key varchar(191) NOT NULL DEFAULT '',
            request_url text NULL,
            fetched_at datetime NOT NULL,
            status_code smallint(5) unsigned NOT NULL DEFAULT 0,
            checksum char(64) NOT NULL DEFAULT '',
            content_type varchar(80) NOT NULL DEFAULT '',
            payload longtext NULL,
            meta longtext NULL,
            PRIMARY KEY  (id),
            KEY provider_resource (provider_id,resource_key(120)),
            KEY provider_fetched (provider_id,fetched_at),
            KEY checksum (checksum)
        ) {$charset};");

        $jobs = self::jobsTable();
        dbDelta("CREATE TABLE {$jobs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider_id varchar(64) NOT NULL,
            job_key varchar(191) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            cursor longtext NULL,
            progress_current bigint(20) unsigned NOT NULL DEFAULT 0,
            progress_total bigint(20) unsigned NOT NULL DEFAULT 0,
            context longtext NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_job (provider_id,job_key(120)),
            KEY provider_status (provider_id,status)
        ) {$charset};");

        $resources = self::resourcesTable();
        dbDelta("CREATE TABLE {$resources} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider_id varchar(64) NOT NULL,
            resource_key varchar(191) NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'missing',
            last_checked datetime NULL,
            next_check datetime NULL,
            completed_at datetime NULL,
            raw_id bigint(20) unsigned NOT NULL DEFAULT 0,
            fingerprint varchar(128) NOT NULL DEFAULT '',
            meta longtext NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_resource (provider_id,resource_key(120)),
            KEY provider_state (provider_id,state),
            KEY next_check (next_check)
        ) {$charset};");

        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public function recordRequest(
        string $providerId,
        int $statusCode = 0,
        int $cost = 1,
        string $endpoint = '',
        string $outcome = '',
        string $message = ''
    ): int {
        global $wpdb;
        $ok = $wpdb->insert(self::requestsTable(), [
            'provider_id' => sanitize_key($providerId),
            'requested_at' => gmdate('Y-m-d H:i:s'),
            'cost' => max(1, $cost),
            'status_code' => max(0, $statusCode),
            'outcome' => sanitize_key($outcome),
            'endpoint' => substr(sanitize_text_field(self::redactUrl($endpoint)), 0, 191),
            'message' => sanitize_textarea_field($message),
        ], ['%s', '%s', '%d', '%d', '%s', '%s', '%s']);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public function usageSince(string $providerId, \DateTimeInterface $since): int
    {
        global $wpdb;
        $utc = \DateTimeImmutable::createFromInterface($since)->setTimezone(new \DateTimeZone('UTC'));
        return (int)$wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(cost), 0) FROM ' . self::requestsTable() . ' WHERE provider_id = %s AND requested_at >= %s',
            sanitize_key($providerId),
            $utc->format('Y-m-d H:i:s')
        ));
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function storeRaw(
        string $providerId,
        string $resourceKey,
        string $payload,
        int $statusCode = 200,
        string $requestUrl = '',
        string $contentType = 'application/json',
        array $meta = []
    ): int {
        global $wpdb;
        $ok = $wpdb->insert(self::rawTable(), [
            'provider_id' => sanitize_key($providerId),
            'resource_key' => substr(sanitize_text_field($resourceKey), 0, 191),
            'request_url' => esc_url_raw(self::redactUrl($requestUrl)),
            'fetched_at' => gmdate('Y-m-d H:i:s'),
            'status_code' => max(0, $statusCode),
            'checksum' => hash('sha256', $payload),
            'content_type' => substr(sanitize_text_field($contentType), 0, 80),
            'payload' => $payload,
            'meta' => wp_json_encode(self::redactMeta($meta), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    /** @return array<string,mixed>|null */
    public function latestRaw(string $providerId, string $resourceKey): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::rawTable() . ' WHERE provider_id = %s AND resource_key = %s ORDER BY id DESC LIMIT 1',
            sanitize_key($providerId),
            substr(sanitize_text_field($resourceKey), 0, 191)
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $job
     */
    public function saveJob(string $providerId, string $jobKey, array $job): int
    {
        global $wpdb;
        $providerId = sanitize_key($providerId);
        $jobKey = substr(sanitize_text_field($jobKey), 0, 191);
        $existing = $this->getJob($providerId, $jobKey);
        $now = gmdate('Y-m-d H:i:s');
        $data = [
            'provider_id' => $providerId,
            'job_key' => $jobKey,
            'status' => sanitize_key((string)($job['status'] ?? 'pending')),
            'cursor' => wp_json_encode($job['cursor'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'progress_current' => max(0, (int)($job['progress_current'] ?? 0)),
            'progress_total' => max(0, (int)($job['progress_total'] ?? 0)),
            'context' => wp_json_encode($job['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_error' => sanitize_textarea_field((string)($job['last_error'] ?? '')),
            'updated_at' => $now,
        ];

        if ($existing !== null) {
            $wpdb->update(self::jobsTable(), $data, ['id' => (int)$existing['id']]);
            return (int)$existing['id'];
        }

        $data['created_at'] = $now;
        $ok = $wpdb->insert(self::jobsTable(), $data);
        return $ok ? (int)$wpdb->insert_id : 0;
    }

    /** @return array<string,mixed>|null */
    public function getJob(string $providerId, string $jobKey): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::jobsTable() . ' WHERE provider_id = %s AND job_key = %s LIMIT 1',
            sanitize_key($providerId),
            substr(sanitize_text_field($jobKey), 0, 191)
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $resource
     */
    public function saveResourceState(string $providerId, string $resourceKey, array $resource): int
    {
        global $wpdb;
        $providerId = sanitize_key($providerId);
        $resourceKey = substr(sanitize_text_field($resourceKey), 0, 191);
        $existing = $this->getResourceState($providerId, $resourceKey);
        $state = sanitize_key((string)($resource['state'] ?? 'missing'));
        if (!in_array($state, ['missing', 'incomplete', 'complete'], true)) $state = 'missing';

        $completedAt = null;
        if ($state === 'complete') {
            if (array_key_exists('completed_at', $resource)) {
                $completedAt = self::mysqlDate($resource['completed_at']);
            } elseif ($existing !== null && (string)($existing['state'] ?? '') === 'complete' && !empty($existing['completed_at'])) {
                $completedAt = (string)$existing['completed_at'];
            } else {
                $completedAt = gmdate('Y-m-d H:i:s');
            }
        }

        $data = [
            'provider_id' => $providerId,
            'resource_key' => $resourceKey,
            'state' => $state,
            'last_checked' => self::mysqlDate($resource['last_checked'] ?? 'now'),
            'next_check' => $state === 'complete' ? null : self::mysqlDate($resource['next_check'] ?? null),
            'completed_at' => $completedAt,
            'raw_id' => max(0, (int)($resource['raw_id'] ?? 0)),
            'fingerprint' => substr(sanitize_text_field((string)($resource['fingerprint'] ?? '')), 0, 128),
            'meta' => wp_json_encode($resource['meta'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($existing !== null) {
            $wpdb->update(self::resourcesTable(), $data, ['id' => (int)$existing['id']]);
            return (int)$existing['id'];
        }

        $ok = $wpdb->insert(self::resourcesTable(), $data);
        return $ok ? (int)$wpdb->insert_id : 0;
    }

    /** @return array<string,mixed>|null */
    public function getResourceState(string $providerId, string $resourceKey): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::resourcesTable() . ' WHERE provider_id = %s AND resource_key = %s LIMIT 1',
            sanitize_key($providerId),
            substr(sanitize_text_field($resourceKey), 0, 191)
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function mysqlDate($value): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            $timezone = new \DateTimeZone('UTC');
            $date = $value === 'now' ? new \DateTimeImmutable('now', $timezone) : new \DateTimeImmutable((string)$value, $timezone);
            return $date->setTimezone($timezone)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function redactUrl(string $url): string
    {
        if ($url === '') return '';
        return (string)preg_replace(
            '/([?&](?:api[_-]?key|apikey|key|token|access[_-]?token|auth|authorization)=)[^&#]*/i',
            '$1***',
            $url
        );
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private static function redactMeta(array $meta): array
    {
        $redacted = [];
        foreach ($meta as $key => $value) {
            $keyText = (string)$key;
            if (preg_match('/(?:api[_-]?key|token|authorization|secret|password)/i', $keyText)) {
                $redacted[$key] = '***';
            } elseif (is_array($value)) {
                $redacted[$key] = self::redactMeta($value);
            } else {
                $redacted[$key] = $value;
            }
        }
        return $redacted;
    }
}
