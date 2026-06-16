<?php

namespace PeopleInside\PowCaptcha\Service;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use PeopleInside\PowCaptcha\Support\IpDetector;
use Psr\Http\Message\ServerRequestInterface;

class PowTokenVerifier
{
    public const CHALLENGE_CACHE_PREFIX = 'powcaptcha:chal:';
    public const MAX_DIFFICULTY = 8;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Config $config
    ) {
    }

    public function verifyToken(string $token, int $difficulty, ?ServerRequestInterface $request = null): bool
    {
        $parts = explode(':', $token, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$challenge, $nonce] = $parts;

        if (!ctype_xdigit($challenge) || strlen($challenge) !== 32) {
            return false;
        }

        if (!ctype_digit($nonce)) {
            return false;
        }

        $hash           = hash('sha256', $challenge . ':' . $nonce);
        $requiredPrefix = str_repeat('0', self::normalizeDifficulty($difficulty));

        if (!str_starts_with($hash, $requiredPrefix)) {
            return false;
        }

        $cacheKey = $this->getChallengeCacheKey($challenge);
        $storedIpHash = $this->cache->pull($cacheKey);

        if ($storedIpHash === null) {
            return false;
        }

        $currentIp = $this->getCurrentRequestIp($request);
        
        return hash_equals((string) $storedIpHash, hash('sha256', $currentIp));
    }

    public function getChallengeCacheKey(string $challenge): string
    {
        return $this->getUniqueInstancePrefix() . ':' . self::CHALLENGE_CACHE_PREFIX . $challenge;
    }

    private function getUniqueInstancePrefix(): string
    {
        $uniqueString = ($this->config['url'] ?? '') . ':' . ($this->config['database']['database'] ?? '');
        $configHash = hash('sha256', $uniqueString);

        $installedId = $this->settings->get('peopleinside-powcaptcha.installation_id');
        if (empty($installedId)) {
            $installedId = bin2hex(random_bytes(16));
            $this->settings->set('peopleinside-powcaptcha.installation_id', $installedId);
        }

        return hash('sha256', $configHash . ':' . $installedId);
    }

    private function getCurrentRequestIp(?ServerRequestInterface $request = null): string
    {
        if ($request !== null) {
            return IpDetector::detect($request, $this->config);
        }

        try {
            // Dynamically resolve 'app' (the Flarum / Illuminate container)
            $container = resolve('app');
            if ($container->bound(ServerRequestInterface::class)) {
                $resolvedRequest = $container->make(ServerRequestInterface::class);
                if ($resolvedRequest instanceof ServerRequestInterface) {
                    return IpDetector::detect($resolvedRequest, $this->config);
                }
            }
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
            // Container resolution failed, fallback safely to IpDetector without request
        } catch (\Throwable $e) {
            // Handle any other environmental exceptions (e.g. calling from CLI context with no HTTP binding)
        }

        return IpDetector::detect(null, $this->config);
    }

    public static function normalizeDifficulty(int $difficulty): int
    {
        // Difficulty values 1 and 2 are from the old scheme and no longer valid.
        // Map them to their nearest equivalent in the new 3-level scheme (3/4/5):
        // old 1 → new 3 (Easy), old 2 → new 4 (High).
        if ($difficulty === 1) {
            return 3;
        }
        if ($difficulty === 2) {
            return 4;
        }
        // Any other out-of-range value is clamped to the valid range [3, MAX_DIFFICULTY].
        return max(3, min(self::MAX_DIFFICULTY, $difficulty));
    }
}
