<?php

namespace PeopleInside\PowCaptcha\Controller;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PowCaptchaChallengeController implements RequestHandlerInterface
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $challenge  = bin2hex(random_bytes(16)); // 32 hex chars, 128-bit randomness
        $difficulty = (int) $this->settings->get('peopleinside-powcaptcha.difficulty', 3);
        $ttl        = 300; // 5 minutes

        // Store the challenge so the server can verify it later.
        $this->cache->put('powcaptcha:chal:' . $challenge, true, $ttl);

        return new JsonResponse([
            'challenge'  => $challenge,
            'difficulty' => $difficulty,
        ]);
    }
}
