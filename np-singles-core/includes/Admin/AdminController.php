<?php

namespace NPS\Core\Admin;

if (!defined('ABSPATH')) exit;

class AdminController
{
    protected object $cache;
    protected object $api;
    protected object $setCardsCache;
    protected object $productIndex;
    protected object $importService;
    protected object $products;
    protected object $history;

    protected array $config;

    public function __construct(
        object $cache,
        object $api,
        object $setCardsCache,
        object $productIndex,
        object $products,
        object $importService,
        object $history,
        array $config = []
    ) {
        $this->cache = $cache;
        $this->api = $api;
        $this->setCardsCache = $setCardsCache;
        $this->productIndex = $productIndex;
        $this->products = $products;
        $this->importService = $importService;
        $this->history = $history;
        $this->config = array_merge([
            "nonce_action" => "nps_nonce",
            "game_id" => "",
            "settings_url" => admin_url("admin.php?page=nps-settings"),
            "canonical_finish" => static fn(string $finish): string => $finish,
            "display_card_name" => static fn(array $snap): string => (string)($snap["name"] ?? ""),
            "debug" => static function (string $message, array $context): void {},
        ], $config);
    }

    public function ajax_sets(): void
    {
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");
        if (!current_user_can('manage_woocommerce')) \NPS\Core\Http::fail('No permission', 403, 'no_access_sets');

        $term = strtolower(trim((string)($_POST['term'] ?? '')));
        $all  = !empty($_POST['all']);

        if (!$all && strlen($term) < 2) {
            \NPS\Core\Http::ok([]);
        }

        // Cache: sets list
        $sets_payload = $this->cache->get('sets');
        if (!$sets_payload) {
            $live = $this->api->get('/sets', [], 15);
            if (!$live['ok']) \NPS\Core\Http::fail('Kunne ikke hente sets fra API.', 502, 'api_sets_unavailable');

            $sets = (array)($live['data']['data'] ?? []);
            $slim = [];

            foreach ($sets as $s) {
                $slim[] = [
                    'id' => (string)($s['id'] ?? ''),
                    'name' => (string)($s['name'] ?? ''),
                    'ptcgoCode' => (string)($s['ptcgoCode'] ?? ''),
                    'series' => (string)($s['series'] ?? ''),
                    'releaseDate' => (string)($s['releaseDate'] ?? ''),
                    'cardCount' => isset($s['cardCount']) && is_numeric($s['cardCount']) ? (int)$s['cardCount'] : null,
                    'images' => [
                        'symbol' => (string)($s['images']['symbol'] ?? ''),
                        'logo'   => (string)($s['images']['logo'] ?? ''),
                    ],
                ];
            }

            $sets_payload = $this->cache->wrap_payload([
                'items' => $slim,
                'count' => count($slim),
            ], false);

            $this->cache->set('sets', [], $sets_payload, \NPS\Core\Cache::TTL_SETS);
        }

        $data = $sets_payload['data'] ?? [];
        $sets = (array)($data['items'] ?? $data);
        $gameId = sanitize_key((string)($this->config['game_id'] ?? ''));
        if ($gameId !== '') {
            $sets = \NPS\Core\SetStatus::instance()->decorateSets($gameId, $sets);
        }

        if ($all) {
            usort($sets, static function ($a, $b) {
                $sa = strtolower((string)($a['series'] ?? ''));
                $sb = strtolower((string)($b['series'] ?? ''));
                if ($sa !== $sb) return $sa <=> $sb;

                $na = strtolower((string)($a['name'] ?? ''));
                $nb = strtolower((string)($b['name'] ?? ''));
                return $na <=> $nb;
            });

            \NPS\Core\Http::ok($sets);
        }

        $items = [];

        foreach ($sets as $s) {
            $name   = strtolower((string)($s['name'] ?? ''));
            $id     = strtolower((string)($s['id'] ?? ''));
            $code   = strtolower((string)($s['ptcgoCode'] ?? ''));
            $series = strtolower((string)($s['series'] ?? ''));

            if ($code !== '' && str_starts_with($code, $term)) {
                $items[] = $s;
            } elseif ($id !== '' && str_starts_with($id, $term)) {
                $items[] = $s;
            } elseif ($name !== '' && strpos($name, $term) !== false) {
                $items[] = $s;
            } elseif ($series !== '' && strpos($series, $term) !== false) {
                $items[] = $s;
            }
        }

        usort($items, static function ($a, $b) {
            $sa = strtolower((string)($a['series'] ?? ''));
            $sb = strtolower((string)($b['series'] ?? ''));
            if ($sa !== $sb) return $sa <=> $sb;

            $na = strtolower((string)($a['name'] ?? ''));
            $nb = strtolower((string)($b['name'] ?? ''));
            return $na <=> $nb;
        });

        $items = array_slice($items, 0, 50);
        \NPS\Core\Http::ok($items);
    }

