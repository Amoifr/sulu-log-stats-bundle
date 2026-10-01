<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Import;

use Amoifr\SuluLogStatsBundle\Entity\DailyVisitors;
use Amoifr\SuluLogStatsBundle\Entity\VisitorDigest;
use Amoifr\SuluLogStatsBundle\Entity\VisitorSalt;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The life of a day's unique visitors: a random salt while the day is open, then only the count,
 * once the salt and the digests are deleted.
 */
final class VisitorDays
{
    /** @var array<string, ?string> */
    private array $salts = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The salt of the day's visitor digests, created on first use, or null if the day is closed.
     */
    public function saltFor(\DateTimeImmutable $day): ?string
    {
        $key = $day->format('Y-m-d');
        if (\array_key_exists($key, $this->salts)) {
            return $this->salts[$key];
        }

        $day = new \DateTimeImmutable($key, new \DateTimeZone('UTC'));

        if ($this->em->getRepository(DailyVisitors::class)->findOneBy(['day' => $day])?->isClosed()) {
            return $this->salts[$key] = null;
        }

        $salt = $this->em->getRepository(VisitorSalt::class)->findOneBy(['day' => $day]);
        if (null === $salt) {
            $salt = new VisitorSalt($day);
            $this->em->persist($salt);
            $this->em->flush();
        }

        return $this->salts[$key] = $salt->getSalt();
    }

    /**
     * Closes the days before $day: their visitor counts become final, and their salts and digests
     * are deleted, so nothing left can be traced back to a client.
     */
    public function closeBefore(\DateTimeImmutable $day): void
    {
        $day = new \DateTimeImmutable($day->format('Y-m-d'), new \DateTimeZone('UTC'));

        $this->em->wrapInTransaction(function () use ($day): void {
            $open = $this->em->createQueryBuilder()
                ->select('v')
                ->from(DailyVisitors::class, 'v')
                ->where('v.day < :day')
                ->andWhere('v.closed = false')
                ->setParameter('day', $day, Types::DATE_IMMUTABLE)
                ->getQuery()
                ->getResult();

            foreach ($open as $visitors) {
                \assert($visitors instanceof DailyVisitors);
                $visitors->close();
            }

            foreach ([VisitorDigest::class, VisitorSalt::class] as $class) {
                $this->em->createQueryBuilder()
                    ->delete($class, 'e')
                    ->where('e.day < :day')
                    ->setParameter('day', $day, Types::DATE_IMMUTABLE)
                    ->getQuery()
                    ->execute();
            }
        });

        $this->salts = [];
    }
}
