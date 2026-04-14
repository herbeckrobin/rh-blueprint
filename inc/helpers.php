<?php

declare(strict_types=1);

use RhBlueprint\Settings\SettingRegistry;

if (!function_exists('rhbp_support_info')) {
    /**
     * Zugriff auf die Support-Informationen aus den Plugin-Settings.
     *
     * @param string|null $key Einzelner Feld-Key (name|role|email|calendar_url|website|phone) oder null fuer alles.
     * @return mixed Array aller Felder, Wert des gesuchten Keys, oder null wenn Key nicht existiert.
     */
    function rhbp_support_info(?string $key = null): mixed
    {
        $data = (array) get_option(SettingRegistry::optionName('support_info'), []);

        if ($key === null) {
            return $data;
        }

        return $data[$key] ?? null;
    }
}

if (!function_exists('rhbp_setting')) {
    /**
     * Generischer Zugriff auf beliebige Setting-Gruppen.
     */
    function rhbp_setting(string $groupId, ?string $key = null, mixed $default = null): mixed
    {
        $data = (array) get_option(SettingRegistry::optionName($groupId), []);

        if ($key === null) {
            return $data;
        }

        return $data[$key] ?? $default;
    }
}
