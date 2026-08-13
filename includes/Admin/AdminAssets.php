<?php

namespace NPS\Core\Admin;

if (!defined('ABSPATH')) exit;

final class AdminAssets
{
    private string $handle;
    private string $pluginDir;
    private string $pluginUrl;
    private string $cssFile;
    private string $jsFile;

    /** @var array<string,true> */
    private array $hooks = [];

    public function __construct(string $handle, string $pluginDir, string $pluginUrl, string $cssFile = 'assets/admin.css', string $jsFile = 'assets/admin.js')
    {
        $this->handle = $handle;
        $this->pluginDir = rtrim($pluginDir, '/\\') . '/';
        $this->pluginUrl = rtrim($pluginUrl, '/') . '/';
        $this->cssFile = ltrim($cssFile, '/');
        $this->jsFile = ltrim($jsFile, '/');
    }

    public function init(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue'], 5);
    }

    public function registerHook(string $hook): void
    {
        if ($hook !== '') {
            $this->hooks[$hook] = true;
        }
    }

    public function enqueue(string $hook): void
    {
        if (!isset($this->hooks[$hook])) {
            return;
        }

        $cssPath = $this->pluginDir . $this->cssFile;
        $jsPath = $this->pluginDir . $this->jsFile;

        if (is_file($cssPath)) {
            wp_enqueue_style($this->handle, $this->pluginUrl . $this->cssFile, [], filemtime($cssPath));
        }

        if (is_file($jsPath)) {
            wp_enqueue_script($this->handle, $this->pluginUrl . $this->jsFile, ['jquery'], filemtime($jsPath), true);
        }
    }

    public function handle(): string
    {
        return $this->handle;
    }
}
