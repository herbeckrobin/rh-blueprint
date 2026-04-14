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
            ['dashicons'],
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

        $this->renderHeader();
        $this->renderToolbar();
        $this->renderTabs($tabs, $activeTab);

        foreach ($tabs as $tabId => $tabLabel) {
            $isActive = $tabId === $activeTab;
            printf(
                '<div class="rhbp-tab-panel" data-tab-panel="%1$s" %2$s>',
                esc_attr($tabId),
                $isActive ? '' : 'hidden'
            );

            /**
             * Wird ganz oben in einem Tab-Panel ausgefuehrt (vor dem Settings-Form).
             * Eignet sich fuer Erfolgs-/Fehlermeldungen von admin-post-Handlern.
             */
            do_action('rh-blueprint/settings/tab_content_before', $tabId);

            $hasGroups = false;
            foreach ($this->registry->groups() as $group) {
                if ($group->tab() !== $tabId) {
                    continue;
                }
                $hasGroups = true;
                break;
            }

            if ($hasGroups) {
                echo '<form action="' . esc_url(admin_url('options.php')) . '" method="post" class="rhbp-form">';
                settings_fields(SettingRegistry::optionGroupForTab($tabId));
                do_settings_sections('rh-blueprint-' . $tabId);
                submit_button(__('Aenderungen speichern', 'rh-blueprint'));
                echo '</form>';
            } else {
                /**
                 * Wenn keine Setting-Gruppen existieren, muss mindestens ein tab_content_after-Hook
                 * eigenen Content liefern, sonst zeigen wir die Empty-State-Meldung.
                 */
                ob_start();
                do_action('rh-blueprint/settings/tab_content_after', $tabId);
                $customContent = (string) ob_get_clean();

                if (trim($customContent) === '') {
                    printf(
                        '<div class="rhbp-empty">%s</div>',
                        esc_html__('Noch keine Einstellungen in diesem Bereich.', 'rh-blueprint')
                    );
                } else {
                    echo $customContent; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }

                echo '</div>';
                continue;
            }

            /**
             * Wird innerhalb eines Tab-Panels NACH dem Settings-Form ausgefuehrt.
             * Ermoeglicht es anderen Modulen eigene Forms / Cards (z.B. DB-Tools) einzuhaengen.
             */
            do_action('rh-blueprint/settings/tab_content_after', $tabId);

            echo '</div>';
        }

        echo '</div>';
    }

    private function renderHeader(): void
    {
        echo '<div class="rhbp-settings__header">';
        echo '<div class="rhbp-settings__logo" aria-hidden="true">';
        echo '<span class="dashicons dashicons-layout"></span>';
        echo '</div>';
        echo '<div class="rhbp-settings__title">';
        echo '<h1>' . esc_html__('RH Blueprint', 'rh-blueprint') . '</h1>';
        echo '<p>' . esc_html__('Zentrale Steuerung fuer Admin-Features, Support-Informationen und Sync Network.', 'rh-blueprint') . '</p>';
        echo '</div>';
        printf(
            '<span class="rhbp-settings__version">v%s</span>',
            esc_html(RHBP_VERSION)
        );
        echo '</div>';
    }

    private function renderToolbar(): void
    {
        echo '<div class="rhbp-settings__toolbar">';
        echo '<div class="rhbp-search">';
        printf(
            '<input type="search" id="rhbp-search-input" placeholder="%s" autocomplete="off" />',
            esc_attr__('Einstellungen durchsuchen…', 'rh-blueprint')
        );
        echo '</div>';
        echo '</div>';
    }

    /**
     * @param array<string, string> $tabs
     */
    private function renderTabs(array $tabs, string $activeTab): void
    {
        echo '<nav class="rhbp-tabs" aria-label="' . esc_attr__('Einstellungs-Kategorien', 'rh-blueprint') . '">';
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
    }
}
