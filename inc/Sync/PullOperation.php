<?php

declare(strict_types=1);

namespace RhBlueprint\Sync;

use RhBlueprint\Db\BackupStorage;
use RhBlueprint\Db\Exporter;
use RhBlueprint\Db\Importer;

/**
 * Pull-Workflow: Holt einen Remote-Snapshot vom Peer und importiert ihn lokal.
 *
 * Schritte:
 *   1. GET  /rhbp/v1/sync/manifest   — Peer-Kompatibilitaet pruefen
 *   2. POST /rhbp/v1/sync/export     — Export anstossen, Token erhalten
 *   3. GET  /rhbp/v1/sync/download   — ZIP streamen
 *   4. Auto-Safety-Backup des lokalen Zustands (vor dem Import)
 *   5. Importer::importFromFile()    — Remote-DB lokal einspielen + URL-Rewrite
 *   6. SyncLog::record()             — Erfolg/Fehler loggen
 *
 * Bei Fehlern nach Step 4: Rollback via Importer auf den Safety-Backup.
 */
final class PullOperation
{
    public function __construct(
        private readonly SyncClient $client,
        private readonly Exporter $exporter,
        private readonly Importer $importer,
        private readonly BackupStorage $storage,
        private readonly SyncLog $log,
    ) {
    }

    public function execute(Peer $peer): PullResult
    {
        $startTime = microtime(true);

        try {
            $manifest = $this->fetchManifest($peer);
            $exportInfo = $this->triggerExport($peer);
            $localZip = $this->downloadBackup($peer, (string) $exportInfo['download_url']);
            $safetyBackup = $this->createSafetyBackup();
            $this->runImport($localZip, $safetyBackup);
            @unlink($localZip);

            $bytes = (int) ($exportInfo['size'] ?? 0);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->log->record($peer, 'pull', 'success', $bytes, $durationMs);

            return new PullResult(
                success: true,
                bytes: $bytes,
                durationMs: $durationMs,
                manifest: $manifest,
                safetyBackup: $safetyBackup,
                error: null,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->log->record($peer, 'pull', 'failed', 0, $durationMs, $e->getMessage());

            return new PullResult(
                success: false,
                bytes: 0,
                durationMs: $durationMs,
                manifest: null,
                safetyBackup: null,
                error: $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchManifest(Peer $peer): array
    {
        $response = $this->client->request($peer, 'GET', '/rhbp/v1/sync/manifest');

        if (!$response->isSuccess()) {
            throw new \RuntimeException(sprintf(
                'Manifest-Request fehlgeschlagen (HTTP %d): %s',
                $response->status,
                $response->error ?? $this->extractErrorMessage($response)
            ));
        }

        $data = $response->json();
        if ($data === null) {
            throw new \RuntimeException('Manifest-Response konnte nicht als JSON geparst werden.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function triggerExport(Peer $peer): array
    {
        $response = $this->client->request($peer, 'POST', '/rhbp/v1/sync/export', ['include_uploads' => false]);

        if (!$response->isSuccess()) {
            throw new \RuntimeException(sprintf(
                'Export-Request fehlgeschlagen (HTTP %d): %s',
                $response->status,
                $response->error ?? $this->extractErrorMessage($response)
            ));
        }

        $data = $response->json();
        if ($data === null || empty($data['download_url'])) {
            throw new \RuntimeException('Export-Response unvollstaendig — kein download_url enthalten.');
        }

        return $data;
    }

    private function downloadBackup(Peer $peer, string $url): string
    {
        $this->storage->ensureReady();
        $target = $this->storage->reserveTempFile('sync-pull') . '.zip';

        $this->client->downloadTo($url, $target, $peer);

        if (!is_file($target) || filesize($target) === 0) {
            throw new \RuntimeException('Heruntergeladene ZIP-Datei ist leer oder fehlt.');
        }

        return $target;
    }

    private function createSafetyBackup(): string
    {
        return $this->exporter->createBackup(false);
    }

    private function runImport(string $zipPath, string $safetyBackup): void
    {
        $guard = new LocalOptionGuard();
        $snapshot = $guard->snapshot();

        try {
            $this->importer->importFromFile($zipPath);
        } catch (\Throwable $e) {
            // Rollback-Versuch: Safety-Backup zurueckspielen.
            try {
                $this->importer->importFromFile($safetyBackup);
            } catch (\Throwable $rollbackError) {
                throw new \RuntimeException(sprintf(
                    'Import fehlgeschlagen (%s) UND Rollback fehlgeschlagen (%s). Manuelle Wiederherstellung noetig: %s',
                    $e->getMessage(),
                    $rollbackError->getMessage(),
                    $safetyBackup
                ));
            }
            throw new \RuntimeException(sprintf(
                'Import fehlgeschlagen: %s. Safety-Backup wurde zurueckgespielt.',
                $e->getMessage()
            ));
        }

        // Erfolg: Die site-spezifischen rhbp_* Options wiederherstellen,
        // die durch den Import ueberschrieben wurden.
        $guard->restore($snapshot);
    }

    private function extractErrorMessage(SyncResponse $response): string
    {
        $data = $response->json();
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }
        $stripped = trim(preg_replace('/\s+/', ' ', $response->body) ?? $response->body);
        if (strlen($stripped) > 200) {
            $stripped = substr($stripped, 0, 200) . '…';
        }
        return 'Unbekannter Fehler. Body-Preview: ' . $stripped;
    }
}

final class PullResult
{
    /**
     * @param array<string, mixed>|null $manifest
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $bytes,
        public readonly int $durationMs,
        public readonly ?array $manifest,
        public readonly ?string $safetyBackup,
        public readonly ?string $error,
    ) {
    }
}
