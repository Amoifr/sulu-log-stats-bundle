<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Import;

final class ResumePoint
{
    public function __construct(
        public readonly int $offset,
        /** True when the line read last is gone: the file was replaced or trimmed past it. */
        public readonly bool $lostTrack,
    ) {
    }
}
