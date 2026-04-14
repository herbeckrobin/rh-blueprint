<?php

declare(strict_types=1);

namespace RhBlueprint\Settings\groups;

use RhBlueprint\Settings\GroupInterface;
use RhBlueprint\Settings\SettingField;

final class ToolsSmoothScrollGroup implements GroupInterface
{
    public function id(): string
    {
        return 'smooth_scroll';
    }

    public function tab(): string
    {
        return 'tools';
    }

    public function title(): string
    {
        return __('Smooth Scroll', 'rh-blueprint');
    }

    public function description(): string
    {
        return __('Aktiviert Smooth Scrolling im Frontend. Setzt voraus, dass das Theme die Lenis-Library als Script-Handle "rh-blueprint-lenis" registriert hat — das Plugin liefert nur die Init-Logik, nicht die Library.', 'rh-blueprint');
    }

    public function fields(): array
    {
        return [
            new SettingField(
                id: 'enabled',
                type: SettingField::TYPE_BOOLEAN,
                label: __('Smooth Scroll aktivieren', 'rh-blueprint'),
                description: __('Wird nur geladen, wenn das aktive Theme die Lenis-Library bereitstellt.', 'rh-blueprint'),
                default: false,
                keywords: ['smooth', 'scroll', 'lenis', 'animation', 'scrolling'],
            ),
        ];
    }
}
