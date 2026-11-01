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

    /** Leading hex zeros required at each level (16^n hashes on average). */
    private const LEVEL_TO_DIFFICULTY = [1 => 3, 2 => 4, 3 => 5];

    /** Absolute ceiling, whatever the adaptive logic asks for (~16.7M hashes). */
    public const HARD_CAP = 6;

    /** Maximum extra zeros the adaptive logic may add on top of the base level. */
    public const MAX_ADAPTIVE_BUMP = 2;

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function level(): int
    {
        $raw = $this->settings->get(self::PREFIX . 'level');

        if (is_numeric($raw) && (int) $raw >= 1 && (int) $raw <= 3) {
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
            return 2;
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

    public function baseDifficulty(): int
    {
        return self::LEVEL_TO_DIFFICULTY[$this->level()];
    }

    public function maxDifficulty(): int
    {
        return min(self::HARD_CAP, $this->baseDifficulty() + self::MAX_ADAPTIVE_BUMP);
    }

    public function adaptive(): bool
    {
        return $this->flag('adaptive', true);
    }

    public function logEnabled(): bool
    {
        return $this->flag('log_enabled', true);
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
