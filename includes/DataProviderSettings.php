<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class DataProviderSettings
{
    private const OPTION = 'nps_data_provider_settings';

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $value = get_option(self::OPTION, []);
        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    public static function get(string $providerId): array
    {
        $providerId = sanitize_key($providerId);
        $definition = DataProviderRegistry::instance()->get($providerId) ?? [];
        $saved = self::all()[$providerId] ?? [];
        if (!is_array($saved)) $saved = [];

        $defaults = is_array($definition['default_limits'] ?? null) ? $definition['default_limits'] : [];
        $credentials = is_array($saved['credentials'] ?? null) ? $saved['credentials'] : [];

        return [
            'active' => array_key_exists('active', $saved) ? (bool)$saved['active'] : true,
            'credentials' => $credentials,
            'limits' => [
                'daily' => max(0, (int)($saved['limits']['daily'] ?? $defaults['daily'] ?? 0)),
                'monthly' => max(0, (int)($saved['limits']['monthly'] ?? $defaults['monthly'] ?? 0)),
                'monthly_reset_day' => min(31, max(1, (int)($saved['limits']['monthly_reset_day'] ?? $defaults['monthly_reset_day'] ?? 1))),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $settings
     */
    public static function save(string $providerId, array $settings): void
    {
        $providerId = sanitize_key($providerId);
        if ($providerId === '') return;

        $all = self::all();
        $existing = is_array($all[$providerId] ?? null) ? $all[$providerId] : [];
        $credentials = is_array($existing['credentials'] ?? null) ? $existing['credentials'] : [];

        if (isset($settings['credentials']) && is_array($settings['credentials'])) {
            foreach ($settings['credentials'] as $key => $value) {
                $key = sanitize_key((string)$key);
                if ($key === '') continue;
                $value = trim((string)$value);
                if ($value === '') continue;
                $credentials[$key] = $value;
            }
        }

        if (isset($settings['clear_credentials']) && is_array($settings['clear_credentials'])) {
            foreach ($settings['clear_credentials'] as $key) {
                unset($credentials[sanitize_key((string)$key)]);
            }
        }

        $limits = is_array($settings['limits'] ?? null) ? $settings['limits'] : [];
        $all[$providerId] = [
            'active' => !empty($settings['active']),
            'credentials' => $credentials,
            'limits' => [
                'daily' => max(0, (int)($limits['daily'] ?? 0)),
                'monthly' => max(0, (int)($limits['monthly'] ?? 0)),
                'monthly_reset_day' => min(31, max(1, (int)($limits['monthly_reset_day'] ?? 1))),
            ],
        ];

        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, $all, '', false);
        } else {
            update_option(self::OPTION, $all, false);
        }
    }

    public static function credential(string $providerId, string $key): string
    {
        $settings = self::get($providerId);
        return (string)($settings['credentials'][sanitize_key($key)] ?? '');
    }
}
