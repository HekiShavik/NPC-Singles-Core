<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

/**
 * Shared bulk product import orchestration.
 *
 * Game plugins keep ownership of ProductBuilder, finish rules and SKU conventions.
 * Core only coordinates the common workflow and receives those differences as config.
 */
class ProductImportService
{
    protected object $products;
    protected object $productIndex;
    protected object $history;
    protected array $config;

    public function __construct(object $products, object $productIndex, object $history, array $config = [])
    {
        $this->products = $products;
        $this->productIndex = $productIndex;
        $this->history = $history;
        $this->config = array_merge([
            'canonical_finish' => static fn(string $finish): string => $finish,
            'sku_finish_code' => static fn(string $finish): string => strtoupper($finish),
            'sku_format' => '%s-%s-%s-%s',
            'format_card_number' => static function (string $number): string {
                return ctype_digit($number)
                    ? str_pad($number, 3, '0', STR_PAD_LEFT)
                    : strtoupper(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $number), '-'));
            },
            'display_card_name' => static fn(array $snap): string => (string)($snap['name'] ?? ''),
            'repair_missing_images' => false,
            'track_image_results' => false,
        ], $config);
    }

    /**
     * @param array<string,array> $byId Card id => card snapshot.
     * @param array<string,array> $norm Compound key => normalized create job.
     */
    public function bulkCreate(string $set_id, array $setInfo, array $byId, array $norm): array
    {
        if (!$norm) {
            $counts = ['created' => 0, 'skipped' => 0, 'failed' => 0];
            if ($this->trackImageResults()) {
                $counts['images_repaired'] = 0;
                $counts['image_failures'] = 0;
            }

            return [
                'created' => [],
                'skipped' => [],
                'failed' => [],
                'counts' => $counts,
            ];
        }

        $cardIds = array_values(array_unique(array_map(
            static fn($x) => (string)($x['cardId'] ?? ''),
            array_values($norm)
        )));
        $cardIds = array_values(array_filter($cardIds));

        // Empty language deliberately means: return all compound card|finish|language keys.
        $existingResult = $this->productIndex->existingByCardIds($cardIds, '');
        $existingByKey = (array)($existingResult['existing'] ?? []);

        $created = [];
        $skipped = [];
        $failed = [];
        $imagesRepaired = 0;
        $imageFailures = 0;

        foreach ($norm as $key => $j) {
            if (isset($existingByKey[$key])) {
                $existing = $existingByKey[$key];

                if ($this->repairMissingImages()) {
                    $post_id = (int)($existing['post_id'] ?? 0);
                    $card_id = (string)($j['cardId'] ?? '');
                    $snap = $byId[$card_id] ?? null;

                    if (
                        $post_id > 0
                        && is_array($snap)
                        && !has_post_thumbnail($post_id)
                        && method_exists($this->products, 'ensure_featured_image_from_card_snapshot')
                    ) {
                        $existing['image_repaired'] = (bool)$this->products->ensure_featured_image_from_card_snapshot(
                            $post_id,
                            $snap,
                            $setInfo,
                            (string)($j['finish'] ?? 'normal'),
                            (string)($j['lang'] ?? 'EN')
                        );

                        if ($existing['image_repaired']) {
                            $imagesRepaired++;
                        } else {
                            $imageFailures++;
                        }
                        $existingByKey[$key] = $existing;
                    }
                }

                $skipped[] = array_merge(['key' => $key], $existing, $j);
                continue;
            }

            $one = $this->createOne($set_id, $setInfo, $byId, $key, $j);

            if (($one['type'] ?? '') === 'created') {
                $created[] = $one['data'];
                if ($this->trackImageResults() && empty($one['data']['image_attached'])) {
                    $imageFailures++;
                }

                $existingByKey[$key] = [
                    'post_id' => (int)($one['data']['post_id'] ?? 0),
                    'status' => (string)($one['data']['status'] ?? 'draft'),
                    'edit_url' => (string)($one['data']['edit_url'] ?? ''),
                    'stock' => (int)($one['data']['qty'] ?? 0),
                ];
            } else {
                $failed[] = $one['data'];
            }
        }

        $counts = [
            'created' => count($created),
            'skipped' => count($skipped),
            'failed' => count($failed),
        ];
        if ($this->trackImageResults()) {
            $counts['images_repaired'] = $imagesRepaired;
            $counts['image_failures'] = $imageFailures;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'counts' => $counts,
        ];
    }

    protected function createOne(string $set_id, array $setInfo, array $byId, string $key, array $j): array
    {
        $canonical = $this->config['canonical_finish'];
        $j['finish'] = $canonical((string)($j['finish'] ?? 'normal'));

        $cardId = (string)($j['cardId'] ?? '');
        $snap = $byId[$cardId] ?? null;
        if (!$snap) {
            return [
                'type' => 'failed',
                'data' => array_merge(['key' => $key], $j, ['error' => 'Kortet findes ikke i set-cache.']),
            ];
        }

        if (!$setInfo) {
            $setInfo = ['id' => $set_id, 'name' => '', 'ptcgoCode' => ''];
        }

        $res = $this->products->create_draft_product_from_card_snapshot(
            $snap,
            (string)$j['finish'],
            (string)$j['lang'],
            $setInfo
        );

        if (!($res['ok'] ?? false)) {
            return [
                'type' => 'failed',
                'data' => array_merge(['key' => $key], $j, ['error' => $res['message'] ?? 'Fejl']),
            ];
        }

        $post_id = (int)($res['post_id'] ?? 0);
        $qty = isset($j['qty']) ? (int)$j['qty'] : 0;
        if ($qty < 0) $qty = 0;

        if ($post_id > 0) {
            update_post_meta($post_id, '_manage_stock', 'yes');
            update_post_meta($post_id, '_stock', $qty);
            update_post_meta($post_id, '_stock_status', $qty > 0 ? 'instock' : 'outofstock');

            $set_code = trim((string)($setInfo['ptcgoCode'] ?? ''));
            if ($set_code === '') {
                $set_code = trim((string)($setInfo['id'] ?? $set_id));
            }

            $card_number = trim((string)($snap['number'] ?? $cardId));
            $formatNumber = $this->config['format_card_number'];
            $card_number = $formatNumber($card_number);

            $finishCode = $this->config['sku_finish_code'];
            $finish_code = $finishCode((string)$j['finish']);
            $card_lang = trim((string)($snap['lang'] ?? $j['lang']));

            $sku = sprintf(
                (string)$this->config['sku_format'],
                $card_lang,
                strtoupper($set_code),
                $card_number,
                $finish_code
            );

            $product = wc_get_product($post_id);
            if ($product) {
                try {
                    $product->set_sku($sku);
                    $product->save();
                    wc_delete_product_transients($post_id);
                } catch (\Throwable $e) {
                    // Product creation succeeded; SKU conflicts/errors stay non-fatal as before.
                }
            }

            $displayName = $this->config['display_card_name'];
            $this->history->recordCreatedProduct(
                $set_id,
                $setInfo,
                $post_id,
                $cardId,
                (string)($snap['number'] ?? ''),
                $displayName($snap),
                (string)$j['finish'],
                (string)$j['lang'],
                0,
                $qty,
                'bulk_create'
            );
        }

        $data = [
            'key' => $key,
            'post_id' => $post_id,
            'status' => 'draft',
            'edit_url' => (string)($res['edit_url'] ?? ''),
            'cardId' => (string)$j['cardId'],
            'finish' => (string)$j['finish'],
            'lang' => (string)$j['lang'],
            'qty' => $qty,
        ];
        if ($this->trackImageResults()) {
            $data['image_attached'] = (bool)($res['image_attached'] ?? false);
        }

        return ['type' => 'created', 'data' => $data];
    }

    protected function repairMissingImages(): bool
    {
        return !empty($this->config['repair_missing_images']);
    }

    protected function trackImageResults(): bool
    {
        return !empty($this->config['track_image_results']);
    }
}
