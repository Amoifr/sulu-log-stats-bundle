<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Log;

/**
 * One request read from a log line. Fields a given log format does not carry are null.
 */
final class LogEntry
{
    public function __construct(
        /** Always in UTC. */
        public readonly \DateTimeImmutable $time,
        public readonly string $method,
        /** The request path, without its query string. */
        public readonly string $path,
        public readonly int $status,
        public readonly ?int $bytes = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $referer = null,
        public readonly ?float $durationMs = null,
    ) {
    }

    public function withDuration(float $durationMs): self
    {
        return new self($this->time, $this->method, $this->path, $this->status, $this->bytes, $this->clientIp, $this->userAgent, $this->referer, $durationMs);
    }

    public function statusClass(): int
    {
        return intdiv($this->status, 100);
    }
}
