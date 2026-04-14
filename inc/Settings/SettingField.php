<?php

declare(strict_types=1);

namespace RhBlueprint\Settings;

final class SettingField
{
    public const TYPE_TEXT = 'text';
    public const TYPE_EMAIL = 'email';
    public const TYPE_URL = 'url';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_SELECT = 'select';
    public const TYPE_TEXTAREA = 'textarea';

    /**
     * @param array<string, string> $choices
     * @param array<int, string>    $keywords
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $label,
        public readonly string $description = '',
        public readonly string|int|bool $default = '',
        public readonly array $choices = [],
        public readonly array $keywords = [],
    ) {
    }

    public function searchIndex(): string
    {
        $parts = array_merge([$this->label, $this->description], $this->keywords);

        return strtolower(implode(' ', array_filter($parts)));
    }

    public function sanitize(mixed $value): mixed
    {
        return match ($this->type) {
            self::TYPE_EMAIL => sanitize_email((string) $value),
            self::TYPE_URL => esc_url_raw((string) $value),
            self::TYPE_BOOLEAN => (bool) $value,
            self::TYPE_SELECT => array_key_exists((string) $value, $this->choices) ? (string) $value : (string) $this->default,
            self::TYPE_TEXTAREA => sanitize_textarea_field((string) $value),
            default => sanitize_text_field((string) $value),
        };
    }

    public function render(string $name, mixed $value): void
    {
        $id = esc_attr($name);
        $current = $value ?? $this->default;

        switch ($this->type) {
            case self::TYPE_TEXTAREA:
                printf(
                    '<textarea id="%1$s" name="%1$s" rows="5" class="large-text code">%2$s</textarea>',
                    $id,
                    esc_textarea((string) $current)
                );
                break;

            case self::TYPE_BOOLEAN:
                printf(
                    '<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
                    $id,
                    checked((bool) $current, true, false),
                    esc_html__('Aktivieren', 'rh-blueprint')
                );
                break;

            case self::TYPE_SELECT:
                printf('<select id="%1$s" name="%1$s">', $id);
                foreach ($this->choices as $choiceValue => $choiceLabel) {
                    printf(
                        '<option value="%1$s" %2$s>%3$s</option>',
                        esc_attr($choiceValue),
                        selected((string) $current, (string) $choiceValue, false),
                        esc_html($choiceLabel)
                    );
                }
                echo '</select>';
                break;

            case self::TYPE_EMAIL:
            case self::TYPE_URL:
            case self::TYPE_TEXT:
            default:
                printf(
                    '<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="regular-text" />',
                    esc_attr($this->type === self::TYPE_TEXT ? 'text' : $this->type),
                    $id,
                    esc_attr((string) $current)
                );
                break;
        }

        if ($this->description !== '') {
            printf('<p class="description">%s</p>', esc_html($this->description));
        }
    }
}
