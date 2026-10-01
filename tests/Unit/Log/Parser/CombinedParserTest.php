<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Log\Parser;

use Amoifr\SuluLogStatsBundle\Log\Parser\CombinedParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CombinedParserTest extends TestCase
{
    #[Test]
    public function it_reads_a_combined_line(): void
    {
        $entry = (new CombinedParser())->parse(
            '203.0.113.7 - - [01/Oct/2026:10:00:00 +0200] "GET /fr/offres?page=2 HTTP/2.0" 200 5123 "https://example.com/" "Mozilla/5.0 (X11; Linux x86_64)"',
        );

        self::assertNotNull($entry);
        self::assertSame('2026-10-01T08:00:00+00:00', $entry->time->format(\DATE_ATOM), 'the time is converted to UTC');
        self::assertSame('GET', $entry->method);
        self::assertSame('/fr/offres', $entry->path);
        self::assertSame(200, $entry->status);
        self::assertSame(2, $entry->statusClass());
        self::assertSame(5123, $entry->bytes);
        self::assertSame('203.0.113.7', $entry->clientIp);
        self::assertSame('https://example.com/', $entry->referer);
        self::assertSame('Mozilla/5.0 (X11; Linux x86_64)', $entry->userAgent);
        self::assertNull($entry->durationMs);
    }

    #[Test]
    public function it_treats_dashes_as_missing_values(): void
    {
        $entry = (new CombinedParser())->parse('2001:db8::1 - - [01/Oct/2026:10:00:00 +0000] "HEAD / HTTP/1.1" 304 - "-" "-"');

        self::assertNotNull($entry);
        self::assertSame('2001:db8::1', $entry->clientIp);
        self::assertSame('HEAD', $entry->method);
        self::assertSame('/', $entry->path);
        self::assertNull($entry->bytes);
        self::assertNull($entry->referer);
        self::assertNull($entry->userAgent);
    }

    #[Test]
    public function it_ignores_fields_appended_after_the_user_agent(): void
    {
        $entry = (new CombinedParser())->parse(
            '203.0.113.7 - - [01/Oct/2026:10:00:00 +0000] "GET /fr HTTP/1.1" 404 12 "-" "curl/8.5.0" 0.012 "203.0.113.8"',
        );

        self::assertNotNull($entry);
        self::assertSame(404, $entry->status);
        self::assertSame('curl/8.5.0', $entry->userAgent);
    }

    #[Test]
    public function it_reads_the_common_format_without_referer_nor_user_agent(): void
    {
        $entry = (new CombinedParser())->parse('203.0.113.7 - alice [01/Oct/2026:10:00:00 +0000] "POST /admin/login HTTP/1.1" 302 0');

        self::assertNotNull($entry);
        self::assertSame('POST', $entry->method);
        self::assertSame(302, $entry->status);
        self::assertNull($entry->userAgent);
    }

    #[Test]
    #[DataProvider('provideUnreadableLines')]
    public function it_skips_a_line_it_cannot_read(string $line): void
    {
        self::assertNull((new CombinedParser())->parse($line));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnreadableLines(): iterable
    {
        yield 'blank line' => [''];
        yield 'malformed request sent by a scanner' => ['203.0.113.7 - - [01/Oct/2026:10:00:00 +0000] "-" 400 0 "-" "-"'];
        yield 'binary garbage as the request' => ['203.0.113.7 - - [01/Oct/2026:10:00:00 +0000] "\x16\x03\x01" 400 157 "-" "-"'];
        yield 'invalid date' => ['203.0.113.7 - - [yesterday] "GET / HTTP/1.1" 200 1 "-" "-"'];
        yield 'another format' => ['2026-10-01T08:00:00Z GET 200 37.215 ms 2048 kB 12.34% /fr'];
    }
}
