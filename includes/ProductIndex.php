<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

class ProductIndex
{
    private string $cardIdMeta;
    private string $finishMeta;
    private string $languageMeta;

    public function __construct(string $cardIdMeta, string $finishMeta, string $languageMeta)
    {
        $this->cardIdMeta = $cardIdMeta;
        $this->finishMeta = $finishMeta;
        $this->languageMeta = $languageMeta;
    }

    public function existingByCardIds(array $card_ids, string $lang = ''): array
    {
        $card_ids = array_values(array_filter(array_map('strval', $card_ids)));
        if (!$card_ids) return ['existing' => [], 'counts' => ['draft' => 0, 'publish' => 0, 'other' => 0]];

        $existing = [];
        $counts = ['draft' => 0, 'publish' => 0, 'other' => 0];
        $rank = static fn(string $st): int => match ($st) {
            'publish' => 3,
            'draft' => 2,
            'pending' => 1,
            default => 0,
        };

        foreach (array_chunk($card_ids, 200) as $chunk) {
            $q = new \WP_Query([
                'post_type' => 'product',
                'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
                'posts_per_page' => 2000,
                'fields' => 'ids',
                'meta_query' => [[
                    'key' => $this->cardIdMeta,
                    'value' => $chunk,
                    'compare' => 'IN',
                ]],
            ]);

            foreach (($q->posts ?? []) as $pid) {
                $pid = (int)$pid;
                $cid = (string)get_post_meta($pid, $this->cardIdMeta, true);
                $fin = (string)get_post_meta($pid, $this->finishMeta, true);
                $plg = strtoupper((string)get_post_meta($pid, $this->languageMeta, true));
                if ($cid === '' || $fin === '' || $plg === '') continue;
                if ($lang !== '' && strtoupper($lang) !== $plg) continue;

                $key = $cid . '|' . $fin . '|' . $plg;
                $status = (string)get_post_status($pid);
                $stock = get_post_meta($pid, '_stock', true);
                $price = null;
                if (function_exists('wc_get_product')) {
                    $p = wc_get_product($pid);
                    if ($p) {
                        $rp = $p->get_regular_price();
                        if ($rp !== '') $price = (float)$rp;
                    }
                }
                if ($price === null) {
                    $rp = get_post_meta($pid, '_regular_price', true);
                    if (is_numeric($rp)) $price = (float)$rp;
                }
                $entry = [
                    'post_id' => $pid,
                    'status' => $status,
                    'edit_url' => get_edit_post_link($pid, 'raw'),
                    'stock' => is_numeric($stock) ? (int)$stock : null,
                    'price' => $price,
                ];
                if (!isset($existing[$key]) || $rank($status) > $rank((string)$existing[$key]['status'])) $existing[$key] = $entry;
            }
            wp_reset_postdata();
        }

        foreach ($existing as $e) {
            if (($e['status'] ?? '') === 'publish') $counts['publish']++;
            elseif (($e['status'] ?? '') === 'draft') $counts['draft']++;
            else $counts['other']++;
        }
        return ['existing' => $existing, 'counts' => $counts];
    }
}
