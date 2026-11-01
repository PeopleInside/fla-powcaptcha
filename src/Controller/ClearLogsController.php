<?php

namespace PeopleInside\PowCaptcha\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use PeopleInside\PowCaptcha\Service\EventLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** DELETE /api/powcaptcha/logs (administrators only). */
class ClearLogsController implements RequestHandlerInterface
{
    public function __construct(private readonly EventLogger $logger)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse(['data' => ['deleted' => $this->logger->clear()]], 200, ['Cache-Control' => 'no-store']);
    }
}
