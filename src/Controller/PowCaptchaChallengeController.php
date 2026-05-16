<?php

namespace PeopleInside\PowCaptcha\Controller;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use PeopleInside\PowCaptcha\Service\PowTokenVerifier;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PowCaptchaChallengeController implements RequestHandlerInterface
{
    private const CHALLENGE_TTL_SECONDS = 300;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_MAX_REQUESTS = 30;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->canIssueChallenge($request)) {
            return new JsonResponse(
                ['error' => 'Too many challenge requests. Please retry shortly.'],
                429,
                ['Retry-After' => (string) self::RATE_LIMIT_WINDOW_SECONDS]
            );
        }

        $challenge = bin2hex(random_bytes(16)); // 32 hex chars, 128-bit randomness
        $difficulty = PowTokenVerifier::normalizeDifficulty(
            (int) $this->settings->get('peopleinside-powcaptcha.difficulty', 3)
        );

        // Store the challenge so the server can verify it later.
        $this->cache->put(
            PowTokenVerifier::CHALLENGE_CACHE_PREFIX . $challenge,
            true,
            self::CHALLENGE_TTL_SECONDS
        );

        return new JsonResponse([
            'challenge'  => $challenge,
            'difficulty' => $difficulty,
        ], 200);
    }

    private function canIssueChallenge(ServerRequestInterface $request): bool
    {
        $rateKey = $this->buildRateLimitKey($request);

        if ($rateKey === null) {
            return false;
        }

        if ($this->cache->add($rateKey, 1, self::RATE_LIMIT_WINDOW_SECONDS)) {
            return true;
        }

        return (int) $this->cache->increment($rateKey) <= self::RATE_LIMIT_MAX_REQUESTS;
    }

    private function buildRateLimitKey(ServerRequestInterface $request): ?string
    {
        $ipAddress = $request->getAttribute('ipAddress');

        if (!is_string($ipAddress) || $ipAddress === '') {
            $ipAddress = $this->resolveForwardedOrRemoteAddress($request);
        }

        if ($ipAddress === '') {
            return null;
        }

        return 'powcaptcha:rate:' . sha1($ipAddress);
    }

    private function resolveForwardedOrRemoteAddress(ServerRequestInterface $request): string
    {
        $headers = $request->getServerParams();
        $forwardedFor = (string) ($headers['HTTP_X_FORWARDED_FOR'] ?? '');

        if ($forwardedFor !== '') {
            $candidates = array_map('trim', explode(',', $forwardedFor));
            $clientIp = $candidates[0] ?? '';

            if ($clientIp !== '') {
                return $clientIp;
            }
        }

        return (string) ($headers['REMOTE_ADDR'] ?? '');
    }
}
