<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle;

use Amoifr\SuluLogStatsBundle\Log\Parser\NginxCombinedParser;
use Amoifr\SuluLogStatsBundle\Log\Parser\UpsunPhpAccessParser;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SuluLogStatsBundle extends AbstractBundle
{
    protected string $extensionAlias = 'amoifr_log_stats';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('sources')
                    ->info('The log files to import, keyed by a name that identifies them in the database.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('path')->isRequired()->cannotBeEmpty()->end()
                            ->enumNode('format')
                                ->values([NginxCombinedParser::getFormat(), UpsunPhpAccessParser::getFormat()])
                                ->isRequired()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('timezone')
                    ->info('Time zone the admin dashboard displays the statistics in. They are stored in UTC.')
                    ->defaultValue('UTC')
                ->end()
                ->arrayNode('page_views')
                    ->info('Which successful GET requests are not counted as page views.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('excluded_path_prefixes')
                            ->scalarPrototype()->end()
                            ->defaultValue(['/admin', '/_', '/build', '/bundles', '/uploads', '/media'])
                        ->end()
                        ->arrayNode('excluded_extensions')
                            ->scalarPrototype()->end()
                            ->defaultValue(['css', 'js', 'map', 'json', 'xml', 'txt', 'ico', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'eot', 'pdf'])
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('bot_user_agent_pattern')
                    ->info('A request whose user agent matches this regular expression is counted as a bot.')
                    ->defaultValue('~bot|crawl|spider|slurp|curl|wget|python|httpclient|headless|lighthouse|monitor|uptime~i')
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('doctrine')) {
            return;
        }

        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'AmoifrSuluLogStatsBundle' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__.'/Entity',
                        'prefix' => 'Amoifr\SuluLogStatsBundle\Entity',
                        'alias' => 'AmoifrLogStats',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('amoifr_log_stats.sources', $config['sources'])
            ->set('amoifr_log_stats.timezone', $config['timezone'])
            ->set('amoifr_log_stats.page_views.excluded_path_prefixes', $config['page_views']['excluded_path_prefixes'])
            ->set('amoifr_log_stats.page_views.excluded_extensions', $config['page_views']['excluded_extensions'])
            ->set('amoifr_log_stats.bot_user_agent_pattern', $config['bot_user_agent_pattern']);
    }
}
