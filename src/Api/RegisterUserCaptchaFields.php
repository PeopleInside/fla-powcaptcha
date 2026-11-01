<?php

namespace PeopleInside\PowCaptcha\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Locale\Translator;
use Flarum\User\User;
use Illuminate\Support\Arr;
use PeopleInside\PowCaptcha\Service\PowSettings;
use PeopleInside\PowCaptcha\Service\PowTokenVerifier;
use PeopleInside\PowCaptcha\Support\CaptchaTokenExtractor;

/**
 * Flarum 2.x: registers the transient `captchaToken` and honeypot attributes on user
 * creation. Neither is ever persisted.
 */
class RegisterUserCaptchaFields
{
    public function __construct(
        private readonly PowSettings $settings,
        private readonly PowTokenVerifier $verifier,
        private readonly Translator $translator
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Str::make(CaptchaTokenExtractor::HONEYPOT_FIELD)
                ->visible(false)
                ->writable(fn (User $user, Context $context) => $this->shouldValidate($context))
                ->save(fn () => null),

            Schema\Str::make('captchaToken')
                ->visible(false)
                ->writable(fn (User $user, Context $context) => $this->shouldValidate($context))
                ->required(fn (Context $context) => $this->shouldValidate($context))
                ->rule(
                    function (Context $context) {
                        $request = $context->request;

                        return function (string $attribute, mixed $value, \Closure $fail) use ($context, $request): void {
                            $body = Arr::get($context->body(), 'data', []);

                            $result = CaptchaTokenExtractor::honeypotValue($body) !== ''
                                ? $this->verifier->rejectHoneypot('signup', $request)
                                : $this->verifier->verify(is_string($value) ? $value : '', 'signup', $request);

                            if (! $result->ok) {
                                $fail($this->translator->trans('peopleinside-powcaptcha.validation.pow_captcha'));
                            }
                        };
                    },
                    fn (Context $context) => $this->shouldValidate($context)
                )
                ->save(fn () => null),
        ];
    }

    private function shouldValidate(Context $context): bool
    {
        if (! $context->creating() || ! $this->settings->enabledFor('signup') || $context->getActor()->isAdmin()) {
            return false;
        }

        return ! CaptchaTokenExtractor::usesOAuthRegistrationToken(Arr::get($context->body(), 'data', []));
    }
}
