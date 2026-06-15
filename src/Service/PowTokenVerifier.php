<?php

namespace PeopleInside\PowCaptcha\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use PeopleInside\PowCaptcha\Support\IpDetector;
use Psr\Http\Message\ServerRequestInterface;

class PowTokenVerifier
{
    public const CHALLENGE_CACHE_PREFIX = 'powcaptcha:chal:';
    public const MAX_DIFFICULTY = 8;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Container $container
    ) {
    }

    public function verifyToken(string $token, int $difficulty): bool
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

        $currentIp = $this->getCurrentRequestIp();
        
        return hash_equals((string) $storedIpHash, hash('sha256', $currentIp));
    }

    public function getChallengeCacheKey(string $challenge): string
    {
        return $this->getUniqueInstancePrefix() . ':' . self::CHALLENGE_CACHE_PREFIX . $challenge;
    }

    private function getUniqueInstancePrefix(): string
    {
        $configHash = '';
        if ($this->container->bound('flarum.config')) {
            $config = $this->container->make('flarum.config');
            if (is_array($config)) {
                $uniqueString = ($config['url'] ?? '') . ':' . ($config['database']['database'] ?? '');
                $configHash = hash('sha256', $uniqueString);
            }
        }

        $installedId = $this->settings->get('peopleinside-powcaptcha.installation_id');
        if (empty($installedId)) {
            $installedId = bin2hex(random_bytes(16));
            $this->settings->set('peopleinside-powcaptcha.installation_id', $installedId);
        }

        return hash('sha256', $configHash . ':' . $installedId);
    }

    private function getCurrentRequestIp(): string
    {
        $ipAddress = '';
        if ($this->container->bound(ServerRequestInterface::class)) {
            $request = $this->container->make(ServerRequestInterface::class);
            if ($request instanceof ServerRequestInterface) {
                $config = $this->container->bound('flarum.config') ? $this->container->make('flarum.config') : null;
                $ipAddress = IpDetector::detect($request, $config);
            }
        }

        if ($ipAddress === '') {
            $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        }

        return $ipAddress;
    }

    public static function normalizeDifficulty(int $difficulty): int
    {
        return max(1, min(self::MAX_DIFFICULTY, $difficulty));
    }
}
