<?php

namespace PeopleInside\PowCaptcha\Listener;

use Flarum\Api\ForgotPasswordValidator;
use Flarum\Foundation\AbstractValidator;
use Flarum\Forum\LogInValidator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Validation\Validator;

/**
 * Invokable class used by `Extend\Validator::configure()`.
 *
 * It is called by Flarum with two arguments:
 *   $flarumValidator  – the Flarum AbstractValidator being configured
 *   $laravelValidator – the underlying Illuminate Validator instance
 */
class AddPowValidatorRule
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly SettingsRepositoryInterface $settings
    ) {
    }

    public function __invoke(AbstractValidator $flarumValidator, Validator $laravelValidator): void
    {
        $difficulty = (int) $this->settings->get('peopleinside-powcaptcha.difficulty', 3);

        // Register the custom "pow_captcha" rule with the Illuminate validator.
        $laravelValidator->addExtension(
            'pow_captcha',
            function (string $attribute, mixed $value) use ($difficulty): bool {
                return is_string($value) && $this->verifyToken($value, $difficulty);
            }
        );

        // Replace the default "{attribute} pow_captcha" message.
        $laravelValidator->addCustomMessages([
            'captchaToken.pow_captcha' => $this->resolveValidationMessage(),
        ]);

        // Only add the rule when the corresponding setting is enabled.
        if ($flarumValidator instanceof LogInValidator
            && $this->settings->get('peopleinside-powcaptcha.enabled_login', true)
        ) {
            $laravelValidator->addRules(['captchaToken' => ['required', 'pow_captcha']]);
        }

        if ($flarumValidator instanceof ForgotPasswordValidator
            && $this->settings->get('peopleinside-powcaptcha.enabled_forgot', true)
        ) {
            $laravelValidator->addRules(['captchaToken' => ['required', 'pow_captcha']]);
        }
    }

    // ─── Verification logic ───────────────────────────────────────────

    /**
     * Verify that a submitted token is a valid, single-use PoW solution.
     *
     * Token format: "<challenge>:<nonce>"
     *   challenge – 32 hex chars (128-bit random value issued by the server)
     *   nonce     – decimal integer found by the client
     */
    private function verifyToken(string $token, int $difficulty): bool
    {
        $parts = explode(':', $token, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$challenge, $nonce] = $parts;

        // Validate format constraints.
        if (!ctype_xdigit($challenge) || strlen($challenge) !== 32) {
            return false;
        }

        if (!ctype_digit($nonce)) {
            return false;
        }

        // Challenge must exist in the cache (issued by us, not expired).
        $cacheKey = 'powcaptcha:chal:' . $challenge;

        $cache = $this->cache->store();

        if (!$cache->has($cacheKey)) {
            return false;
        }

        // Verify the hash meets the difficulty requirement.
        $hash           = hash('sha256', $challenge . ':' . $nonce);
        $requiredPrefix = str_repeat('0', $difficulty);

        if (!str_starts_with($hash, $requiredPrefix)) {
            return false;
        }

        // Consume the challenge to prevent replay attacks.
        $cache->forget($cacheKey);

        return true;
    }

    private function resolveValidationMessage(): string
    {
        // Flarum's translator is not available here; return a plain fallback.
        // The JS frontend shows localised messages for the inline widget.
        return 'The security challenge could not be verified. Please try again.';
    }
}
