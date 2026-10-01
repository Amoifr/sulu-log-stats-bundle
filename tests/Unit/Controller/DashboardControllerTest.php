<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Controller;

use Amoifr\SuluLogStatsBundle\Admin\LogStatsAdmin;
use Amoifr\SuluLogStatsBundle\Controller\DashboardController;
use Amoifr\SuluLogStatsBundle\Statistics\DashboardStatistics;
use Amoifr\SuluLogStatsBundle\Tests\TestEntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

#[RequiresPhpExtension('pdo_sqlite')]
final class DashboardControllerTest extends TestCase
{
    #[Test]
    public function it_defaults_to_the_last_thirty_days_in_the_configured_time_zone(): void
    {
        // already Oct 1st in Paris
        $data = $this->call([], now: '2026-09-30 23:30:00');

        self::assertSame(['2026-09-02', '2026-10-01'], [$data['from'], $data['to']]);
        self::assertCount(30, $data['days']);
    }

    #[Test]
    public function it_returns_the_requested_period(): void
    {
        $data = $this->call(['from' => '2026-02-27', 'to' => '2026-03-01']);

        self::assertSame(['2026-02-27', '2026-02-28', '2026-03-01'], array_column($data['days'], 'day'));
    }

    #[Test]
    public function a_period_can_end_today_with_its_start_defaulting_to_thirty_days_before(): void
    {
        $data = $this->call(['to' => '2026-01-30']);

        self::assertSame('2026-01-01', $data['from']);
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('provideInvalidPeriods')]
    public function it_rejects_an_invalid_period(array $query, string $message): void
    {
        $response = $this->controller()(new Request($query));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString($message, (string) $response->getContent());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function provideInvalidPeriods(): iterable
    {
        yield 'not a date' => [['from' => 'yesterday'], 'YYYY-MM-DD'];
        yield 'an impossible date' => [['from' => '2026-02-30'], 'YYYY-MM-DD'];
        yield 'a date with a time' => [['to' => '2026-10-01 10:00'], 'YYYY-MM-DD'];
        yield 'start after end' => [['from' => '2026-10-02', 'to' => '2026-10-01'], 'must not be after'];
        yield 'too long' => [['from' => '2025-01-01', 'to' => '2026-01-02'], 'at most 366 days'];
    }

    #[Test]
    public function a_full_leap_year_is_allowed(): void
    {
        $data = $this->call(['from' => '2028-01-01', 'to' => '2028-12-31']);

        self::assertCount(366, $data['days']);
    }

    #[Test]
    public function it_requires_the_log_statistics_permission(): void
    {
        self::assertSame(LogStatsAdmin::SECURITY_CONTEXT, $this->controller()->getSecurityContext());
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function call(array $query, string $now = '2026-10-01 12:00:00'): array
    {
        $response = $this->controller($now)(new Request($query));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function controller(string $now = '2026-10-01 12:00:00'): DashboardController
    {
        return new DashboardController(
            new DashboardStatistics(TestEntityManager::create(), 'Europe/Paris'),
            new MockClock($now, 'UTC'),
        );
    }
}
