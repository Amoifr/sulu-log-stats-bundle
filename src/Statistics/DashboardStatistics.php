<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Statistics;

use Amoifr\SuluLogStatsBundle\Entity\DailyPage;
use Amoifr\SuluLogStatsBundle\Entity\DailyResponseTime;
use Amoifr\SuluLogStatsBundle\Entity\DailyVisitors;
use Amoifr\SuluLogStatsBundle\Entity\HourlyTraffic;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the aggregates of a period of days, for the admin dashboard.
 */
final class DashboardStatistics
{
    public const TOP_PAGES_LIMIT = 20;
    private const STATUS_CLASSES = [1, 2, 3, 4, 5];

    private readonly \DateTimeZone $timezone;
    private readonly \DateTimeZone $utc;

    public function __construct(
        private readonly EntityManagerInterface $em,
        string $timezone = 'UTC',
    ) {
        $this->timezone = new \DateTimeZone($timezone);
        $this->utc = new \DateTimeZone('UTC');
    }

    public function getTimezone(): \DateTimeZone
    {
        return $this->timezone;
    }

    /**
     * @param \DateTimeImmutable $from first day of the period, in the configured time zone
     * @param \DateTimeImmutable $to   last day of the period, included
     *
     * @return array<string, mixed>
     */
    public function forPeriod(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = new \DateTimeImmutable($from->format('Y-m-d'), $this->timezone);
        $to = new \DateTimeImmutable($to->format('Y-m-d'), $this->timezone);

        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = [
                'day' => $day->format('Y-m-d'),
                'requests' => 0,
                'pageViews' => 0,
                'botRequests' => 0,
                'bytes' => 0,
                'visitors' => 0,
                'statusClasses' => array_fill_keys(self::STATUS_CLASSES, 0),
                'responseTime' => null,
            ];
        }
        $hoursOfDay = array_fill(0, 24, 0);

        $this->addTraffic($days, $hoursOfDay, $from, $to);
        $this->addVisitors($days, $from, $to);
        $histogram = $this->addResponseTimes($days, $from, $to);

        $totals = ['requests' => 0, 'pageViews' => 0, 'botRequests' => 0, 'bytes' => 0, 'visitors' => 0, 'statusClasses' => array_fill_keys(self::STATUS_CLASSES, 0)];
        foreach ($days as $day) {
            foreach (['requests', 'pageViews', 'botRequests', 'bytes', 'visitors'] as $field) {
                $totals[$field] += $day[$field];
            }
            foreach ($day['statusClasses'] as $class => $count) {
                $totals['statusClasses'][$class] += $count;
            }
        }

        return [
            'timezone' => $this->timezone->getName(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'totals' => $totals,
            'days' => array_values($days),
            'pageViewsByHourOfDay' => $hoursOfDay,
            'responseTime' => $this->responseTime($histogram['requests'], $histogram['totalMs'], $histogram['buckets']),
            'topPages' => $this->topPages('views', $from, $to),
            'topNotFound' => $this->topPages('notFound', $from, $to),
            'topServerErrors' => $this->topPages('serverErrors', $from, $to),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $days
     * @param array<int, int>                     $hoursOfDay
     */
    private function addTraffic(array &$days, array &$hoursOfDay, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $rows = $this->em->createQueryBuilder()
            ->select('t.hour, t.status, t.requests, t.pageViews, t.botRequests, t.bytes')
            ->from(HourlyTraffic::class, 't')
            ->where('t.hour >= :start')
            ->andWhere('t.hour < :end')
            ->setParameter('start', $from->setTimezone($this->utc), Types::DATETIME_IMMUTABLE)
            ->setParameter('end', $to->modify('+1 day')->setTimezone($this->utc), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            // stored as a UTC wall time, but read back in the PHP default time zone
            $hour = new \DateTimeImmutable($row['hour']->format('Y-m-d H:i:s'), $this->utc);
            $local = $hour->setTimezone($this->timezone);
            $key = $local->format('Y-m-d');
            if (!isset($days[$key])) {
                continue;
            }

            $days[$key]['requests'] += $row['requests'];
            $days[$key]['pageViews'] += $row['pageViews'];
            $days[$key]['botRequests'] += $row['botRequests'];
            $days[$key]['bytes'] += (int) $row['bytes'];
            $class = intdiv((int) $row['status'], 100);
            if (isset($days[$key]['statusClasses'][$class])) {
                $days[$key]['statusClasses'][$class] += $row['requests'];
            }
            $hoursOfDay[(int) $local->format('G')] += $row['pageViews'];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $days
     */
    private function addVisitors(array &$days, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        foreach ($this->dailyRows(DailyVisitors::class, $from, $to) as $row) {
            \assert($row instanceof DailyVisitors);
            $key = $row->getDay()->format('Y-m-d');
            if (isset($days[$key])) {
                $days[$key]['visitors'] = $row->getVisitors();
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $days
     *
     * @return array{requests: int, totalMs: float, buckets: array<string, int>}
     */
    private function addResponseTimes(array &$days, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $requests = 0;
        $totalMs = 0.0;
        $histograms = [];
        foreach ($this->dailyRows(DailyResponseTime::class, $from, $to) as $row) {
            \assert($row instanceof DailyResponseTime);
            $key = $row->getDay()->format('Y-m-d');
            if (!isset($days[$key])) {
                continue;
            }

            $days[$key]['responseTime'] = $this->responseTime($row->getRequests(), $row->getTotalMs(), $row->getHistogram());
            $requests += $row->getRequests();
            $totalMs += $row->getTotalMs();
            $histograms[] = $row->getHistogram();
        }

        return ['requests' => $requests, 'totalMs' => $totalMs, 'buckets' => Histogram::merge(...$histograms)];
    }

    /**
     * @param array<string, int> $histogram
     *
     * @return array{requests: int, averageMs: float, p95: array{boundMs: int, beyond: bool}|null, histogram: array<string, int>}|null
     */
    private function responseTime(int $requests, float $totalMs, array $histogram): ?array
    {
        if (0 === $requests) {
            return null;
        }

        return [
            'requests' => $requests,
            'averageMs' => round($totalMs / $requests, 1),
            'p95' => Histogram::percentile($histogram, 95),
            'histogram' => Histogram::merge($histogram),
        ];
    }

    /**
     * @param 'views'|'notFound'|'serverErrors' $field
     *
     * @return list<array{path: string, count: int}>
     */
    private function topPages(string $field, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select("p.path AS path, SUM(p.{$field}) AS total")
            ->from(DailyPage::class, 'p')
            ->where('p.day BETWEEN :from AND :to')
            ->groupBy('p.path')
            ->having("SUM(p.{$field}) > 0")
            ->orderBy('total', 'DESC')
            ->addOrderBy('p.path', 'ASC')
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->setMaxResults(self::TOP_PAGES_LIMIT)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $row): array => ['path' => (string) $row['path'], 'count' => (int) $row['total']], $rows);
    }

    /**
     * @param class-string<DailyVisitors|DailyResponseTime> $class
     *
     * @return list<object>
     */
    private function dailyRows(string $class, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->em->createQueryBuilder()
            ->select('d')
            ->from($class, 'd')
            ->where('d.day BETWEEN :from AND :to')
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }
}
