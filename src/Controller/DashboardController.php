<?php

declare(strict_types=1);

namespace Amoifr\SuluLogStatsBundle\Controller;

use Amoifr\SuluLogStatsBundle\Admin\LogStatsAdmin;
use Amoifr\SuluLogStatsBundle\Statistics\DashboardStatistics;
use Psr\Clock\ClockInterface;
use Sulu\Component\Security\SecuredControllerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /admin/api/log-stats?from=YYYY-MM-DD&to=YYYY-MM-DD: the statistics of a period of days,
 * the last 30 days by default. Sulu requires the "view" permission on the log statistics.
 *
 * "visitors" adds up the unique visitors of each day: someone coming back another day counts again.
 */
final class DashboardController implements SecuredControllerInterface
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly DashboardStatistics $statistics,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $timezone = $this->statistics->getTimezone();
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone($timezone)->format('Y-m-d'), $timezone);

        try {
            $to = $this->day($request->query->get('to'), $timezone) ?? $today;
            $from = $this->day($request->query->get('from'), $timezone) ?? $to->modify(\sprintf('-%d days', self::DEFAULT_DAYS - 1));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        if ($from > $to) {
            return $this->error('"from" must not be after "to".');
        }

        if ($from->diff($to)->days + 1 > self::MAX_DAYS) {
            return $this->error(\sprintf('A period spans at most %d days.', self::MAX_DAYS));
        }

        return new JsonResponse($this->statistics->forPeriod($from, $to));
    }

    public function getSecurityContext(): string
    {
        return LogStatsAdmin::SECURITY_CONTEXT;
    }

    public function getLocale(Request $request): ?string
    {
        return null;
    }

    private function day(mixed $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $day = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone) : false;
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(\sprintf('Expected a day formatted as YYYY-MM-DD, got "%s".', \is_scalar($value) ? $value : get_debug_type($value)));
        }

        return $day;
    }

    private function error(string $message): JsonResponse
    {
        return new JsonResponse(['code' => Response::HTTP_BAD_REQUEST, 'message' => $message], Response::HTTP_BAD_REQUEST);
    }
}
