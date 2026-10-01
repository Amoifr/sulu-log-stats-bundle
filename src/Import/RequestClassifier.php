<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Import;

use Amoifr\SuluLogStatsBundle\Log\LogEntry;

final class RequestClassifier
{
    /**
     * @param list<string> $excludedPathPrefixes matched on whole path segments: "/admin" excludes "/admin/login", not "/administration"
     * @param list<string> $excludedExtensions
     */
    public function __construct(
        private readonly string $botUserAgentPattern,
        private readonly array $excludedPathPrefixes,
        private readonly array $excludedExtensions,
    ) {
    }

    /**
     * A request without a user agent comes from a script, not a browser.
     */
    public function isBot(LogEntry $entry): bool
    {
        return null === $entry->userAgent || 1 === preg_match($this->botUserAgentPattern, $entry->userAgent);
    }

    /**
     * Whether the request asked for a page, as opposed to an asset, the admin or a technical route.
     */
    public function isPagePath(string $path): bool
    {
        foreach ($this->excludedPathPrefixes as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return '' === $extension || !\in_array($extension, $this->excludedExtensions, true);
    }

    /**
     * A successful GET of a page by a human visitor.
     */
    public function isPageView(LogEntry $entry): bool
    {
        return 'GET' === $entry->method
            && 200 === $entry->status
            && $this->isPagePath($entry->path)
            && !$this->isBot($entry);
    }
}
