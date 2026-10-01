<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Log\Parser;

use Amoifr\SuluLogStatsBundle\Log\LogEntry;

/**
 * The "combined" format of Apache followed by the time taken to serve the request, in microseconds
 * (%D), as the last field:
 *
 *   LogFormat "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\" %D" timed
 */
final class ApacheTimedParser implements LogParserInterface
{
    public static function getFormat(): string
    {
        return 'apache_timed';
    }

    public function parse(string $line): ?LogEntry
    {
        $parsed = CombinedLine::parse($line);
        if (null === $parsed) {
            return null;
        }

        [$entry, $rest] = $parsed;
        $microseconds = CombinedLine::lastNumber($rest);

        return null === $microseconds ? $entry : $entry->withDuration($microseconds / 1000);
    }
}
