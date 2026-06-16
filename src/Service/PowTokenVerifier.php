<?php

namespace PeopleInside\PowCaptcha\Service;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Container\Container;
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
        private readonly Config $config,
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
        $uniqueString = ($this->config['url'] ?? '') . ':' . ($this->config['database']['database'] ?? '');
        $configHash = hash('sha256', $uniqueString);

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
            try {
                $request = $this->container->make(ServerRequestInterface::class);
                if ($request instanceof ServerRequestInterface) {
                    $ipAddress = IpDetector::detect($request, $this->config);
                }
            } catch (\Throwable) {
                // Fail silently and fallback to superglobals
            }
        }

        if ($ipAddress === '') {
            $ipAddress = $this->getIpFromSuperglobals();
        }

        return $ipAddress;
    }

    private function getIpFromSuperglobals(): string
    {
        $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        
        $trustProxy = false;
        $proxyHeaders = $this->config['proxy_headers'] ?? null;
        $proxyAll = $this->config['proxy_all'] ?? null;
        if (!empty($proxyHeaders) || !empty($proxyAll)) {
            $trustProxy = true;
        }

        if ($trustProxy) {
            $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP'];
            foreach ($headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $ips = explode(',', $_SERVER[$header]);
                    $ip = trim($ips[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '';
    }

    public static function normalizeDifficulty(int $difficulty): int
    {
        // Difficulty values 1 and 2 are deprecated and insecure; auto-upgrade them to 4 (default).
        if ($difficulty < 3) {
            return 4;
        }
        return max(3, min(self::MAX_DIFFICULTY, $difficulty));
    }
}
