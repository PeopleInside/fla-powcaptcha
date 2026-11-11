<?php

namespace PeopleInside\PowCaptcha\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use PeopleInside\PowCaptcha\Service\EventLogger;
use PeopleInside\PowCaptcha\Service\PowSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/powcaptcha/logs (administrators only). */
class ListLogsController implements RequestHandlerInterface
{
    public function __construct(
        private readonly EventLogger $logger,
        private readonly PowSettings $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse(
            [
                'data' => $this->logger->latest(),
                'keep' => EventLogger::KEEP,
                'show_ip' => $this->settings->logIp(),
            ],
            200,
            ['Cache-Control' => 'no-store']
        );
    }
}
