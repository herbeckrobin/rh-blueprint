<?php

declare(strict_types=1);

namespace RhBlueprint\Frontend;

use RhBlueprint\Settings\SettingRegistry;

final class SmoothScrollEnqueue
{
    public const THEME_HANDLE = 'rh-blueprint-lenis';
    public const INIT_HANDLE = 'rh-blueprint-smooth-scroll-init';

    public function boot(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'maybeEnqueue'], 20);
    }

    public function maybeEnqueue(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!wp_script_is(self::THEME_HANDLE, 'registered')) {
            return;
        }

        wp_enqueue_script(self::THEME_HANDLE);

        $file = RHBP_PLUGIN_DIR . 'assets/public/smooth-scroll-init.js';
        $url = RHBP_PLUGIN_URL . 'assets/public/smooth-scroll-init.js';

        wp_enqueue_script(
            self::INIT_HANDLE,
            $url,
            [self::THEME_HANDLE],
            file_exists($file) ? (string) filemtime($file) : RHBP_VERSION,
            true
        );
    }

    private function isEnabled(): bool
    {
        $options = (array) get_option(SettingRegistry::optionName('smooth_scroll'), []);

        return !empty($options['enabled']);
    }
}