    public function ajax_find(): void
    {
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");
        if (!current_user_can('manage_woocommerce')) \NPS\Core\Http::fail('No permission', 403, 'no_access_find');

        $set_id = trim((string)($_POST['setId'] ?? ''));
        $number = trim((string)($_POST['number'] ?? ''));
        $lang   = strtoupper(trim((string)($_POST['lang'] ?? 'EN')));

        if ($set_id === '' || $number === '') {
            \NPS\Core\Http::fail('Mangler set eller nummer', 400, 'arguments_find');
        }

        // 100% cache-baseret: load_set_cards_cached må IKKE lave live API her
        $set_payload = $this->setCardsCache->get($set_id, false);
        if (!($set_payload['ok'] ?? false)) {
            \NPS\Core\Http::fail($set_payload['message'] ?? 'Kunne ikke hente set-kort endnu. Prøv igen.', 409, 'unavailable_set_cards');
        }

        // Hvis set ikke er cached endnu (eller cache er tom), så bed UI om preload
        $cards = (array)($set_payload['data']['cards'] ?? []);
        if (!$cards) {
            $settings_url = (string)$this->config["settings_url"];
            $message = 'Set data mangler i cache. <a href="' . esc_url($settings_url) . '">Opdater cache i Indstillinger</a>.';
            \NPS\Core\Http::fail($message, 409, 'cache_miss_set_cards');
        }

        $matches = [];
        foreach ($cards as $c) {
            if ((string)($c['number'] ?? '') === $number) {
                $matches[] = $c;
            }
        }

        if (!$matches) {
            \NPS\Core\Http::fail('Ingen kort fundet i set-cache for dette nummer.', 404, 'cache_miss_single');
        }

        \NPS\Core\Http::ok([
            'from_cache' => true,      // find er altid cache-baseret nu
            'cards'      => $matches,
            'lang'       => $lang,
        ]);
    }

    public function ajax_create(): void
    {
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");
        if (!current_user_can('manage_woocommerce')) \NPS\Core\Http::fail('No permission', 403, 'no_access_create');

        $set_id = trim((string)($_POST['setId'] ?? ''));
        $cardId = trim((string)($_POST['cardId'] ?? ''));
        $finish = $this->canonicalFinish(trim((string)($_POST['finish'] ?? 'normal')));
        $lang   = strtoupper(trim((string)($_POST['lang'] ?? 'EN')));

        if ($set_id === '' || $cardId === '') {
            \NPS\Core\Http::fail('Mangler setId eller cardId', 400, 'arguments_create');
        }

        $payload = $this->setCardsCache->get($set_id, false);
        $cards = (array)($payload['data']['cards'] ?? []);

        $snap = null;
        foreach ($cards as $c) {
            if ((string)($c['id'] ?? '') === $cardId) {
                $snap = $c;
                break;
            }
        }
        if (!$snap) {
            \NPS\Core\Http::fail('Kortet findes ikke i set-cache. Prøv at forudindlæse set igen.', 409, 'cache_miss_create');
        }

        $setInfo = $this->get_set_info_by_id($set_id) ?: ['id' => $set_id, 'name' => '', 'ptcgoCode' => '', 'series' => ''];

        $this->debug('bulk_create:first_snap', ['snap' => $snap]);
        $res = $this->products->create_draft_product_from_card_snapshot($snap, $finish, $lang, $setInfo);
        if (!($res['ok'] ?? false)) {
            \NPS\Core\Http::fail($res['message'] ?? 'Fejl', 500, 'draft_create');
        }

        $post_id = (int)($res['post_id'] ?? 0);
        if ($post_id > 0) {
            \NPS\Core\ProductDefaults::applyWeight($post_id, (string)($this->config['game_id'] ?? ''));

            $this->history->recordCreatedProduct(
                $set_id,
                $setInfo,
                $post_id,
                $cardId,
                (string)($snap['number'] ?? ''),
                ($this->displayCardName($snap)),
                $finish,
                $lang,
                0,
                0,
                'single_create'
            );
        }

        if ($post_id > 0) {
            \NPS\Core\SetStatus::instance()->syncProduct($post_id);
        }

        \NPS\Core\Http::ok([
            'message'  => $res['message'] ?? 'Oprettet!',
            'post_id'  => $post_id,
            'edit_url' => (string)$res['edit_url'],
        ]);
    }

