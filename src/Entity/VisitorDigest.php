<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One visitor seen on a day still open: a salted hash of the client address and user agent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'als_visitor_digest')]
#[ORM\UniqueConstraint(name: 'als_visitor_digest_day_digest', columns: ['day', 'digest'])]
class VisitorDigest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $day,
        #[ORM\Column(length: 64)]
        private string $digest,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getDigest(): string
    {
        return $this->digest;
    }
}
