<?php

declare(strict_types=1);

namespace RhBlueprint\Admin;

use RhBlueprint\Settings\SettingsPage;

final class BlueprintWidget
{
    public const WIDGET_ID = 'rh_blueprint_widget';

    public function boot(): void
    {
        add_action('wp_dashboard_setup', [$this, 'register']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'index.php') {
            return;
        }

        $file = RHBP_PLUGIN_DIR . 'assets/admin/dashboard-widget.css';

        wp_enqueue_style(
            'rh-blueprint-dashboard-widget',
            RHBP_PLUGIN_URL . 'assets/admin/dashboard-widget.css',
            ['dashicons'],
            file_exists($file) ? (string) filemtime($file) : RHBP_VERSION
        );
    }

    public function register(): void
    {
        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('RH Blueprint', 'rh-blueprint'),
            [$this, 'render']
        );

        global $wp_meta_boxes;
        if (isset($wp_meta_boxes['dashboard']['normal']['core'][self::WIDGET_ID])) {
            $widget = $wp_meta_boxes['dashboard']['normal']['core'][self::WIDGET_ID];
            unset($wp_meta_boxes['dashboard']['normal']['core'][self::WIDGET_ID]);
            $wp_meta_boxes['dashboard']['normal']['high'] = array_merge(
                [self::WIDGET_ID => $widget],
                $wp_meta_boxes['dashboard']['normal']['high'] ?? []
            );
        }
    }

    public function render(): void
    {
        echo '<div class="rhbp-widget">';

        $this->renderSupportBox();
        $this->renderQuickLinks();
        $this->renderSections();

        echo '</div>';
    }

    private function renderSupportBox(): void
    {
        /** @var array<string, string> $info */
        $info = (array) rhbp_support_info();

        $name = isset($info['name']) ? (string) $info['name'] : '';
        $email = isset($info['email']) ? (string) $info['email'] : '';

        if ($name === '' && $email === '') {
            return;
        }

        $role = isset($info['role']) ? (string) $info['role'] : '';
        $calendarUrl = isset($info['calendar_url']) ? (string) $info['calendar_url'] : '';
        $phone = isset($info['phone']) ? (string) $info['phone'] : '';

        echo '<div class="rhbp-widget__section">';
        echo '<header class="rhbp-widget__section-header">';
        echo '<span class="dashicons dashicons-businessperson" aria-hidden="true"></span>';
        echo '<h3>' . esc_html__('Support', 'rh-blueprint') . '</h3>';
        echo '</header>';

        echo '<div class="rhbp-support-card">';
        echo '<div class="rhbp-support-card__avatar" aria-hidden="true">' . esc_html($this->getInitials($name)) . '</div>';
        echo '<div class="rhbp-support-card__body">';
        if ($name !== '') {
            echo '<strong>' . esc_html($name) . '</strong>';
        }
        if ($role !== '') {
            echo '<span>' . esc_html($role) . '</span>';
        }
        echo '</div>';
        echo '</div>';

        $actions = [];

        if ($email !== '') {
            $actions[] = [
                'url' => 'mailto:' . $email,
                'icon' => 'email-alt',
                'label' => __('E-Mail', 'rh-blueprint'),
                'target' => '',
            ];
        }

        if ($phone !== '') {
            $phoneClean = preg_replace('/\s+/', '', $phone) ?? '';
            $actions[] = [
                'url' => 'tel:' . $phoneClean,
                'icon' => 'phone',
                'label' => __('Anrufen', 'rh-blueprint'),
                'target' => '',
            ];
        }

        if ($calendarUrl !== '') {
            $actions[] = [
                'url' => $calendarUrl,
                'icon' => 'calendar-alt',
                'label' => __('Termin', 'rh-blueprint'),
                'target' => '_blank',
            ];
        }

        if ($actions !== []) {
            echo '<div class="rhbp-actions">';
            foreach ($actions as $action) {
                printf(
                    '<a href="%1$s"%2$s><span class="dashicons dashicons-%3$s" aria-hidden="true"></span>%4$s</a>',
                    esc_attr($action['url']),
                    $action['target'] !== '' ? ' target="' . esc_attr($action['target']) . '" rel="noopener"' : '',
                    esc_attr($action['icon']),
                    esc_html($action['label'])
                );
            }
            echo '</div>';
        }

        echo '</div>';
    }

    private function renderQuickLinks(): void
    {
        $default = [
            [
                'label' => __('Einstellungen', 'rh-blueprint'),
                'url' => admin_url('options-general.php?page=' . SettingsPage::MENU_SLUG),
                'icon' => 'admin-generic',
            ],
            [
                'label' => __('Sync Network', 'rh-blueprint'),
                'url' => admin_url('options-general.php?page=' . SettingsPage::MENU_SLUG . '&tab=sync_network'),
                'icon' => 'update',
            ],
        ];

        /** @var array<int, array<string, mixed>> $links */
        $links = apply_filters('rh-blueprint/dashboard/quick_links', $default);

        if ($links === []) {
            return;
        }

        echo '<div class="rhbp-widget__section">';
        echo '<header class="rhbp-widget__section-header">';
        echo '<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>';
        echo '<h3>' . esc_html__('Schnellzugriff', 'rh-blueprint') . '</h3>';
        echo '</header>';

        echo '<div class="rhbp-quick-links">';
        foreach ($links as $link) {
            $label = isset($link['label']) ? (string) $link['label'] : '';
            $url = isset($link['url']) ? (string) $link['url'] : '';
            $icon = isset($link['icon']) ? (string) $link['icon'] : 'arrow-right-alt2';

            if ($label === '' || $url === '') {
                continue;
            }

            printf(
                '<a href="%1$s"><span class="dashicons dashicons-%2$s" aria-hidden="true"></span>%3$s<span class="rhbp-quick-links__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>',
                esc_url($url),
                esc_attr($icon),
                esc_html($label)
            );
        }
        echo '</div>';
        echo '</div>';
    }

    private function renderSections(): void
    {
        /** @var array<int, array<string, mixed>> $sections */
        $sections = apply_filters('rh-blueprint/dashboard/widget_sections', []);

        foreach ($sections as $section) {
            $callback = $section['callback'] ?? null;
            if (!is_callable($callback)) {
                continue;
            }

            $title = isset($section['title']) ? (string) $section['title'] : '';
            $id = isset($section['id']) ? (string) $section['id'] : '';
            $icon = isset($section['icon']) ? (string) $section['icon'] : 'admin-plugins';

            echo '<div class="rhbp-widget__section" data-section="' . esc_attr($id) . '">';
            if ($title !== '') {
                echo '<header class="rhbp-widget__section-header">';
                echo '<span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span>';
                echo '<h3>' . esc_html($title) . '</h3>';
                echo '</header>';
            }
            call_user_func($callback);
            echo '</div>';
        }
    }

    private function getInitials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'RH';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : 'RH';
    }
}
