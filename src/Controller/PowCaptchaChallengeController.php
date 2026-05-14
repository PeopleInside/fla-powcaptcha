<?php

namespace PeopleInside\PowCaptcha\Controller;

use JsonException;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PowCaptchaChallengeController implements RequestHandlerInterface
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $challenge  = bin2hex(random_bytes(16)); // 32 hex chars, 128-bit randomness
        $difficulty = (int) $this->settings->get('peopleinside-powcaptcha.difficulty', 3);
        $ttl        = 300; // 5 minutes

        // Store the challenge so the server can verify it later.
        $this->cache->store()->put('powcaptcha:chal:' . $challenge, true, $ttl);

        try {
            $payload = json_encode([
                'challenge'  => $challenge,
                'difficulty' => $difficulty,
            ], JSON_THROW_ON_ERROR);

            return $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($payload));
        } catch (JsonException) {
            $errorPayload = json_encode(['error' => 'Failed to generate challenge response']) ?: '{"error":"Failed to generate challenge response"}';

            return $this->responseFactory->createResponse(500)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($errorPayload));
        }
    }
}
