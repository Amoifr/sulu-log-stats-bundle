<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Admin;

use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItem;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The "Statistics" entry of the admin menu, and the dashboard view it opens.
 */
final class LogStatsAdmin extends Admin
{
    public const SECURITY_CONTEXT = 'sulu.settings.log_stats';
    public const NAVIGATION_ITEM = 'amoifr_log_stats.statistics';
    public const DASHBOARD_VIEW = 'amoifr_log_stats.dashboard';
    public const API_ROUTE = 'amoifr_log_stats.dashboard_api';

    public function __construct(
        private readonly ViewBuilderFactoryInterface $viewBuilderFactory,
        private readonly SecurityCheckerInterface $securityChecker,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $timezone,
    ) {
    }

    public function configureNavigationItems(NavigationItemCollection $navigationItemCollection): void
    {
        if (!$this->canView()) {
            return;
        }

        $item = new NavigationItem(self::NAVIGATION_ITEM);
        $item->setView(self::DASHBOARD_VIEW);
        $item->setIcon('su-chart');
        $item->setPosition(80);

        $navigationItemCollection->add($item);
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        if (!$this->canView()) {
            return;
        }

        $viewCollection->add(
            $this->viewBuilderFactory->createViewBuilder(self::DASHBOARD_VIEW, '/log-stats', self::DASHBOARD_VIEW)
                ->setOption('apiUrl', $this->apiUrl())
                ->setOption('timezone', $this->timezone),
        );
    }

    public function getSecurityContexts()
    {
        return [
            self::SULU_ADMIN_SECURITY_SYSTEM => [
                'Settings' => [
                    self::SECURITY_CONTEXT => [PermissionTypes::VIEW],
                ],
            ],
        ];
    }

    private function canView(): bool
    {
        return $this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::VIEW);
    }

    /**
     * Null when the application did not import the bundle routes: the view then says so, instead of
     * the whole admin failing to load.
     */
    private function apiUrl(): ?string
    {
        try {
            return $this->urlGenerator->generate(self::API_ROUTE);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
