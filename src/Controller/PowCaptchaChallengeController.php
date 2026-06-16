<?php

namespace PeopleInside\PowCaptcha\Controller;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
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
        private readonly PowTokenVerifier $tokenVerifier,
        private readonly Config $config
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

        $difficultySetting = $this->settings->get('peopleinside-powcaptcha.difficulty', 4);
        $difficultyVal = is_numeric($difficultySetting) ? (int) $difficultySetting : 4;

        // normalizeDifficulty handles legacy values (1→3, 2→4) and clamps to [3, MAX].
        $difficulty = PowTokenVerifier::normalizeDifficulty($difficultyVal);

        $ip = $this->getClientIp($request);

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

        $rateData = $this->cache->get($rateKey);
        $now = time();

        if (is_array($rateData) && isset($rateData['attempts'], $rateData['expires_at'])) {
            if ($now >= $rateData['expires_at']) {
                $rateData = [
                    'attempts' => 1,
                    'expires_at' => $now + self::RATE_LIMIT_WINDOW_SECONDS
                ];
                $this->cache->put($rateKey, $rateData, self::RATE_LIMIT_WINDOW_SECONDS);
            } else {
                if ($rateData['attempts'] >= self::RATE_LIMIT_MAX_REQUESTS) {
                    $retryAfter = $rateData['expires_at'] - $now;
                    return false;
                }
                $rateData['attempts']++;
                $ttl = $rateData['expires_at'] - $now;
                $this->cache->put($rateKey, $rateData, max(1, $ttl));
            }
        } else {
            $rateData = [
                'attempts' => 1,
                'expires_at' => $now + self::RATE_LIMIT_WINDOW_SECONDS
            ];
            $this->cache->put($rateKey, $rateData, self::RATE_LIMIT_WINDOW_SECONDS);
        }

        return true;
    }

    private function buildRateLimitKey(ServerRequestInterface $request): ?string
    {
        $ipAddress = $this->getClientIp($request);

        if ($ipAddress === '') {
            return null;
        }

        return 'powcaptcha:rate:' . hash('sha256', $ipAddress);
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        return IpDetector::detect($request, $this->config);
    }
}
