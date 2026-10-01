<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The random salt of one day's visitor digests. It is deleted with them when the day is closed,
 * after which a digest can no longer be matched to a client.
 */
#[ORM\Entity]
#[ORM\Table(name: 'als_visitor_salt')]
#[ORM\UniqueConstraint(name: 'als_visitor_salt_day', columns: ['day'])]
class VisitorSalt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $salt;

    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $day,
    ) {
        $this->salt = bin2hex(random_bytes(32));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getSalt(): string
    {
        return $this->salt;
    }
}
