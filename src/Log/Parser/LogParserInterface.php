<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Log\Parser;

use Amoifr\SuluLogStatsBundle\Log\LogEntry;

interface LogParserInterface
{
    /**
     * The name used in the bundle configuration to pick this parser for a source.
     */
    public static function getFormat(): string;

    /**
     * Returns null for a line that is not a request this parser can read (blank line, malformed request).
     */
    public function parse(string $line): ?LogEntry;
}
