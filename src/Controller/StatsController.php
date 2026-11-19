<?php

namespace PeopleInside\PowCaptcha\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use PeopleInside\PowCaptcha\Service\PowSettings;
use PeopleInside\PowCaptcha\Service\StatsTracker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/powcaptcha/stats (administrators only): data for the dashboard widget. */
class StatsController implements RequestHandlerInterface
{
    public function __construct(private readonly StatsTracker $stats)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $summary = $this->stats->summary();

        return new JsonResponse(
            [
                'ready' => $summary['ready'],
                'today' => $summary['today'],
                'week' => $summary['week'],
                'version' => PowSettings::version(),
            ],
            200,
            ['Cache-Control' => 'no-store']
        );
    }
}
