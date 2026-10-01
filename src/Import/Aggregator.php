<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Import;

use Amoifr\SuluLogStatsBundle\Entity\DailyResponseTime;
use Amoifr\SuluLogStatsBundle\Log\LogEntry;

/**
 * Adds up log entries in memory, until {@see AggregateWriter} writes the totals.
 *
 * An entry carrying a client address (an access log) counts towards traffic, pages and visitors; an
 * entry carrying a duration (a PHP access log) towards response times. The two logs describe the same
 * requests, so neither is counted twice when both are imported.
 */
final class Aggregator
{
    /** @var array<string, array<int, array{requests: int, pageViews: int, botRequests: int, bytes: int}>> by UTC hour, then status */
    private array $traffic = [];

    /** @var array<string, array<string, array{views: int, notFound: int, serverErrors: int}>> by UTC day, then path */
    private array $pages = [];

    /** @var array<string, array<string, true>> visitor digests by UTC day */
    private array $visitors = [];

    /** @var array<string, array{requests: int, totalMs: float, histogram: array<string, int>}> by UTC day */
    private array $responseTimes = [];

    /**
     * @param \Closure(\DateTimeImmutable): ?string $visitorSalt the salt of a day's visitor digests, or null if that day is closed
     */
    public function __construct(
        private readonly RequestClassifier $classifier,
        private readonly \Closure $visitorSalt,
    ) {
    }

    public function add(LogEntry $entry): void
    {
        $day = $entry->time->format('Y-m-d');

        if (null !== $entry->clientIp) {
            $this->addTraffic($entry, $day);
        }

        if (null !== $entry->durationMs) {
            $times = $this->responseTimes[$day] ?? ['requests' => 0, 'totalMs' => 0.0, 'histogram' => []];
            ++$times['requests'];
            $times['totalMs'] += $entry->durationMs;
            $bucket = DailyResponseTime::bucketFor($entry->durationMs);
            $times['histogram'][$bucket] = ($times['histogram'][$bucket] ?? 0) + 1;
            $this->responseTimes[$day] = $times;
        }
    }

    public function isEmpty(): bool
    {
        return !$this->traffic && !$this->pages && !$this->visitors && !$this->responseTimes;
    }

    /**
     * @return array<string, array<int, array{requests: int, pageViews: int, botRequests: int, bytes: int}>>
     */
    public function getTraffic(): array
    {
        return $this->traffic;
    }

    /**
     * @return array<string, array<string, array{views: int, notFound: int, serverErrors: int}>>
     */
    public function getPages(): array
    {
        return $this->pages;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getVisitorDigests(): array
    {
        return array_map(static fn(array $digests): array => array_keys($digests), $this->visitors);
    }

    /**
     * @return array<string, array{requests: int, totalMs: float, histogram: array<string, int>}>
     */
    public function getResponseTimes(): array
    {
        return $this->responseTimes;
    }

    public function reset(): void
    {
        $this->traffic = $this->pages = $this->visitors = $this->responseTimes = [];
    }

    private function addTraffic(LogEntry $entry, string $day): void
    {
        $isBot = $this->classifier->isBot($entry);
        $isPageView = $this->classifier->isPageView($entry);

        $hour = $entry->time->format('Y-m-d H:00:00');
        $counts = $this->traffic[$hour][$entry->status] ?? ['requests' => 0, 'pageViews' => 0, 'botRequests' => 0, 'bytes' => 0];
        ++$counts['requests'];
        $counts['pageViews'] += (int) $isPageView;
        $counts['botRequests'] += (int) $isBot;
        $counts['bytes'] += $entry->bytes ?? 0;
        $this->traffic[$hour][$entry->status] = $counts;

        // bots are left out of pages: scanners would fill the table with made up paths
        $isNotFound = 404 === $entry->status;
        $isServerError = 5 === $entry->statusClass();
        if (!$isBot && ($isPageView || (($isNotFound || $isServerError) && $this->classifier->isPagePath($entry->path)))) {
            $page = $this->pages[$day][$entry->path] ?? ['views' => 0, 'notFound' => 0, 'serverErrors' => 0];
            $page['views'] += (int) $isPageView;
            $page['notFound'] += (int) $isNotFound;
            $page['serverErrors'] += (int) $isServerError;
            $this->pages[$day][$entry->path] = $page;
        }

        if ($isPageView && null !== $salt = ($this->visitorSalt)($entry->time->setTime(0, 0))) {
            $this->visitors[$day][hash('sha256', $salt.'|'.$entry->clientIp.'|'.$entry->userAgent)] = true;
        }
    }
}
