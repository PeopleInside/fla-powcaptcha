<?php

namespace PeopleInside\PowCaptcha\Service;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Typed access to the extension settings.
 *
 * Difficulty is stored as a "level" (1–3). The legacy `difficulty` key (3/4/5, i.e.
 * the number of leading hex zeros) is kept untouched in the database and used only
 * as a fallback when `level` has not been written yet.
 */
class PowSettings
{
    public const PREFIX = 'peopleinside-powcaptcha.';

    /**
     * Difficulty is expressed in leading zero BITS of the SHA-256 hash (2^n hashes on
     * average). Levels 1-3 are exactly the old 3/4/5 hex zeros (12/16/20 bits); levels
     * 4-5 are finer steps above them. Note that each extra bit doubles the work.
     */
    private const LEVEL_TO_DIFFICULTY = [1 => 12, 2 => 16, 3 => 20, 4 => 22, 5 => 24];

    public const MAX_LEVEL = 5;
    public const DEFAULT_LEVEL = 3;

    public const LOG_KEEP_DEFAULT = 100;
    public const LOG_KEEP_MIN = 10;
    public const LOG_KEEP_MAX = 1000;

    /** Age-based log retention: on by default, entries older than 30 days are deleted. */
    public const LOG_MAX_AGE_DEFAULT = 30;
    public const LOG_MAX_AGE_MIN = 1;
    public const LOG_MAX_AGE_MAX = 365;

    /** Absolute ceiling for the ADAPTIVE bump, in bits (24 bits = ~16.7M hashes). */
    public const HARD_CAP = 24;

    /** Maximum number of adaptive steps added on top of the base level. */
    public const MAX_ADAPTIVE_BUMP = 2;

    /** Bits added by each adaptive step (4 bits = one old hex zero = 16x the work). */
    public const BITS_PER_STEP = 4;

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function level(): int
    {
        $raw = $this->settings->get(self::PREFIX . 'level');

        if (is_numeric($raw) && (int) $raw >= 1 && (int) $raw <= self::MAX_LEVEL) {
            return (int) $raw;
        }

        return self::levelFromLegacyDifficulty($this->settings->get(self::PREFIX . 'difficulty'));
    }

    /**
     * Maps a value of the old `difficulty` setting to a level, preserving the real
     * work required: old 1 and 3 → level 1, old 2 and 4 → level 2, old 5+ → level 3.
     */
    public static function levelFromLegacyDifficulty(mixed $value): int
    {
        if (! is_numeric($value)) {
            return self::DEFAULT_LEVEL;
        }

        $legacy = (int) $value;

        return match (true) {
            $legacy <= 1 => 1,
            $legacy === 2 => 2,
            $legacy === 3 => 1,
            $legacy === 4 => 2,
            default => 3,
        };
    }

    /** Base difficulty of the configured level, in leading zero bits. */
    public function baseDifficulty(): int
    {
        return self::LEVEL_TO_DIFFICULTY[$this->level()];
    }

    /**
     * Highest difficulty the adaptive logic may reach, in bits. Never lower than the
     * base level itself (levels 4-5 are already at or near the adaptive ceiling).
     */
    public function maxDifficulty(): int
    {
        $base = $this->baseDifficulty();

        return max($base, min(self::HARD_CAP, $base + self::MAX_ADAPTIVE_BUMP * self::BITS_PER_STEP));
    }

    public function adaptive(): bool
    {
        return $this->flag('adaptive', true);
    }

    public function logEnabled(): bool
    {
        return $this->flag('log_enabled', true);
    }

    public function logIp(): bool
    {
        return $this->flag('log_ip', false);
    }

    /**
     * How many events to keep in the admin log PER OUTCOME (passed / failed), so the
     * table holds at most twice this number. Default 100, clamped to 10-1000.
     */
    public function logKeep(): int
    {
        $raw = $this->settings->get(self::PREFIX . 'log_keep');

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return self::LOG_KEEP_DEFAULT;
        }

        return max(self::LOG_KEEP_MIN, min(self::LOG_KEEP_MAX, (int) $raw));
    }

    /** Whether log entries older than logMaxAgeDays() are deleted. On by default. */
    public function logPruneByAge(): bool
    {
        return $this->flag('log_prune_age', true);
    }

    /**
     * Maximum age of a log entry, in days. Default 30, clamped to 1-365. Invalid or
     * empty values fall back to the default.
     */
    public function logMaxAgeDays(): int
    {
        $raw = $this->settings->get(self::PREFIX . 'log_max_age_days');

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return self::LOG_MAX_AGE_DEFAULT;
        }

        return max(self::LOG_MAX_AGE_MIN, min(self::LOG_MAX_AGE_MAX, (int) $raw));
    }

    /** Whether the short privacy notice is shown under the CAPTCHA. On by default. */
    public function privacyNotice(): bool
    {
        return $this->flag('privacy_notice', true);
    }

    /** Optional link to the site's privacy policy (empty = no link). Always sanitised. */
    public function privacyUrl(): string
    {
        return self::sanitizeUrl($this->settings->get(self::PREFIX . 'privacy_url'));
    }

    /**
     * Accepts only absolute http(s) URLs or site-relative paths ("/privacy"); anything
     * else (javascript:, data:, protocol-relative //host, garbage) becomes ''. The value
     * ends up in an href visible to every visitor, so it is never trusted as stored.
     */
    public static function sanitizeUrl(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $url = trim($value);

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return '';
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_contains($url, '\\')) {
            return $url;
        }

        if (preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }

        return '';
    }

    public function behaviorChecks(): bool
    {
        return $this->flag('behavior_checks', true);
    }

    /** Max successful registrations per network in 24 h (0 = unlimited). Default 2. */
    public function signupLimit(): int
    {
        return $this->intSetting('signup_limit', 2, 100);
    }

    /** Max login attempts per network in 10 minutes (0 = unlimited). Default 10. */
    public function loginLimit(): int
    {
        return $this->intSetting('login_limit', 10, 1000);
    }

    /** Max password-reset requests per network in 60 minutes (0 = unlimited). Default 3. */
    public function forgotLimit(): int
    {
        return $this->intSetting('forgot_limit', 3, 1000);
    }

    private function intSetting(string $key, int $default, int $max): int
    {
        $raw = $this->settings->get(self::PREFIX . $key);

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return $default;
        }

        return max(0, min($max, (int) $raw));
    }

    public function enabledFor(string $action): bool
    {
        return match ($action) {
            'login' => $this->flag('enabled_login', true),
            'signup' => $this->flag('enabled_signup', true),
            'forgot' => $this->flag('enabled_forgot', true),
            'token' => $this->flag('protect_token_endpoint', false),
            default => false,
        };
    }

    /** Secret used to sign challenges and to hash identifiers in cache keys. */
    public function secret(): string
    {
        $secret = $this->settings->get(self::PREFIX . 'secret');

        if (! is_string($secret) || strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $this->settings->set(self::PREFIX . 'secret', $secret);
        }

        return $secret;
    }

    private function flag(string $key, bool $default): bool
    {
        $raw = $this->settings->get(self::PREFIX . $key);

        if ($raw === null) {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
