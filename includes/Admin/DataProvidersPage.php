<?php
namespace NPS\Core\Admin;

use NPS\Core\DataProviderQuota;
use NPS\Core\DataProviderRegistry;
use NPS\Core\DataProviderSettings;

if (!defined('ABSPATH')) exit;

final class DataProvidersPage
{
    public static function boot(): void
    {
        add_action('admin_post_nps_save_data_provider', [self::class, 'save']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) return;

        $providers = DataProviderRegistry::instance()->all();
        echo '<div class="wrap" style="margin-top:18px;">';
        echo '<h2>Datakilder</h2>';
        echo '<p>Fælles oversigt over Singles-datakilder, API-nøgler og lokale requestbudgetter. Et budget på 0 betyder ingen lokal grænse.</p>';

        if (!$providers) {
            echo '<div class="notice notice-info inline"><p>Ingen datakilder er registreret endnu. Spil- og prisplugins kan registrere deres providers gennem Singles Core.</p></div>';
            echo '</div>';
            return;
        }

        $quota = new DataProviderQuota();
        foreach ($providers as $provider) {
            $id = (string)$provider['id'];
            $settings = DataProviderSettings::get($id);
            $usage = $quota->summary($id);
            self::renderProvider($provider, $settings, $usage);
        }
        echo '</div>';
    }

    public static function save(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('Ikke tilladt.');
        check_admin_referer('nps_save_data_provider');

        $providerId = isset($_POST['provider_id']) ? sanitize_key(wp_unslash((string)$_POST['provider_id'])) : '';
        $definition = DataProviderRegistry::instance()->get($providerId);
        if ($providerId === '' || $definition === null) {
            wp_safe_redirect(AdminHub::dataSourcesUrl());
            exit;
        }

        $credentials = [];
        $clear = [];
        foreach ((array)($definition['credential_fields'] ?? []) as $field) {
            if (!is_array($field)) continue;
            $key = sanitize_key((string)($field['key'] ?? ''));
            if ($key === '') continue;
            if (isset($_POST['credentials'][$key])) {
                $credentials[$key] = sanitize_text_field(wp_unslash((string)$_POST['credentials'][$key]));
            }
            if (!empty($_POST['clear_credentials'][$key])) $clear[] = $key;
        }

        $limits = isset($_POST['limits']) && is_array($_POST['limits']) ? wp_unslash($_POST['limits']) : [];
        DataProviderSettings::save($providerId, [
            'active' => !empty($_POST['active']),
            'credentials' => $credentials,
            'clear_credentials' => $clear,
            'limits' => [
                'daily' => max(0, (int)($limits['daily'] ?? 0)),
                'monthly' => max(0, (int)($limits['monthly'] ?? 0)),
                'monthly_reset_day' => min(31, max(1, (int)($limits['monthly_reset_day'] ?? 1))),
            ],
        ]);

        wp_safe_redirect(add_query_arg(['updated' => '1'], AdminHub::dataSourcesUrl()));
        exit;
    }

    /**
     * @param array<string,mixed> $provider
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $usage
     */
    private static function renderProvider(array $provider, array $settings, array $usage): void
    {
        $id = (string)$provider['id'];
        $uses = array_map('strval', (array)($provider['uses'] ?? []));
        echo '<div class="postbox" style="max-width:900px;margin-top:18px;">';
        echo '<div class="postbox-header"><h2 class="hndle">' . esc_html((string)$provider['label']) . '</h2></div>';
        echo '<div class="inside">';
        if ((string)($provider['description'] ?? '') !== '') {
            echo '<p>' . esc_html((string)$provider['description']) . '</p>';
        }
        if ($uses) {
            echo '<p><strong>Bruges til:</strong> ' . esc_html(implode(', ', $uses)) . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('nps_save_data_provider');
        echo '<input type="hidden" name="action" value="nps_save_data_provider">';
        echo '<input type="hidden" name="provider_id" value="' . esc_attr($id) . '">';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Status</th><td><label><input type="checkbox" name="active" value="1" ' . checked(!empty($settings['active']), true, false) . '> Aktiv</label></td></tr>';

        foreach ((array)($provider['credential_fields'] ?? []) as $field) {
            if (!is_array($field)) continue;
            $key = sanitize_key((string)($field['key'] ?? ''));
            if ($key === '') continue;
            $hasValue = (string)($settings['credentials'][$key] ?? '') !== '';
            $type = (string)($field['type'] ?? 'password');
            echo '<tr><th scope="row"><label for="nps-provider-' . esc_attr($id . '-' . $key) . '">' . esc_html((string)($field['label'] ?? $key)) . '</label></th><td>';
            printf(
                '<input class="regular-text" id="%s" type="%s" name="credentials[%s]" value="" placeholder="%s" autocomplete="off">',
                esc_attr('nps-provider-' . $id . '-' . $key),
                esc_attr($type === 'text' ? 'text' : 'password'),
                esc_attr($key),
                esc_attr($hasValue ? 'Gemt - lad feltet stå tomt for at beholde' : (string)($field['placeholder'] ?? ''))
            );
            if ($hasValue) {
                echo '<br><label><input type="checkbox" name="clear_credentials[' . esc_attr($key) . ']" value="1"> Fjern gemt værdi</label>';
            }
            echo '</td></tr>';
        }

        $limits = is_array($settings['limits'] ?? null) ? $settings['limits'] : [];
        echo '<tr><th scope="row">Dagligt budget</th><td><input type="number" min="0" step="1" name="limits[daily]" value="' . esc_attr((string)($limits['daily'] ?? 0)) . '"> requests pr. dag</td></tr>';
        echo '<tr><th scope="row">Månedligt budget</th><td><input type="number" min="0" step="1" name="limits[monthly]" value="' . esc_attr((string)($limits['monthly'] ?? 0)) . '"> requests pr. måned</td></tr>';
        echo '<tr><th scope="row">Månedlig nulstillingsdag</th><td><input type="number" min="1" max="31" step="1" name="limits[monthly_reset_day]" value="' . esc_attr((string)($limits['monthly_reset_day'] ?? 1)) . '"></td></tr>';
        echo '</tbody></table>';

        echo '<p><strong>Lokalt registreret forbrug:</strong> ';
        echo esc_html(self::usageText((array)$usage['daily'], 'dag')) . ' · ';
        echo esc_html(self::usageText((array)$usage['monthly'], 'måned'));
        echo '</p>';
        submit_button('Gem datakilde', 'secondary', 'submit', false);
        echo '</form>';
        echo '</div></div>';
    }

    /** @param array<string,mixed> $usage */
    private static function usageText(array $usage, string $period): string
    {
        $used = (int)($usage['used'] ?? 0);
        $limit = (int)($usage['limit'] ?? 0);
        $reset = (string)($usage['resets_at'] ?? '');
        $text = $limit > 0 ? sprintf('%d / %d pr. %s', $used, $limit, $period) : sprintf('%d pr. %s (ingen grænse)', $used, $period);
        if ($limit > 0 && $reset !== '') {
            try {
                $date = new \DateTimeImmutable($reset);
                $date = $date->setTimezone(wp_timezone());
                $text .= ' · nulstilles ' . wp_date('d-m-Y H:i', $date->getTimestamp(), wp_timezone());
            } catch (\Throwable $e) {
                // Keep the usage text even if a date cannot be formatted.
            }
        }
        return $text;
    }
}
