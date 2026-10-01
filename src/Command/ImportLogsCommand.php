<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Command;

use Amoifr\SuluLogStatsBundle\Import\LogImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'amoifr:log-stats:import',
    description: 'Imports the lines the configured log files gained since the previous run into the statistics tables.',
)]
final class ImportLogsCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly LogImporter $importer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('Another import is running: nothing to do.');

            return Command::SUCCESS;
        }

        try {
            $reports = $this->importer->importAll();
        } finally {
            $this->release();
        }

        if (!$reports) {
            $io->warning('No log source is configured under "amoifr_log_stats.sources".');

            return Command::SUCCESS;
        }

        $rows = [];
        $status = Command::SUCCESS;
        foreach ($reports as $report) {
            if ($report->fileMissing) {
                $rows[] = [$report->source, $report->path, 'file missing or unreadable', '', '', ''];
                $status = Command::FAILURE;

                continue;
            }

            $rows[] = [
                $report->source,
                $report->path,
                $report->lostTrack ? 'restarted from the top' : 'resumed',
                $report->linesRead,
                $report->entriesImported,
                $report->unreadableLines + $report->skippedLines,
            ];
        }

        $io->table(['Source', 'Path', 'Read', 'Lines', 'Imported', 'Ignored'], $rows);

        return $status;
    }
}
