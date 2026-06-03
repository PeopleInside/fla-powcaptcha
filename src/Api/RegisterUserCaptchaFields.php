<?php

namespace PeopleInside\PowCaptcha\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Support\Arr;
use PeopleInside\PowCaptcha\Service\PowTokenVerifier;
use PeopleInside\PowCaptcha\Support\CaptchaTokenExtractor;

/**
 * Registers a transient captchaToken attribute on user creation (Flarum 2.x JSON:API).
 */
class RegisterUserCaptchaFields
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly PowTokenVerifier $tokenVerifier
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Str::make('captchaToken')
                ->visible(false)
                ->writable(fn (User $user, Context $context) => $this->shouldValidate($context))
                ->required(fn (Context $context) => $this->shouldValidate($context))
                ->rule(
                    function (Context $context) {
                        $difficulty = (int) $this->settings->get('peopleinside-powcaptcha.difficulty', 3);

                        return function (string $attribute, mixed $value, \Closure $fail) use ($difficulty): void {
                            if (! is_string($value) || ! $this->tokenVerifier->verifyToken($value, $difficulty)) {
                                $fail($this->validationMessage());
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
        if (! $context->creating()) {
            return false;
        }

        if (! $this->settings->get('peopleinside-powcaptcha.enabled_signup', true)) {
            return false;
        }

        if ($context->getActor()->isAdmin()) {
            return false;
        }

        $bodyData = Arr::get($context->body(), 'data', []);

        if (CaptchaTokenExtractor::usesOAuthRegistrationToken($bodyData)) {
            return false;
        }

        return true;
    }

    private function validationMessage(): string
    {
        return 'The security challenge could not be verified. Please try again.';
    }
}
