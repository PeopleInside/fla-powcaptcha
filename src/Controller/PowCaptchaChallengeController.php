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

class PowCaptchaChallengeController implements RequestHandlerInterface
{
    private const CHALLENGE_TTL_SECONDS = 300;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_MAX_REQUESTS = 30;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingsRepositoryInterface $settings,
        private readonly RateLimiter $rateLimiter
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

    private function canIssueChallenge(ServerRequestInterface $request, ?int &$retryAfter = null): bool
    {
        $rateKey = $this->buildRateLimitKey($request, true);

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

    private function buildRateLimitKey(ServerRequestInterface $request, bool $withPrefix = false): ?string
    {
        $ipAddress = $request->getAttribute('ipAddress');

        if (!is_string($ipAddress) || $ipAddress === '') {
            $ipAddress = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        }

        if ($ipAddress === '') {
            return null;
        }

        $hashedIp = sha1($ipAddress);

        return $withPrefix ? 'powcaptcha:rate:' . $hashedIp : $hashedIp;
    }
}
