<?php

declare(strict_types=1);

namespace RhBlueprint;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function boot(): void
    {
        self::instance()->registerHooks();
    }

    private function __construct()
    {
    }

    private function registerHooks(): void
    {
        add_action('init', [$this, 'onInit']);
    }

    public function onInit(): void
    {
        load_plugin_textdomain(
            'rh-blueprint',
            false,
            dirname(plugin_basename(RHBP_PLUGIN_FILE)) . '/languages'
        );
    }

    public function version(): string
    {
        return RHBP_VERSION;
    }

    public function pluginDir(): string
    {
        return RHBP_PLUGIN_DIR;
    }

    public function pluginFile(): string
    {
        return RHBP_PLUGIN_FILE;
    }
}
