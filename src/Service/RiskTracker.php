<?php

namespace PeopleInside\PowCaptcha\Service;

use PeopleInside\PowCaptcha\Support\CacheGuard;
use PeopleInside\PowCaptcha\Support\Ip;
use Psr\Log\LoggerInterface;

/**
 * Short-lived, anonymous abuse counters used for rate limiting and adaptive
 * difficulty. Nothing here is written to the database or to the log: counters live
 * in the cache, expire in minutes and are keyed by a keyed hash (HMAC) of the
 * network bucket, never by the raw IP address.
 */
class RiskTracker
{
    public const ISSUE_LIMIT = 30;
    public const ISSUE_WINDOW = 60;
    public const RISK_WINDOW = 600;
    public const GLOBAL_WINDOW = 300;
    public const GLOBAL_FAIL_THRESHOLD = 60;

    public function __construct(
        private readonly CacheGuard $cache,
        private readonly PowSettings $settings,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Seconds the client must wait before requesting a new challenge (0 = allowed). */
    public function issuanceRetryAfter(string $ip): int
    {
        if (! Ip::isPublic($ip)) {
            return 0;
        }

        try {
            $window = intdiv(time(), self::ISSUE_WINDOW);
            $count = $this->cache->hit($this->ipKey('rate:' . $window, $ip), self::ISSUE_WINDOW);

            if ($count > self::ISSUE_LIMIT) {
                return max(1, ($window + 1) * self::ISSUE_WINDOW - time());
            }
        } catch (\Throwable $e) {
            $this->warn('rate limiter', $e);
        }

        return 0;
    }

    /**
     * Extra leading zeros to require right now (0–2).
     *
     *  - captcha failures from the same network in the last 10 minutes
     *  - repeated successful sign-ups / password-reset requests from the same network
     *  - "under attack" mode: many failures site-wide in the last 5 minutes (+1 for all)
     */
    public function bump(string $ip, string $action): int
    {
        if (! $this->settings->adaptive()) {
            return 0;
        }

        $bump = 0;

        try {
            if (Ip::isPublic($ip)) {
                $fails = $this->cache->count($this->ipKey('fail', $ip));
                $bump = max($bump, $fails >= 8 ? 2 : ($fails >= 3 ? 1 : 0));

                if ($action === 'signup' || $action === 'forgot') {
                    $oks = $this->cache->count($this->ipKey('ok:' . $action, $ip));
                    [$one, $two] = $action === 'signup' ? [3, 8] : [4, 10];
                    $bump = max($bump, $oks >= $two ? 2 : ($oks >= $one ? 1 : 0));
                }
            }

            if ($this->cache->count($this->globalKey()) >= self::GLOBAL_FAIL_THRESHOLD) {
                $bump = max($bump, 1);
            }
        } catch (\Throwable $e) {
            $this->warn('risk lookup', $e);
        }

        return min(PowSettings::MAX_ADAPTIVE_BUMP, $bump);
    }

    public function recordFailure(string $ip): void
    {
        try {
            if (Ip::isPublic($ip)) {
                $this->cache->hit($this->ipKey('fail', $ip), self::RISK_WINDOW);
            }

            $this->cache->hit($this->globalKey(), self::GLOBAL_WINDOW);
        } catch (\Throwable $e) {
            $this->warn('failure counter', $e);
        }
    }

    public function recordSuccess(string $ip, string $action): void
    {
        if (($action !== 'signup' && $action !== 'forgot') || ! Ip::isPublic($ip)) {
            return;
        }

        try {
            $this->cache->hit($this->ipKey('ok:' . $action, $ip), self::RISK_WINDOW);
        } catch (\Throwable $e) {
            $this->warn('success counter', $e);
        }
    }

    public function instancePrefix(): string
    {
        return 'powcaptcha:' . substr(hash('sha256', $this->settings->secret()), 0, 8) . ':';
    }

    private function ipKey(string $type, string $ip): string
    {
        $hash = substr(hash_hmac('sha256', Ip::bucket($ip), $this->settings->secret()), 0, 24);

        return $this->instancePrefix() . 'risk:' . $type . ':' . $hash;
    }

    private function globalKey(): string
    {
        return $this->instancePrefix() . 'risk:global:' . intdiv(time(), self::GLOBAL_WINDOW);
    }

    private function warn(string $what, \Throwable $e): void
    {
        $this->logger->warning('[fla-powcaptcha] Cache problem in ' . $what . ' (continuing): ' . $e->getMessage());
    }
}
