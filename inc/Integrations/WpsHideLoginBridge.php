<?php

declare(strict_types=1);

namespace RhBlueprint\Integrations;

final class WpsHideLoginBridge
{
    public const CONSTANT_NAME = 'RHBP_LOGIN_SLUG';
    public const PLUGIN_FILE = 'wps-hide-login/wps-hide-login.php';
    public const WPS_OPTION = 'whl_page';

    public function boot(): void
    {
        add_action('admin_init', [$this, 'sync']);
        add_action('admin_notices', [$this, 'maybeShowNotice']);
    }

    public function sync(): void
    {
        if (!defined(self::CONSTANT_NAME)) {
            return;
        }

        if (!$this->isWpsHideLoginActive()) {
            return;
        }

        $slug = sanitize_title((string) constant(self::CONSTANT_NAME));

        if ($slug === '') {
            return;
        }

        $current = (string) get_option(self::WPS_OPTION, '');

        if ($current !== $slug) {
            update_option(self::WPS_OPTION, $slug);
        }
    }

    public function maybeShowNotice(): void
    {
        if (!defined(self::CONSTANT_NAME)) {
            return;
        }

        if ($this->isWpsHideLoginActive()) {
            return;
        }

        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        printf(
            /* translators: %1$s: Name of the WordPress constant. %2$s: Name of the plugin. */
            esc_html__('Die Konstante %1$s ist in wp-config.php gesetzt, aber das Plugin %2$s ist nicht aktiv. Die Login-URL bleibt auf dem WordPress-Default, bis das Plugin aktiviert wird.', 'rh-blueprint'),
            '<code>' . esc_html(self::CONSTANT_NAME) . '</code>',
            '<strong>WPS Hide Login</strong>'
        );
        echo '</p></div>';
    }

    private function isWpsHideLoginActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active(self::PLUGIN_FILE);
    }
}
