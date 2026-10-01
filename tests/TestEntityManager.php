<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * An entity manager over an in-memory SQLite database holding the bundle schema.
 */
final class TestEntityManager
{
    public static function create(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__).'/src/Entity'], true);
        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $em;
    }
}
