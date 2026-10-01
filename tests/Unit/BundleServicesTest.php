<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit;

use Amoifr\SuluLogStatsBundle\Command\ImportLogsCommand;
use Amoifr\SuluLogStatsBundle\Import\LogImporter;
use Amoifr\SuluLogStatsBundle\Log\Parser\ParserRegistry;
use Amoifr\SuluLogStatsBundle\SuluLogStatsBundle;
use Amoifr\SuluLogStatsBundle\Tests\TestEntityManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[RequiresPhpExtension('pdo_sqlite')]
final class BundleServicesTest extends TestCase
{
    #[Test]
    public function the_services_wire_up(): void
    {
        $container = $this->container([
            'access' => ['path' => '/var/log/access.log', 'format' => 'combined'],
            'php' => ['path' => '/var/log/php.access.log', 'format' => 'upsun_php_access'],
        ]);

        self::assertInstanceOf(ImportLogsCommand::class, $container->get(ImportLogsCommand::class));
        self::assertInstanceOf(LogImporter::class, $container->get(LogImporter::class));

        $parsers = $container->get(ParserRegistry::class);
        self::assertInstanceOf(ParserRegistry::class, $parsers);
        self::assertSame('upsun_php_access', $parsers->get('upsun_php_access')::getFormat(), 'every parser is registered');
    }

    /**
     * @param array<string, array{path: string, format: string}> $sources
     */
    private function container(array $sources): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $bundle = new SuluLogStatsBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('amoifr_log_stats', ['sources' => $sources]);

        // what the host application provides
        $container->register(EntityManagerInterface::class)->setSynthetic(true);
        $container->register(ClockInterface::class, MockClock::class);

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'Amoifr\\')) {
                        $definition->setPublic(true);
                    }
                }
            }
        });

        $container->compile();
        $container->set(EntityManagerInterface::class, TestEntityManager::create());

        return $container;
    }
}
