<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class ProviderStoreSchemaRepair
{
    private const REPAIR_OPTION = 'nps_provider_store_schema_repair_0814_v3';

    public static function run(): void
    {
        if ((string)get_option(self::REPAIR_OPTION, '') === 'done') return;

        global $wpdb;
        $jobs = DataProviderStore::jobsTable();

        // `cursor` is a reserved keyword on some MySQL/MariaDB versions. The
        // original dbDelta schema used it unquoted, which can make creation of
        // the jobs table fail while the other provider tables are installed.
        // Create this one table explicitly with the column quoted before asking
        // dbDelta to normalize the rest of the provider schema.
        $foundJobs = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jobs));
        if ((string)$foundJobs !== $jobs) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE IF NOT EXISTS {$jobs} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                provider_id varchar(64) NOT NULL,
                job_key varchar(191) NOT NULL,
                status varchar(32) NOT NULL DEFAULT 'pending',
                `cursor` longtext NULL,
                progress_current bigint(20) unsigned NOT NULL DEFAULT 0,
                progress_total bigint(20) unsigned NOT NULL DEFAULT 0,
                context longtext NULL,
                last_error text NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY provider_job (provider_id,job_key(120)),
                KEY provider_status (provider_id,status)
            ) {$charset}");
        }

        // Force one complete dbDelta normalization for 0.8.14. Some development
        // builds already had all four table names present, but an older/partial
        // table definition could still make job inserts fail.
        delete_option('nps_data_provider_db_version');
        DataProviderStore::install();

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
        $columns = $wpdb->get_col('SHOW COLUMNS FROM `' . esc_sql($jobs) . '`', 0);
        if (!is_array($columns)) return;

        $available = array_map('strval', $columns);
        foreach ($requiredJobColumns as $column) {
            if (!in_array($column, $available, true)) return;
        }

        update_option(self::REPAIR_OPTION, 'done', false);
    }
}
