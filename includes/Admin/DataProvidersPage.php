<?php
namespace NPS\Core\Admin;

use NPS\Core\DataProviderInspector;
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
        echo '<p>Fælles oversigt over Singles-datakilder, API-nøgler, requestbudgetter og synkroniseringsstatus. Et budget på 0 betyder ingen lokal grænse.</p>';

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success inline"><p>Datakilden er gemt.</p></div>';
        }

        if (!$providers) {
            echo '<div class="notice notice-info inline"><p>Ingen datakilder er registreret endnu. Spil- og prisplugins kan registrere deres providers gennem Singles Core.</p></div>';
            echo '</div>';
            return;
        }

        $quota = new DataProviderQuota();
        $inspector = new DataProviderInspector();
        foreach ($providers as $provider) {
            $id = (string)$provider['id'];
            $settings = DataProviderSettings::get($id);
            $usage = $quota->summary($id);
            $diagnostics = [
                'latest_request' => $inspector->latestRequest($id),
                'jobs' => $inspector->jobs($id),
                'resources' => $inspector->resourceCounts($id),
                'raw_count' => $inspector->rawCount($id),
            ];
            self::renderProvider($provider, $settings, $usage, $diagnostics);
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
                $credentials[$key] = trim(wp_unslash((string)$_POST['credentials'][$key]));
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
     * @param array<string,mixed> $diagnostics
     */
    private static function renderProvider(array $provider, array $settings, array $usage, array $diagnostics): void
    {
        $id = (string)$provider['id'];
        $uses = array_map('strval', (array)($provider['uses'] ?? []));
        echo '<div class="postbox" style="max-width:980px;margin-top:18px;">';
        echo '<div class="postbox-header"><h2 class="hndle">' . esc_html((string)$provider['label']) . '</h2></div>';
        echo '<div class="inside">';
        if ((string)($provider['description'] ?? '') !== '') {
            echo '<p>' . esc_html((string)$provider['description']) . '</p>';
        }
        if ($uses) {
            echo '<p><strong>Bruges til:</strong> ' . esc_html(implode(', ', $uses)) . '</p>';
        }

        self::renderDiagnostics($diagnostics);

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

    /** @param array<string,mixed> $diagnostics */
    private static function renderDiagnostics(array $diagnostics): void
    {
        $resources = is_array($diagnostics['resources'] ?? null) ? $diagnostics['resources'] : [];
        $rawCount = (int)($diagnostics['raw_count'] ?? 0);
        echo '<p><strong>Gemte råsvar:</strong> ' . esc_html((string)$rawCount);
        echo ' · <strong>Resources:</strong> ';
        echo esc_html(sprintf(
            '%d komplette · %d delvise · %d manglende',
            (int)($resources['complete'] ?? 0),
            (int)($resources['incomplete'] ?? 0),
            (int)($resources['missing'] ?? 0)
        ));
        echo '</p>';

        $request = is_array($diagnostics['latest_request'] ?? null) ? $diagnostics['latest_request'] : null;
        if ($request !== null) {
            $parts = [(string)($request['requested_at'] ?? '')];
            if ((int)($request['status_code'] ?? 0) > 0) $parts[] = 'HTTP ' . (int)$request['status_code'];
            if ((string)($request['outcome'] ?? '') !== '') $parts[] = (string)$request['outcome'];
            if ((string)($request['endpoint'] ?? '') !== '') $parts[] = (string)$request['endpoint'];
            echo '<p><strong>Seneste request:</strong> ' . esc_html(implode(' · ', array_filter($parts))) . '</p>';
        }

        $jobs = is_array($diagnostics['jobs'] ?? null) ? $diagnostics['jobs'] : [];
        if (!$jobs) return;

        echo '<details style="margin:10px 0 14px;"><summary><strong>Seneste synkroniseringer</strong></summary>';
        echo '<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>Job</th><th>Status</th><th>Fremdrift</th><th>Opdateret</th></tr></thead><tbody>';
        foreach ($jobs as $job) {
            if (!is_array($job)) continue;
            $current = (int)($job['progress_current'] ?? 0);
            $total = (int)($job['progress_total'] ?? 0);
            $progress = $total > 0 ? sprintf('%d / %d', $current, $total) : (string)$current;
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html((string)($job['job_key'] ?? '')),
                esc_html((string)($job['status'] ?? '')),
                esc_html($progress),
                esc_html((string)($job['updated_at'] ?? ''))
            );
        }
        echo '</tbody></table></details>';
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