    public function ajax_existing_for_set(): void
    {
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");
        if (!current_user_can('manage_woocommerce')) \NPS\Core\Http::fail('No permission', 403, 'no_access_exists');

        $set_id = trim((string)($_POST['setId'] ?? ''));
        $lang   = strtoupper(trim((string)($_POST['lang'] ?? 'EN')));
        if ($set_id === '') \NPS\Core\Http::fail('Mangler setId', 400, 'arguments_exists');

        $set_payload = $this->setCardsCache->get($set_id, false);
        if (!($set_payload['ok'] ?? false)) {
            \NPS\Core\Http::fail($set_payload['message'] ?? 'Kunne ikke hente set-kort endnu. Prøv igen.', 409, 'cache_miss_existing');
        }
        $cards = (array)($set_payload['data']['cards'] ?? []);
        $gameId = sanitize_key((string)($this->config['game_id'] ?? ''));
        if ($gameId !== '' && $cards) {
            \NPS\Core\SetStatus::instance()->observeSetCards($gameId, $set_id, $cards);
        }
        if (!$cards) \NPS\Core\Http::ok(['existing' => [], 'counts' => ['draft' => 0, 'publish' => 0, 'other' => 0]]);

        $card_ids = array_values(array_filter(array_map(fn($c) => (string)($c['id'] ?? ''), $cards)));
        if (!$card_ids) \NPS\Core\Http::ok(['existing' => [], 'counts' => ['draft' => 0, 'publish' => 0, 'other' => 0]]);

        $result = $this->productIndex->existingByCardIds($card_ids, $lang);
        \NPS\Core\Http::ok($result);
    }

    public function ajax_set_cards(): void
    {
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");
        if (!current_user_can('manage_woocommerce')) \NPS\Core\Http::fail('No permission', 403, 'no_access_cards');

        $set_id = trim((string)($_POST['setId'] ?? ''));
        if ($set_id === '') \NPS\Core\Http::fail('Mangler setId', 400, 'arguments_cards');

        $payload = $this->setCardsCache->get($set_id, false);
        if (!($payload['ok'] ?? false)) {
            \NPS\Core\Http::fail($payload['message'] ?? 'Kunne ikke hente set-kort.', 409, 'cache_miss_set_cards');
        }

        $cards = (array)($payload['data']['cards'] ?? []);
        $gameId = sanitize_key((string)($this->config['game_id'] ?? ''));
        if ($gameId !== '') {
            \NPS\Core\SetStatus::instance()->observeSetCards($gameId, $set_id, $cards);
        }

        \NPS\Core\Http::ok([
            'from_cache' => (bool)($payload['from_cache'] ?? false),
            'ts' => (int)($payload['ts'] ?? 0),
            'cards' => $cards,
        ]);
    }

