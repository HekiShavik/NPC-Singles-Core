<?php

namespace NPS\Core\Admin;

use NPS\Core\Http;
use NPS\Core\LocalCardIndex;
use NPS\Core\Registry;

if (!defined('ABSPATH')) exit;

/**
 * Shared local-card search UI and generic index builder.
 *
 * Games with their own richer index pipeline can advertise the
 * `local_card_index` capability. Their existing index remains authoritative;
 * Core only owns the common search presentation in that case.
 */
final class LocalCardSearchTool
{
    private const NONCE_ACTION = 'nps_local_card_search';
    private const BUILD_OPTION = 'nps_local_card_index_build_state';

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;

        add_action('wp_ajax_nps_local_card_search', [self::class, 'ajaxSearch']);
        add_action('wp_ajax_nps_local_card_index_start', [self::class, 'ajaxIndexStart']);
        add_action('wp_ajax_nps_local_card_index_step', [self::class, 'ajaxIndexStep']);
    }

    public static function enqueue(string $section, string $gameId, string $uiPrefix): void
    {
        if ($section !== 'bulk' && $section !== 'settings') return;

        $jsPath = NPS_CORE_DIR . 'assets/local-card-search.js';
        $cssPath = NPS_CORE_DIR . 'assets/local-card-search.css';
        if (!is_file($jsPath)) return;

        if (is_file($cssPath)) {
            wp_enqueue_style(
                'nps-local-card-search',
                NPS_CORE_URL . 'assets/local-card-search.css',
                [],
                filemtime($cssPath)
            );
        }

        wp_enqueue_script(
            'nps-local-card-search',
            NPS_CORE_URL . 'assets/local-card-search.js',
            [],
            filemtime($jsPath),
            true
        );

        $index = new LocalCardIndex();
        $games = [];
        foreach (Registry::instance()->all() as $id => $provider) {
            $id = sanitize_key((string)$id);
            $caps = (array)$provider->capabilities();
            $games[] = [
                'id' => $id,
                'name' => (string)$provider->name(),
                'count' => $index->count($id),
                'externalIndex' => !empty($caps['local_card_index']),
                'settingsUrl' => AdminHub::settingsUrl($id),
            ];
        }

        wp_localize_script('nps-local-card-search', 'NPS_CARD_SEARCH', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'mode' => $section,
            'gameId' => sanitize_key($gameId),
            'uiPrefix' => sanitize_key($uiPrefix),
            'games' => $games,
        ]);
    }

    public static function renderSettingsPanel(): void
    {
        if (!current_user_can('manage_woocommerce')) return;

        $games = Registry::instance()->all();
        if (!$games) return;

        $index = new LocalCardIndex();

        echo '<div class="card nps-card-index-settings" style="max-width:none;margin-top:16px;">';
        echo '<h2>Kortsøgning</h2>';
        echo '<p>Navnesøgningen bruger et lokalt indeks, så den ikke behøver at spørge kort-API\'et for hver søgning. Indekset opdateres kun, når du beder om det.</p>';
        echo '<table class="widefat striped" style="max-width:1000px;">';
        echo '<thead><tr><th>Spil</th><th style="width:220px;">Indekseret</th><th style="width:330px;">Handling</th></tr></thead><tbody>';

        foreach ($games as $gameId => $provider) {
            $gameId = sanitize_key((string)$gameId);
            $caps = (array)$provider->capabilities();
            $external = !empty($caps['local_card_index']);
            $count = $index->count($gameId);

            echo '<tr data-nps-index-game="' . esc_attr($gameId) . '">';
            echo '<td><strong>' . esc_html((string)$provider->name()) . '</strong></td>';
            echo '<td class="nps-card-index__count">' . esc_html(number_format_i18n($count)) . ' printings</td>';
            echo '<td>';
            if ($external) {
                echo '<a class="button" href="' . esc_url(AdminHub::settingsUrl($gameId)) . '">Åbn spillets indeksopdatering</a>';
                echo ' <span class="nps-card-index__status">Administreres af spil-pluginet.</span>';
            } else {
                echo '<button type="button" class="button button-primary nps-card-index__rebuild" data-game="' . esc_attr($gameId) . '">Opdatér søgeindeks</button>';
                echo ' <span class="nps-card-index__status" aria-live="polite"></span>';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public static function ajaxSearch(): void
    {
        self::assertAjaxAccess();

        $gameId = self::postedGameId();
        $term = trim((string)wp_unslash($_POST['term'] ?? ''));
        $termLength = function_exists('mb_strlen') ? mb_strlen($term) : strlen($term);

        $index = new LocalCardIndex();
        if ($termLength < 2) {
            wp_send_json_success([
                'items' => [],
                'count' => 0,
                'indexed' => $index->count($gameId),
            ]);
        }

        $items = $index->search($gameId, $term, 60);
        foreach ($items as &$item) {
            $item = apply_filters('nps_local_card_search_result', $item, $gameId);
        }
        unset($item);

        wp_send_json_success([
            'items' => $items,
            'count' => count($items),
            'indexed' => $index->count($gameId),
        ]);
    }

    public static function ajaxIndexStart(): void
    {
        self::assertAjaxAccess();

        $gameId = self::postedGameId();
        $provider = self::providerOrFail($gameId);
        $caps = (array)$provider->capabilities();
        if (!empty($caps['local_card_index'])) {
            wp_send_json_error([
                'message' => 'Dette spil vedligeholder sit eget søgeindeks. Brug spillets indeksopdatering under Indstillinger.',
            ], 409);
        }

        $runtime = self::createRuntime($provider);
        if (is_wp_error($runtime)) {
            wp_send_json_error(['message' => $runtime->get_error_message()], 500);
        }

        $sets = self::loadSets($runtime['cache'], $runtime['api']);
        if (is_wp_error($sets)) {
            wp_send_json_error(['message' => $sets->get_error_message()], 502);
        }
        if (!$sets) {
            wp_send_json_error(['message' => 'Ingen sæt kunne findes til indeksering. Opdatér spillets set-liste først.'], 409);
        }

        $token = wp_generate_uuid4();
        $state = self::buildState();
        $state[$gameId] = [
            'token' => $token,
            'cursor' => 0,
            'sets' => array_values($sets),
            'accepted' => 0,
            'started_at' => time(),
        ];
        update_option(self::BUILD_OPTION, $state, false);

        wp_send_json_success([
            'token' => $token,
            'processed' => 0,
            'totalSets' => count($sets),
            'indexed' => (new LocalCardIndex())->count($gameId),
            'done' => false,
        ]);
    }

    public static function ajaxIndexStep(): void
    {
        self::assertAjaxAccess();

        $gameId = self::postedGameId();
        $provider = self::providerOrFail($gameId);
        $token = trim((string)wp_unslash($_POST['token'] ?? ''));

        $allState = self::buildState();
        $state = isset($allState[$gameId]) && is_array($allState[$gameId]) ? $allState[$gameId] : [];
        if ($token === '' || empty($state['token']) || !hash_equals((string)$state['token'], $token)) {
            wp_send_json_error(['message' => 'Indekskørslen er ikke længere aktiv. Start den igen.'], 409);
        }

        $sets = is_array($state['sets'] ?? null) ? array_values($state['sets']) : [];
        $cursor = max(0, (int)($state['cursor'] ?? 0));
        if ($cursor >= count($sets)) {
            self::finishBuild($gameId, $token, $state, $allState);
            return;
        }

        $setInfo = is_array($sets[$cursor] ?? null) ? $sets[$cursor] : [];
        $setId = trim((string)($setInfo['id'] ?? ''));
        if ($setId === '') {
            $state['cursor'] = $cursor + 1;
            $allState[$gameId] = $state;
            update_option(self::BUILD_OPTION, $allState, false);
            self::sendProgress($gameId, $state, count($sets), false);
        }

        $runtime = self::createRuntime($provider);
        if (is_wp_error($runtime)) {
            wp_send_json_error(['message' => $runtime->get_error_message()], 500);
        }

        try {
            $payload = (array)$runtime['set_cache']->get($setId, false);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'Kunne ikke indlæse ' . $setId . ': ' . $e->getMessage()], 500);
        }

        if (!($payload['ok'] ?? false)) {
            wp_send_json_error([
                'message' => 'Kunne ikke indlæse ' . ($setInfo['name'] ?? $setId) . ': ' . (string)($payload['message'] ?? 'ukendt fejl'),
            ], 502);
        }

        $cards = (array)($payload['data']['cards'] ?? []);
        $rows = self::rowsForSet($setInfo, $cards);
        if ($rows) {
            $written = (new LocalCardIndex())->upsertBatch($gameId, $rows, $token);
            if ($written <= 0) {
                wp_send_json_error(['message' => 'Kunne ikke gemme kortene fra ' . ($setInfo['name'] ?? $setId) . ' i søgeindekset.'], 500);
            }
            $state['accepted'] = (int)($state['accepted'] ?? 0) + count($rows);
        }

        $state['cursor'] = $cursor + 1;
        $allState[$gameId] = $state;
        update_option(self::BUILD_OPTION, $allState, false);

        if ((int)$state['cursor'] >= count($sets)) {
            self::finishBuild($gameId, $token, $state, $allState);
            return;
        }

        self::sendProgress($gameId, $state, count($sets), false);
    }

    private static function finishBuild(string $gameId, string $token, array $state, array $allState): void
    {
        $index = new LocalCardIndex();
        $index->pruneOtherSyncs($gameId, $token);
        $count = $index->count($gameId);

        unset($allState[$gameId]);
        update_option(self::BUILD_OPTION, $allState, false);

        wp_send_json_success([
            'token' => $token,
            'processed' => count((array)($state['sets'] ?? [])),
            'totalSets' => count((array)($state['sets'] ?? [])),
            'accepted' => (int)($state['accepted'] ?? 0),
            'indexed' => $count,
            'done' => true,
        ]);
    }

    private static function sendProgress(string $gameId, array $state, int $total, bool $done): void
    {
        wp_send_json_success([
            'token' => (string)($state['token'] ?? ''),
            'processed' => (int)($state['cursor'] ?? 0),
            'totalSets' => $total,
            'accepted' => (int)($state['accepted'] ?? 0),
            'indexed' => (new LocalCardIndex())->count($gameId),
            'done' => $done,
        ]);
    }

    /** @return array<int,array<string,mixed>>|\WP_Error */
    private static function loadSets(object $cache, object $api)
    {
        $cached = method_exists($cache, 'get') ? $cache->get('sets') : null;
        $sets = self::setsFromPayload(is_array($cached) ? $cached : []);
        if ($sets) return $sets;

        try {
            $first = (array)$api->get('/sets', [
                'page' => 1,
                'pageSize' => 250,
                'orderBy' => 'releaseDate',
            ], 45);
        } catch (\Throwable $e) {
            return new \WP_Error('nps_card_index_sets', 'Kunne ikke hente set-listen: ' . $e->getMessage());
        }

        if (!($first['ok'] ?? false)) {
            return new \WP_Error('nps_card_index_sets', (string)($first['message'] ?? $first['error'] ?? 'Kunne ikke hente set-listen.'));
        }

        $sets = self::setsFromApiResponse($first);
        $total = (int)($first['data']['totalCount'] ?? $first['data']['data']['totalCount'] ?? 0);
        if ($total > count($sets)) {
            $page = 2;
            while (count($sets) < $total && $page <= 50) {
                $next = (array)$api->get('/sets', [
                    'page' => $page,
                    'pageSize' => 250,
                    'orderBy' => 'releaseDate',
                ], 45);
                if (!($next['ok'] ?? false)) break;
                $more = self::setsFromApiResponse($next);
                if (!$more) break;
                foreach ($more as $row) {
                    $sets[(string)$row['id']] = $row;
                }
                $sets = array_values($sets);
                $page++;
            }
        }

        return array_values($sets);
    }

    /** @return array<int,array<string,mixed>> */
    private static function setsFromPayload(array $payload): array
    {
        $data = $payload['data'] ?? [];
        $rows = is_array($data) ? ($data['items'] ?? $data['data'] ?? $data) : [];
        return self::normalizeSets(is_array($rows) ? $rows : []);
    }

    /** @return array<int,array<string,mixed>> */
    private static function setsFromApiResponse(array $response): array
    {
        $data = $response['data'] ?? [];
        $rows = is_array($data) ? ($data['data'] ?? $data['items'] ?? $data) : [];
        return self::normalizeSets(is_array($rows) ? $rows : []);
    }

    /** @return array<int,array<string,mixed>> */
    private static function normalizeSets(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') continue;
            $out[$id] = [
                'id' => $id,
                'name' => trim((string)($row['name'] ?? '')),
                'ptcgoCode' => trim((string)($row['ptcgoCode'] ?? $row['code'] ?? '')),
                'series' => trim((string)($row['series'] ?? '')),
                'releaseDate' => trim((string)($row['releaseDate'] ?? $row['release_date'] ?? '')),
            ];
        }
        return array_values($out);
    }

    /** @return array<int,array<string,mixed>> */
    private static function rowsForSet(array $setInfo, array $cards): array
    {
        $rows = [];
        foreach ($cards as $card) {
            if (!is_array($card)) continue;
            $cardId = trim((string)($card['id'] ?? ''));
            $name = trim((string)($card['name'] ?? ''));
            if ($cardId === '' || $name === '') continue;

            $version = trim((string)($card['version'] ?? ''));
            $artist = trim((string)($card['artist'] ?? ''));
            $illustrator = trim((string)($card['illustrator'] ?? ''));
            $oracleName = trim((string)($card['oracle_name'] ?? ''));
            $number = trim((string)($card['number'] ?? $card['collector_number'] ?? ''));
            $language = strtoupper(trim((string)($card['lang'] ?? $card['language'] ?? 'EN')));
            if ($language === '') $language = 'EN';

            $rows[] = [
                'card_id' => $cardId,
                'card_name' => $name,
                'set_id' => (string)($setInfo['id'] ?? ''),
                'set_name' => (string)($setInfo['name'] ?? ''),
                'collector_number' => $number,
                'language' => $language,
                'rarity' => trim((string)($card['rarity'] ?? '')),
                'image_url' => self::firstImage($card),
                'image_back_url' => self::backImage($card),
                'finishes' => is_array($card['finishes'] ?? null) ? array_values($card['finishes']) : [],
                'release_date' => (string)($setInfo['releaseDate'] ?? ''),
                'search_text' => implode(' ', array_filter([
                    $name,
                    $version,
                    $oracleName,
                    (string)($setInfo['name'] ?? ''),
                    (string)($setInfo['id'] ?? ''),
                    (string)($setInfo['ptcgoCode'] ?? ''),
                    $number,
                    $artist,
                    $illustrator,
                ])),
                'payload' => [
                    'version' => $version,
                    'oracle_name' => $oracleName,
                    'artist' => $artist,
                    'illustrator' => $illustrator,
                    'orientation' => (string)($card['orientation'] ?? ''),
                    'supertype' => (string)($card['supertype'] ?? ''),
                    'subtypes' => is_array($card['subtypes'] ?? null) ? array_values($card['subtypes']) : [],
                    'set_code' => (string)($setInfo['ptcgoCode'] ?? ''),
                ],
            ];
        }
        return $rows;
    }

    private static function firstImage(array $card): string
    {
        foreach ([
            $card['image'] ?? '',
            $card['thumbnail'] ?? '',
            $card['images']['full'] ?? '',
            $card['images']['large'] ?? '',
            $card['images']['normal'] ?? '',
            $card['images']['small'] ?? '',
        ] as $value) {
            $value = trim((string)$value);
            if ($value !== '') return $value;
        }
        return '';
    }

    private static function backImage(array $card): string
    {
        return trim((string)($card['images']['back'] ?? $card['image_back'] ?? ''));
    }

    private static function createRuntime($provider)
    {
        $config = (array)$provider->config();
        $namespace = trim((string)($config['namespace'] ?? ''), '\\');
        if ($namespace === '') {
            return new \WP_Error('nps_card_index_namespace', 'Spil-pluginet oplyser ikke sit namespace.');
        }

        $apiClass = (string)($config['api_client_class'] ?? ($namespace . '\\ApiClient'));
        $cacheClass = (string)($config['cache_class'] ?? ($namespace . '\\Cache'));
        $setCacheClass = (string)($config['set_cards_cache_class'] ?? ($namespace . '\\SetCardsCache'));

        if (!class_exists($apiClass) || !class_exists($cacheClass) || !class_exists($setCacheClass)) {
            return new \WP_Error('nps_card_index_runtime', 'Spil-pluginet mangler API/cache-klasser til at bygge søgeindekset.');
        }

        try {
            $api = new $apiClass();
            $cache = new $cacheClass();
            $setCache = new $setCacheClass($cache, $api);
        } catch (\Throwable $e) {
            return new \WP_Error('nps_card_index_runtime', 'Kunne ikke initialisere søgeindekset: ' . $e->getMessage());
        }

        return ['api' => $api, 'cache' => $cache, 'set_cache' => $setCache];
    }

    private static function buildState(): array
    {
        $state = get_option(self::BUILD_OPTION, []);
        return is_array($state) ? $state : [];
    }

    private static function assertAjaxAccess(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Ingen adgang.'], 403);
        }
    }

    private static function postedGameId(): string
    {
        $gameId = isset($_POST['gameId']) ? sanitize_key(wp_unslash((string)$_POST['gameId'])) : '';
        if ($gameId === '') {
            wp_send_json_error(['message' => 'Spil-id mangler.'], 400);
        }
        return $gameId;
    }

    private static function providerOrFail(string $gameId)
    {
        $provider = Registry::instance()->get($gameId);
        if ($provider === null) {
            wp_send_json_error(['message' => 'Singles-integration blev ikke fundet.'], 404);
        }
        return $provider;
    }
}
