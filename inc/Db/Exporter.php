<?php

declare(strict_types=1);

namespace RhBlueprint\Db;

final class Exporter
{
    public const CHUNK_SIZE = 500;

    public function __construct(private readonly BackupStorage $storage)
    {
    }

    /**
     * Erstellt ein ZIP mit db.sql + manifest.json (+ optional uploads/).
     *
     * @return string Absoluter Pfad zur ZIP-Datei.
     * @throws \RuntimeException bei Fehlern
     */
    public function createBackup(bool $includeUploads = false): string
    {
        @set_time_limit(0);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $this->storage->ensureReady();

        $sqlFile = $this->storage->reserveTempFile('db');
        $this->writeSqlDump($sqlFile);

        $manifest = $this->buildManifest($sqlFile, $includeUploads);
        $manifestFile = $this->storage->reserveTempFile('manifest');
        file_put_contents($manifestFile, (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $zipName = sprintf('backup-%s.zip', gmdate('Ymd-His'));
        $zipPath = trailingslashit($this->storage->backupsPath()) . $zipName;

        $this->buildZip($zipPath, $sqlFile, $manifestFile, $includeUploads);

        @unlink($sqlFile);
        @unlink($manifestFile);

        return $zipPath;
    }

    private function writeSqlDump(string $targetFile): void
    {
        global $wpdb;

        $handle = fopen($targetFile, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Konnte SQL-Dump-Datei nicht oeffnen.');
        }

        $header = sprintf(
            "-- RH Blueprint DB Export\n-- Date: %s\n-- Site: %s\n-- Prefix: %s\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n",
            gmdate('c'),
            (string) get_site_url(),
            $wpdb->prefix
        );
        fwrite($handle, $header);

        $prefix = $wpdb->prefix;
        $like = str_replace('_', '\\_', $prefix) . '%';
        /** @var array<int, string> $tables */
        $tables = (array) $wpdb->get_col(
            $wpdb->prepare('SHOW TABLES LIKE %s', $like)
        );

        foreach ($tables as $table) {
            $this->dumpTable($handle, (string) $table);
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    /**
     * @param resource $handle
     */
    private function dumpTable($handle, string $table): void
    {
        global $wpdb;

        $tableEsc = $this->quoteIdentifier($table);

        fwrite($handle, sprintf("\n-- Table: %s\n", $table));
        fwrite($handle, sprintf("DROP TABLE IF EXISTS %s;\n", $tableEsc));

        /** @var array<int, mixed>|null $create */
        $create = $wpdb->get_row("SHOW CREATE TABLE {$tableEsc}", ARRAY_N);
        if (is_array($create) && isset($create[1]) && is_string($create[1])) {
            fwrite($handle, $create[1] . ";\n\n");
        }

        $offset = 0;
        while (true) {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$tableEsc} LIMIT %d OFFSET %d",
                    self::CHUNK_SIZE,
                    $offset
                ),
                ARRAY_A
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                fwrite($handle, $this->buildInsert($table, $row) . "\n");
            }

            $offset += self::CHUNK_SIZE;
            if (count($rows) < self::CHUNK_SIZE) {
                break;
            }
        }

        fwrite($handle, "\n");
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildInsert(string $table, array $row): string
    {
        global $wpdb;

        $columns = array_map([$this, 'quoteIdentifier'], array_keys($row));
        $values = [];
        foreach ($row as $value) {
            if ($value === null) {
                $values[] = 'NULL';
            } else {
                $values[] = "'" . $wpdb->_real_escape((string) $value) . "'";
            }
        }

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s);',
            $this->quoteIdentifier($table),
            implode(', ', $columns),
            implode(', ', $values)
        );
    }

    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(string $sqlFile, bool $includeUploads): array
    {
        global $wpdb;

        return [
            'plugin_version' => defined('RHBP_VERSION') ? RHBP_VERSION : '0.0.0',
            'wp_version' => get_bloginfo('version'),
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'db_prefix' => $wpdb->prefix,
            'db_size' => filesize($sqlFile) ?: 0,
            'includes_uploads' => $includeUploads,
            'created_at' => gmdate('c'),
        ];
    }

    private function buildZip(string $zipPath, string $sqlFile, string $manifestFile, bool $includeUploads): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive-Klasse nicht verfuegbar. Bitte ZIP-PHP-Extension aktivieren.');
        }

        $zip = new \ZipArchive();
        $status = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($status !== true) {
            throw new \RuntimeException('Konnte ZIP nicht erstellen: ' . (string) $status);
        }

        $zip->addFile($sqlFile, 'db.sql');
        $zip->addFile($manifestFile, 'manifest.json');

        if ($includeUploads) {
            $uploads = wp_upload_dir();
            $uploadBase = (string) $uploads['basedir'];
            if ($uploadBase !== '' && is_dir($uploadBase)) {
                $this->addDirectoryToZip($zip, $uploadBase, 'uploads');
            }
        }

        $zip->close();
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $dirPath, string $zipPrefix): void
    {
        $dirPath = rtrim($dirPath, DIRECTORY_SEPARATOR);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $real = $file->getRealPath();
            if ($real === false) {
                continue;
            }

            $rel = ltrim(str_replace($dirPath, '', $real), DIRECTORY_SEPARATOR);
            $zip->addFile($real, trailingslashit($zipPrefix) . $rel);
        }
    }
}
