<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Connector\Upsun;

use Amoifr\SuluLogStatsBundle\Connector\Upsun\UpsunPhpAccessParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpsunPhpAccessParserTest extends TestCase
{
    #[Test]
    public function it_reads_a_php_access_line(): void
    {
        $entry = (new UpsunPhpAccessParser())->parse('2026-10-01T08:00:00Z GET 200 37.215 ms 2048 kB 12.34% /fr/offres?page=2');

        self::assertNotNull($entry);
        self::assertSame('2026-10-01T08:00:00+00:00', $entry->time->format(\DATE_ATOM));
        self::assertSame('GET', $entry->method);
        self::assertSame(200, $entry->status);
        self::assertSame(37.215, $entry->durationMs);
        self::assertSame('/fr/offres', $entry->path);
        self::assertNull($entry->clientIp, 'this log does not carry the client');
        self::assertNull($entry->userAgent);
    }

    #[Test]
    public function it_reads_a_whole_number_of_milliseconds(): void
    {
        $entry = (new UpsunPhpAccessParser())->parse('2026-10-01T08:00:00Z POST 500 1200 ms 4096 kB 98.10% /api/contact');

        self::assertNotNull($entry);
        self::assertSame(1200.0, $entry->durationMs);
        self::assertSame(5, $entry->statusClass());
    }

    #[Test]
    #[DataProvider('provideUnreadableLines')]
    public function it_skips_a_line_it_cannot_read(string $line): void
    {
        self::assertNull((new UpsunPhpAccessParser())->parse($line));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnreadableLines(): iterable
    {
        yield 'blank line' => [''];
        yield 'invalid date' => ['not-a-date GET 200 37.215 ms 2048 kB 12.34% /fr'];
        yield 'combined format' => ['203.0.113.7 - - [01/Oct/2026:10:00:00 +0000] "GET / HTTP/1.1" 200 1 "-" "-"'];
    }
}
