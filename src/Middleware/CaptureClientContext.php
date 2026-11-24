<?php

namespace PeopleInside\PowCaptcha\Middleware;

use Flarum\Foundation\Config;
use PeopleInside\PowCaptcha\Support\BehaviorAnalyzer;
use PeopleInside\PowCaptcha\Support\ClientContext;
use PeopleInside\PowCaptcha\Support\Ip;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Makes the client IP and the header-based behaviour score available to code that
 * does not receive the HTTP request (Flarum validators).
 */
class CaptureClientContext implements MiddlewareInterface
{
    public function __construct(
        private readonly ClientContext $context,
        private readonly Config $config
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context->setIp(Ip::fromRequest($request));

        if ($request->getMethod() === 'POST') {
            try {
                $siteHost = (string) $this->config->url()->getHost();
            } catch (\Throwable $e) {
                $siteHost = '';
            }

            $this->context->setHeaderScore(BehaviorAnalyzer::headerScore($request, $siteHost));
        }

        return $handler->handle($request);
    }
}
