<?php

use Flarum\Extend;
use Flarum\Forum\LogInValidator;
use Flarum\Api\ForgotPasswordValidator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Saving as UserSaving;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PeopleInside\PowCaptcha\Listener\AddPowValidatorRule;
use PeopleInside\PowCaptcha\Listener\ValidateRegistrationPow;

/**
 * Inline API controller used by the PoW challenge route.
 *
 * Keeping this class in extend.php avoids runtime failures when external
 * controller class resolution is unavailable in some deployments.
 */
class PowCaptchaChallengeRouteController implements RequestHandlerInterface
{
    private const CHALLENGE_TTL_SECONDS = 300;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $challenge  = bin2hex(random_bytes(16));
        $difficulty = (int) $this->settings->get('peopleinside-powcaptcha.difficulty', 3);

        $this->cache->put('powcaptcha:chal:' . $challenge, true, self::CHALLENGE_TTL_SECONDS);

        return new JsonResponse([
            'challenge'  => $challenge,
            'difficulty' => $difficulty,
        ], 200);
    }
}

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
        ->get('/powcaptcha/challenge', 'powcaptcha.challenge', PowCaptchaChallengeRouteController::class),

    // ── Validator hooks: login + forgot password ───────────────────────
    (new Extend\Validator(LogInValidator::class))
        ->configure(AddPowValidatorRule::class),

    (new Extend\Validator(ForgotPasswordValidator::class))
        ->configure(AddPowValidatorRule::class),

    // ── Event listener: registration (User\Event\Saving) ──────────────
    (new Extend\Event())
        ->listen(UserSaving::class, ValidateRegistrationPow::class),
];
