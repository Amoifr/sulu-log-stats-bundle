<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Log\Parser;

use Amoifr\SuluLogStatsBundle\Log\LogEntry;

/**
 * The default access log format of nginx and Apache ("combined"). Fields appended after the user
 * agent are ignored.
 */
final class CombinedParser implements LogParserInterface
{
    public static function getFormat(): string
    {
        return 'combined';
    }

    public function parse(string $line): ?LogEntry
    {
        return CombinedLine::parse($line)[0] ?? null;
    }
}
