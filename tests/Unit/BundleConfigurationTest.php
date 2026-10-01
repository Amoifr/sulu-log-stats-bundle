<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit;

use Amoifr\SuluLogStatsBundle\SuluLogStatsBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BundleConfigurationTest extends TestCase
{
    #[Test]
    public function the_extension_uses_the_bundle_alias(): void
    {
        self::assertSame('amoifr_log_stats', (new SuluLogStatsBundle())->getContainerExtension()?->getAlias());
    }

    #[Test]
    public function it_applies_the_defaults(): void
    {
        $config = $this->process([]);

        self::assertSame([], $config['sources']);
        self::assertSame('UTC', $config['timezone']);
        self::assertContains('/admin', $config['page_views']['excluded_path_prefixes']);
        self::assertContains('css', $config['page_views']['excluded_extensions']);
        self::assertSame(1, preg_match($config['bot_user_agent_pattern'], 'Mozilla/5.0 (compatible; Googlebot/2.1)'));
        self::assertSame(1, preg_match($config['bot_user_agent_pattern'], 'ts-cache-warmer'), 'a cache warmer is not a visitor');
        self::assertSame(0, preg_match($config['bot_user_agent_pattern'], 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0'));
    }

    #[Test]
    public function it_keys_the_sources_by_name(): void
    {
        $config = $this->process(['sources' => [
            'access' => ['path' => '/var/log/access.log', 'format' => 'combined'],
            'php' => ['path' => '/var/log/php.access.log', 'format' => 'upsun_php_access'],
        ]]);

        self::assertSame(['access', 'php'], array_keys($config['sources']));
        self::assertSame('upsun_php_access', $config['sources']['php']['format']);
    }

    #[Test]
    public function it_rejects_an_unknown_format(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['sources' => ['access' => ['path' => '/var/log/access.log', 'format' => 'w3c']]]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $extension = (new SuluLogStatsBundle())->getContainerExtension();
        self::assertNotNull($extension);

        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);

        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}
