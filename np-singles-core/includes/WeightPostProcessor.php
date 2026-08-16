<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class WeightPostProcessor
{
    private const AJAX_ACTION = 'nps_weight_postprocess';
    private const NONCE_ACTION = 'nps_weight_postprocess';
    private const BATCH_SIZE = 100;

    private function __construct() {}

    public static function boot(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'ajaxProcess']);
    }

    public static function render(string $gameId): void
    {
        $provider = Registry::instance()->get($gameId);
        if (!$provider) return;

        $kg = ProductDefaults::weightKgForGame($gameId);
        $displayKg = rtrim(rtrim(number_format($kg, 3, ',', ''), '0'), ',');
        if ($displayKg === '') $displayKg = '0';

        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $buttonLabel = sprintf('Anvend %s kg på eksisterende %s Singles', $displayKg, $provider->name());
        ?>
        <div class="card" style="max-width:920px;margin-top:20px;">
            <h2 style="margin-top:0;">Efterbehandling af vægt</h2>
            <p>
                Anvend den nuværende standardvægt for dette spil på alle eksisterende Singles-varer.
                Varer i papirkurven berøres ikke. Lager, pris, status og historik ændres ikke.
            </p>
            <p><strong>Nuværende standardvægt:</strong> <?php echo esc_html($displayKg); ?> kg</p>
            <p>
                <button type="button" class="button button-secondary" id="nps_weight_postprocess_button">
                    <?php echo esc_html($buttonLabel); ?>
                </button>
            </p>
            <div id="nps_weight_postprocess_progress" style="display:none;max-width:620px;">
                <div style="height:10px;background:#dcdcde;border-radius:5px;overflow:hidden;">
                    <div id="nps_weight_postprocess_bar" style="height:100%;width:0;background:#2271b1;transition:width .15s ease;"></div>
                </div>
                <p id="nps_weight_postprocess_message" style="margin:8px 0 0;"></p>
            </div>
        </div>
        <script>
        (() => {
            const button = document.getElementById('nps_weight_postprocess_button');
            const wrap = document.getElementById('nps_weight_postprocess_progress');
            const bar = document.getElementById('nps_weight_postprocess_bar');
            const message = document.getElementById('nps_weight_postprocess_message');
            if (!button || !wrap || !bar || !message) return;

            const gameId = <?php echo wp_json_encode($gameId); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;
            let running = false;

            const setProgress = (processed, total, text) => {
                const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 100;
                bar.style.width = pct + '%';
                message.textContent = text;
            };

            const runBatch = async (start) => {
                const body = new URLSearchParams();
                body.set('action', <?php echo wp_json_encode(self::AJAX_ACTION); ?>);
                body.set('_ajax_nonce', nonce);
                body.set('game_id', gameId);
                if (start) body.set('start', '1');

                let response;
                try {
                    response = await fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString(),
                    });
                } catch (error) {
                    throw new Error('Netværksfejl. Tryk på knappen igen for at fortsætte.');
                }

                const text = await response.text();
                let payload;
                try {
                    payload = JSON.parse(text);
                } catch (error) {
                    throw new Error('Serveren returnerede et ugyldigt svar (HTTP ' + response.status + ').');
                }

                if (!response.ok || !payload.success) {
                    const errorText = payload && payload.data && payload.data.message
                        ? payload.data.message
                        : 'Vægtopdateringen fejlede.';
                    throw new Error(errorText);
                }

                return payload.data;
            };

            const execute = async () => {
                if (running) return;
                running = true;
                button.disabled = true;
                wrap.style.display = 'block';

                try {
                    let data = await runBatch(true);
                    setProgress(data.processed, data.total, data.message);

                    while (!data.done) {
                        data = await runBatch(false);
                        setProgress(data.processed, data.total, data.message);
                    }

                    setProgress(data.total, data.total, data.message);
                } catch (error) {
                    message.textContent = error && error.message ? error.message : 'Vægtopdateringen fejlede.';
                } finally {
                    running = false;
                    button.disabled = false;
                }
            };

            button.addEventListener('click', execute);
        })();
        </script>
        <?php
    }

    public static function ajaxProcess(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Du har ikke adgang til denne handling.'], 403);
        }

        check_ajax_referer(self::NONCE_ACTION);

        $gameId = isset($_POST['game_id']) ? sanitize_key(wp_unslash((string)$_POST['game_id'])) : '';
        $provider = Registry::instance()->get($gameId);
        if (!$provider) {
            wp_send_json_error(['message' => 'Ukendt Singles-spil.'], 400);
        }

        $targetKg = ProductDefaults::weightKgForGame($gameId);
        $targetWeight = ProductDefaults::storeWeightForGame($gameId);
        $stateKey = self::stateKey(get_current_user_id(), $gameId);
        $start = !empty($_POST['start']);
        $state = get_transient($stateKey);

        if (!is_array($state) || abs((float)($state['target_kg'] ?? 0) - $targetKg) > 0.000001) {
            $state = [
                'target_kg' => $targetKg,
                'target_weight' => $targetWeight,
                'cursor' => 0,
                'processed' => 0,
                'changed' => 0,
                'total' => self::countProducts($provider),
            ];
            set_transient($stateKey, $state, DAY_IN_SECONDS);
        }

        $ids = self::nextProductIds($provider, (int)$state['cursor'], self::BATCH_SIZE);
        foreach ($ids as $productId) {
            $state['cursor'] = max((int)$state['cursor'], $productId);
            $state['processed']++;

            $current = (string)get_post_meta($productId, '_weight', true);
            if ((string)wc_format_decimal($current, 6) === (string)$targetWeight) {
                continue;
            }

            update_post_meta($productId, '_weight', $targetWeight);
            clean_post_cache($productId);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($productId);
            }
            $state['changed']++;
        }

        $done = count($ids) < self::BATCH_SIZE;
        if ($done) {
            delete_transient($stateKey);
        } else {
            set_transient($stateKey, $state, DAY_IN_SECONDS);
        }

        $message = $done
            ? sprintf('Færdig. %d varer gennemgået · %d vægte ændret.', (int)$state['processed'], (int)$state['changed'])
            : sprintf('%d / %d varer gennemgået · %d vægte ændret.', (int)$state['processed'], (int)$state['total'], (int)$state['changed']);

        wp_send_json_success([
            'done' => $done,
            'processed' => (int)$state['processed'],
            'changed' => (int)$state['changed'],
            'total' => (int)$state['total'],
            'message' => $message,
        ]);
    }

    private static function countProducts(GameProvider $provider): int
    {
        global $wpdb;
        $cardIdMeta = (string)($provider->metaKeys()['card_id'] ?? '');
        if ($cardIdMeta === '') return 0;

        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = 'product'
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND pm.meta_value <> ''",
            $cardIdMeta
        );
        return (int)$wpdb->get_var($sql);
    }

    /** @return int[] */
    private static function nextProductIds(GameProvider $provider, int $cursor, int $limit): array
    {
        global $wpdb;
        $cardIdMeta = (string)($provider->metaKeys()['card_id'] ?? '');
        if ($cardIdMeta === '') return [];

        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = 'product'
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND pm.meta_value <> ''
               AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT %d",
            $cardIdMeta,
            $cursor,
            $limit
        );

        return array_map('intval', (array)$wpdb->get_col($sql));
    }

    private static function stateKey(int $userId, string $gameId): string
    {
        return 'nps_weight_' . $userId . '_' . sanitize_key($gameId);
    }
}
