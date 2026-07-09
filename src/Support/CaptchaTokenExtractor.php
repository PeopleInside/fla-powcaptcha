<?php

namespace PeopleInside\PowCaptcha\Support;

use Flarum\User\Exception\InvalidConfirmationTokenException;
use Flarum\User\RegistrationToken;
use Illuminate\Support\Arr;

final class CaptchaTokenExtractor
{
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

    public static function fromHoneypotData(array $data): string
    {
        $honeypot = Arr::get($data, 'attributes.email_confirmation')
            ?? Arr::get($data, 'email_confirmation')
            ?? Arr::get($data, 'data.attributes.email_confirmation');

        return is_string($honeypot) ? $honeypot : '';
    }

    /**
     * Whether this registration is being completed via a genuine, previously-issued
     * OAuth registration token (Flarum core's own `RegistrationToken` artefact),
     * in which case the PoW captcha can safely be skipped.
     *
     * IMPORTANT: this does NOT simply check whether a `token` attribute is present.
     * A non-empty string alone proves nothing — any bot can send `"token": "x"` in
     * its registration payload. We instead validate the token against the exact
     * same store/rule Flarum core uses (`RegistrationToken::validOrFail()`), so the
     * skip is only granted for a token that was genuinely issued after a real OAuth
     * provider authentication and hasn't expired. If validation fails for any reason,
     * we fall through to requiring the normal PoW check.
     */
    public static function usesOAuthRegistrationToken(array $data): bool
    {
        $token = Arr::get($data, 'attributes.token')
            ?? Arr::get($data, 'token')
            ?? Arr::get($data, 'data.attributes.token');

        if (!is_string($token) || $token === '') {
            return false;
        }

        try {
            RegistrationToken::validOrFail($token);

            return true;
        } catch (InvalidConfirmationTokenException $e) {
            return false;
        } catch (\Throwable $e) {
            // Any unexpected error (e.g. DB unavailable) must fail closed: do NOT
            // grant a captcha skip on an error we can't positively verify.
            return false;
        }
    }
}
