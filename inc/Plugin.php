<?php

declare(strict_types=1);

namespace RhBlueprint;

use RhBlueprint\Frontend\SmoothScrollEnqueue;
use RhBlueprint\Settings\SettingRegistry;
use RhBlueprint\Settings\SettingsPage;

final class Plugin
{
    private static ?self $instance = null;

    private SettingRegistry $settingRegistry;

    private SettingsPage $settingsPage;

    private SmoothScrollEnqueue $smoothScrollEnqueue;

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
        $this->settingRegistry = new SettingRegistry();
        $this->settingsPage = new SettingsPage($this->settingRegistry);
        $this->smoothScrollEnqueue = new SmoothScrollEnqueue();
    }

    private function registerHooks(): void
    {
        add_action('init', [$this, 'onInit']);

        $this->settingRegistry->boot();
        $this->settingsPage->boot();
        $this->smoothScrollEnqueue->boot();
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