    public function ajax_bulk_create(): void
    {
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");
        if (!current_user_can('manage_woocommerce')) \NPS\Core\Http::fail('No permission', 403, 'no_access_bulk_create');

        $this->debug('ajax_bulk_create:start', [
            'setId' => (string)($_POST['setId'] ?? ''),
            'lang'  => (string)($_POST['lang'] ?? ''),
        ]);

        $set_id = trim((string)($_POST['setId'] ?? ''));
        if ($set_id === '') \NPS\Core\Http::fail('Mangler setId', 400, 'arguments_bulk_create');

        $setInfo = $this->get_set_info_by_id($set_id);

        $set_payload = $this->setCardsCache->get($set_id, false);
        if (!($set_payload['ok'] ?? false)) {
            \NPS\Core\Http::fail($set_payload['message'] ?? 'Kunne ikke hente set-kort endnu.', 409, 'cache_miss_bulk_create');
        }

        $set_cards = (array)($set_payload['data']['cards'] ?? []);
        $byId = [];
        foreach ($set_cards as $c) {
            $id = (string)($c['id'] ?? '');
            if ($id !== '') $byId[$id] = $c;
        }

        $lang = strtoupper(trim((string)($_POST['lang'] ?? 'EN')));

        $jobs_raw = $_POST['jobs'] ?? '';
        if (!is_string($jobs_raw) || $jobs_raw === '') {
            \NPS\Core\Http::fail('Mangler jobs', 400, 'arguments_jobs');
        }

        $jobs = json_decode(wp_unslash($jobs_raw), true);
        if (!is_array($jobs) || count($jobs) === 0) {
            \NPS\Core\Http::fail('Ugyldig jobs payload', 400, 'arguments_invalid_jobs');
        }

        // Normaliser jobs + dedupe input
        $norm = [];
        foreach ($jobs as $j) {
            $cardId = isset($j['cardId']) ? trim((string)$j['cardId']) : '';
            $finish = isset($j['finish']) ? $this->canonicalFinish(trim((string)$j['finish'])) : 'normal';
            $plang  = strtoupper(trim((string)($j['lang'] ?? $lang)));

            if ($cardId === '') continue;
            $key = $cardId . '|' . $finish . '|' . $plang;

            $qty = isset($j['qty']) ? (int)$j['qty'] : 0;
            if ($qty < 0) $qty = 0;

            $norm[$key] = ['cardId' => $cardId, 'finish' => $finish, 'lang' => $plang, 'qty' => $qty];
        }

        if (!$norm) \NPS\Core\Http::fail('Ingen gyldige jobs', 400, 'jobs_not_available');

        // Delegér alt det tunge
        $result = $this->importService->bulkCreate($set_id, is_array($setInfo) ? $setInfo : [], $byId, $norm);
        foreach ((array)($result['created'] ?? []) as $created) {
            $pid = (int)($created['post_id'] ?? 0);
            if ($pid > 0) \NPS\Core\SetStatus::instance()->syncProduct($pid);
        }

        $this->debug('ajax_bulk_create:done', [
            'created' => count($result['created'] ?? []),
            'skipped' => count($result['skipped'] ?? []),
            'failed'  => count($result['failed'] ?? []),
        ]);

        \NPS\Core\Http::ok($result);
    }

    public function ajax_stock_delta(): void
    {
        // Match din anden AJAX: den bruger 'psw_nonce'
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");

        if (!current_user_can('manage_woocommerce')) {
            \NPS\Core\Http::fail('No permission', 403, 'no_access_stock');
        }

        $post_id = isset($_POST['postId']) ? (int) $_POST['postId'] : 0;
        $delta   = isset($_POST['delta']) ? (int) $_POST['delta'] : 0;

        if ($post_id <= 0) {
            \NPS\Core\Http::fail('Mangler/ugyldig postId', 400, 'arguments_stock_id');
        }
        if ($delta === 0) {
            \NPS\Core\Http::fail('Mangler/ugyldig delta', 400, 'arguments_stock_delta');
        }

        // Ensure it is a product
        if (get_post_type($post_id) !== 'product') {
            \NPS\Core\Http::fail('Not a product', 400, 'invalid_stock');
        }

        // Ensure manage_stock is enabled (otherwise Woo stock helpers can behave weird)
        update_post_meta($post_id, '_manage_stock', 'yes');

        $current = (int) get_post_meta($post_id, '_stock', true);
        $new = $current + $delta;
        if ($new < 0) $new = 0;

        update_post_meta($post_id, '_stock', $new);
        update_post_meta($post_id, '_stock_status', $new > 0 ? 'instock' : 'outofstock');

        $this->history->recordStockChangeForProduct($post_id, $current, $new, 'stock_delta');

        \NPS\Core\Http::ok(['stock' => $new]);
    }

