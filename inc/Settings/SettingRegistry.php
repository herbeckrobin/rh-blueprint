<?php

declare(strict_types=1);

namespace RhBlueprint\Settings;

final class SettingRegistry
{
    public const OPTION_GROUP = 'rh_blueprint_settings';
    public const OPTION_PREFIX = 'rhbp_settings_';

    /** @var array<int, GroupInterface> */
    private array $groups = [];

    /** @var array<string, string> */
    private array $tabs = [];

    private bool $groupsLoaded = false;

    public function boot(): void
    {
        add_action('init', [$this, 'loadGroups'], 5);
        add_action('admin_init', [$this, 'register']);
    }

    /**
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return $this->tabs;
    }

    /**
     * @return array<int, GroupInterface>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    public static function optionName(string $groupId): string
    {
        return self::OPTION_PREFIX . $groupId;
    }

    public static function fieldName(string $groupId, string $fieldId): string
    {
        return sprintf('%s[%s]', self::optionName($groupId), $fieldId);
    }

    public function register(): void
    {
        foreach ($this->groups as $group) {
            $optionName = self::optionName($group->id());
            $section = 'rhbp_section_' . $group->id();
            $page = 'rh-blueprint-' . $group->tab();

            register_setting(
                self::OPTION_GROUP,
                $optionName,
                [
                    'type' => 'array',
                    'sanitize_callback' => function (mixed $input) use ($group): array {
                        return $this->sanitizeGroup($group, $input);
                    },
                    'default' => [],
                ]
            );

            add_settings_section(
                $section,
                $group->title(),
                function () use ($group): void {
                    if ($group->description() !== '') {
                        printf('<p>%s</p>', esc_html($group->description()));
                    }
                },
                $page
            );

            $stored = (array) get_option($optionName, []);

            foreach ($group->fields() as $field) {
                add_settings_field(
                    $optionName . '_' . $field->id,
                    esc_html($field->label),
                    function () use ($field, $optionName, $stored): void {
                        $value = $stored[$field->id] ?? $field->default;
                        $name = sprintf('%s[%s]', $optionName, $field->id);
                        echo '<div class="rhbp-field" data-search-index="' . esc_attr($field->searchIndex()) . '">';
                        $field->render($name, $value);
                        echo '</div>';
                    },
                    $page,
                    $section,
                    ['label_for' => $optionName . '_' . $field->id]
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeGroup(GroupInterface $group, mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $clean = [];

        foreach ($group->fields() as $field) {
            $raw = $input[$field->id] ?? null;

            if ($field->type === SettingField::TYPE_BOOLEAN) {
                $clean[$field->id] = !empty($raw);
                continue;
            }

            $clean[$field->id] = $field->sanitize($raw);
        }

        return $clean;
    }

    public function loadGroups(): void
    {
        if ($this->groupsLoaded) {
            return;
        }

        $this->groupsLoaded = true;

        $files = glob(__DIR__ . '/groups/*.php') ?: [];

        foreach ($files as $file) {
            require_once $file;

            $className = __NAMESPACE__ . '\\groups\\' . basename($file, '.php');

            if (!class_exists($className)) {
                continue;
            }

            $instance = new $className();

            if ($instance instanceof GroupInterface) {
                $this->groups[] = $instance;
            }
        }

        $defaultTabs = [
            'support' => __('Support', 'rh-blueprint'),
            'tools' => __('Tools', 'rh-blueprint'),
            'design' => __('Design', 'rh-blueprint'),
            'integrations' => __('Integrationen', 'rh-blueprint'),
            'sync_network' => __('Sync Network', 'rh-blueprint'),
        ];

        /** @var array<string, string> $tabs */
        $tabs = apply_filters('rh-blueprint/settings/tabs', $defaultTabs);
        $this->tabs = $tabs;
    }
}
