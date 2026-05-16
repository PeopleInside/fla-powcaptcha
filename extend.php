<?php

use Flarum\Extend;
use Flarum\Forum\LogInValidator;
use Flarum\Api\ForgotPasswordValidator;
use Flarum\User\Event\Saving as UserSaving;
use PeopleInside\PowCaptcha\Controller\PowCaptchaChallengeController;
use PeopleInside\PowCaptcha\Listener\AddPowValidatorRule;
use PeopleInside\PowCaptcha\Listener\ValidateRegistrationPow;

return [
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

    // ── Event listener: registration (User\Event\Saving) ──────────────
    (new Extend\Event())
        ->listen(UserSaving::class, ValidateRegistrationPow::class),
];
