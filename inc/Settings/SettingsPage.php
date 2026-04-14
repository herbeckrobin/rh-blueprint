<?php

declare(strict_types=1);

namespace RhBlueprint\Settings;

final class SettingsPage
{
    public const MENU_SLUG = 'rh-blueprint';
    public const CAPABILITY = 'manage_options';

    public function __construct(private readonly SettingRegistry $registry)
    {
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'options-general.php',
            __('RH Blueprint', 'rh-blueprint'),
            __('RH Blueprint', 'rh-blueprint'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::MENU_SLUG) {
            return;
        }

        $assetsUrl = RHBP_PLUGIN_URL . 'assets/admin/';
        $assetsDir = RHBP_PLUGIN_DIR . 'assets/admin/';

        wp_enqueue_style(
            'rh-blueprint-settings',
            $assetsUrl . 'settings.css',
            [],
            file_exists($assetsDir . 'settings.css') ? (string) filemtime($assetsDir . 'settings.css') : RHBP_VERSION
        );

        wp_enqueue_script(
            'rh-blueprint-settings',
            $assetsUrl . 'settings.js',
            [],
            file_exists($assetsDir . 'settings.js') ? (string) filemtime($assetsDir . 'settings.js') : RHBP_VERSION,
            true
        );
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $tabs = $this->registry->tabs();
        $activeTab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : (string) array_key_first($tabs);

        if (!isset($tabs[$activeTab])) {
            $activeTab = (string) array_key_first($tabs);
        }

        echo '<div class="wrap rhbp-settings" data-active-tab="' . esc_attr($activeTab) . '">';
        echo '<h1>' . esc_html__('RH Blueprint', 'rh-blueprint') . '</h1>';

        echo '<div class="rhbp-search">';
        printf(
            '<input type="search" id="rhbp-search-input" placeholder="%s" autocomplete="off" />',
            esc_attr__('Einstellungen durchsuchen…', 'rh-blueprint')
        );
        echo '</div>';

        echo '<nav class="nav-tab-wrapper rhbp-tabs">';
        foreach ($tabs as $tabId => $tabLabel) {
            $url = add_query_arg([
                'page' => self::MENU_SLUG,
                'tab' => $tabId,
            ], admin_url('options-general.php'));

            printf(
                '<a href="%1$s" class="nav-tab %2$s" data-tab="%3$s">%4$s</a>',
                esc_url($url),
                $tabId === $activeTab ? 'nav-tab-active' : '',
                esc_attr($tabId),
                esc_html($tabLabel)
            );
        }
        echo '</nav>';

        echo '<form action="options.php" method="post" class="rhbp-form">';
        settings_fields(SettingRegistry::OPTION_GROUP);

        foreach ($tabs as $tabId => $tabLabel) {
            $isActive = $tabId === $activeTab;
            printf(
                '<div class="rhbp-tab-panel" data-tab-panel="%1$s" %2$s>',
                esc_attr($tabId),
                $isActive ? '' : 'hidden'
            );

            $hasGroups = false;
            foreach ($this->registry->groups() as $group) {
                if ($group->tab() !== $tabId) {
                    continue;
                }

                $hasGroups = true;
                do_settings_sections('rh-blueprint-' . $tabId);
            }

            if (!$hasGroups) {
                printf(
                    '<p class="rhbp-empty">%s</p>',
                    esc_html__('Noch keine Einstellungen in diesem Bereich.', 'rh-blueprint')
                );
            }

            echo '</div>';
        }

        submit_button();
        echo '</form>';
        echo '</div>';
    }
}
