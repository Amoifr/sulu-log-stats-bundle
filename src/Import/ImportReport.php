<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Import;

final class ImportReport
{
    public int $linesRead = 0;
    public int $entriesImported = 0;
    public int $unreadableLines = 0;

    /** Lines older than the last entry imported, skipped after losing track of the file. */
    public int $skippedLines = 0;

    public bool $fileMissing = false;

    /** The line read last is gone: the file was replaced, or trimmed past it. */
    public bool $lostTrack = false;

    /** The rotated file whose remaining lines were read first, if the log was rotated since the previous import. */
    public ?string $rotatedPath = null;

    public function __construct(
        public readonly string $source,
        public readonly string $path,
    ) {
    }
}
