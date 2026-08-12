<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

class Cache
{
    public const TTL_SETS = 30 * DAY_IN_SECONDS;
    public const TTL_SET_CARDS = 30 * DAY_IN_SECONDS;
    public const TTL_CARD = 30 * DAY_IN_SECONDS;

    private string $prefix;
    private string $lockPrefix;

    public function __construct(string $prefix = 'nps')
    {
        $prefix = trim($prefix, '_');
        $this->prefix = $prefix . '_';
        $this->lockPrefix = $prefix . '_lock_';
    }

    public function key(string $name, array $parts = []): string
    {
        return $this->prefix . $name . '_' . md5(wp_json_encode($parts));
    }

    public function get(string $name, array $parts = []): ?array
    {
        $v = get_transient($this->key($name, $parts));
        return is_array($v) ? $v : null;
    }

    public function set(string $name, array $parts, array $payload, int $ttl): void
    {
        set_transient($this->key($name, $parts), $payload, $ttl);
    }

    public function delete(string $name, array $parts = []): void
    {
        delete_transient($this->key($name, $parts));
    }

    public function wrap_payload(array $data, bool $from_cache, ?int $ts = null): array
    {
        return [
            'ts' => $ts ?? time(),
            'from_cache' => $from_cache,
            'data' => $data,
        ];
    }

    private function lock_key(string $mainKey): string
    {
        return $this->lockPrefix . md5($mainKey);
    }

    public function try_lock(string $mainKey, int $ttlSeconds = 120): bool
    {
        $lk = $this->lock_key($mainKey);
        if (get_transient($lk)) return false;
        set_transient($lk, 1, $ttlSeconds);
        return true;
    }

    public function unlock(string $mainKey): void
    {
        delete_transient($this->lock_key($mainKey));
    }

    public function set_atomic_transient(string $mainKey, array $payload, int $ttlSeconds): bool
    {
        if (empty($payload) || !is_array($payload)) return false;
        if (empty($payload['data']) || !is_array($payload['data'])) return false;

        $payload['_ttl'] = $ttlSeconds;
        $tmpKey = $mainKey . '__tmp__' . wp_generate_uuid4();
        set_transient($tmpKey, $payload, $ttlSeconds);
        set_transient($mainKey, $payload, $ttlSeconds);
        delete_transient($tmpKey);
        return true;
    }
}
