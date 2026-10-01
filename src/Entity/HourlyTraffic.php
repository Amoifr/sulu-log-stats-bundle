<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Requests of one hour (UTC) answered with one status code.
 */
#[ORM\Entity]
#[ORM\Table(name: 'als_hourly_traffic')]
#[ORM\UniqueConstraint(name: 'als_hourly_traffic_hour_status', columns: ['hour', 'status'])]
class HourlyTraffic
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $requests = 0;

    #[ORM\Column]
    private int $pageViews = 0;

    #[ORM\Column]
    private int $botRequests = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private string $bytes = '0';

    public function __construct(
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $hour,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $status,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHour(): \DateTimeImmutable
    {
        return $this->hour;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getRequests(): int
    {
        return $this->requests;
    }

    public function getPageViews(): int
    {
        return $this->pageViews;
    }

    public function getBotRequests(): int
    {
        return $this->botRequests;
    }

    public function getBytes(): int
    {
        return (int) $this->bytes;
    }

    public function add(int $requests, int $pageViews, int $botRequests, int $bytes): void
    {
        $this->requests += $requests;
        $this->pageViews += $pageViews;
        $this->botRequests += $botRequests;
        $this->bytes = (string) ((int) $this->bytes + $bytes);
    }
}
