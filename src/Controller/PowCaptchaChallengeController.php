<?php

namespace PeopleInside\PowCaptcha\Controller;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use PeopleInside\PowCaptcha\Service\PowTokenVerifier;
use PeopleInside\PowCaptcha\Support\IpDetector;
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
        private readonly SettingsRepositoryInterface $settings,
        private readonly RateLimiter $rateLimiter,
        private readonly PowTokenVerifier $tokenVerifier
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $retryAfter = self::RATE_LIMIT_WINDOW_SECONDS;

        if (!$this->canIssueChallenge($request, $retryAfter)) {
            return new JsonResponse(
                ['error' => 'Too many challenge requests. Please retry shortly.'],
                429,
                ['Retry-After' => (string) max(1, $retryAfter)]
            );
        }

        $challenge = bin2hex(random_bytes(16)); // 32 hex chars, 128-bit randomness
        $difficulty = PowTokenVerifier::normalizeDifficulty(
            (int) $this->settings->get('peopleinside-powcaptcha.difficulty', 3)
        );

        $config = null;
        if (function_exists('resolve')) {
            try {
                $configResolved = resolve('flarum.config');
                if (is_array($configResolved) || $configResolved instanceof \ArrayAccess) {
                    $config = $configResolved;
                }
            } catch (\Throwable) {
                // Silently fallback to null
            }
        }
        $ip = IpDetector::detect($request, $config);

        // Store the challenge with its hashed IP binding under the multi-instance safe prefix.
        $this->cache->put(
            $this->tokenVerifier->getChallengeCacheKey($challenge),
            hash('sha256', $ip),
            self::CHALLENGE_TTL_SECONDS
        );

        return new JsonResponse([
            'challenge'  => $challenge,
            'difficulty' => $difficulty,
        ], 200);
    }

    private function canIssueChallenge(ServerRequestInterface $request, ?int &$retryAfter = null): bool
    {
        $rateKey = $this->buildRateLimitKey($request);

        if ($rateKey === null) {
            $retryAfter = self::RATE_LIMIT_WINDOW_SECONDS;
            return false;
        }

        if ($this->rateLimiter->tooManyAttempts($rateKey, self::RATE_LIMIT_MAX_REQUESTS)) {
            $retryAfter = $this->rateLimiter->availableIn($rateKey);

            return false;
        }

        $this->rateLimiter->hit($rateKey, self::RATE_LIMIT_WINDOW_SECONDS);

        return true;
    }

    private function buildRateLimitKey(ServerRequestInterface $request): ?string
    {
        $config = null;
        if (function_exists('resolve')) {
            try {
                $configResolved = resolve('flarum.config');
                if (is_array($configResolved) || $configResolved instanceof \ArrayAccess) {
                    $config = $configResolved;
                }
            } catch (\Throwable) {
                // Silently fallback to null
            }
        }
        $ipAddress = IpDetector::detect($request, $config);

        if ($ipAddress === '') {
            return null;
        }

        return 'powcaptcha:rate:' . hash('sha256', $ipAddress);
    }
}
