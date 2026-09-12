<?php

namespace NPS\Core\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Small shared UX enhancements for the game-specific set pickers.
 *
 * The game plugins still own their picker markup, but they share the same
 * structural class suffixes. Core can therefore provide safe fallback behavior
 * without teaching each game about every other game's CSS prefix.
 */
final class SetPickerEnhancements
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;
        add_action('admin_enqueue_scripts', [self::class, 'enqueue'], 40);
    }

    public static function enqueue(): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string)$_GET['page'])) : '';
        if ($page !== AdminHub::PAGE_SLUG) return;

        $path = NPS_CORE_DIR . 'assets/set-picker-enhancements.js';
        if (!is_file($path)) return;

        wp_enqueue_script(
            'nps-set-picker-enhancements',
            NPS_CORE_URL . 'assets/set-picker-enhancements.js',
            [],
            filemtime($path),
            true
        );
    }
}
