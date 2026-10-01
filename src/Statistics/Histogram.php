<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Statistics;

use Amoifr\SuluLogStatsBundle\Entity\DailyResponseTime;

/**
 * @internal
 */
final class Histogram
{
    /**
     * @param array<string, int> ...$histograms
     *
     * @return array<string, int> the request count per bucket, in bucket order
     */
    public static function merge(array ...$histograms): array
    {
        $merged = [];
        foreach ([...array_map('strval', DailyResponseTime::BUCKETS), DailyResponseTime::OVERFLOW_BUCKET] as $bucket) {
            $count = 0;
            foreach ($histograms as $histogram) {
                $count += $histogram[$bucket] ?? 0;
            }
            if ($count > 0) {
                $merged[$bucket] = $count;
            }
        }

        return $merged;
    }

    /**
     * The upper bound, in milliseconds, of the bucket holding the given percentile: the requests
     * below it took at most that long. Null without any request; the overflow bucket gives the last
     * bound with $beyond set, since nothing is known above it.
     *
     * @param array<string, int> $histogram
     *
     * @return array{boundMs: int, beyond: bool}|null
     */
    public static function percentile(array $histogram, float $percentile): ?array
    {
        $total = array_sum($histogram);
        if (0 === $total) {
            return null;
        }

        $target = $total * $percentile / 100;
        $seen = 0;
        foreach (self::merge($histogram) as $bucket => $count) {
            $seen += $count;
            if ($seen >= $target) {
                return DailyResponseTime::OVERFLOW_BUCKET === (string) $bucket
                    ? ['boundMs' => DailyResponseTime::BUCKETS[\count(DailyResponseTime::BUCKETS) - 1], 'beyond' => true]
                    : ['boundMs' => (int) $bucket, 'beyond' => false];
            }
        }

        return null;
    }
}
