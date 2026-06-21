<?php

namespace PeopleInside\PowCaptcha\Listener;

use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Saving;
use PeopleInside\PowCaptcha\Service\PowTokenVerifier;
use PeopleInside\PowCaptcha\Support\CaptchaTokenExtractor;

/**
 * Validates the PoW token during user registration (User\Event\Saving).
 *
 * Registration is NOT covered by the Extend\Validator extender because it
 * fires a domain event instead of calling a dedicated validator directly.
 */
class ValidateRegistrationPow
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly PowTokenVerifier $tokenVerifier,
        private readonly Translator $translator
    ) {
    }

    public function handle(Saving $event): void
    {
        // Only apply to brand-new users, skip admin-created accounts.
        if ($event->user->exists) {
            return;
        }

        if (!$this->settings->get('peopleinside-powcaptcha.enabled_signup', true)) {
            return;
        }

        if ($event->actor->isAdmin()) {
            return;
        }

        if (CaptchaTokenExtractor::usesOAuthRegistrationToken($event->data)) {
            return;
        }

        $token = CaptchaTokenExtractor::fromRegistrationData($event->data);
        $honeypot = CaptchaTokenExtractor::fromHoneypotData($event->data);
        $difficulty = (int) $this->settings->get('peopleinside-powcaptcha.difficulty', 4);

        if ($honeypot !== '') {
            throw new \Flarum\Foundation\ValidationException(
                ['captchaToken' => [$this->translator->trans('peopleinside-powcaptcha.validation.pow_captcha')]]
            );
        }

        if (!is_string($token) || !$this->tokenVerifier->verifyToken($token, $difficulty)) {
            throw new \Flarum\Foundation\ValidationException(
                ['captchaToken' => [$this->translator->trans('peopleinside-powcaptcha.validation.pow_captcha')]]
            );
        }
    }
}
