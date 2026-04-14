<?php

declare(strict_types=1);

namespace RhBlueprint\Settings;

interface GroupInterface
{
    public function id(): string;

    public function tab(): string;

    public function title(): string;

    public function description(): string;

    /**
     * @return array<int, SettingField>
     */
    public function fields(): array;
}
