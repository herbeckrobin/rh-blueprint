<?php

declare(strict_types=1);

namespace RhBlueprint\Sync;

final class Peer
{
    /**
     * @param array{direction: string, timestamp: int, status: string, bytes: int}|null $lastSync
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $url,
        public readonly string $token,
        public readonly ?array $lastSync,
        public readonly int $createdAt,
    ) {
    }

    public static function create(string $name, string $url, ?string $token = null): self
    {
        return new self(
            id: wp_generate_uuid4(),
            name: $name,
            url: untrailingslashit(trim($url)),
            token: $token !== null && $token !== '' ? $token : self::generateToken(),
            lastSync: null,
            createdAt: time(),
        );
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{direction: string, timestamp: int, status: string, bytes: int}|null $lastSync */
        $lastSync = isset($data['last_sync']) && is_array($data['last_sync']) ? $data['last_sync'] : null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            token: (string) ($data['token'] ?? ''),
            lastSync: $lastSync,
            createdAt: (int) ($data['created_at'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'token' => $this->token,
            'last_sync' => $this->lastSync,
            'created_at' => $this->createdAt,
        ];
    }

    public function withLastSync(string $direction, string $status, int $bytes): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            url: $this->url,
            token: $this->token,
            lastSync: [
                'direction' => $direction,
                'status' => $status,
                'bytes' => $bytes,
                'timestamp' => time(),
            ],
            createdAt: $this->createdAt,
        );
    }

    public function withToken(string $token): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            url: $this->url,
            token: $token,
            lastSync: $this->lastSync,
            createdAt: $this->createdAt,
        );
    }

    public function maskedToken(): string
    {
        if (strlen($this->token) < 12) {
            return str_repeat('*', 8);
        }

        return substr($this->token, 0, 4) . str_repeat('•', 24) . substr($this->token, -4);
    }
}
