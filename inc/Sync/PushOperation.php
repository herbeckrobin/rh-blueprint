<?php

declare(strict_types=1);

namespace RhBlueprint\Sync;

use RhBlueprint\Db\Exporter;

/**
 * Push-Workflow: Schiebt einen lokalen Snapshot zum Ziel-Peer und loest dort den Import aus.
 *
 * Schritte:
 *   1. Lokaler Export via `Exporter::createBackup()`
 *   2. POST /rhbp/v1/sync/import/init          — Session anlegen, Session-ID holen
 *   3. PUT  /rhbp/v1/sync/import/{sid}/chunk/N — ZIP in 5-MB-Chunks hochladen
 *   4. POST /rhbp/v1/sync/import/{sid}/complete — Ziel assembliert + importiert (mit Auto-Safety-Backup dort)
 *   5. SyncLog-Eintrag
 */
final class PushOperation
{
    public const CHUNK_SIZE = 5 * 1024 * 1024; // 5 MB

    public function __construct(
        private readonly SyncClient $client,
        private readonly Exporter $exporter,
        private readonly SyncLog $log,
    ) {
    }

    public function execute(Peer $peer): PushResult
    {
        $startTime = microtime(true);
        $localZip = null;

        try {
            $localZip = $this->exporter->createBackup(false, SyncDefaults::excludedTables());
            $totalSize = (int) filesize($localZip);

            $sessionId = $this->initSession($peer);
            $chunks = $this->uploadChunks($peer, $sessionId, $localZip);
            $completion = $this->completeSession($peer, $sessionId);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->log->record($peer, 'push', 'success', $totalSize, $durationMs);

            return new PushResult(
                success: true,
                bytes: $totalSize,
                chunks: $chunks,
                durationMs: $durationMs,
                remoteImportMs: isset($completion['duration_ms']) ? (int) $completion['duration_ms'] : null,
                error: null,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->log->record($peer, 'push', 'failed', 0, $durationMs, $e->getMessage());

            return new PushResult(
                success: false,
                bytes: 0,
                chunks: 0,
                durationMs: $durationMs,
                remoteImportMs: null,
                error: $e->getMessage(),
            );
        } finally {
            if ($localZip !== null && is_file($localZip)) {
                @unlink($localZip);
            }
        }
    }

    private function initSession(Peer $peer): string
    {
        $response = $this->client->request($peer, 'POST', '/rhbp/v1/sync/import/init', []);

        if (!$response->isSuccess()) {
            throw new \RuntimeException(sprintf(
                'Import-Init fehlgeschlagen (HTTP %d): %s',
                $response->status,
                $response->error ?? $this->extractErrorMessage($response)
            ));
        }

        $data = $response->json();
        if (!is_array($data) || !isset($data['session_id']) || !is_string($data['session_id'])) {
            throw new \RuntimeException(sprintf(
                'Init-Response unvollstaendig (kein session_id). HTTP %d, Body-Preview: %s',
                $response->status,
                $this->previewBody($response->body)
            ));
        }

        return $data['session_id'];
    }

    private function uploadChunks(Peer $peer, string $sessionId, string $zipPath): int
    {
        $handle = fopen($zipPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('ZIP konnte nicht gelesen werden.');
        }

        try {
            $index = 0;
            while (!feof($handle)) {
                $chunk = (string) fread($handle, self::CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }

                $route = sprintf('/rhbp/v1/sync/import/%s/chunk/%d', $sessionId, $index);
                $response = $this->client->requestRaw(
                    $peer,
                    'PUT',
                    $route,
                    $chunk,
                    'application/octet-stream',
                    SyncClient::DOWNLOAD_TIMEOUT
                );

                if (!$response->isSuccess()) {
                    throw new \RuntimeException(sprintf(
                        'Chunk %d fehlgeschlagen (HTTP %d): %s',
                        $index,
                        $response->status,
                        $response->error ?? $this->extractErrorMessage($response)
                    ));
                }

                $index++;
            }

            return $index;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSession(Peer $peer, string $sessionId): array
    {
        $route = sprintf('/rhbp/v1/sync/import/%s/complete', $sessionId);
        $response = $this->client->request($peer, 'POST', $route, []);

        if (!$response->isSuccess()) {
            throw new \RuntimeException(sprintf(
                'Import-Complete fehlgeschlagen (HTTP %d): %s',
                $response->status,
                $response->error ?? $this->extractErrorMessage($response)
            ));
        }

        return $response->json() ?? [];
    }

    private function extractErrorMessage(SyncResponse $response): string
    {
        $data = $response->json();
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }
        return 'Unbekannter Fehler. Body-Preview: ' . $this->previewBody($response->body);
    }

    private function previewBody(string $body): string
    {
        $stripped = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        if (strlen($stripped) > 200) {
            return substr($stripped, 0, 200) . '…';
        }
        return $stripped;
    }
}

final class PushResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $bytes,
        public readonly int $chunks,
        public readonly int $durationMs,
        public readonly ?int $remoteImportMs,
        public readonly ?string $error,
    ) {
    }
}
