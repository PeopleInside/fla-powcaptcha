<?php

namespace PeopleInside\PowCaptcha\Support;

use Flarum\User\Exception\InvalidConfirmationTokenException;
use Flarum\User\RegistrationToken;
use Illuminate\Support\Arr;

final class CaptchaTokenExtractor
{
    /**
     * Name of the honeypot field. Deliberately unique (no other extension uses it,
     * so it cannot clash with real user attributes) and not a typical autofill
     * target, while still looking attractive to form-filling bots.
     */
    public const HONEYPOT_FIELD = 'pow_website';

    /**
     * Extract the PoW token from registration payloads across Flarum 1.x and 2.x.
     */
    public static function fromRegistrationData(array $data): string
    {
        $token = Arr::get($data, 'attributes.captchaToken')
            ?? Arr::get($data, 'captchaToken')
            ?? Arr::get($data, 'data.attributes.captchaToken');

        return is_string($token) ? $token : '';
    }

    /**
     * Returns the raw honeypot value ('' when absent). Any non-empty value, of any
     * type, means a bot filled the field.
     */
    public static function honeypotValue(array $data): string
    {
        $value = Arr::get($data, 'attributes.' . self::HONEYPOT_FIELD)
            ?? Arr::get($data, self::HONEYPOT_FIELD)
            ?? Arr::get($data, 'data.attributes.' . self::HONEYPOT_FIELD);

        if ($value === null || $value === '' || $value === false || $value === []) {
            return '';
        }

        return is_scalar($value) ? (string) $value : 'x';
    }

    /**
     * Whether this registration is completed through a genuine, previously issued
     * OAuth registration token (Flarum core's own `RegistrationToken`), in which case
     * the PoW captcha can safely be skipped.
     *
     * A non-empty string alone proves nothing, so the token is validated with the very
     * same rule Flarum core uses. Any failure falls through to the normal PoW check.
     */
    public static function usesOAuthRegistrationToken(array $data): bool
    {
        $token = Arr::get($data, 'attributes.token')
            ?? Arr::get($data, 'token')
            ?? Arr::get($data, 'data.attributes.token');

        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            RegistrationToken::validOrFail($token);

            return true;
        } catch (InvalidConfirmationTokenException $e) {
            return false;
        } catch (\Throwable $e) {
            // Unexpected error (e.g. DB unavailable): never grant a skip we cannot verify.
            return false;
        }
    }
}
