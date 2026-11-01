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
 * Optional protection for the public `POST /api/token` endpoint.
 *
 * Flarum's own login form posts to `/login`, which calls `/api/token` internally,
 * so the login validator never sees a bot that talks to `/api/token` directly. When
 * enabled, direct (non-internal) calls must carry a valid PoW token obtained with
 * `GET /api/powcaptcha/challenge?action=token`.
 *
 * Internal calls are recognised through Flarum core's own request marker. Core
 * versions without that marker are left untouched (the guard then does nothing)
 * rather than risk blocking the regular login form.
 */
class ProtectTokenEndpoint implements MiddlewareInterface
{
    public function __construct(
        private readonly PowSettings $settings,
        private readonly PowTokenVerifier $verifier,
        private readonly Translator $translator
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('routeName') !== 'token'
            || $request->getMethod() !== 'POST'
            || ! $this->settings->enabledFor('token')
            || ! method_exists(RequestUtil::class, 'isInternal')
            || RequestUtil::isInternal($request)
        ) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $result = CaptchaTokenExtractor::honeypotValue($body) !== ''
            ? $this->verifier->rejectHoneypot('token', $request)
            : $this->verifier->verify(CaptchaTokenExtractor::fromRegistrationData($body), 'token', $request);

        if (! $result->ok) {
            throw new ValidationException([
                'captchaToken' => [$this->translator->trans('peopleinside-powcaptcha.validation.pow_captcha')],
            ]);
        }

        return $handler->handle($request);
    }
}
