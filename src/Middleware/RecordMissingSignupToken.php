<?php

namespace PeopleInside\PowCaptcha\Middleware;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use PeopleInside\PowCaptcha\Service\PowSettings;
use PeopleInside\PowCaptcha\Service\PowTokenVerifier;
use PeopleInside\PowCaptcha\Support\CaptchaTokenExtractor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Flarum 2.x: records and rejects sign-ups that carry NO captcha token (or a filled
 * honeypot without a token).
 *
 * Without this, such requests are stopped by the `required` rule of the `captchaToken`
 * field before the PoW rule runs, so they were never written to the event log and never
 * fed the adaptive difficulty. Requests that DO carry a token are left to the field rule.
 */
class RecordMissingSignupToken implements MiddlewareInterface
{
    public function __construct(
        private readonly PowSettings $settings,
        private readonly PowTokenVerifier $verifier,
        private readonly Translator $translator
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('routeName') !== 'users.create'
            || $request->getMethod() !== 'POST'
            || ! $this->settings->enabledFor('signup')
        ) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        if (CaptchaTokenExtractor::fromRegistrationData($body) !== ''
            || CaptchaTokenExtractor::usesOAuthRegistrationToken($body)
            || $this->actorIsAdmin($request)
        ) {
            return $handler->handle($request);
        }

        CaptchaTokenExtractor::honeypotValue($body) !== ''
            ? $this->verifier->rejectHoneypot('signup', $request)
            : $this->verifier->verify('', 'signup', $request);

        throw new ValidationException([
            'captchaToken' => $this->translator->trans('peopleinside-powcaptcha.validation.pow_captcha'),
        ]);
    }

    private function actorIsAdmin(ServerRequestInterface $request): bool
    {
        try {
            return RequestUtil::getActor($request)->isAdmin();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
