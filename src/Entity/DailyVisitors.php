<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Unique visitors of one day (UTC). Only this count is kept: the digests it comes from are deleted
 * once the day is closed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'als_daily_visitors')]
#[ORM\UniqueConstraint(name: 'als_daily_visitors_day', columns: ['day'])]
class DailyVisitors
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $visitors = 0;

    #[ORM\Column]
    private bool $closed = false;

    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $day,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getVisitors(): int
    {
        return $this->visitors;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function addVisitors(int $visitors): void
    {
        $this->visitors += $visitors;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
