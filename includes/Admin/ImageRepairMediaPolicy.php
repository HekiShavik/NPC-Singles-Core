<?php

namespace NPS\Core\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Keeps missing-image repair requests lightweight on constrained hosting.
 *
 * Repair only needs the original attachment to restore the product's featured
 * image. Storefront can lazily recreate the concrete display renditions later,
 * so generating every WordPress/WooCommerce intermediate size during the repair
 * request wastes both CPU time and disk space.
 */
final class ImageRepairMediaPolicy
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;

        add_filter('intermediate_image_sizes_advanced', [self::class, 'filterIntermediateSizes'], 9999, 3);
        add_filter('big_image_size_threshold', [self::class, 'filterBigImageThreshold'], 9999, 4);
    }

    public static function filterIntermediateSizes(array $sizes, array $imageMeta = [], int $attachmentId = 0): array
    {
        return self::isRepairRequest() ? [] : $sizes;
    }

    public static function filterBigImageThreshold($threshold, array $imagesize = [], string $file = '', int $attachmentId = 0)
    {
        return self::isRepairRequest() ? false : $threshold;
    }

    private static function isRepairRequest(): bool
    {
        if (!wp_doing_ajax()) return false;

        $action = isset($_REQUEST['action'])
            ? sanitize_key(wp_unslash((string)$_REQUEST['action']))
            : '';

        return $action === 'nps_image_repair_batch';
    }
}
