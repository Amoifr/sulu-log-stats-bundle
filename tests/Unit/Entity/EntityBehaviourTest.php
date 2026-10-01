<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Entity;

use Amoifr\SuluLogStatsBundle\Entity\DailyPage;
use Amoifr\SuluLogStatsBundle\Entity\DailyResponseTime;
use Amoifr\SuluLogStatsBundle\Entity\SourceCursor;
use Amoifr\SuluLogStatsBundle\Entity\VisitorSalt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntityBehaviourTest extends TestCase
{
    #[Test]
    #[DataProvider('provideDurations')]
    public function a_duration_goes_to_the_first_bucket_that_holds_it(float $durationMs, string $bucket): void
    {
        self::assertSame($bucket, DailyResponseTime::bucketFor($durationMs));
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function provideDurations(): iterable
    {
        yield 'zero' => [0.0, '25'];
        yield 'on a bound' => [50.0, '50'];
        yield 'just over a bound' => [50.001, '100'];
        yield 'the last bound' => [10_000.0, '10000'];
        yield 'beyond every bound' => [10_000.1, DailyResponseTime::OVERFLOW_BUCKET];
    }

    #[Test]
    public function histograms_add_up(): void
    {
        $responseTime = new DailyResponseTime(new \DateTimeImmutable('2026-10-01'));
        $responseTime->add(2, 60.0, ['25' => 1, '50' => 1]);
        $responseTime->add(3, 300.0, ['50' => 2, '200' => 1]);

        self::assertSame(5, $responseTime->getRequests());
        self::assertSame(360.0, $responseTime->getTotalMs());
        self::assertSame(['25' => 1, '50' => 3, '200' => 1], $responseTime->getHistogram());
    }

    #[Test]
    public function a_long_path_is_truncated_to_fit_its_column(): void
    {
        $page = new DailyPage(new \DateTimeImmutable('2026-10-01'), '/'.str_repeat('é', 300));

        self::assertSame(DailyPage::PATH_MAX_LENGTH, mb_strlen($page->getPath()));
    }

    #[Test]
    public function moving_a_cursor_without_a_new_entry_keeps_the_last_entry_time(): void
    {
        $cursor = new SourceCursor('access');
        $time = new \DateTimeImmutable('2026-10-01 10:00:00');
        $cursor->moveTo(100, 'hash', $time);
        $cursor->moveTo(150, 'other', null);

        self::assertSame(150, $cursor->getOffset());
        self::assertSame($time, $cursor->getLastEntryTime());
    }

    #[Test]
    public function each_day_gets_its_own_salt(): void
    {
        $first = new VisitorSalt(new \DateTimeImmutable('2026-10-01'));
        $second = new VisitorSalt(new \DateTimeImmutable('2026-10-02'));

        self::assertSame(64, \strlen($first->getSalt()));
        self::assertNotSame($first->getSalt(), $second->getSalt());
    }
}
