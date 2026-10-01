<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Import;

/**
 * Reads the complete lines a log file gained since the previous import.
 *
 * Hosts trim or rotate their logs (Upsun cuts them to 100 MB), so an offset alone can't tell where to
 * resume: the line ending at the stored offset must still be the one read last time.
 */
final class LogFileReader
{
    public static function hash(string $line): string
    {
        return hash('sha256', $line);
    }

    /**
     * Where to resume reading: the stored offset if the file only grew since, right after the last
     * line read if the file was trimmed but still holds it, or the start of the file otherwise.
     */
    public function resolveStart(string $path, int $offset, ?string $lastLineHash): ResumePoint
    {
        if (0 === $offset || null === $lastLineHash) {
            return new ResumePoint(0, false);
        }

        clearstatcache(true, $path);
        $size = @filesize($path);
        if (false === $size) {
            return new ResumePoint(0, true);
        }

        if ($size >= $offset && $this->lineEndingAt($path, $offset) === $lastLineHash) {
            return new ResumePoint($offset, false);
        }

        foreach ($this->lines($path, 0) as $end => $line) {
            if (self::hash($line) === $lastLineHash) {
                return new ResumePoint($end, false);
            }
        }

        return new ResumePoint(0, true);
    }

    /**
     * The complete lines from $offset on, without their line break, keyed by the offset right after
     * each line. A last line without a line break is still being written: it is left for later.
     *
     * @return \Generator<int, string>
     */
    public function lines(string $path, int $offset): \Generator
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return;
        }

        try {
            if (0 !== fseek($handle, $offset)) {
                return;
            }

            $position = $offset;
            while (false !== $line = fgets($handle)) {
                if (!str_ends_with($line, "\n")) {
                    return;
                }

                $position += \strlen($line);

                yield $position => rtrim($line, "\r\n");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The hash of the line that ends right before $offset, or null if there is none.
     */
    private function lineEndingAt(string $path, int $offset): ?string
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return null;
        }

        try {
            // the byte right before $offset must be the line break of the line read last
            if (0 !== fseek($handle, $offset - 1) || "\n" !== fgetc($handle)) {
                return null;
            }

            // read backwards by chunks until the line break that precedes the line, or the file start
            $start = $offset - 1;
            $line = '';
            while ($start > 0) {
                $length = min(4096, $start);
                $start -= $length;
                fseek($handle, $start);
                $line = fread($handle, $length).$line;

                $break = strrpos($line, "\n");
                if (false !== $break) {
                    return self::hash(rtrim(substr($line, $break + 1), "\r"));
                }
            }

            return self::hash(rtrim($line, "\r"));
        } finally {
            fclose($handle);
        }
    }
}
