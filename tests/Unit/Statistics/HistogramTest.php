<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Statistics;

use Amoifr\SuluLogStatsBundle\Entity\DailyResponseTime;
use Amoifr\SuluLogStatsBundle\Statistics\Histogram;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HistogramTest extends TestCase
{
    #[Test]
    public function it_merges_in_bucket_order_and_drops_empty_buckets(): void
    {
        self::assertSame(
            ['25' => 1, '100' => 3, DailyResponseTime::OVERFLOW_BUCKET => 1],
            Histogram::merge([DailyResponseTime::OVERFLOW_BUCKET => 1, '100' => 2], ['25' => 1, '100' => 1, '50' => 0]),
        );
    }

    #[Test]
    public function the_percentile_is_the_bound_of_the_bucket_holding_it(): void
    {
        $histogram = ['25' => 90, '200' => 5, '1000' => 5];

        self::assertSame(['boundMs' => 25, 'beyond' => false], Histogram::percentile($histogram, 50));
        self::assertSame(['boundMs' => 25, 'beyond' => false], Histogram::percentile($histogram, 90));
        self::assertSame(['boundMs' => 200, 'beyond' => false], Histogram::percentile($histogram, 95));
        self::assertSame(['boundMs' => 1000, 'beyond' => false], Histogram::percentile($histogram, 96));
    }

    #[Test]
    public function a_percentile_in_the_overflow_bucket_is_beyond_the_last_bound(): void
    {
        self::assertSame(['boundMs' => 10000, 'beyond' => true], Histogram::percentile(['25' => 1, DailyResponseTime::OVERFLOW_BUCKET => 99], 95));
    }

    #[Test]
    public function there_is_no_percentile_without_requests(): void
    {
        self::assertNull(Histogram::percentile([], 95));
    }
}
