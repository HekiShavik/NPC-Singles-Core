<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class Registry
{
    private static ?self $instance = null;

    /** @var array<string,GameProvider> */
    private array $games = [];

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function register(GameProvider $provider): void
    {
        $id = sanitize_key($provider->id());
        if ($id === '') {
            throw new \InvalidArgumentException('A Singles game provider must have a non-empty id.');
        }

        $this->games[$id] = $provider;
        do_action('nps_game_registered', $provider, $this);
    }

    public function has(string $id): bool
    {
        return isset($this->games[sanitize_key($id)]);
    }

    public function get(string $id): ?GameProvider
    {
        return $this->games[sanitize_key($id)] ?? null;
    }

    /** @return array<string,GameProvider> */
    public function all(): array
    {
        return $this->games;
    }
}
