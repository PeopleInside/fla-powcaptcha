<?php

namespace PeopleInside\PowCaptcha\Support;

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

    public static function usesOAuthRegistrationToken(array $data): bool
    {
        $token = Arr::get($data, 'attributes.token')
            ?? Arr::get($data, 'token')
            ?? Arr::get($data, 'data.attributes.token');

        return is_string($token) && $token !== '';
    }
}
