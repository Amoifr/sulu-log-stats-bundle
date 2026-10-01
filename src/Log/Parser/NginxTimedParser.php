<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Log\Parser;

use Amoifr\SuluLogStatsBundle\Log\LogEntry;

/**
 * The "combined" format of nginx followed by the request time in seconds, as the last field:
 *
 *   log_format timed '$remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent" $request_time';
 */
final class NginxTimedParser implements LogParserInterface
{
    public static function getFormat(): string
    {
        return 'nginx_timed';
    }

    public function parse(string $line): ?LogEntry
    {
        $parsed = CombinedLine::parse($line);
        if (null === $parsed) {
            return null;
        }

        [$entry, $rest] = $parsed;
        $seconds = CombinedLine::lastNumber($rest);

        return null === $seconds ? $entry : $entry->withDuration($seconds * 1000);
    }
}
