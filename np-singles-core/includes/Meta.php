<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class Meta
{
    public const GAME = '_nps_game';
    public const CARD_ID = '_nps_card_id';
    public const SET_ID = '_nps_set_id';
    public const SET_CODE = '_nps_set_code';
    public const CARD_NUMBER = '_nps_card_number';
    public const CARD_NAME = '_nps_card_name';
    public const FINISH = '_nps_finish';
    public const LANGUAGE = '_nps_language';
    public const RARITY = '_nps_rarity';

    private function __construct() {}

    public static function read(int $productId, GameProvider $provider, string $logicalField, $default = '')
    {
        $generic = self::genericKey($logicalField);
        if ($generic !== '') {
            $value = get_post_meta($productId, $generic, true);
            if ($value !== '') return $value;
        }

        $keys = $provider->metaKeys();
        $legacy = $keys[$logicalField] ?? '';
        if ($legacy !== '') {
            $value = get_post_meta($productId, $legacy, true);
            if ($value !== '') return $value;
        }

        return $default;
    }

    public static function genericKey(string $logicalField): string
    {
        return match ($logicalField) {
            'game' => self::GAME,
            'card_id' => self::CARD_ID,
            'set_id' => self::SET_ID,
            'set_code' => self::SET_CODE,
            'card_number' => self::CARD_NUMBER,
            'card_name' => self::CARD_NAME,
            'finish' => self::FINISH,
            'language', 'lang' => self::LANGUAGE,
            'rarity' => self::RARITY,
            default => '',
        };
    }
}
