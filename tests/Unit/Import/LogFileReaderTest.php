<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Import;

use Amoifr\SuluLogStatsBundle\Import\LogFileReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogFileReaderTest extends TestCase
{
    private string $path;
    private LogFileReader $reader;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'als');
        $this->reader = new LogFileReader();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    #[Test]
    public function it_reads_complete_lines_keyed_by_the_offset_after_them(): void
    {
        file_put_contents($this->path, "first\nsecond\r\n");

        self::assertSame([6 => 'first', 14 => 'second'], iterator_to_array($this->reader->lines($this->path, 0)));
        self::assertSame([14 => 'second'], iterator_to_array($this->reader->lines($this->path, 6)));
    }

    #[Test]
    public function it_leaves_a_line_still_being_written_for_later(): void
    {
        file_put_contents($this->path, "first\nhalf a li");

        self::assertSame([6 => 'first'], iterator_to_array($this->reader->lines($this->path, 0)));
    }

    #[Test]
    public function it_reads_nothing_from_a_missing_file(): void
    {
        self::assertSame([], iterator_to_array($this->reader->lines($this->path.'.missing', 0)));
    }

    #[Test]
    public function it_starts_from_the_top_on_a_first_import(): void
    {
        file_put_contents($this->path, "first\n");

        $point = $this->reader->resolveStart($this->path, 0, null);

        self::assertSame(0, $point->offset);
        self::assertFalse($point->lostTrack);
    }

    #[Test]
    public function it_resumes_at_the_offset_of_a_file_that_only_grew(): void
    {
        file_put_contents($this->path, "first\nsecond\n");
        $offset = 13;
        file_put_contents($this->path, "third\n", \FILE_APPEND);

        $point = $this->reader->resolveStart($this->path, $offset, LogFileReader::hash('second'));

        self::assertSame($offset, $point->offset);
        self::assertFalse($point->lostTrack);
        self::assertSame(['third'], array_values(iterator_to_array($this->reader->lines($this->path, $point->offset))));
    }

    #[Test]
    public function it_resumes_after_the_last_line_read_when_the_start_of_the_file_was_trimmed(): void
    {
        // read up to "second" (offset 13), then the host drops the oldest line and new lines come in
        file_put_contents($this->path, "second\nthird\n");

        $point = $this->reader->resolveStart($this->path, 13, LogFileReader::hash('second'));

        self::assertSame(7, $point->offset);
        self::assertFalse($point->lostTrack);
        self::assertSame(['third'], array_values(iterator_to_array($this->reader->lines($this->path, $point->offset))));
    }

    #[Test]
    public function it_does_not_trust_an_offset_where_another_line_now_ends(): void
    {
        // the offset still fits in the file, but another line now ends there
        file_put_contents($this->path, "second\nthird\nfourth\n");

        $point = $this->reader->resolveStart($this->path, 13, LogFileReader::hash('first'));

        self::assertSame(0, $point->offset);
        self::assertTrue($point->lostTrack);

        $point = $this->reader->resolveStart($this->path, 13, LogFileReader::hash('third'));
        self::assertSame(13, $point->offset, 'the line ending at 13 is the one read last');
    }

    #[Test]
    public function it_starts_again_from_the_top_when_the_last_line_read_is_gone(): void
    {
        file_put_contents($this->path, "a brand new file\n");

        $point = $this->reader->resolveStart($this->path, 13, LogFileReader::hash('second'));

        self::assertSame(0, $point->offset);
        self::assertTrue($point->lostTrack);
    }

    #[Test]
    public function it_finds_the_last_line_read_beyond_one_read_chunk(): void
    {
        $long = str_repeat('x', 10_000);
        file_put_contents($this->path, "first\n{$long}\n");

        $point = $this->reader->resolveStart($this->path, 6 + 10_001, LogFileReader::hash($long));

        self::assertSame(10_007, $point->offset);
        self::assertFalse($point->lostTrack);
    }

    #[Test]
    public function it_checks_the_first_line_of_the_file_too(): void
    {
        file_put_contents($this->path, "first\nsecond\n");

        self::assertSame(6, $this->reader->resolveStart($this->path, 6, LogFileReader::hash('first'))->offset);
        self::assertTrue($this->reader->resolveStart($this->path, 6, LogFileReader::hash('other'))->lostTrack);
    }

    #[Test]
    public function it_starts_again_from_the_top_when_the_file_disappeared(): void
    {
        $point = $this->reader->resolveStart($this->path.'.missing', 13, LogFileReader::hash('second'));

        self::assertSame(0, $point->offset);
        self::assertTrue($point->lostTrack);
    }
}
