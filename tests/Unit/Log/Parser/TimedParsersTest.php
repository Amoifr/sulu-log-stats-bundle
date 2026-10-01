<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Log\Parser;

use Amoifr\SuluLogStatsBundle\Log\Parser\ApacheTimedParser;
use Amoifr\SuluLogStatsBundle\Log\Parser\CombinedParser;
use Amoifr\SuluLogStatsBundle\Log\Parser\LogParserInterface;
use Amoifr\SuluLogStatsBundle\Log\Parser\NginxTimedParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TimedParsersTest extends TestCase
{
    private const COMBINED = '203.0.113.7 - - [01/Oct/2026:10:00:00 +0200] "GET /fr/offres?page=2 HTTP/1.1" 200 5123 "-" "Mozilla/5.0 (X11; Linux x86_64)"';

    #[Test]
    #[DataProvider('provideTimedLines')]
    public function it_reads_the_duration_from_the_last_field(LogParserInterface $parser, string $suffix, ?float $durationMs): void
    {
        $entry = $parser->parse(self::COMBINED.$suffix);

        self::assertNotNull($entry);
        self::assertSame('/fr/offres', $entry->path, 'the combined fields are read as usual');
        self::assertSame('203.0.113.7', $entry->clientIp);
        self::assertSame($durationMs, $entry->durationMs);
    }

    /**
     * @return iterable<string, array{LogParserInterface, string, ?float}>
     */
    public static function provideTimedLines(): iterable
    {
        yield 'nginx, seconds' => [new NginxTimedParser(), ' 0.125', 125.0];
        yield 'nginx, whole seconds' => [new NginxTimedParser(), ' 2', 2000.0];
        yield 'nginx, after other appended fields' => [new NginxTimedParser(), ' "203.0.113.8" 0.042', 42.0];
        yield 'nginx, trailing spaces' => [new NginxTimedParser(), " 0.125  ", 125.0];
        yield 'nginx, no duration' => [new NginxTimedParser(), '', null];
        yield 'nginx, a last field that is not a number' => [new NginxTimedParser(), ' "-"', null];
        yield 'apache, microseconds' => [new ApacheTimedParser(), ' 125000', 125.0];
        yield 'apache, no duration' => [new ApacheTimedParser(), '', null];
        yield 'combined ignores a duration' => [new CombinedParser(), ' 0.125', null];
    }

    #[Test]
    public function the_formats_have_distinct_names(): void
    {
        self::assertSame(['combined', 'nginx_timed', 'apache_timed'], [CombinedParser::getFormat(), NginxTimedParser::getFormat(), ApacheTimedParser::getFormat()]);
    }

    #[Test]
    public function an_unreadable_line_stays_unreadable(): void
    {
        self::assertNull((new NginxTimedParser())->parse('203.0.113.7 - - [01/Oct/2026:10:00:00 +0000] "-" 400 0 "-" "-" 0.001'));
        self::assertNull((new ApacheTimedParser())->parse(''));
    }
}
