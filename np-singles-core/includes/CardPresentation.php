<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class CardPresentation
{
    public const PORTRAIT = 'portrait';
    public const LANDSCAPE = 'landscape';

    public static function rotation($value): int
    {
        $rotation = (int)$value;
        $rotation %= 360;
        if ($rotation < 0) $rotation += 360;
        return in_array($rotation, [0, 90, 180, 270], true) ? $rotation : 0;
    }

    public static function orientation($value): string
    {
        if (is_bool($value)) return $value ? self::LANDSCAPE : self::PORTRAIT;
        $value = strtolower(trim((string)$value));
        return in_array($value, ['landscape', 'horizontal', 'art_series'], true)
            ? self::LANDSCAPE
            : self::PORTRAIT;
    }
}
