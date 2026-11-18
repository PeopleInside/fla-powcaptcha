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
