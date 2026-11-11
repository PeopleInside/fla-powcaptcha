<?php

require_once __DIR__ . '/autoload.php';

use Flarum\Api\Middleware\ThrottleApi;
use Flarum\Api\ForgotPasswordValidator;
use Flarum\Extend;
use Flarum\Forum\LogInValidator;
use PeopleInside\PowCaptcha\Api\RegisterUserCaptchaFields;
use PeopleInside\PowCaptcha\Controller\ClearLogsController;
use PeopleInside\PowCaptcha\Controller\ListLogsController;
use PeopleInside\PowCaptcha\Controller\PowCaptchaChallengeController;
use PeopleInside\PowCaptcha\Listener\AddPowValidatorRule;
use PeopleInside\PowCaptcha\Listener\ValidateRegistrationPow;
use PeopleInside\PowCaptcha\Middleware\CaptureClientContext;
use PeopleInside\PowCaptcha\Middleware\ProtectTokenEndpoint;
use PeopleInside\PowCaptcha\Provider\PowServiceProvider;

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

    // ── Services ───────────────────────────────────────────────────────
    (new Extend\ServiceProvider())
        ->register(PowServiceProvider::class),

    // ── Settings ───────────────────────────────────────────────────────
    (new Extend\Settings())
        ->default('peopleinside-powcaptcha.level', 2)
        ->default('peopleinside-powcaptcha.enabled_login', true)
        ->default('peopleinside-powcaptcha.enabled_signup', true)
        ->default('peopleinside-powcaptcha.enabled_forgot', true)
        ->default('peopleinside-powcaptcha.adaptive', true)
        ->default('peopleinside-powcaptcha.log_enabled', true)
        ->default('peopleinside-powcaptcha.log_ip', false)
        ->default('peopleinside-powcaptcha.protect_token_endpoint', false)
        ->serializeToForum('peopleinside-powcaptcha.enabledLogin', 'peopleinside-powcaptcha.enabled_login', 'boolval')
        ->serializeToForum('peopleinside-powcaptcha.enabledSignup', 'peopleinside-powcaptcha.enabled_signup', 'boolval')
        ->serializeToForum('peopleinside-powcaptcha.enabledForgot', 'peopleinside-powcaptcha.enabled_forgot', 'boolval'),

    // ── API routes: challenge issuing + admin-only event log ───────────
    (new Extend\Routes('api'))
        ->get('/powcaptcha/challenge', 'powcaptcha.challenge', PowCaptchaChallengeController::class)
        ->get('/powcaptcha/logs', 'powcaptcha.logs.index', ListLogsController::class)
        ->delete('/powcaptcha/logs', 'powcaptcha.logs.clear', ClearLogsController::class),

    // ── Middleware ─────────────────────────────────────────────────────
    (new Extend\Middleware('forum'))
        ->add(CaptureClientContext::class),

    (new Extend\Middleware('api'))
        ->add(CaptureClientContext::class)
        ->insertBefore(ThrottleApi::class, ProtectTokenEndpoint::class),

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
