<?php

namespace PeopleInside\PowCaptcha\Listener;

use Flarum\Api\ForgotPasswordValidator;
use Flarum\Foundation\AbstractValidator;
use Flarum\Forum\LogInValidator;
use Flarum\Locale\Translator;
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
        private readonly PowTokenVerifier $tokenVerifier,
        private readonly Translator $translator
    ) {
    }

    public function __invoke(AbstractValidator $flarumValidator, Validator $laravelValidator): void
    {
        $difficultySetting = $this->settings->get('peopleinside-powcaptcha.difficulty', 4);
        $difficulty = is_numeric($difficultySetting) ? (int) $difficultySetting : 4;

        if ($difficulty < 3 || $difficulty > 5) {
            $this->settings->set('peopleinside-powcaptcha.difficulty', 4);
            $difficulty = 4;
        }

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
            $laravelValidator->addRules(['captchaToken' => ['required', 'pow_captcha']]);
        }

        if ($flarumValidator instanceof ForgotPasswordValidator
            && $this->settings->get('peopleinside-powcaptcha.enabled_forgot', true)
        ) {
            $laravelValidator->addRules(['captchaToken' => ['required', 'pow_captcha']]);
        }
    }

    private function resolveValidationMessage(): string
    {
        return $this->translator->trans('peopleinside-powcaptcha.validation.pow_captcha');
    }
}
