<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class ProviderStoreSchemaRepair
{
    private const REPAIR_OPTION = 'nps_provider_store_schema_repair_0814';

    public static function run(): void
    {
        if ((string)get_option(self::REPAIR_OPTION, '') === 'done') return;

        global $wpdb;
        $tables = [
            DataProviderStore::requestsTable(),
            DataProviderStore::rawTable(),
            DataProviderStore::jobsTable(),
            DataProviderStore::resourcesTable(),
        ];

        $missing = false;
        foreach ($tables as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ((string)$found !== $table) {
                $missing = true;
                break;
            }
        }

        if ($missing) {
            // Some development builds already stored provider DB version 1 before
            // every provider table existed. Clear only the schema marker and let
            // the existing dbDelta installer recreate any missing tables.
            delete_option('nps_data_provider_db_version');
            DataProviderStore::install();
        }

        $allPresent = true;
        foreach ($tables as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ((string)$found !== $table) {
                $allPresent = false;
                break;
            }
        }

        if ($allPresent) update_option(self::REPAIR_OPTION, 'done', false);
    }
}
