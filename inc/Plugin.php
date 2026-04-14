<?php

declare(strict_types=1);

namespace RhBlueprint;

use RhBlueprint\Admin\BlueprintWidget;
use RhBlueprint\Admin\DashboardCleanup;
use RhBlueprint\Admin\DbToolsPage;
use RhBlueprint\Db\BackupStorage;
use RhBlueprint\Db\Exporter;
use RhBlueprint\Db\Importer;
use RhBlueprint\Db\SearchReplace;
use RhBlueprint\Frontend\SmoothScrollEnqueue;
use RhBlueprint\Integrations\WpsHideLoginBridge;
use RhBlueprint\Settings\SettingRegistry;
use RhBlueprint\Settings\SettingsPage;
use RhBlueprint\Sync\PeerRegistry;
use RhBlueprint\Sync\SyncPeersPage;
use RhBlueprint\UpdateChecker;

final class Plugin
{
    private static ?self $instance = null;

    private SettingRegistry $settingRegistry;

    private SettingsPage $settingsPage;

    private SmoothScrollEnqueue $smoothScrollEnqueue;

    private DashboardCleanup $dashboardCleanup;

    private BlueprintWidget $blueprintWidget;

    private WpsHideLoginBridge $wpsHideLoginBridge;

    private DbToolsPage $dbToolsPage;

    private SyncPeersPage $syncPeersPage;

    private UpdateChecker $updateChecker;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function boot(): void
    {
        self::instance()->registerHooks();
    }

    private function __construct()
    {
        $this->settingRegistry = new SettingRegistry();
        $this->settingsPage = new SettingsPage($this->settingRegistry);
        $this->smoothScrollEnqueue = new SmoothScrollEnqueue();
        $this->dashboardCleanup = new DashboardCleanup();
        $this->blueprintWidget = new BlueprintWidget();
        $this->wpsHideLoginBridge = new WpsHideLoginBridge();

        $backupStorage = new BackupStorage();
        $searchReplace = new SearchReplace();
        $this->dbToolsPage = new DbToolsPage(
            $backupStorage,
            new Exporter($backupStorage),
            new Importer($backupStorage, $searchReplace)
        );

        $peerRegistry = new PeerRegistry();
        $this->syncPeersPage = new SyncPeersPage($peerRegistry);

        $this->updateChecker = new UpdateChecker();
    }

    private function registerHooks(): void
    {
        add_action('init', [$this, 'onInit']);

        $this->settingRegistry->boot();
        $this->settingsPage->boot();
        $this->smoothScrollEnqueue->boot();
        $this->dashboardCleanup->boot();
        $this->blueprintWidget->boot();
        $this->wpsHideLoginBridge->boot();
        $this->dbToolsPage->boot();
        $this->syncPeersPage->boot();
        $this->updateChecker->boot();
    }

    public function onInit(): void
    {
        load_plugin_textdomain(
            'rh-blueprint',
            false,
            dirname(plugin_basename(RHBP_PLUGIN_FILE)) . '/languages'
        );
    }

    public function version(): string
    {
        return RHBP_VERSION;
    }

    public function pluginDir(): string
    {
        return RHBP_PLUGIN_DIR;
    }

    public function pluginFile(): string
    {
        return RHBP_PLUGIN_FILE;
    }
}
