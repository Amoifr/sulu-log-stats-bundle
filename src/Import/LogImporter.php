<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Import;

use Amoifr\SuluLogStatsBundle\Entity\SourceCursor;
use Amoifr\SuluLogStatsBundle\Log\Parser\ParserRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Imports the lines the configured log files gained since the previous run.
 *
 * Lines are imported by chunks: the totals of a chunk and the move of the read cursor are saved in
 * one transaction, so an interrupted import never counts a line twice nor skips one.
 */
final class LogImporter
{
    /**
     * Days whose last lines may still be waiting in a log are kept open this long after midnight (UTC).
     */
    private const DAY_CLOSING_DELAY = 'PT2H';

    /**
     * @param array<string, array{path: string, format: string}> $sources
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LogFileReader $reader,
        private readonly ParserRegistry $parsers,
        private readonly RequestClassifier $classifier,
        private readonly AggregateWriter $writer,
        private readonly VisitorDays $visitorDays,
        private readonly ClockInterface $clock,
        private readonly array $sources,
        private readonly int $chunkSize = 20_000,
    ) {
    }

    /**
     * @return list<ImportReport>
     */
    public function importAll(): array
    {
        $reports = [];
        foreach ($this->sources as $name => $source) {
            $reports[] = $this->import($name, $source['path'], $source['format']);
        }

        $this->visitorDays->closeBefore($this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->sub(new \DateInterval(self::DAY_CLOSING_DELAY)));

        return $reports;
    }

    public function import(string $name, string $path, string $format): ImportReport
    {
        $report = new ImportReport($name, $path);
        if (!is_file($path) || !is_readable($path)) {
            $report->fileMissing = true;

            return $report;
        }

        $parser = $this->parsers->get($format);
        $cursor = $this->cursor($name);
        $point = $this->reader->resolveStart($path, $cursor->getOffset(), $cursor->getLastLineHash());
        $report->lostTrack = $point->lostTrack;

        // after losing track, the top of the file may hold lines imported already: skip what is older
        $notBefore = $point->lostTrack ? $cursor->getLastEntryTime() : null;

        $aggregator = new Aggregator($this->classifier, $this->visitorDays->saltFor(...));
        $offset = $point->offset;
        // the line ending at the resume point is still the one read last, unless track was lost
        $lastHash = $point->lostTrack ? null : $cursor->getLastLineHash();
        $lastTime = null;
        $pending = 0;

        foreach ($this->reader->lines($path, $point->offset) as $offset => $line) {
            ++$report->linesRead;
            ++$pending;
            $lastHash = LogFileReader::hash($line);

            $entry = $parser->parse($line);
            if (null === $entry) {
                ++$report->unreadableLines;
            } elseif (null !== $notBefore && $entry->time < $notBefore) {
                ++$report->skippedLines;
            } else {
                $aggregator->add($entry);
                ++$report->entriesImported;
                $lastTime = $entry->time;
            }

            if ($pending >= $this->chunkSize) {
                $this->save($name, $aggregator, $offset, $lastHash, $lastTime);
                $pending = 0;
            }
        }

        if ($pending > 0 || $point->offset !== $cursor->getOffset()) {
            $this->save($name, $aggregator, $offset, $lastHash, $lastTime);
        }

        return $report;
    }

    private function save(string $name, Aggregator $aggregator, int $offset, ?string $lastHash, ?\DateTimeImmutable $lastTime): void
    {
        $this->em->wrapInTransaction(function () use ($name, $aggregator, $offset, $lastHash, $lastTime): void {
            $this->writer->write($aggregator);
            $this->cursor($name)->moveTo($offset, $lastHash, $lastTime);
        });

        $aggregator->reset();
        $this->em->clear();
    }

    private function cursor(string $name): SourceCursor
    {
        $cursor = $this->em->find(SourceCursor::class, $name);
        if (null === $cursor) {
            $cursor = new SourceCursor($name);
            $this->em->persist($cursor);
        }

        return $cursor;
    }
}
