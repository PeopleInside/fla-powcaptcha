<?php

namespace PeopleInside\PowCaptcha\Listener;

use Flarum\Api\ForgotPasswordValidator;
use Flarum\Foundation\AbstractValidator;
use Flarum\Forum\LogInValidator;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Validation\Validator;
use PeopleInside\PowCaptcha\Service\PowTokenVerifier;

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
        private readonly SettingsRepositoryInterface $settings,
        private readonly PowTokenVerifier $tokenVerifier
    ) {
    }

    public function __invoke(AbstractValidator $flarumValidator, Validator $laravelValidator): void
    {
        $difficulty = (int) $this->settings->get('peopleinside-powcaptcha.difficulty', 3);

        // Register the custom "pow_captcha" rule with the Illuminate validator.
        $laravelValidator->addExtension(
            'pow_captcha',
            function (string $attribute, mixed $value) use ($difficulty): bool {
                return is_string($value) && $this->tokenVerifier->verifyToken($value, $difficulty);
            }
        );

        // Replace the default "{attribute} pow_captcha" message when supported.
        if (method_exists($laravelValidator, 'setCustomMessages')) {
            $laravelValidator->setCustomMessages([
                'captchaToken.pow_captcha' => $this->resolveValidationMessage(),
            ]);
        }

        // Only add the rule when the corresponding setting is enabled.
        if ($flarumValidator instanceof LogInValidator
            && $this->settings->get('peopleinside-powcaptcha.enabled_login', true)
        ) {
            $this->appendCaptchaRules($laravelValidator);
        }

        if ($flarumValidator instanceof ForgotPasswordValidator
            && $this->settings->get('peopleinside-powcaptcha.enabled_forgot', true)
        ) {
            $this->appendCaptchaRules($laravelValidator);
        }
    }

    private function appendCaptchaRules(Validator $laravelValidator): void
    {
        $rules = ['captchaToken' => ['required', 'pow_captcha']];

        if (method_exists($laravelValidator, 'appendRules')) {
            $laravelValidator->appendRules($rules);

            return;
        }

        $laravelValidator->addRules($rules);
    }

    private function resolveValidationMessage(): string
    {
        // Flarum's translator is not available here; return a plain fallback.
        // The JS frontend shows localised messages for the inline widget.
        return 'The security challenge could not be verified. Please try again.';
    }
}
