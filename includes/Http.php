<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

class Http
{
    protected function __construct() {}

    public static function ok(array $data = [], string $message = ''): void
    {
        wp_send_json_success([
            'ok' => true,
            'data' => $data,
            'message' => $message,
        ]);
    }

    public static function fail(string $message, int $status = 400, string $code = ''): void
    {
        wp_send_json_error([
            'ok' => false,
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
