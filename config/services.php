<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Amoifr\SuluLogStatsBundle\Command\ImportLogsCommand;
use Amoifr\SuluLogStatsBundle\Import\AggregateWriter;
use Amoifr\SuluLogStatsBundle\Import\LogFileReader;
use Amoifr\SuluLogStatsBundle\Import\LogImporter;
use Amoifr\SuluLogStatsBundle\Import\RequestClassifier;
use Amoifr\SuluLogStatsBundle\Import\VisitorDays;
use Amoifr\SuluLogStatsBundle\Log\Parser\LogParserInterface;
use Amoifr\SuluLogStatsBundle\Log\Parser\NginxCombinedParser;
use Amoifr\SuluLogStatsBundle\Log\Parser\ParserRegistry;
use Amoifr\SuluLogStatsBundle\Log\Parser\UpsunPhpAccessParser;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->private()
            ->autowire()
            ->autoconfigure();

    $services->instanceof(LogParserInterface::class)
        ->tag('amoifr_log_stats.parser');

    $services->set(NginxCombinedParser::class);
    $services->set(UpsunPhpAccessParser::class);
    $services->set(ParserRegistry::class)
        ->args([tagged_iterator('amoifr_log_stats.parser')]);

    $services->set(LogFileReader::class);
    $services->set(RequestClassifier::class)
        ->args([
            param('amoifr_log_stats.bot_user_agent_pattern'),
            param('amoifr_log_stats.page_views.excluded_path_prefixes'),
            param('amoifr_log_stats.page_views.excluded_extensions'),
        ]);
    $services->set(AggregateWriter::class);
    $services->set(VisitorDays::class);
    $services->set(LogImporter::class)
        ->arg('$sources', param('amoifr_log_stats.sources'));

    $services->set(ImportLogsCommand::class);
};
