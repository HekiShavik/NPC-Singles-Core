<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class ProviderStoreSchemaRepair
{
    private const REPAIR_OPTION = 'nps_provider_store_schema_repair_0814_v2';

    public static function run(): void
    {
        if ((string)get_option(self::REPAIR_OPTION, '') === 'done') return;

        // Force one complete dbDelta normalization for 0.8.14. Some development
        // builds already had all four table names present, but an older/partial
        // table definition could still make job inserts fail. Table presence is
        // therefore not enough; rerun the canonical schema once.
        delete_option('nps_data_provider_db_version');
        DataProviderStore::install();

        global $wpdb;
        $tables = [
            DataProviderStore::requestsTable(),
            DataProviderStore::rawTable(),
            DataProviderStore::jobsTable(),
            DataProviderStore::resourcesTable(),
        ];

        foreach ($tables as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ((string)$found !== $table) return;
        }

        $requiredJobColumns = [
            'id',
            'provider_id',
            'job_key',
            'status',
            'cursor',
            'progress_current',
            'progress_total',
            'context',
            'last_error',
            'created_at',
            'updated_at',
        ];
        $columns = $wpdb->get_col('SHOW COLUMNS FROM `' . esc_sql(DataProviderStore::jobsTable()) . '`', 0);
        if (!is_array($columns)) return;

        $available = array_map('strval', $columns);
        foreach ($requiredJobColumns as $column) {
            if (!in_array($column, $available, true)) return;
        }

        update_option(self::REPAIR_OPTION, 'done', false);
    }
}