    public function ajax_set_stock_absolute(): void
    {
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");
        if (!current_user_can('manage_woocommerce')) \NPS\Core\Http::fail('No permission', 403, 'no_access_stock_abs');

        $post_id = isset($_POST['postId']) ? (int)$_POST['postId'] : 0;
        if ($post_id <= 0) \NPS\Core\Http::fail('Mangler/ugyldig postId', 400, 'arguments_stock_abs_id');
        if (get_post_type($post_id) !== 'product') \NPS\Core\Http::fail('Not a product', 400, 'invalid_stock_abs');
        if (!current_user_can('edit_post', $post_id)) \NPS\Core\Http::fail('No permission', 403, 'no_access_stock_abs_edit');

        $raw = isset($_POST['stock']) ? (string)$_POST['stock'] : '';
        $raw = trim(wp_unslash($raw));

        // Tomt felt => 0 (som aftalt)
        if ($raw === '') {
            $new = 0;
        } else {
            if (!is_numeric($raw)) \NPS\Core\Http::fail('Ugyldigt lager-tal', 400, 'arguments_stock_abs_stock');
            $new = (int)$raw;
            if ($new < 0) $new = 0;
        }

        $current = (int)get_post_meta($post_id, '_stock', true);

        update_post_meta($post_id, '_manage_stock', 'yes');
        update_post_meta($post_id, '_stock', $new);
        update_post_meta($post_id, '_stock_status', $new > 0 ? 'instock' : 'outofstock');

        $this->history->recordStockChangeForProduct($post_id, $current, $new, 'stock_absolute');

        \NPS\Core\Http::ok(['stock' => $new]);
    }

    public function ajax_toggle_publish(): void
    {
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");
        if (!current_user_can('manage_woocommerce')) \NPS\Core\Http::fail('No permission', 403, 'no_access_publish_toggle');

        $post_id = isset($_POST['postId']) ? (int)$_POST['postId'] : 0;
        if ($post_id <= 0) \NPS\Core\Http::fail('Mangler/ugyldig postId', 400, 'arguments_publish_toggle_id');
        if (get_post_type($post_id) !== 'product') \NPS\Core\Http::fail('Not a product', 400, 'invalid_publish_toggle');
        if (!current_user_can('edit_post', $post_id)) \NPS\Core\Http::fail('No permission', 403, 'no_access_publish_toggle_edit');

        $current = (string)get_post_status($post_id);
        $next = ($current === 'publish') ? 'draft' : 'publish';

        $r = wp_update_post([
            'ID' => $post_id,
            'post_status' => $next,
        ], true);

        if (is_wp_error($r)) {
            \NPS\Core\Http::fail($r->get_error_message(), 500, 'arguments_publish_toggle_stock');
        }

        \NPS\Core\Http::ok(['status' => $next]);
    }

    public function ajax_set_status(): void
    {
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");
        if (!current_user_can('manage_woocommerce')) \NPS\Core\Http::fail('No permission', 403, 'no_access_set_status');

        $post_id = isset($_POST['postId']) ? (int)$_POST['postId'] : 0;
        if ($post_id <= 0) \NPS\Core\Http::fail('Mangler/ugyldig postId', 400, 'arguments_set_status_id');
        if (get_post_type($post_id) !== 'product') \NPS\Core\Http::fail('Not a product', 400, 'invalid_set_status');
        if (!current_user_can('edit_post', $post_id)) \NPS\Core\Http::fail('No permission', 403, 'no_access_set_status_edit');

        $status = isset($_POST['status']) ? (string)$_POST['status'] : '';
        $status = trim(wp_unslash($status));
        if ($status !== 'publish' && $status !== 'draft') \NPS\Core\Http::fail('Ugyldig status', 400, 'arguments_set_status_invalid');

        $r = wp_update_post([
            'ID' => $post_id,
            'post_status' => $status,
        ], true);

        if (is_wp_error($r)) {
            \NPS\Core\Http::fail($r->get_error_message(), 500, 'error_set_status');
        }

        \NPS\Core\Http::ok(['status' => $status]);
    }

