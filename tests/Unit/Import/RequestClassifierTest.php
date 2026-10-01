<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Import;

use Amoifr\SuluLogStatsBundle\Import\RequestClassifier;
use Amoifr\SuluLogStatsBundle\Log\LogEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestClassifierTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0';

    private RequestClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new RequestClassifier('~bot|curl~i', ['/admin', '/_profiler', '/uploads/'], ['css', 'png']);
    }

    #[Test]
    #[DataProvider('provideRequests')]
    public function it_tells_page_views_apart(string $method, string $path, int $status, ?string $userAgent, bool $isPageView): void
    {
        $entry = new LogEntry(new \DateTimeImmutable(), $method, $path, $status, userAgent: $userAgent, clientIp: '203.0.113.7');

        self::assertSame($isPageView, $this->classifier->isPageView($entry));
    }

    /**
     * @return iterable<string, array{string, string, int, ?string, bool}>
     */
    public static function provideRequests(): iterable
    {
        yield 'a page' => ['GET', '/fr/offres', 200, self::BROWSER, true];
        yield 'the home page' => ['GET', '/', 200, self::BROWSER, true];
        yield 'a page whose name starts like an excluded prefix' => ['GET', '/administration-publique', 200, self::BROWSER, true];
        yield 'a page with a dot in its name' => ['GET', '/fr/v2.0-release', 200, self::BROWSER, true];
        yield 'an excluded prefix' => ['GET', '/admin', 200, self::BROWSER, false];
        yield 'below an excluded prefix' => ['GET', '/admin/api/pages', 200, self::BROWSER, false];
        yield 'below a prefix configured with a trailing slash' => ['GET', '/uploads/media/x.jpg', 200, self::BROWSER, false];
        yield 'an excluded extension, whatever its case' => ['GET', '/build/app.CSS', 200, self::BROWSER, false];
        yield 'a bot' => ['GET', '/fr/offres', 200, 'Mozilla/5.0 (compatible; Googlebot/2.1)', false];
        yield 'no user agent' => ['GET', '/fr/offres', 200, null, false];
        yield 'a redirect' => ['GET', '/fr/offres', 301, self::BROWSER, false];
        yield 'a revalidation' => ['GET', '/fr/offres', 304, self::BROWSER, false];
        yield 'a form post' => ['POST', '/fr/contact', 200, self::BROWSER, false];
        yield 'a HEAD request' => ['HEAD', '/fr/offres', 200, self::BROWSER, false];
    }
}
