<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class WeightDisplay
{
    private function __construct() {}

    public static function boot(): void
    {
        add_filter('woocommerce_display_product_attributes', [self::class, 'formatProductAttributes'], 20, 2);
    }

    public static function formatProductAttributes(array $attributes, \WC_Product $product): array
    {
        if (!isset($attributes['weight']) || !self::isSinglesProduct($product->get_id())) {
            return $attributes;
        }

        $storedWeight = (float)$product->get_weight();
        if ($storedWeight <= 0) return $attributes;

        $kg = function_exists('wc_get_weight')
            ? (float)wc_get_weight($storedWeight, 'kg')
            : $storedWeight;

        if ($kg < 1) {
            $grams = (int)round($kg * 1000);
            $attributes['weight']['value'] = number_format_i18n($grams, 0) . ' g';
            return $attributes;
        }

        $formattedKg = function_exists('wc_format_decimal')
            ? wc_format_decimal($kg, 3, true)
            : rtrim(rtrim(number_format($kg, 3, '.', ''), '0'), '.');

        if (function_exists('wc_format_localized_decimal')) {
            $formattedKg = wc_format_localized_decimal($formattedKg);
        }

        $attributes['weight']['value'] = $formattedKg . ' kg';
        return $attributes;
    }

    private static function isSinglesProduct(int $productId): bool
    {
        if ($productId <= 0) return false;

        $game = trim((string)get_post_meta($productId, Meta::GAME, true));
        if ($game !== '') return true;

        foreach (Registry::instance()->all() as $provider) {
            $meta = $provider->metaKeys();
            $cardMeta = trim((string)($meta['card_id'] ?? ''));
            if ($cardMeta !== '' && get_post_meta($productId, $cardMeta, true) !== '') {
                return true;
            }
        }

        return false;
    }
}
