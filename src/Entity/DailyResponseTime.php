<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Response times of one day (UTC), kept as a histogram: unlike a percentile, histograms of several
 * days can be added up, so the percentile of any period can be computed afterwards.
 */
#[ORM\Entity]
#[ORM\Table(name: 'als_daily_response_time')]
#[ORM\UniqueConstraint(name: 'als_daily_response_time_day', columns: ['day'])]
class DailyResponseTime
{
    /**
     * Upper bounds of the histogram buckets, in milliseconds. Slower requests go to the overflow bucket.
     */
    public const BUCKETS = [25, 50, 100, 200, 300, 500, 750, 1000, 1500, 2000, 3000, 5000, 10000];
    public const OVERFLOW_BUCKET = 'over';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $requests = 0;

    #[ORM\Column]
    private float $totalMs = 0.0;

    /**
     * @var array<string, int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $histogram = [];

    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $day,
    ) {
    }

    public static function bucketFor(float $durationMs): string
    {
        foreach (self::BUCKETS as $upperBound) {
            if ($durationMs <= $upperBound) {
                return (string) $upperBound;
            }
        }

        return self::OVERFLOW_BUCKET;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getRequests(): int
    {
        return $this->requests;
    }

    public function getTotalMs(): float
    {
        return $this->totalMs;
    }

    /**
     * @return array<string, int> request count per bucket, keyed by the bucket upper bound
     */
    public function getHistogram(): array
    {
        return $this->histogram;
    }

    /**
     * @param array<string, int> $histogram
     */
    public function add(int $requests, float $totalMs, array $histogram): void
    {
        $this->requests += $requests;
        $this->totalMs += $totalMs;

        // a new array, so that Doctrine sees the change of a JSON column
        $merged = $this->histogram;
        foreach ($histogram as $bucket => $count) {
            $merged[(string) $bucket] = ($merged[(string) $bucket] ?? 0) + $count;
        }
        $this->histogram = $merged;
    }
}
