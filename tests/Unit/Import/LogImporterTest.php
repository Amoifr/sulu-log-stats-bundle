<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Import;

use Amoifr\SuluLogStatsBundle\Connector\Upsun\UpsunPhpAccessParser;
use Amoifr\SuluLogStatsBundle\Entity\DailyPage;
use Amoifr\SuluLogStatsBundle\Entity\DailyResponseTime;
use Amoifr\SuluLogStatsBundle\Entity\DailyVisitors;
use Amoifr\SuluLogStatsBundle\Entity\HourlyTraffic;
use Amoifr\SuluLogStatsBundle\Entity\VisitorDigest;
use Amoifr\SuluLogStatsBundle\Entity\VisitorSalt;
use Amoifr\SuluLogStatsBundle\Import\AggregateWriter;
use Amoifr\SuluLogStatsBundle\Import\LogFileReader;
use Amoifr\SuluLogStatsBundle\Import\LogImporter;
use Amoifr\SuluLogStatsBundle\Import\RequestClassifier;
use Amoifr\SuluLogStatsBundle\Import\VisitorDays;
use Amoifr\SuluLogStatsBundle\Log\Parser\CombinedParser;
use Amoifr\SuluLogStatsBundle\Log\Parser\NginxTimedParser;
use Amoifr\SuluLogStatsBundle\Log\Parser\ParserRegistry;
use Amoifr\SuluLogStatsBundle\Tests\TestEntityManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[RequiresPhpExtension('pdo_sqlite')]
final class LogImporterTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0';
    private const OTHER_BROWSER = 'Mozilla/5.0 (Macintosh) Safari/605.1.15';

    private EntityManager $em;
    private MockClock $clock;
    private string $access;
    private string $php;

    protected function setUp(): void
    {
        $this->em = TestEntityManager::create();
        $this->clock = new MockClock('2026-10-01 12:00:00', 'UTC');
        $this->access = (string) tempnam(sys_get_temp_dir(), 'als');
        $this->php = (string) tempnam(sys_get_temp_dir(), 'als');
    }

    protected function tearDown(): void
    {
        @unlink($this->access);
        @unlink($this->access.'.1');
        @unlink($this->php);
    }

    #[Test]
    public function it_adds_up_traffic_pages_and_visitors(): void
    {
        $this->write($this->access, [
            self::access('10:00:01', '203.0.113.1', 'GET /fr/offres?page=2', 200, 1000),
            self::access('10:00:02', '203.0.113.1', 'GET /fr/offres', 200, 1000),
            self::access('10:30:00', '203.0.113.2', 'GET /fr/offres', 200, 500),
            self::access('10:31:00', '203.0.113.2', 'GET /build/app.css', 200, 300),
            self::access('10:40:00', '203.0.113.3', 'GET /fr/old', 404, 10),
            self::access('10:41:00', '198.51.100.9', 'GET /wp-login.php', 404, 10, 'Mozilla/5.0 (compatible; bingbot/2.0)'),
            self::access('11:00:00', '203.0.113.1', 'GET /fr', 200, 2000, self::OTHER_BROWSER),
            '203.0.113.9 - - [01/Oct/2026:11:00:00 +0000] "-" 400 0 "-" "-"',
        ]);

        [$report] = $this->importer(['access' => $this->access])->importAll();

        self::assertSame([8, 7, 1, 0], [$report->linesRead, $report->entriesImported, $report->unreadableLines, $report->skippedLines]);

        self::assertSame([
            '2026-10-01 10:00 200' => [4, 3, 0, 2800],
            '2026-10-01 10:00 404' => [2, 0, 1, 20],
            '2026-10-01 11:00 200' => [1, 1, 0, 2000],
        ], $this->traffic());

        self::assertSame([
            '/fr' => [1, 0, 0],
            '/fr/offres' => [3, 0, 0],
            '/fr/old' => [0, 1, 0],
        ], $this->pages(), 'assets and bot requests are not pages');

        // 203.0.113.1 came back with another browser: a different visitor
        self::assertSame(3, $this->visitors('2026-10-01'));
    }

    #[Test]
    public function it_only_imports_what_was_added_since_the_previous_run(): void
    {
        $this->write($this->access, [self::access('10:00:00', '203.0.113.1', 'GET /fr', 200, 100)]);
        $importer = $this->importer(['access' => $this->access]);
        $importer->importAll();

        $this->write($this->access, [self::access('10:05:00', '203.0.113.1', 'GET /fr', 200, 100)], append: true);
        file_put_contents($this->access, '203.0.113.2 - - [01/Oct/2026:10:06:00 +0000] "GET /fr HTT', \FILE_APPEND);
        [$report] = $importer->importAll();

        self::assertSame(1, $report->linesRead, 'the line being written is left for later');
        self::assertSame(['/fr' => [2, 0, 0]], $this->pages());
        self::assertSame(1, $this->visitors('2026-10-01'), 'the same visitor is counted once a day, across runs');

        file_put_contents($this->access, 'P/1.1" 200 100 "-" "'.self::OTHER_BROWSER."\"\n", \FILE_APPEND);
        [$report] = $importer->importAll();

        self::assertSame(1, $report->linesRead);
        self::assertSame(['/fr' => [3, 0, 0]], $this->pages());
        self::assertSame(2, $this->visitors('2026-10-01'));
    }

    #[Test]
    public function it_counts_nothing_twice_when_the_host_trims_the_log(): void
    {
        $this->write($this->access, [
            self::access('10:00:00', '203.0.113.1', 'GET /fr/a', 200, 100),
            self::access('10:01:00', '203.0.113.1', 'GET /fr/b', 200, 100),
        ]);
        $importer = $this->importer(['access' => $this->access]);
        $importer->importAll();

        // the oldest line is dropped while a new one comes in
        $this->write($this->access, [
            self::access('10:01:00', '203.0.113.1', 'GET /fr/b', 200, 100),
            self::access('10:02:00', '203.0.113.1', 'GET /fr/c', 200, 100),
        ]);
        [$report] = $importer->importAll();

        self::assertFalse($report->lostTrack);
        self::assertSame(1, $report->linesRead);
        self::assertSame(['/fr/a' => [1, 0, 0], '/fr/b' => [1, 0, 0], '/fr/c' => [1, 0, 0]], $this->pages());

        // trimmed again, with no new line: the next run must not start over
        $this->write($this->access, [self::access('10:02:00', '203.0.113.1', 'GET /fr/c', 200, 100)]);
        [$report] = $importer->importAll();
        [$report] = $importer->importAll();

        self::assertSame(0, $report->linesRead);
        self::assertSame(['/fr/a' => [1, 0, 0], '/fr/b' => [1, 0, 0], '/fr/c' => [1, 0, 0]], $this->pages());
    }

    #[Test]
    public function it_skips_lines_older_than_the_last_import_after_losing_track_of_the_file(): void
    {
        $this->write($this->access, [self::access('10:00:00', '203.0.113.1', 'GET /fr/a', 200, 100)]);
        $importer = $this->importer(['access' => $this->access]);
        $importer->importAll();

        // replaced by a file that does not hold the line read last
        $this->write($this->access, [
            self::access('09:00:00', '203.0.113.1', 'GET /fr/old', 200, 100),
            self::access('10:30:00', '203.0.113.1', 'GET /fr/new', 200, 100),
        ]);
        [$report] = $importer->importAll();

        self::assertTrue($report->lostTrack);
        self::assertSame([1, 1], [$report->entriesImported, $report->skippedLines]);
        self::assertSame(['/fr/a' => [1, 0, 0], '/fr/new' => [1, 0, 0]], $this->pages());
    }

    #[Test]
    public function no_line_is_lost_nor_counted_twice_across_a_rotation(): void
    {
        $this->write($this->access, [
            self::access('10:00:00', '203.0.113.1', 'GET /fr/a', 200, 100),
            self::access('10:01:00', '203.0.113.1', 'GET /fr/b', 200, 100),
        ]);
        $importer = $this->importer(['access' => $this->access], chunkSize: 1);
        $importer->importAll();

        // a line comes in, then logrotate renames the log and the server writes a new one
        $this->write($this->access, [self::access('10:02:00', '203.0.113.1', 'GET /fr/c', 200, 100)], append: true);
        rename($this->access, $this->access.'.1');
        $this->write($this->access, [self::access('10:03:00', '203.0.113.1', 'GET /fr/d', 200, 100)]);

        [$report] = $importer->importAll();

        self::assertSame($this->access.'.1', $report->rotatedPath);
        self::assertSame(2, $report->linesRead);
        self::assertSame(['/fr/a' => [1, 0, 0], '/fr/b' => [1, 0, 0], '/fr/c' => [1, 0, 0], '/fr/d' => [1, 0, 0]], $this->pages());

        // later runs read the new file only
        $this->write($this->access, [self::access('10:04:00', '203.0.113.1', 'GET /fr/e', 200, 100)], append: true);
        [$report] = $importer->importAll();

        self::assertNull($report->rotatedPath);
        self::assertSame(1, $report->linesRead);
        self::assertSame([1, 1, 1, 1, 1], array_column($this->pages(), 0));
    }

    #[Test]
    public function a_rotation_with_no_new_line_yet_resumes_on_the_new_file(): void
    {
        $this->write($this->access, [self::access('10:00:00', '203.0.113.1', 'GET /fr/a', 200, 100)]);
        $importer = $this->importer(['access' => $this->access]);
        $importer->importAll();

        $this->write($this->access, [self::access('10:01:00', '203.0.113.1', 'GET /fr/b', 200, 100)], append: true);
        rename($this->access, $this->access.'.1');
        file_put_contents($this->access, '');
        $importer->importAll();

        $this->write($this->access, [self::access('10:02:00', '203.0.113.1', 'GET /fr/c', 200, 100)]);
        [$report] = $importer->importAll();

        self::assertNull($report->rotatedPath, 'the rotated file was finished by the previous run');
        self::assertSame(['/fr/a' => [1, 0, 0], '/fr/b' => [1, 0, 0], '/fr/c' => [1, 0, 0]], $this->pages());
    }

    #[Test]
    public function chunks_add_up_to_the_same_totals(): void
    {
        $lines = [];
        foreach (range(1, 7) as $i) {
            $lines[] = self::access(\sprintf('10:%02d:00', $i), '203.0.113.'.($i % 3), 'GET /fr', 200, 100);
        }
        $this->write($this->access, $lines);

        [$report] = $this->importer(['access' => $this->access], chunkSize: 2)->importAll();

        self::assertSame(7, $report->entriesImported);
        self::assertSame(['/fr' => [7, 0, 0]], $this->pages());
        self::assertSame(['2026-10-01 10:00 200' => [7, 7, 0, 700]], $this->traffic());
        self::assertSame(3, $this->visitors('2026-10-01'));
    }

    #[Test]
    public function the_php_access_log_only_feeds_response_times(): void
    {
        $this->write($this->access, [self::access('10:00:00', '203.0.113.1', 'GET /fr', 200, 100)]);
        $this->write($this->php, [
            '2026-10-01T10:00:00Z GET 200 20.5 ms 2048 kB 10.00% /fr',
            '2026-10-01T10:00:01Z GET 200 140 ms 2048 kB 10.00% /fr/offres',
            '2026-10-01T10:00:02Z POST 500 12000 ms 4096 kB 99.00% /fr/contact',
        ]);

        $this->importer(['access' => $this->access, 'php' => $this->php])->importAll();

        self::assertSame(['/fr' => [1, 0, 0]], $this->pages(), 'the request is counted once, from the access log');

        $times = $this->em->getRepository(DailyResponseTime::class)->findOneBy(['day' => new \DateTimeImmutable('2026-10-01')]);
        self::assertSame(3, $times?->getRequests());
        self::assertSame(12160.5, $times->getTotalMs());
        self::assertSame(['25' => 1, '200' => 1, DailyResponseTime::OVERFLOW_BUCKET => 1], $times->getHistogram());
    }

    #[Test]
    public function a_timed_access_log_feeds_response_times_with_its_page_requests_only(): void
    {
        $this->write($this->access, [
            self::access('10:00:00', '203.0.113.1', 'GET /fr', 200, 100).' 0.200',
            self::access('10:00:01', '203.0.113.1', 'GET /fr/offres', 404, 100).' 0.040',
            self::access('10:00:02', '203.0.113.1', 'GET /build/app.css', 200, 100).' 0.001',
            self::access('10:00:03', '203.0.113.1', 'POST /admin/login', 302, 100).' 0.300',
        ]);

        [$report] = $this->importer(['access' => $this->access], formats: ['access' => 'nginx_timed'])->importAll();

        self::assertSame(4, $report->entriesImported);
        self::assertSame(['/fr' => [1, 0, 0], '/fr/offres' => [0, 1, 0]], $this->pages(), 'traffic and pages are read from the same lines');

        $times = $this->em->getRepository(DailyResponseTime::class)->findOneBy(['day' => new \DateTimeImmutable('2026-10-01')]);
        self::assertSame(2, $times?->getRequests(), 'the asset and the admin request are left out');
        self::assertSame(240.0, $times->getTotalMs());
    }

    #[Test]
    public function closing_a_day_keeps_only_its_visitor_count(): void
    {
        $this->write($this->access, [
            self::access('10:00:00', '203.0.113.1', 'GET /fr', 200, 100, day: '30/Sep/2026'),
            self::access('10:00:00', '203.0.113.1', 'GET /fr', 200, 100),
        ]);
        $importer = $this->importer(['access' => $this->access]);
        $importer->importAll();

        self::assertSame(1, $this->visitors('2026-09-30'));
        self::assertTrue($this->em->getRepository(DailyVisitors::class)->findOneBy(['day' => new \DateTimeImmutable('2026-09-30')])?->isClosed());
        self::assertSame(['2026-10-01'], $this->days(VisitorDigest::class), 'the closed day lost its digests');
        self::assertSame(['2026-10-01'], $this->days(VisitorSalt::class), 'the closed day lost its salt');

        // a late line for the closed day must not count as a new visitor
        $this->write($this->access, [self::access('23:59:00', '203.0.113.5', 'GET /fr', 200, 100, day: '30/Sep/2026')], append: true);
        $this->importer(['access' => $this->access])->importAll();

        self::assertSame(1, $this->visitors('2026-09-30'));
    }

    #[Test]
    public function a_day_stays_open_for_a_while_after_midnight(): void
    {
        $this->clock->modify('2026-10-02 01:00:00');
        $this->write($this->access, [self::access('23:59:00', '203.0.113.1', 'GET /fr', 200, 100)]);

        $this->importer(['access' => $this->access])->importAll();

        self::assertFalse($this->em->getRepository(DailyVisitors::class)->findOneBy(['day' => new \DateTimeImmutable('2026-10-01')])?->isClosed());
    }

    #[Test]
    public function days_follow_the_configured_time_zone(): void
    {
        // 23:30 UTC on September 30th is already October 1st in Paris; the hour stays in UTC
        $this->write($this->access, [self::access('23:30:00', '203.0.113.1', 'GET /fr', 200, 100, day: '30/Sep/2026')]);

        $this->importer(['access' => $this->access], timezone: 'Europe/Paris')->importAll();

        self::assertSame(1, $this->visitors('2026-10-01'));
        self::assertNull($this->visitors('2026-09-30'));
        self::assertSame(['2026-09-30 23:00 200' => [1, 1, 0, 100]], $this->traffic());
    }

    #[Test]
    public function it_reports_a_missing_file(): void
    {
        [$report] = $this->importer(['access' => $this->access.'.missing'])->importAll();

        self::assertTrue($report->fileMissing);
    }

    /**
     * @param array<string, string> $files   path by source name
     * @param array<string, string> $formats format by source name, "combined" by default and "upsun_php_access" for "php"
     */
    private function importer(array $files, int $chunkSize = 20_000, string $timezone = 'UTC', array $formats = []): LogImporter
    {
        $sources = [];
        foreach ($files as $name => $path) {
            $sources[$name] = ['path' => $path, 'format' => $formats[$name] ?? ('php' === $name ? 'upsun_php_access' : 'combined')];
        }

        return new LogImporter(
            $this->em,
            new LogFileReader(),
            new ParserRegistry([new CombinedParser(), new NginxTimedParser(), new UpsunPhpAccessParser()]),
            new RequestClassifier('~bot~i', ['/admin', '/build'], ['css']),
            new AggregateWriter($this->em),
            new VisitorDays($this->em),
            $this->clock,
            $sources,
            $timezone,
            $chunkSize,
        );
    }

    private static function access(string $time, string $ip, string $request, int $status, int $bytes, string $agent = self::BROWSER, string $day = '01/Oct/2026'): string
    {
        return \sprintf('%s - - [%s:%s +0000] "%s HTTP/1.1" %d %d "-" "%s"', $ip, $day, $time, $request, $status, $bytes, $agent);
    }

    /**
     * @param list<string> $lines
     */
    private function write(string $path, array $lines, bool $append = false): void
    {
        file_put_contents($path, implode("\n", $lines)."\n", $append ? \FILE_APPEND : 0);
    }

    /**
     * @return array<string, array{int, int, int, int}> by "hour status": requests, page views, bots, bytes
     */
    private function traffic(): array
    {
        $traffic = [];
        foreach ($this->em->getRepository(HourlyTraffic::class)->findBy([], ['hour' => 'ASC', 'status' => 'ASC']) as $row) {
            $traffic[$row->getHour()->format('Y-m-d H:i').' '.$row->getStatus()] = [$row->getRequests(), $row->getPageViews(), $row->getBotRequests(), $row->getBytes()];
        }

        return $traffic;
    }

    /**
     * @return array<string, array{int, int, int}> by path: views, 404, 5xx
     */
    private function pages(): array
    {
        $pages = [];
        foreach ($this->em->getRepository(DailyPage::class)->findBy([], ['path' => 'ASC']) as $row) {
            $pages[$row->getPath()] = [$row->getViews(), $row->getNotFound(), $row->getServerErrors()];
        }

        return $pages;
    }

    private function visitors(string $day): ?int
    {
        return $this->em->getRepository(DailyVisitors::class)->findOneBy(['day' => new \DateTimeImmutable($day)])?->getVisitors();
    }

    /**
     * @param class-string<VisitorDigest|VisitorSalt> $class
     *
     * @return list<string>
     */
    private function days(string $class): array
    {
        $this->em->clear();

        return array_values(array_unique(array_map(
            static fn(VisitorDigest|VisitorSalt $row): string => $row->getDay()->format('Y-m-d'),
            $this->em->getRepository($class)->findAll(),
        )));
    }
}
