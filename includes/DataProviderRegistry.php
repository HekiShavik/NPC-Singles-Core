<?php
namespace NPS\Core;

if (!defined('ABSPATH')) exit;

final class DataProviderRegistry
{
    private static ?self $instance = null;

    /** @var array<string,array<string,mixed>> */
    private array $providers = [];

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    /**
     * @param array<string,mixed> $definition
     */
    public function register(array $definition): void
    {
        $id = sanitize_key((string)($definition['id'] ?? ''));
        $label = sanitize_text_field((string)($definition['label'] ?? ''));
        if ($id === '' || $label === '') {
            throw new \InvalidArgumentException('A Singles data provider must have a non-empty id and label.');
        }

        $uses = [];
        foreach ((array)($definition['uses'] ?? []) as $use) {
            $use = sanitize_text_field((string)$use);
            if ($use !== '') $uses[] = $use;
        }

        $credentialFields = [];
        foreach ((array)($definition['credential_fields'] ?? []) as $field) {
            if (!is_array($field)) continue;
            $key = sanitize_key((string)($field['key'] ?? ''));
            if ($key === '') continue;
            $type = sanitize_key((string)($field['type'] ?? 'password'));
            if (!in_array($type, ['password', 'text'], true)) $type = 'password';
            $credentialFields[] = [
                'key' => $key,
                'label' => sanitize_text_field((string)($field['label'] ?? $key)),
                'type' => $type,
                'placeholder' => sanitize_text_field((string)($field['placeholder'] ?? '')),
            ];
        }

        $limits = is_array($definition['default_limits'] ?? null) ? $definition['default_limits'] : [];
        $this->providers[$id] = [
            'id' => $id,
            'label' => $label,
            'description' => sanitize_textarea_field((string)($definition['description'] ?? '')),
            'uses' => array_values(array_unique($uses)),
            'credential_fields' => $credentialFields,
            'default_limits' => [
                'daily' => max(0, (int)($limits['daily'] ?? 0)),
                'monthly' => max(0, (int)($limits['monthly'] ?? 0)),
                'monthly_reset_day' => min(31, max(1, (int)($limits['monthly_reset_day'] ?? 1))),
            ],
        ];

        do_action('nps_data_provider_registered', $this->providers[$id], $this);
    }

    public function has(string $id): bool
    {
        return isset($this->providers[sanitize_key($id)]);
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        return $this->providers[sanitize_key($id)] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        $providers = $this->providers;
        uasort($providers, static fn(array $a, array $b): int => strcasecmp((string)$a['label'], (string)$b['label']));
        return $providers;
    }
}
