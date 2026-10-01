<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where the import stopped in a log file. The hash of the last line read lets the next run tell a
 * file that only grew from one that was trimmed or replaced, since its offset alone can't.
 */
#[ORM\Entity]
#[ORM\Table(name: 'als_source_cursor')]
class SourceCursor
{
    // "offset" is a reserved word in PostgreSQL
    #[ORM\Column(name: 'read_offset', type: Types::BIGINT)]
    private string $offset = '0';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $lastLineHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastEntryTime = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        private string $source,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getOffset(): int
    {
        return (int) $this->offset;
    }

    public function getLastLineHash(): ?string
    {
        return $this->lastLineHash;
    }

    public function getLastEntryTime(): ?\DateTimeImmutable
    {
        return $this->lastEntryTime;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function moveTo(int $offset, ?string $lastLineHash, ?\DateTimeImmutable $lastEntryTime): void
    {
        $this->offset = (string) $offset;
        $this->lastLineHash = $lastLineHash;
        $this->lastEntryTime = $lastEntryTime ?? $this->lastEntryTime;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
