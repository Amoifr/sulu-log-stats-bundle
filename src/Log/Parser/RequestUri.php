<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Log\Parser;

/**
 * @internal
 */
final class RequestUri
{
    /**
     * Keeps the path of a request target, without the query string or the fragment.
     */
    public static function path(string $uri): string
    {
        $path = strtok($uri, '?#');

        return false === $path || '' === $path ? '/' : $path;
    }

    public static function utc(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone('UTC'));
    }
}
