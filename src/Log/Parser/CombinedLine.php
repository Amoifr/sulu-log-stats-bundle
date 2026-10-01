<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Log\Parser;

use Amoifr\SuluLogStatsBundle\Log\LogEntry;

/**
 * The "combined" line shared by nginx and Apache, and what follows it:
 *
 *   203.0.113.7 - - [01/Oct/2026:10:00:00 +0200] "GET /fr/offres?page=2 HTTP/2.0" 200 5123 "https://example.com/" "Mozilla/5.0 ..."
 *
 * @internal
 */
final class CombinedLine
{
    private const PATTERN = '~^(?<ip>\S+) \S+ \S+ \[(?<time>[^\]]+)\] "(?<method>[A-Z]+) (?<uri>\S+)(?: [^"]*)?" (?<status>\d{3}) (?<bytes>\d+|-)(?: "(?<referer>[^"]*)" "(?<agent>[^"]*)")?~';

    /**
     * @return array{LogEntry, string}|null the entry, and the rest of the line after the combined fields
     */
    public static function parse(string $line): ?array
    {
        if (!preg_match(self::PATTERN, $line, $match)) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $match['time']);
        if (false === $time) {
            return null;
        }

        $entry = new LogEntry(
            time: RequestUri::utc($time),
            method: $match['method'],
            path: RequestUri::path($match['uri']),
            status: (int) $match['status'],
            bytes: '-' === $match['bytes'] ? null : (int) $match['bytes'],
            clientIp: $match['ip'],
            userAgent: self::optional($match['agent'] ?? null),
            referer: self::optional($match['referer'] ?? null),
        );

        return [$entry, substr($line, \strlen($match[0]))];
    }

    /**
     * The last field of the rest of the line, if it is a number.
     */
    public static function lastNumber(string $rest): ?float
    {
        return preg_match('~(?:^|\s)(\d+(?:\.\d+)?)\s*$~', $rest, $match) ? (float) $match[1] : null;
    }

    private static function optional(?string $value): ?string
    {
        return null === $value || '' === $value || '-' === $value ? null : $value;
    }
}
