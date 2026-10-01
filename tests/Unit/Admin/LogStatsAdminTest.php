<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Tests\Unit\Admin;

use Amoifr\SuluLogStatsBundle\Admin\LogStatsAdmin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Admin\Navigation\NavigationItemCollection;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactory;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LogStatsAdminTest extends TestCase
{
    #[Test]
    public function it_adds_the_menu_entry_and_the_dashboard_view(): void
    {
        $admin = $this->admin(canView: true);

        $navigation = new NavigationItemCollection();
        $admin->configureNavigationItems($navigation);
        $item = $navigation->get(LogStatsAdmin::NAVIGATION_ITEM);
        self::assertSame(LogStatsAdmin::DASHBOARD_VIEW, $item->getView());
        self::assertSame('su-chart', $item->getIcon());

        $views = new ViewCollection();
        $admin->configureViews($views);
        $view = $views->get(LogStatsAdmin::DASHBOARD_VIEW)->getView();
        self::assertSame('/log-stats', $view->getPath());
        self::assertSame(LogStatsAdmin::DASHBOARD_VIEW, $view->getType());
        self::assertSame('/admin/api/log-stats', $view->getOption('apiUrl'));
        self::assertSame('Europe/Paris', $view->getOption('timezone'));
    }

    #[Test]
    public function it_shows_nothing_without_the_view_permission(): void
    {
        $admin = $this->admin(canView: false);

        $navigation = new NavigationItemCollection();
        $admin->configureNavigationItems($navigation);
        $views = new ViewCollection();
        $admin->configureViews($views);

        self::assertSame([], $navigation->all());
        self::assertSame([], $views->all());
    }

    #[Test]
    public function the_admin_still_loads_when_the_application_did_not_import_the_routes(): void
    {
        $admin = $this->admin(canView: true, routeImported: false);

        $views = new ViewCollection();
        $admin->configureViews($views);

        self::assertNull($views->get(LogStatsAdmin::DASHBOARD_VIEW)->getView()->getOption('apiUrl'));
    }

    #[Test]
    public function it_declares_a_view_permission_under_settings(): void
    {
        self::assertSame(
            ['Sulu' => ['Settings' => [LogStatsAdmin::SECURITY_CONTEXT => [PermissionTypes::VIEW]]]],
            $this->admin(canView: true)->getSecurityContexts(),
        );
    }

    private function admin(bool $canView, bool $routeImported = true): LogStatsAdmin
    {
        $securityChecker = $this->createStub(SecurityCheckerInterface::class);
        $securityChecker->method('hasPermission')->willReturnCallback(
            static fn(string $context, string $permission): bool => $canView && LogStatsAdmin::SECURITY_CONTEXT === $context && PermissionTypes::VIEW === $permission,
        );

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        if ($routeImported) {
            $urlGenerator->method('generate')->willReturn('/admin/api/log-stats');
        } else {
            $urlGenerator->method('generate')->willThrowException(new RouteNotFoundException());
        }

        return new LogStatsAdmin(new ViewBuilderFactory(), $securityChecker, $urlGenerator, 'Europe/Paris');
    }
}
