<?php

declare(strict_types=1);

namespace RhBlueprint\Sync;

use RhBlueprint\Db\BackupStorage;
use RhBlueprint\Db\Exporter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class SyncController
{
    public const NAMESPACE = 'rhbp/v1';
    public const DOWNLOAD_TRANSIENT_PREFIX = 'rhbp_sync_dl_';
    public const DOWNLOAD_TTL = 600; // 10 Minuten

    public function __construct(
        private readonly HmacAuth $auth,
        private readonly BackupStorage $storage,
        private readonly Exporter $exporter,
    ) {
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/sync/manifest', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handleManifest'],
            'permission_callback' => [$this, 'checkAuth'],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/export', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handleExport'],
            'permission_callback' => [$this, 'checkAuth'],
            'args' => [
                'include_uploads' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/sync/download', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handleDownload'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => [
                    'type' => 'string',
                    'required' => true,
                ],
            ],
        ]);
    }

    public function checkAuth(WP_REST_Request $request): bool|WP_Error
    {
        $peer = $this->auth->verifyRestRequest($request);

        if ($peer === null) {
            return new WP_Error(
                'rhbp_unauthorized',
                __('HMAC-Verifizierung fehlgeschlagen.', 'rh-blueprint'),
                ['status' => 401]
            );
        }

        $request->set_param('_peer_id', $peer->id);

        return true;
    }

    public function handleManifest(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $uploads = wp_upload_dir();
        $uploadBase = (string) $uploads['basedir'];

        $postCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish'");
        $lastModified = (string) $wpdb->get_var("SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}");

        $dbName = defined('DB_NAME') ? (string) constant('DB_NAME') : '';
        $dbSize = (int) $wpdb->get_var(sprintf(
            "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = '%s' AND table_name LIKE '%s'",
            esc_sql($dbName),
            esc_sql(str_replace('_', '\\_', $wpdb->prefix) . '%')
        ));

        return new WP_REST_Response([
            'plugin_version' => defined('RHBP_VERSION') ? RHBP_VERSION : '0.0.0',
            'wp_version' => get_bloginfo('version'),
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'db_prefix' => $wpdb->prefix,
            'db_size' => $dbSize,
            'uploads_size' => $this->estimateDirectorySize($uploadBase),
            'post_count' => $postCount,
            'last_modified' => $lastModified,
            'generated_at' => gmdate('c'),
        ]);
    }

    public function handleExport(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $includeUploads = (bool) $request->get_param('include_uploads');

        try {
            $zipPath = $this->exporter->createBackup($includeUploads);
        } catch (\Throwable $e) {
            return new WP_Error(
                'rhbp_export_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }

        $token = wp_generate_password(40, false, false);
        set_transient(
            self::DOWNLOAD_TRANSIENT_PREFIX . $token,
            [
                'path' => $zipPath,
                'peer_id' => (string) $request->get_param('_peer_id'),
                'created' => time(),
            ],
            self::DOWNLOAD_TTL
        );

        return new WP_REST_Response([
            'token' => $token,
            'download_url' => add_query_arg(
                ['token' => $token],
                rest_url(self::NAMESPACE . '/sync/download')
            ),
            'expires_at' => gmdate('c', time() + self::DOWNLOAD_TTL),
            'size' => is_file($zipPath) ? (int) filesize($zipPath) : 0,
            'filename' => basename($zipPath),
        ]);
    }

    /**
     * @return never|WP_Error
     */
    public function handleDownload(WP_REST_Request $request)
    {
        $token = (string) $request->get_param('token');

        if ($token === '' || !preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return new WP_Error('rhbp_invalid_token', __('Ungueltiges Token.', 'rh-blueprint'), ['status' => 400]);
        }

        $transientKey = self::DOWNLOAD_TRANSIENT_PREFIX . $token;
        $data = get_transient($transientKey);

        if (!is_array($data) || empty($data['path']) || !is_string($data['path'])) {
            return new WP_Error('rhbp_token_expired', __('Token ungueltig oder abgelaufen.', 'rh-blueprint'), ['status' => 404]);
        }

        $zipPath = (string) $data['path'];

        // Path-Validation: muss im backups/ Ordner liegen
        $resolved = $this->storage->resolveInside($this->storage->backupsPath(), basename($zipPath));
        if ($resolved === null || !is_readable($resolved)) {
            delete_transient($transientKey);
            return new WP_Error('rhbp_file_missing', __('Backup-Datei nicht lesbar.', 'rh-blueprint'), ['status' => 404]);
        }

        // Token ist Single-Use
        delete_transient($transientKey);

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($resolved) . '"');
        header('Content-Length: ' . (string) filesize($resolved));

        readfile($resolved);
        exit;
    }

    private function estimateDirectorySize(string $path): int
    {
        if ($path === '' || !is_dir($path)) {
            return 0;
        }

        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }

        return $size;
    }
}
