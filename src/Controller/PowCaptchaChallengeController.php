<?php

namespace PeopleInside\PowCaptcha\Controller;

use Laminas\Diactoros\Response\JsonResponse;
use PeopleInside\PowCaptcha\Service\PowTokenVerifier;
use PeopleInside\PowCaptcha\Service\RiskTracker;
use PeopleInside\PowCaptcha\Support\Ip;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /api/powcaptcha/challenge?action=login|signup|forgot|token
 *
 * Issues a signed, stateless challenge for one specific form.
 */
class PowCaptchaChallengeController implements RequestHandlerInterface
{
    public function __construct(
        private readonly PowTokenVerifier $verifier,
        private readonly RiskTracker $risk,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $action = $request->getQueryParams()['action'] ?? '';

        if (! is_string($action) || ! isset(PowTokenVerifier::ACTIONS[$action])) {
            return new JsonResponse(['error' => 'Invalid or missing "action" parameter.'], 400, ['Cache-Control' => 'no-store']);
        }

        $retryAfter = $this->risk->issuanceRetryAfter(Ip::fromRequest($request));

        if ($retryAfter > 0) {
            return new JsonResponse(
                ['error' => 'Too many challenge requests. Please retry shortly.', 'retryAfter' => $retryAfter],
                429,
                ['Retry-After' => (string) $retryAfter, 'Cache-Control' => 'no-store']
            );
        }

        try {
            return new JsonResponse($this->verifier->issue($action, $request), 200, ['Cache-Control' => 'no-store']);
        } catch (\Throwable $e) {
            $this->logger->error('[fla-powcaptcha] Could not issue a challenge: ' . $e->getMessage(), ['exception' => $e]);

            return new JsonResponse(
                ['error' => 'The security CAPTCHA service is temporarily unavailable. Please try again shortly.'],
                503,
                ['Retry-After' => '30', 'Cache-Control' => 'no-store']
            );
        }
    }
}
