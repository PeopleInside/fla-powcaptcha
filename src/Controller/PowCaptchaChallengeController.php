<?php

namespace PeopleInside\PowCaptcha\Controller;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use PeopleInside\PowCaptcha\Service\PowTokenVerifier;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class PowCaptchaChallengeController implements RequestHandlerInterface
{
    private const CHALLENGE_TTL_SECONDS = 300;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_MAX_REQUESTS = 30;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingsRepositoryInterface $settings,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
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

        // Store the challenge so the server can verify it later.
        // F-06: wrap in try/catch — if the cache backend is unavailable we
        // return a 503 instead of silently issuing an unverifiable challenge.
        try {
            $this->cache->put(
                PowTokenVerifier::CHALLENGE_CACHE_PREFIX . $challenge,
                true,
                self::CHALLENGE_TTL_SECONDS
            );
        } catch (\Throwable $e) {
            $this->logger->error('[powcaptcha] Cache write failed: ' . $e->getMessage());

            return new JsonResponse(
                ['error' => 'Security service temporarily unavailable. Please try again in a moment.'],
                503,
                ['Retry-After' => '10']
            );
        }

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

    /**
     * Build the rate-limit key for the request.
     *
     * F-03: We rely exclusively on the ipAddress attribute that Flarum
     * resolves via its TrustProxies middleware.  That middleware must be
     * configured in config.php with the real trusted-proxy IPs so that
     * X-Forwarded-For spoofing is not possible.  If no IP can be resolved
     * (e.g. CLI context) we block the request rather than falling through
     * to an unauthenticated REMOTE_ADDR that might be a shared proxy.
     */
    private function buildRateLimitKey(ServerRequestInterface $request): ?string
    {
        $ipAddress = $request->getAttribute('ipAddress');

        if (!is_string($ipAddress) || $ipAddress === '') {
            // F-03: Do NOT fall back to REMOTE_ADDR — it may be a shared proxy
            // address and would make rate-limiting ineffective.
            return null;
        }

        return 'powcaptcha:rate:' . sha1($ipAddress);
    }
}
