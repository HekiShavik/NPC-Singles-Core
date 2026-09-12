<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

class ProductIndex
{
    private string $cardIdMeta;
    private string $finishMeta;
    private string $languageMeta;
    /** @var array<string,string> */
    private array $languageAliases;

    /**
     * @param array<string,string> $languageAliases alias => canonical, e.g. ['JP' => 'JA']
     */
    public function __construct(string $cardIdMeta, string $finishMeta, string $languageMeta, array $languageAliases = [])
    {
        $this->cardIdMeta = $cardIdMeta;
        $this->finishMeta = $finishMeta;
        $this->languageMeta = $languageMeta;
        $this->languageAliases = [];
        foreach ($languageAliases as $alias => $canonical) {
            $alias = strtoupper(trim((string)$alias));
            $canonical = strtoupper(trim((string)$canonical));
            if ($alias !== '' && $canonical !== '') $this->languageAliases[$alias] = $canonical;
        }
    }

    public function existingByCardIds(array $card_ids, string $lang = ''): array
    {
        $card_ids = array_values(array_filter(array_map('strval', $card_ids)));
        if (!$card_ids) return ['existing' => [], 'counts' => ['draft' => 0, 'publish' => 0, 'other' => 0]];

        $requestedLanguage = $this->canonicalLanguage($lang);
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
                $plg = $this->canonicalLanguage((string)get_post_meta($pid, $this->languageMeta, true));
                if ($cid === '' || $fin === '' || $plg === '') continue;
                if ($requestedLanguage !== '' && $requestedLanguage !== $plg) continue;

                // Use the canonical language in the UI key. This lets a legacy
                // JP product satisfy a new JA row without rewriting product meta.
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

    private function canonicalLanguage(string $language): string
    {
        $language = strtoupper(trim($language));
        if ($language === '') return '';
        return $this->languageAliases[$language] ?? $language;
    }
}
