<?php

declare(strict_types=1);

namespace RhBlueprint;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * GitHub-basierter Auto-Update-Checker.
 *
 * Haengt sich in die native WordPress-Update-Mechanik via `pre_set_site_transient_update_plugins`.
 * Kein eigenes UI — Updates erscheinen wie normale Plugin-Updates im Admin.
 *
 * Release-Strategie: Die Library zieht ZIP-Assets aus GitHub Releases (Tags `v*`),
 * nicht den Branch-Zipball. Damit ist das committed `vendor/` enthalten.
 */
final class UpdateChecker
{
    public const GITHUB_REPO = 'https://github.com/herbeckrobin/rh-blueprint/';
    public const PLUGIN_SLUG = 'rh-blueprint';

    public function boot(): void
    {
        if (!class_exists(PucFactory::class)) {
            return;
        }

        $updateChecker = PucFactory::buildUpdateChecker(
            self::GITHUB_REPO,
            RHBP_PLUGIN_FILE,
            self::PLUGIN_SLUG
        );

        $vcsApi = $updateChecker->getVcsApi();
        if ($vcsApi !== null && method_exists($vcsApi, 'enableReleaseAssets')) {
            $vcsApi->enableReleaseAssets();
        }
    }
}
