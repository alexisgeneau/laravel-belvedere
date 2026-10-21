<?php

declare(strict_types=1);

namespace Belvedere\Support;

use JsonException;

final readonly class Payload
{
    public const METADATA = 'belvedere';

    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}

    public static function fromJson(string $json): self
    {
        return new self((array) json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public static function tryFromJson(string $json): ?self
    {
        try {
            return self::fromJson($json);
        } catch (JsonException) {
            return null;
        }
    }

    public function uuid(): ?string
    {
        $uuid = $this->data['uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    public function displayName(): string
    {
        $name = $this->data['displayName'] ?? $this->data['job'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'unknown';
    }

    public function attempts(): int
    {
        return (int) ($this->data['attempts'] ?? 0);
    }

    public function maxTries(): ?int
    {
        return isset($this->data['maxTries']) ? (int) $this->data['maxTries'] : null;
    }

    public function timeout(): ?int
    {
        return isset($this->data['timeout']) ? (int) $this->data['timeout'] : null;
    }

    public function commandName(): ?string
    {
        $name = $this->data['data']['commandName'] ?? null;

        return is_string($name) ? $name : null;
    }

    /** @return list<string> */
    public function tags(): array
    {
        $tags = $this->metadata()['tags'] ?? [];

        return is_array($tags) ? array_values(array_filter($tags, is_string(...))) : [];
    }

    public function batchId(): ?string
    {
        $batch = $this->metadata()['batch'] ?? null;

        return is_string($batch) && $batch !== '' ? $batch : null;
    }

    /** @return array<string, mixed>|null */
    public function snapshot(): ?array
    {
        $snapshot = $this->metadata()['snapshot'] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }

    public function forRetry(): self
    {
        $data = $this->data;
        $data['attempts'] = 0;
        unset($data['retryUntil']);

        return new self($data);
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        $metadata = $this->data[self::METADATA] ?? null;

        return is_array($metadata) ? $metadata : [];
    }
}
