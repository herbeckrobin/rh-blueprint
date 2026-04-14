<?php

declare(strict_types=1);

namespace RhBlueprint\Sync;

/**
 * Low-level HTTP-Client fuer ausgehende Sync-Requests zu anderen Peers.
 *
 * Signiert jeden Request mit HMAC-SHA256 (Shared-Secret aus Peer-Token),
 * leitet ihn ueber `wp_remote_*` an den Ziel-Peer und liefert das Ergebnis
 * als strukturiertes Response-Objekt.
 */
final class SyncClient
{
    public const DEFAULT_TIMEOUT = 60;
    public const DOWNLOAD_TIMEOUT = 600;

    public function __construct(private readonly HmacAuth $auth)
    {
    }

    /**
     * @param array<string, mixed>|null $bodyData
     */
    public function request(Peer $peer, string $method, string $route, ?array $bodyData = null): SyncResponse
    {
        $method = strtoupper($method);
        $body = $bodyData !== null ? (string) wp_json_encode($bodyData) : '';
        $path = HmacAuth::canonicalPath($route);

        $headers = $this->auth->buildHeaders($method, $path, $body, $peer);
        if ($bodyData !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $url = untrailingslashit($peer->url) . $path;

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => self::DEFAULT_TIMEOUT,
            'sslverify' => apply_filters('rh-blueprint/sync/sslverify', true, $peer),
        ];
        if ($body !== '') {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return new SyncResponse(0, '', [], $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $responseBody = (string) wp_remote_retrieve_body($response);
        /** @var array<string, string> $responseHeaders */
        $responseHeaders = (array) wp_remote_retrieve_headers($response);

        return new SyncResponse($status, $responseBody, $responseHeaders, null);
    }

    /**
     * Streamt einen HTTP-Response in eine lokale Datei.
     *
     * @throws \RuntimeException wenn der Download fehlschlaegt.
     */
    public function downloadTo(string $url, string $destination, ?Peer $peer = null): int
    {
        $args = [
            'timeout' => self::DOWNLOAD_TIMEOUT,
            'stream' => true,
            'filename' => $destination,
            'sslverify' => apply_filters('rh-blueprint/sync/sslverify', true, $peer),
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Download fehlgeschlagen: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status !== 200) {
            throw new \RuntimeException('Download fehlgeschlagen mit HTTP-Status ' . $status);
        }

        if (!is_file($destination) || filesize($destination) === 0) {
            throw new \RuntimeException('Download-Datei leer oder nicht vorhanden.');
        }

        return $status;
    }
}

final class SyncResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly ?string $error = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
