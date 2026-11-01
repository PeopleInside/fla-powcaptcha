<?php

namespace PeopleInside\PowCaptcha\Middleware;

use PeopleInside\PowCaptcha\Support\ClientContext;
use PeopleInside\PowCaptcha\Support\Ip;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Makes the client IP available to code that does not receive the HTTP request
 * (Flarum validators and the Flarum 1.x "Saving" event).
 */
class CaptureClientContext implements MiddlewareInterface
{
    public function __construct(private readonly ClientContext $context)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context->setIp(Ip::fromRequest($request));

        return $handler->handle($request);
    }
}
