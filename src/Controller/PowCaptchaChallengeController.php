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
use Psr\Log\LoggerInterface;

class PowCaptchaChallengeController implements RequestHandlerInterface
{
    private const CHALLENGE_TTL_SECONDS = 300;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_MAX_REQUESTS = 30;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingsRepositoryInterface $settings,
        private readonly PowTokenVerifier $tokenVerifier,
        private readonly Config $config,
        private readonly LoggerInterface $logger
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
        $ipAddress = $this->getClientIp($request);
        if ($ipAddress === '') {
            $this->logger->warning(
                '[fla-powcaptcha] Empty client IP detected while issuing a challenge; ' .
                'rejecting with 429. If this server sits behind a reverse proxy/load ' .
                "balancer, set the 'proxy_headers' or 'proxy_all' keys in config.php " .
                '(see README) or PoW captcha will block all users on login, signup, ' .
                'and password reset.'
            );
            $retryAfter = self::RATE_LIMIT_WINDOW_SECONDS;
            return false;
        }

        $ipHash = hash('sha256', $ipAddress);
        $now = time();
        $window = (int) floor($now / self::RATE_LIMIT_WINDOW_SECONDS);
        $expiresAt = ($window + 1) * self::RATE_LIMIT_WINDOW_SECONDS;
        $ttl = max(1, $expiresAt - $now);

        // Try to claim any slot from 1 up to MAX_REQUESTS using atomic cache->add
        for ($slot = 1; $slot <= self::RATE_LIMIT_MAX_REQUESTS; $slot++) {
            $key = "powcaptcha:rate:{$ipHash}:{$window}:{$slot}";
            if ($this->cache->add($key, 1, $ttl)) {
                return true;
            }
        }

        $retryAfter = $ttl;
        return false;
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        return IpDetector::detect($request, $this->config);
    }
}
