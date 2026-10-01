<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Amoifr\SuluLogStatsBundle\Admin\LogStatsAdmin;
use Amoifr\SuluLogStatsBundle\Command\ImportLogsCommand;
use Amoifr\SuluLogStatsBundle\Connector\Upsun\UpsunPhpAccessParser;
use Amoifr\SuluLogStatsBundle\Controller\DashboardController;
use Amoifr\SuluLogStatsBundle\Import\AggregateWriter;
use Amoifr\SuluLogStatsBundle\Import\LogFileReader;
use Amoifr\SuluLogStatsBundle\Import\LogImporter;
use Amoifr\SuluLogStatsBundle\Import\RequestClassifier;
use Amoifr\SuluLogStatsBundle\Import\VisitorDays;
use Amoifr\SuluLogStatsBundle\Log\Parser\ApacheTimedParser;
use Amoifr\SuluLogStatsBundle\Log\Parser\CombinedParser;
use Amoifr\SuluLogStatsBundle\Log\Parser\LogParserInterface;
use Amoifr\SuluLogStatsBundle\Log\Parser\NginxTimedParser;
use Amoifr\SuluLogStatsBundle\Log\Parser\ParserRegistry;
use Amoifr\SuluLogStatsBundle\Statistics\DashboardStatistics;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->private()
            ->autowire()
            ->autoconfigure();

    $services->instanceof(LogParserInterface::class)
        ->tag('amoifr_log_stats.parser');

    $services->set(CombinedParser::class);
    $services->set(NginxTimedParser::class);
    $services->set(ApacheTimedParser::class);
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
        ->arg('$sources', param('amoifr_log_stats.sources'))
        ->arg('$timezone', param('amoifr_log_stats.timezone'));

    $services->set(ImportLogsCommand::class);

    $services->set(DashboardStatistics::class)
        ->arg('$timezone', param('amoifr_log_stats.timezone'));
    $services->set(DashboardController::class)
        ->public()
        ->tag('controller.service_arguments');
    $services->set(LogStatsAdmin::class)
        ->arg('$timezone', param('amoifr_log_stats.timezone'));
};
