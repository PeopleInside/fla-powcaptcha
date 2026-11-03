<?php

namespace PeopleInside\PowCaptcha\Listener;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\User\Event\Saving;
use PeopleInside\PowCaptcha\Service\PowSettings;
use PeopleInside\PowCaptcha\Service\PowTokenVerifier;
use PeopleInside\PowCaptcha\Support\CaptchaTokenExtractor;

/**
 * Flarum 1.x only: validates the PoW token while a new user is being saved.
 * (On Flarum 2.x the same check is done by Api\RegisterUserCaptchaFields.)
 */
class ValidateRegistrationPow
{
    public function __construct(
        private readonly PowSettings $settings,
        private readonly PowTokenVerifier $verifier,
        private readonly Translator $translator
    ) {
    }

    public function handle(Saving $event): void
    {
        // Only brand-new users; skip admin-created accounts.
        if ($event->user->exists || ! $this->settings->enabledFor('signup') || $event->actor->isAdmin()) {
            return;
        }

        if (CaptchaTokenExtractor::usesOAuthRegistrationToken($event->data)) {
            return;
        }

        $result = CaptchaTokenExtractor::honeypotValue($event->data) !== ''
            ? $this->verifier->rejectHoneypot('signup')
            : $this->verifier->verify(CaptchaTokenExtractor::fromRegistrationData($event->data), 'signup');

        if (! $result->ok) {
            throw new ValidationException([
                'captchaToken' => [$this->translator->trans('peopleinside-powcaptcha.validation.pow_captcha')],
            ]);
        }
    }
}