    public function ajax_set_price(): void
    {
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");
        if (!current_user_can('manage_woocommerce')) \NPS\Core\Http::fail('No permission', 403, 'no_access_set_price');

        $post_id = isset($_POST['postId']) ? (int)$_POST['postId'] : 0;
        if ($post_id <= 0) \NPS\Core\Http::fail('Mangler/ugyldig postId', 400, 'arguments_set_price_id');
        if (get_post_type($post_id) !== 'product') \NPS\Core\Http::fail('Not a product', 400, 'invalid_set_price');
        if (!current_user_can('edit_post', $post_id)) \NPS\Core\Http::fail('No permission', 403, 'no_access_set_price_edit');

        $raw = isset($_POST['price']) ? (string)$_POST['price'] : '';
        $raw = trim(wp_unslash($raw));
        $raw = str_replace(',', '.', $raw);

        // Tom => ryd pris
        if ($raw === '') {
            delete_post_meta($post_id, '_regular_price');
            delete_post_meta($post_id, '_price');
            \NPS\Core\Http::ok(['price' => null]);
        }

        if (!is_numeric($raw)) \NPS\Core\Http::fail('Ugyldig pris', 400, 'arguments_set_price_value');
        $price = (float)$raw;
        if ($price < 0) $price = 0.0;

        if (function_exists('wc_get_product')) {
            $p = wc_get_product($post_id);
            if ($p) {
                $p->set_regular_price((string)$price);
                $p->set_price((string)$price);
                $p->save();
                \NPS\Core\Http::ok(['price' => $price]);
            }
        }

        update_post_meta($post_id, '_regular_price', (string)$price);
        update_post_meta($post_id, '_price', (string)$price);
        \NPS\Core\Http::ok(['price' => $price]);
    }

    public function ajax_delete_product(): void
    {
        check_ajax_referer((string)$this->config["nonce_action"], "nonce");
        if (!current_user_can('manage_woocommerce')) \NPS\Core\Http::fail('No permission', 403, 'no_access_delete');

        $post_id = isset($_POST['postId']) ? (int)$_POST['postId'] : 0;
        if ($post_id <= 0) \NPS\Core\Http::fail('Mangler/ugyldig postId', 400, 'arguments_delete_id');
        if (get_post_type($post_id) !== 'product') \NPS\Core\Http::fail('Not a product', 400, 'invalid_delete');
        if (!current_user_can('delete_post', $post_id)) \NPS\Core\Http::fail('No permission', 403, 'no_access_delete_edit');

        $st = (string)get_post_status($post_id);
        if ($st === 'publish') {
            \NPS\Core\Http::fail('Kan ikke slette en publiceret vare. Skjul den først.', 409, 'invalid_delete_state');
        }

        $this->history->recordDeletedProduct($post_id, 'delete_product');

        $r = wp_delete_post($post_id, true); // true = force delete (ingen papirkurv)
        if (!$r) {
            \NPS\Core\Http::fail('Kunne ikke slette varen.', 500, 'error_delete');
        }

        \NPS\Core\Http::ok(['deleted' => true]);
    }

    private function get_set_info_by_id(string $set_id): array
    {
        $set_id = trim($set_id);
        if ($set_id === '') return [];

        $sets_payload = $this->cache->get('sets', []);
        $data = $sets_payload['data'] ?? [];
        $sets = is_array($data) ? (array)($data['items'] ?? $data) : [];

        foreach ($sets as $s) {
            if (!is_array($s)) continue;
            if ((string)($s['id'] ?? '') === $set_id) {
                return [
                    'id' => (string)($s['id'] ?? ''),
                    'name' => (string)($s['name'] ?? ''),
                    'ptcgoCode' => (string)($s['ptcgoCode'] ?? ''),
                    'series' => (string)($s['series'] ?? ''),
                    'releaseDate' => (string)($s['releaseDate'] ?? ''),
                    'cardCount' => isset($s['cardCount']) && is_numeric($s['cardCount']) ? (int)$s['cardCount'] : null,
                    'images' => [
                        'symbol' => (string)($s['images']['symbol'] ?? ''),
                        'logo'   => (string)($s['images']['logo'] ?? ''),
                    ],
                ];
            }
        }

        return [];
    }

    protected function canonicalFinish(string $finish): string
    {
        $callback = $this->config["canonical_finish"];
        return (string)$callback($finish);
    }

    protected function displayCardName(array $snap): string
    {
        $callback = $this->config["display_card_name"];
        return (string)$callback($snap);
    }

    protected function debug(string $message, array $context = []): void
    {
        $callback = $this->config["debug"];
        $callback($message, $context);
    }
}
