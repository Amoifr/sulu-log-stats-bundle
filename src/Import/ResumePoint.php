<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Import;

final class ResumePoint
{
    public function __construct(
        /** Where to read the log file from, once the rotated file, if any, is finished. */
        public readonly int $offset,
        /** True when the line read last is gone: the file was replaced or trimmed past it. */
        public readonly bool $lostTrack,
        /** The rotated file still holding lines not read yet: the log was rotated since the previous import. */
        public readonly ?string $rotatedPath = null,
        public readonly int $rotatedOffset = 0,
    ) {
    }
}
