<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Connector\Upsun;

use Amoifr\SuluLogStatsBundle\Log\LogEntry;
use Amoifr\SuluLogStatsBundle\Log\Parser\LogParserInterface;
use Amoifr\SuluLogStatsBundle\Log\Parser\RequestUri;

/**
 * Upsun connector: the PHP-FPM access log of an Upsun (Platform.sh) application container, written with the format
 * "%{%FT%TZ}t %m %s %{mili}d ms %{kilo}M kB %C%% %{REQUEST_URI}e":
 *
 *   2026-10-01T08:00:00Z GET 200 37.215 ms 2048 kB 12.34% /fr/offres?page=2
 */
final class UpsunPhpAccessParser implements LogParserInterface
{
    private const PATTERN = '~^(?<time>\S+) (?<method>[A-Z]+) (?<status>\d{3}) (?<duration>\d+(?:\.\d+)?) ms \d+ kB [\d.]+% (?<uri>\S+)~';

    public static function getFormat(): string
    {
        return 'upsun_php_access';
    }

    public function parse(string $line): ?LogEntry
    {
        if (!preg_match(self::PATTERN, $line, $match)) {
            return null;
        }

        try {
            $time = new \DateTimeImmutable($match['time']);
        } catch (\Exception) {
            return null;
        }

        return new LogEntry(
            time: RequestUri::utc($time),
            method: $match['method'],
            path: RequestUri::path($match['uri']),
            status: (int) $match['status'],
            durationMs: (float) $match['duration'],
        );
    }
}
