<?php
namespace NPS\Core;
if (!defined('ABSPATH')) exit;
final class ProductDefaults
{
    public const DEFAULT_SINGLE_WEIGHT_KG = 0.020;
    private function __construct() {}
    public static function weightKgForGame(string $gameId): float
    {
        $provider = Registry::instance()->get($gameId);
        if (!$provider) return self::DEFAULT_SINGLE_WEIGHT_KG;
        $optionKey = trim((string)($provider->config()['weight_option'] ?? ''));
        if ($optionKey === '') return self::DEFAULT_SINGLE_WEIGHT_KG;
        $raw = get_option($optionKey, '');
        $value = (float)str_replace(',', '.', trim((string)$raw));
        return $value > 0 ? $value : self::DEFAULT_SINGLE_WEIGHT_KG;
    }
    public static function applyWeight(int $productId, string $gameId): void
    {
        if ($productId <= 0 || $gameId === '') return;
        $product = wc_get_product($productId);
        if (!$product) return;
        $kg = self::weightKgForGame($gameId);
        $unit = (string)get_option('woocommerce_weight_unit', 'kg');
        $weight = function_exists('wc_get_weight') ? (float)wc_get_weight($kg, $unit, 'kg') : $kg;
        $product->set_weight(wc_format_decimal($weight, 6));
        $product->save();
    }
}
