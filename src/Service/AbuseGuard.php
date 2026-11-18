<?php

namespace PeopleInside\PowCaptcha\Service;

use Illuminate\Database\ConnectionInterface;
use PeopleInside\PowCaptcha\Support\CacheGuard;
use PeopleInside\PowCaptcha\Support\Ip;
use Psr\Log\LoggerInterface;

/**
 * Per-network limits:
 *  - registrations: max N per rolling 24 h (persistent, survives cache clears);
 *  - login / password reset / token / sign-up ATTEMPTS: fixed window counters, and once
 *    the limit is exceeded the network stays locked for the whole lockout period.
 *
 * Private/reserved addresses are never limited (they usually mean "behind a proxy
 * without real-IP configuration": everybody would share one bucket).
 */
class AbuseGuard
{
    public const SIGNUP_WINDOW = 86400;

    /** action => [window seconds, lockout seconds] */
    private const ATTEMPT_RULES = [
        'login' => [600, 900],
        'token' => [600, 900],
        'forgot' => [3600, 3600],
        'signup' => [600, 900],
    ];

    private const SIGNUP_ATTEMPT_LIMIT = 10;

    public function __construct(
        private readonly CacheGuard $cache,
        private readonly RiskTracker $risk,
        private readonly PowSettings $settings,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Seconds until this network may register again (0 = allowed). */
    public function signupRetryAfter(string $ip): int
    {
        $limit = $this->settings->signupLimit();

        if ($limit < 1 || ! Ip::isPublic($ip)) {
            return 0;
        }

        try {
            $rows = $this->db->table('powcaptcha_signups')
                ->where('ip_hash', $this->risk->ipHash($ip, 32))
                ->where('created_at', '>=', gmdate('Y-m-d H:i:s', time() - self::SIGNUP_WINDOW))
                ->orderByDesc('created_at')
                ->limit($limit)
                ->pluck('created_at')
                ->all();

            if (count($rows) >= $limit) {
                // The oldest of the last N registrations is the first to leave the window.
                $oldest = (int) strtotime(end($rows) . ' UTC');

                return max(60, $oldest + self::SIGNUP_WINDOW - time());
            }
        } catch (\Throwable $e) {
            $this->warn('signup limit', $e);
        }

        return 0;
    }

    /** Call after a registration really happened. */
    public function recordSignup(string $ip): void
    {
        if (! Ip::isPublic($ip)) {
            return;
        }

        try {
            $this->db->table('powcaptcha_signups')->insert([
                'ip_hash' => $this->risk->ipHash($ip, 32),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $this->db->table('powcaptcha_signups')
                ->where('created_at', '<', gmdate('Y-m-d H:i:s', time() - 2 * self::SIGNUP_WINDOW))
                ->delete();
        } catch (\Throwable $e) {
            $this->warn('signup record', $e);
        }
    }

    /**
     * Counts one attempt and returns the seconds the network must wait (0 = allowed).
     * Fail-open on cache errors for these limits (the PoW itself still fails closed).
     */
    public function attemptRetryAfter(string $action, string $ip): int
    {
        $rule = self::ATTEMPT_RULES[$action] ?? null;
        $limit = match ($action) {
            'login', 'token' => $this->settings->loginLimit(),
            'forgot' => $this->settings->forgotLimit(),
            'signup' => self::SIGNUP_ATTEMPT_LIMIT,
            default => 0,
        };

        if ($rule === null || $limit < 1 || ! Ip::isPublic($ip)) {
            return 0;
        }

        [$window, $lockout] = $rule;
        $prefix = $this->risk->instancePrefix() . 'abuse:' . $action . ':' . $this->risk->ipHash($ip);

        try {
            $lockedUntil = $this->cache->count($prefix . ':lock');

            if ($lockedUntil > time()) {
                return $lockedUntil - time();
            }

            $count = $this->cache->hit($prefix . ':n:' . intdiv(time(), $window), $window);

            if ($count > $limit) {
                $this->cache->put($prefix . ':lock', time() + $lockout, $lockout);
                $this->risk->recordFailure($ip);

                return $lockout;
            }
        } catch (\Throwable $e) {
            $this->warn('attempt limit', $e);
        }

        return 0;
    }

    private function warn(string $what, \Throwable $e): void
    {
        $this->logger->warning('[fla-powcaptcha] Problem in ' . $what . ' (continuing): ' . $e->getMessage());
    }
}
