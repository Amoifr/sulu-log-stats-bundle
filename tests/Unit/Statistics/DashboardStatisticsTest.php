<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Statistics;

use Amoifr\SuluLogStatsBundle\Entity\DailyPage;
use Amoifr\SuluLogStatsBundle\Entity\DailyResponseTime;
use Amoifr\SuluLogStatsBundle\Entity\DailyVisitors;
use Amoifr\SuluLogStatsBundle\Entity\HourlyTraffic;
use Amoifr\SuluLogStatsBundle\Statistics\DashboardStatistics;
use Amoifr\SuluLogStatsBundle\Tests\TestEntityManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class DashboardStatisticsTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->em = TestEntityManager::create();
    }

    #[Test]
    public function it_lays_out_every_day_of_the_period_in_the_configured_time_zone(): void
    {
        // 22:00 UTC on Sept 30th is midnight in Paris: it belongs to Oct 1st there
        $this->traffic('2026-09-30 21:00:00', 200, requests: 1, pageViews: 1);
        $this->traffic('2026-09-30 22:00:00', 200, requests: 4, pageViews: 3, bytes: 4000);
        $this->traffic('2026-09-30 22:00:00', 404, requests: 2, botRequests: 1);
        $this->traffic('2026-10-02 08:00:00', 503, requests: 1);
        $this->traffic('2026-10-03 22:00:00', 200, requests: 9, pageViews: 9);
        $this->em->flush();

        $data = $this->statistics('Europe/Paris')->forPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-03'));

        self::assertSame(['Europe/Paris', '2026-10-01', '2026-10-03'], [$data['timezone'], $data['from'], $data['to']]);
        self::assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], array_column($data['days'], 'day'), 'a day without traffic is still listed');

        [$first, $second, $third] = $data['days'];
        self::assertSame([6, 3, 1, 4000], [$first['requests'], $first['pageViews'], $first['botRequests'], $first['bytes']]);
        self::assertSame([1 => 0, 2 => 4, 3 => 0, 4 => 2, 5 => 0], $first['statusClasses']);
        self::assertSame(1, $second['statusClasses'][5]);
        self::assertSame(0, $third['requests'], '22:00 UTC on Oct 3rd is already Oct 4th in Paris');

        self::assertSame(7, $data['totals']['requests']);
        self::assertSame([1 => 0, 2 => 4, 3 => 0, 4 => 2, 5 => 1], $data['totals']['statusClasses']);
        self::assertSame(3, $data['pageViewsByHourOfDay'][0], 'page views by local hour');
        self::assertSame(0, $data['pageViewsByHourOfDay'][23]);
    }

    #[Test]
    public function it_adds_up_visitors_and_response_times(): void
    {
        $this->visitors('2026-10-01', 10);
        $this->visitors('2026-10-02', 5);
        $this->visitors('2026-10-05', 99);
        $this->responseTimes('2026-10-01', 90, 900.0, ['25' => 90]);
        $this->responseTimes('2026-10-02', 10, 5000.0, ['200' => 5, '1000' => 5]);
        $this->em->flush();

        $data = $this->statistics()->forPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-02'));

        self::assertSame([10, 5], array_column($data['days'], 'visitors'));
        self::assertSame(15, $data['totals']['visitors']);

        self::assertSame(['requests' => 90, 'averageMs' => 10.0, 'p95' => ['boundMs' => 25, 'beyond' => false], 'histogram' => ['25' => 90]], $data['days'][0]['responseTime']);
        self::assertSame(100, $data['responseTime']['requests']);
        self::assertSame(59.0, $data['responseTime']['averageMs']);
        self::assertSame(['boundMs' => 200, 'beyond' => false], $data['responseTime']['p95']);
    }

    #[Test]
    public function it_has_no_response_time_without_requests(): void
    {
        $data = $this->statistics()->forPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-01'));

        self::assertNull($data['responseTime']);
        self::assertNull($data['days'][0]['responseTime']);
    }

    #[Test]
    public function it_ranks_the_pages_over_the_period(): void
    {
        $this->page('2026-10-01', '/fr', views: 5);
        $this->page('2026-10-02', '/fr', views: 5);
        $this->page('2026-10-02', '/fr/offres', views: 7, serverErrors: 2);
        $this->page('2026-10-02', '/fr/old', notFound: 3);
        $this->page('2026-09-30', '/fr/outside', views: 100);
        foreach (range(1, 25) as $i) {
            $this->page('2026-10-01', \sprintf('/fr/p%02d', $i), views: 1);
        }
        $this->em->flush();

        $data = $this->statistics()->forPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-02'));

        self::assertCount(DashboardStatistics::TOP_PAGES_LIMIT, $data['topPages']);
        self::assertSame([['path' => '/fr', 'count' => 10], ['path' => '/fr/offres', 'count' => 7], ['path' => '/fr/p01', 'count' => 1]], \array_slice($data['topPages'], 0, 3));
        self::assertSame([['path' => '/fr/old', 'count' => 3]], $data['topNotFound'], 'only paths that had a 404');
        self::assertSame([['path' => '/fr/offres', 'count' => 2]], $data['topServerErrors']);
    }

    private function statistics(string $timezone = 'UTC'): DashboardStatistics
    {
        return new DashboardStatistics($this->em, $timezone);
    }

    private function traffic(string $hour, int $status, int $requests = 0, int $pageViews = 0, int $botRequests = 0, int $bytes = 0): void
    {
        $row = new HourlyTraffic(new \DateTimeImmutable($hour, new \DateTimeZone('UTC')), $status);
        $row->add($requests, $pageViews, $botRequests, $bytes);
        $this->em->persist($row);
    }

    private function visitors(string $day, int $count): void
    {
        $row = new DailyVisitors(new \DateTimeImmutable($day));
        $row->addVisitors($count);
        $this->em->persist($row);
    }

    /**
     * @param array<string, int> $histogram
     */
    private function responseTimes(string $day, int $requests, float $totalMs, array $histogram): void
    {
        $row = new DailyResponseTime(new \DateTimeImmutable($day));
        $row->add($requests, $totalMs, $histogram);
        $this->em->persist($row);
    }

    private function page(string $day, string $path, int $views = 0, int $notFound = 0, int $serverErrors = 0): void
    {
        $row = new DailyPage(new \DateTimeImmutable($day), $path);
        $row->add($views, $notFound, $serverErrors);
        $this->em->persist($row);
    }
}
