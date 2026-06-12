<?php

namespace PeopleInside\PowCaptcha\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use PeopleInside\PowCaptcha\Support\IpDetector;

class PowTokenVerifier
{
    public const CHALLENGE_CACHE_PREFIX = 'powcaptcha:chal:';
    public const MAX_DIFFICULTY = 8;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingsRepositoryInterface $settings
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
        
        return hash_equals((string) $storedIpHash, sha1($currentIp));
    }

    public function getChallengeCacheKey(string $challenge): string
    {
        return $this->getUniqueInstancePrefix() . ':' . self::CHALLENGE_CACHE_PREFIX . $challenge;
    }

    private function getUniqueInstancePrefix(): string
    {
        $configHash = '';
        if (function_exists('app') && app()->bound('flarum.config')) {
            $config = app('flarum.config');
            if (is_array($config)) {
                $uniqueString = ($config['url'] ?? '') . ':' . ($config['database']['database'] ?? '');
                $configHash = sha1($uniqueString);
            }
        }

        $installedId = $this->settings->get('peopleinside-powcaptcha.installation_id');
        if (empty($installedId)) {
            $installedId = bin2hex(random_bytes(16));
            $this->settings->set('peopleinside-powcaptcha.installation_id', $installedId);
        }

        return sha1($configHash . ':' . $installedId);
    }

    private function getCurrentRequestIp(): string
    {
        $ipAddress = '';
        if (function_exists('app') && app()->bound(\Psr\Http\Message\ServerRequestInterface::class)) {
            try {
                $request = app(\Psr\Http\Message\ServerRequestInterface::class);
                if ($request instanceof \Psr\Http\Message\ServerRequestInterface) {
                    $ipAddress = IpDetector::detect($request);
                }
            } catch (\Throwable $e) {
                // Fail silently and fallback to REMOTE_ADDR
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
