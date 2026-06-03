<?php

use Flarum\Extend;
use Flarum\Forum\LogInValidator;
use Flarum\Api\ForgotPasswordValidator;
use PeopleInside\PowCaptcha\Api\RegisterUserCaptchaFields;
use PeopleInside\PowCaptcha\Controller\PowCaptchaChallengeController;
use PeopleInside\PowCaptcha\Listener\AddPowValidatorRule;
use PeopleInside\PowCaptcha\Listener\ValidateRegistrationPow;

$extenders = [
    // ── Frontend assets ────────────────────────────────────────────────
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    // ── Translations ───────────────────────────────────────────────────
    new Extend\Locales(__DIR__ . '/locale'),

    // ── Settings ───────────────────────────────────────────────────────
    (new Extend\Settings())
        ->default('peopleinside-powcaptcha.difficulty', 3)
        ->default('peopleinside-powcaptcha.enabled_login', true)
        ->default('peopleinside-powcaptcha.enabled_signup', true)
        ->default('peopleinside-powcaptcha.enabled_forgot', true)
        ->serializeToForum('peopleinside-powcaptcha.enabledLogin', 'peopleinside-powcaptcha.enabled_login', 'boolval')
        ->serializeToForum('peopleinside-powcaptcha.enabledSignup', 'peopleinside-powcaptcha.enabled_signup', 'boolval')
        ->serializeToForum('peopleinside-powcaptcha.enabledForgot', 'peopleinside-powcaptcha.enabled_forgot', 'boolval'),

    // ── API route: issue a PoW challenge ───────────────────────────────
    (new Extend\Routes('api'))
        ->get('/powcaptcha/challenge', 'powcaptcha.challenge', PowCaptchaChallengeController::class),

    // ── Validator hooks: login + forgot password ───────────────────────
    (new Extend\Validator(LogInValidator::class))
        ->configure(AddPowValidatorRule::class),

    (new Extend\Validator(ForgotPasswordValidator::class))
        ->configure(AddPowValidatorRule::class),

];

// Flarum 2.x: register captchaToken on the User JSON:API resource.
if (class_exists(\Flarum\Extend\ApiResource::class) && class_exists(\Flarum\Api\Resource\UserResource::class)) {
    $extenders[] = (new Extend\ApiResource(\Flarum\Api\Resource\UserResource::class))
        ->fields(RegisterUserCaptchaFields::class);
} else {
    // Flarum 1.x: validate during User\Event\Saving (before core validation).
    $extenders[] = (new Extend\Event())
        ->listen(\Flarum\User\Event\Saving::class, ValidateRegistrationPow::class);
}

return $extenders;
