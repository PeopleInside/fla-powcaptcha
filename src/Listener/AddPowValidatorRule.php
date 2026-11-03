<?php

namespace PeopleInside\PowCaptcha\Listener;

use Flarum\Api\ForgotPasswordValidator;
use Flarum\Foundation\AbstractValidator;
use Flarum\Forum\LogInValidator;
use Flarum\Locale\Translator;
use Illuminate\Validation\Validator;
use PeopleInside\PowCaptcha\Service\PowSettings;
use PeopleInside\PowCaptcha\Service\PowTokenVerifier;
use PeopleInside\PowCaptcha\Support\CaptchaTokenExtractor;

/**
 * Invokable class used by `Extend\Validator::configure()` for the login and the
 * forgot-password forms. Flarum calls it with the Flarum validator being configured
 * and the underlying Illuminate validator instance.
 */
class AddPowValidatorRule
{
    public function __construct(
        private readonly PowSettings $settings,
        private readonly PowTokenVerifier $verifier,
        private readonly Translator $translator
    ) {
    }

    public function __invoke(AbstractValidator $flarumValidator, Validator $laravelValidator): void
    {
        $action = match (true) {
            $flarumValidator instanceof LogInValidator => 'login',
            $flarumValidator instanceof ForgotPasswordValidator => 'forgot',
            default => null,
        };

        if ($action === null || ! $this->settings->enabledFor($action)) {
            return;
        }

        // Implicit rule: it also runs when the token is missing or empty, so those
        // attempts are counted and logged instead of being skipped by "required".
        $laravelValidator->addImplicitExtension(
            'pow_captcha',
            function (string $attribute, mixed $value) use ($action, $laravelValidator): bool {
                if (CaptchaTokenExtractor::honeypotValue($laravelValidator->getData()) !== '') {
                    return $this->verifier->rejectHoneypot($action)->ok;
                }

                return $this->verifier->verify(is_string($value) ? $value : '', $action)->ok;
            }
        );

        $laravelValidator->addRules(['captchaToken' => ['pow_captcha']]);

        if (method_exists($laravelValidator, 'setCustomMessages')) {
            $laravelValidator->setCustomMessages([
                'captchaToken.pow_captcha' => $this->translator->trans('peopleinside-powcaptcha.validation.pow_captcha'),
            ]);
        }
    }
}
