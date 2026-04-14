<?php

declare(strict_types=1);

namespace RhBlueprint\Db;

final class Importer
{
    /** @var array<int, string> */
    private const ALLOWED_ENTRIES = ['db.sql', 'manifest.json'];

    public function __construct(
        private readonly BackupStorage $storage,
        private readonly SearchReplace $searchReplace
    ) {
    }

    /**
     * Importiert ein Backup aus einer ZIP-Datei im `rh-blueprint-data/backups/` Ordner.
     *
     * @return array<string, mixed> Manifest-Daten aus dem Backup
     * @throws \RuntimeException
     */
    public function importFromFile(string $zipPath): array
    {
        @set_time_limit(0);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        if (!is_readable($zipPath)) {
            throw new \RuntimeException('Backup-Datei nicht lesbar: ' . $zipPath);
        }

        $this->storage->ensureReady();

        $extractDir = $this->storage->reserveTempFile('import') . '.d';
        wp_mkdir_p($extractDir);

        $this->extractZipSafely($zipPath, $extractDir);

        $sqlFile = trailingslashit($extractDir) . 'db.sql';
        $manifestFile = trailingslashit($extractDir) . 'manifest.json';

        if (!is_readable($sqlFile) || !is_readable($manifestFile)) {
            $this->cleanupDir($extractDir);
            throw new \RuntimeException('Backup enthaelt weder db.sql noch manifest.json.');
        }

        /** @var array<string, mixed> $manifest */
        $manifest = (array) json_decode((string) file_get_contents($manifestFile), true);

        try {
            $this->importSqlFile($sqlFile);
            $this->rewriteUrls($manifest);
        } finally {
            $this->cleanupDir($extractDir);
        }

        return $manifest;
    }

    private function extractZipSafely(string $zipPath, string $destination): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive-Klasse nicht verfuegbar.');
        }

        $zip = new \ZipArchive();
        $status = $zip->open($zipPath);
        if ($status !== true) {
            throw new \RuntimeException('ZIP konnte nicht geoeffnet werden: ' . (string) $status);
        }

        $destination = trailingslashit($destination);
        $destReal = realpath($destination) ?: $destination;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $name = (string) $stat['name'];
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            // Zip-Slip: normalisieren und Path-Traversal verhindern
            $normalized = str_replace('\\', '/', $name);
            if (str_contains($normalized, '..')) {
                continue;
            }

            $baseName = basename($normalized);
            if (!in_array($baseName, self::ALLOWED_ENTRIES, true)) {
                // Uploads werden (falls vorhanden) separat in einer zukuenftigen Phase behandelt.
                continue;
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }

            $targetPath = $destination . $baseName;
            $out = fopen($targetPath, 'wb');
            if ($out === false) {
                fclose($stream);
                continue;
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);

            $realTarget = realpath($targetPath);
            if ($realTarget === false || !str_starts_with($realTarget, $destReal)) {
                @unlink($targetPath);
                continue;
            }
        }

        $zip->close();
    }

    private function importSqlFile(string $sqlFile): void
    {
        global $wpdb;

        $handle = fopen($sqlFile, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('SQL-Datei nicht lesbar.');
        }

        $buffer = '';
        while (!feof($handle)) {
            $chunk = fgets($handle, 65535);
            if ($chunk === false) {
                break;
            }

            $trimmed = ltrim($chunk);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            $buffer .= $chunk;

            if (str_ends_with(rtrim($chunk), ';')) {
                $wpdb->query($buffer);
                $buffer = '';
            }
        }

        fclose($handle);

        if (trim($buffer) !== '') {
            $wpdb->query($buffer);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function rewriteUrls(array $manifest): void
    {
        global $wpdb;

        $oldSiteUrl = isset($manifest['site_url']) ? (string) $manifest['site_url'] : '';
        $oldHomeUrl = isset($manifest['home_url']) ? (string) $manifest['home_url'] : '';
        $newSiteUrl = (string) get_site_url();
        $newHomeUrl = (string) get_home_url();

        $pairs = [];
        if ($oldSiteUrl !== '' && $oldSiteUrl !== $newSiteUrl) {
            $pairs[$oldSiteUrl] = $newSiteUrl;
        }
        if ($oldHomeUrl !== '' && $oldHomeUrl !== $newHomeUrl) {
            $pairs[$oldHomeUrl] = $newHomeUrl;
        }

        if ($pairs === []) {
            return;
        }

        $prefix = $wpdb->prefix;
        $like = str_replace('_', '\\_', $prefix) . '%';
        /** @var array<int, string> $tables */
        $tables = (array) $wpdb->get_col(
            $wpdb->prepare('SHOW TABLES LIKE %s', $like)
        );

        foreach ($tables as $table) {
            $this->rewriteTable((string) $table, $pairs);
        }
    }

    /**
     * @param array<string, string> $pairs
     */
    private function rewriteTable(string $table, array $pairs): void
    {
        global $wpdb;

        $tableEsc = '`' . str_replace('`', '``', $table) . '`';

        /** @var array<int, array<string, string>> $columns */
        $columns = (array) $wpdb->get_results("SHOW COLUMNS FROM {$tableEsc}", ARRAY_A);

        $textColumns = [];
        $primaryKey = null;
        foreach ($columns as $col) {
            $type = strtolower((string) ($col['Type'] ?? ''));
            $field = (string) ($col['Field'] ?? '');
            if ($field === '') {
                continue;
            }
            if (str_contains($type, 'char') || str_contains($type, 'text') || str_contains($type, 'blob')) {
                $textColumns[] = $field;
            }
            if (($col['Key'] ?? '') === 'PRI' && $primaryKey === null) {
                $primaryKey = $field;
            }
        }

        if ($textColumns === [] || $primaryKey === null) {
            return;
        }

        $selectCols = array_merge([$primaryKey], $textColumns);
        $selectList = implode(', ', array_map(
            static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`',
            $selectCols
        ));

        $offset = 0;
        $chunk = 200;
        while (true) {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare("SELECT {$selectList} FROM {$tableEsc} LIMIT %d OFFSET %d", $chunk, $offset),
                ARRAY_A
            );
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $updates = [];
                foreach ($textColumns as $col) {
                    $original = $row[$col] ?? null;
                    if (!is_string($original) || $original === '') {
                        continue;
                    }
                    $replaced = $original;
                    foreach ($pairs as $from => $to) {
                        $replaced = $this->searchReplace->recursiveReplace($replaced, $from, $to);
                    }
                    if ($replaced !== $original) {
                        $updates[$col] = $replaced;
                    }
                }

                if ($updates !== []) {
                    $wpdb->update($table, $updates, [$primaryKey => $row[$primaryKey]]);
                }
            }

            $offset += $chunk;
            if (count($rows) < $chunk) {
                break;
            }
        }
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob(trailingslashit($dir) . '*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
