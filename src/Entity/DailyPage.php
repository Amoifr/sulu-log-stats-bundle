<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Views and errors of one path on one day (UTC).
 */
#[ORM\Entity]
#[ORM\Table(name: 'als_daily_page')]
#[ORM\UniqueConstraint(name: 'als_daily_page_day_path', columns: ['day', 'path'])]
class DailyPage
{
    public const PATH_MAX_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $views = 0;

    #[ORM\Column]
    private int $notFound = 0;

    #[ORM\Column]
    private int $serverErrors = 0;

    #[ORM\Column(length: self::PATH_MAX_LENGTH)]
    private string $path;

    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $day,
        string $path,
    ) {
        $this->path = mb_substr($path, 0, self::PATH_MAX_LENGTH);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getViews(): int
    {
        return $this->views;
    }

    public function getNotFound(): int
    {
        return $this->notFound;
    }

    public function getServerErrors(): int
    {
        return $this->serverErrors;
    }

    public function add(int $views, int $notFound, int $serverErrors): void
    {
        $this->views += $views;
        $this->notFound += $notFound;
        $this->serverErrors += $serverErrors;
    }
}
