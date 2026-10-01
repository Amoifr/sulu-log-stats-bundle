<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Log\Parser;

use Amoifr\SuluLogStatsBundle\Log\LogEntry;

/**
 * The "combined" format shared by nginx and Apache:
 *
 *   203.0.113.7 - - [01/Oct/2026:10:00:00 +0200] "GET /fr/offres?page=2 HTTP/2.0" 200 5123 "https://example.com/" "Mozilla/5.0 ..."
 *
 * Fields appended after the user agent (eg: a request time) are ignored.
 */
final class NginxCombinedParser implements LogParserInterface
{
    private const PATTERN = '~^(?<ip>\S+) \S+ \S+ \[(?<time>[^\]]+)\] "(?<method>[A-Z]+) (?<uri>\S+)(?: [^"]*)?" (?<status>\d{3}) (?<bytes>\d+|-)(?: "(?<referer>[^"]*)" "(?<agent>[^"]*)")?~';

    public static function getFormat(): string
    {
        return 'combined';
    }

    public function parse(string $line): ?LogEntry
    {
        if (!preg_match(self::PATTERN, $line, $match)) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $match['time']);
        if (false === $time) {
            return null;
        }

        return new LogEntry(
            time: RequestUri::utc($time),
            method: $match['method'],
            path: RequestUri::path($match['uri']),
            status: (int) $match['status'],
            bytes: '-' === $match['bytes'] ? null : (int) $match['bytes'],
            clientIp: $match['ip'],
            userAgent: self::optional($match['agent'] ?? null),
            referer: self::optional($match['referer'] ?? null),
        );
    }

    private static function optional(?string $value): ?string
    {
        return null === $value || '' === $value || '-' === $value ? null : $value;
    }
}
