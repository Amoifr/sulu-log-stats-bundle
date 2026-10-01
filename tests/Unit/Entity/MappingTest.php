<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Entity;

use Amoifr\SuluLogStatsBundle\Entity\DailyPage;
use Amoifr\SuluLogStatsBundle\Entity\DailyResponseTime;
use Amoifr\SuluLogStatsBundle\Entity\DailyVisitors;
use Amoifr\SuluLogStatsBundle\Entity\HourlyTraffic;
use Amoifr\SuluLogStatsBundle\Entity\SourceCursor;
use Amoifr\SuluLogStatsBundle\Entity\VisitorDigest;
use Amoifr\SuluLogStatsBundle\Entity\VisitorSalt;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class MappingTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    #[Test]
    public function the_mapping_is_valid(): void
    {
        self::assertSame([], (new SchemaValidator($this->em))->validateMapping());
    }

    #[Test]
    public function every_table_has_the_bundle_prefix(): void
    {
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            self::assertStringStartsWith('als_', $metadata->getTableName(), $metadata->getName());
        }
    }

    #[Test]
    public function the_entities_survive_a_round_trip(): void
    {
        $day = new \DateTimeImmutable('2026-10-01');

        $cursor = new SourceCursor('access');
        $cursor->moveTo(4_294_967_296, str_repeat('a', 64), new \DateTimeImmutable('2026-10-01 10:00:00'));

        $traffic = new HourlyTraffic(new \DateTimeImmutable('2026-10-01 10:00:00'), 404);
        $traffic->add(3, 0, 1, 512);
        $traffic->add(2, 0, 0, 3_000_000_000);

        $page = new DailyPage($day, '/fr/offres');
        $page->add(10, 0, 1);

        $visitors = new DailyVisitors($day);
        $visitors->addVisitors(7);

        $responseTime = new DailyResponseTime($day);
        $responseTime->add(2, 80.0, ['50' => 1, '100' => 1]);
        $responseTime->add(1, 12_000.0, [DailyResponseTime::OVERFLOW_BUCKET => 1]);

        foreach ([$cursor, $traffic, $page, $visitors, $responseTime, new VisitorSalt($day), new VisitorDigest($day, str_repeat('b', 64))] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $cursor = $this->em->find(SourceCursor::class, 'access');
        self::assertSame(4_294_967_296, $cursor?->getOffset(), 'offsets beyond 32 bits are kept');

        $traffic = $this->em->getRepository(HourlyTraffic::class)->findOneBy(['status' => 404]);
        self::assertSame([5, 1, 3_000_000_512], [$traffic?->getRequests(), $traffic->getBotRequests(), $traffic->getBytes()]);

        $page = $this->em->getRepository(DailyPage::class)->findOneBy(['path' => '/fr/offres']);
        self::assertSame([10, 0, 1], [$page?->getViews(), $page->getNotFound(), $page->getServerErrors()]);
        self::assertSame(7, $this->em->getRepository(DailyVisitors::class)->findOneBy(['day' => $day])?->getVisitors());

        $responseTime = $this->em->getRepository(DailyResponseTime::class)->findOneBy(['day' => $day]);
        self::assertSame(3, $responseTime?->getRequests());
        self::assertSame(['50' => 1, '100' => 1, DailyResponseTime::OVERFLOW_BUCKET => 1], $responseTime->getHistogram());
    }

    #[Test]
    public function an_hour_has_one_row_per_status(): void
    {
        $hour = new \DateTimeImmutable('2026-10-01 10:00:00');
        $this->em->persist(new HourlyTraffic($hour, 200));
        $this->em->persist(new HourlyTraffic($hour, 200));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    #[Test]
    public function a_day_has_one_visitor_count(): void
    {
        $this->em->persist(new DailyVisitors(new \DateTimeImmutable('2026-10-01')));
        $this->em->persist(new DailyVisitors(new \DateTimeImmutable('2026-10-01')));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    #[Test]
    public function a_visitor_is_counted_once_a_day(): void
    {
        $day = new \DateTimeImmutable('2026-10-01');
        $this->em->persist(new VisitorDigest($day, str_repeat('c', 64)));
        $this->em->persist(new VisitorDigest($day, str_repeat('c', 64)));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
