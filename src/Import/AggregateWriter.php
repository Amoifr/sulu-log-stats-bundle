<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Import;

use Amoifr\SuluLogStatsBundle\Entity\DailyPage;
use Amoifr\SuluLogStatsBundle\Entity\DailyResponseTime;
use Amoifr\SuluLogStatsBundle\Entity\DailyVisitors;
use Amoifr\SuluLogStatsBundle\Entity\HourlyTraffic;
use Amoifr\SuluLogStatsBundle\Entity\VisitorDigest;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Adds the totals of an {@see Aggregator} to the stored aggregates. It does not flush: the caller
 * does, in the transaction that also moves the read cursor.
 */
final class AggregateWriter
{
    private const IN_CHUNK = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function write(Aggregator $aggregator): void
    {
        $this->writeTraffic($aggregator->getTraffic());
        $this->writePages($aggregator->getPages());
        $this->writeVisitors($aggregator->getVisitorDigests());
        $this->writeResponseTimes($aggregator->getResponseTimes());
    }

    /**
     * @param array<string, array<int, array{requests: int, pageViews: int, botRequests: int, bytes: int}>> $traffic
     */
    private function writeTraffic(array $traffic): void
    {
        if (!$traffic) {
            return;
        }

        $hours = array_keys($traffic);
        $existing = [];
        $rows = $this->em->createQueryBuilder()
            ->select('t')
            ->from(HourlyTraffic::class, 't')
            ->where('t.hour BETWEEN :from AND :to')
            ->setParameter('from', self::utc(min($hours)), Types::DATETIME_IMMUTABLE)
            ->setParameter('to', self::utc(max($hours)), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult();
        foreach ($rows as $row) {
            \assert($row instanceof HourlyTraffic);
            $existing[$row->getHour()->format('Y-m-d H:00:00')][$row->getStatus()] = $row;
        }

        foreach ($traffic as $hour => $statuses) {
            foreach ($statuses as $status => $counts) {
                $row = $existing[$hour][$status] ?? null;
                if (null === $row) {
                    $row = new HourlyTraffic(self::utc($hour), $status);
                    $this->em->persist($row);
                }
                $row->add($counts['requests'], $counts['pageViews'], $counts['botRequests'], $counts['bytes']);
            }
        }
    }

    /**
     * @param array<string, array<string, array{views: int, notFound: int, serverErrors: int}>> $pages
     */
    private function writePages(array $pages): void
    {
        foreach ($pages as $day => $paths) {
            // the paths are added up as stored, ie: truncated, so that two long paths share one row
            $totals = [];
            foreach ($paths as $path => $counts) {
                $stored = mb_substr((string) $path, 0, DailyPage::PATH_MAX_LENGTH);
                $total = $totals[$stored] ?? ['views' => 0, 'notFound' => 0, 'serverErrors' => 0];
                foreach ($counts as $field => $count) {
                    $total[$field] += $count;
                }
                $totals[$stored] = $total;
            }

            $existing = [];
            foreach (array_chunk(array_keys($totals), self::IN_CHUNK) as $chunk) {
                $rows = $this->em->createQueryBuilder()
                    ->select('p')
                    ->from(DailyPage::class, 'p')
                    ->where('p.day = :day')
                    ->andWhere('p.path IN (:paths)')
                    ->setParameter('day', self::utc($day), Types::DATE_IMMUTABLE)
                    ->setParameter('paths', array_map('strval', $chunk), ArrayParameterType::STRING)
                    ->getQuery()
                    ->getResult();
                foreach ($rows as $row) {
                    \assert($row instanceof DailyPage);
                    $existing[$row->getPath()] = $row;
                }
            }

            foreach ($totals as $path => $counts) {
                $row = $existing[(string) $path] ?? null;
                if (null === $row) {
                    $row = new DailyPage(self::utc($day), (string) $path);
                    $this->em->persist($row);
                }
                $row->add($counts['views'], $counts['notFound'], $counts['serverErrors']);
            }
        }
    }

    /**
     * @param array<string, list<string>> $digestsByDay
     */
    private function writeVisitors(array $digestsByDay): void
    {
        foreach ($digestsByDay as $day => $digests) {
            $date = self::utc($day);
            $visitors = $this->em->getRepository(DailyVisitors::class)->findOneBy(['day' => $date]);
            if ($visitors?->isClosed()) {
                continue;
            }

            $known = [];
            foreach (array_chunk($digests, self::IN_CHUNK) as $chunk) {
                $rows = $this->em->createQueryBuilder()
                    ->select('d.digest')
                    ->from(VisitorDigest::class, 'd')
                    ->where('d.day = :day')
                    ->andWhere('d.digest IN (:digests)')
                    ->setParameter('day', $date, Types::DATE_IMMUTABLE)
                    ->setParameter('digests', $chunk, ArrayParameterType::STRING)
                    ->getQuery()
                    ->getSingleColumnResult();
                foreach ($rows as $digest) {
                    $known[$digest] = true;
                }
            }

            $new = array_values(array_filter($digests, static fn(string $digest): bool => !isset($known[$digest])));
            if (!$new) {
                continue;
            }

            foreach ($new as $digest) {
                $this->em->persist(new VisitorDigest($date, $digest));
            }

            if (null === $visitors) {
                $visitors = new DailyVisitors($date);
                $this->em->persist($visitors);
            }
            $visitors->addVisitors(\count($new));
        }
    }

    /**
     * @param array<string, array{requests: int, totalMs: float, histogram: array<string, int>}> $responseTimes
     */
    private function writeResponseTimes(array $responseTimes): void
    {
        foreach ($responseTimes as $day => $times) {
            $date = self::utc($day);
            $row = $this->em->getRepository(DailyResponseTime::class)->findOneBy(['day' => $date]);
            if (null === $row) {
                $row = new DailyResponseTime($date);
                $this->em->persist($row);
            }
            $row->add($times['requests'], $times['totalMs'], $times['histogram']);
        }
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }
}
