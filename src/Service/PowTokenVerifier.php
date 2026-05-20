<?php

namespace PeopleInside\PowCaptcha\Service;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

class PowTokenVerifier
{
    public const CHALLENGE_CACHE_PREFIX = 'powcaptcha:chal:';
    public const MAX_DIFFICULTY = 8;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function verifyToken(string $token, int $difficulty): bool
    {
        $parts = explode(':', $token, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$challenge, $nonce] = $parts;

        if (!ctype_xdigit($challenge) || strlen($challenge) !== 32) {
            return false;
        }

        if (!ctype_digit($nonce)) {
            return false;
        }

        $hash           = hash('sha256', $challenge . ':' . $nonce);
        $requiredPrefix = str_repeat('0', self::normalizeDifficulty($difficulty));

        if (!str_starts_with($hash, $requiredPrefix)) {
            return false;
        }

        // F-06: If the cache backend is unavailable, pull() may throw.
        // We treat a cache failure as a verification failure (fail-closed)
        // and log the error so operators are alerted.
        try {
            return $this->cache->pull(self::CHALLENGE_CACHE_PREFIX . $challenge) !== null;
        } catch (\Throwable $e) {
            $this->logger->error('[powcaptcha] Cache read failed during token verification: ' . $e->getMessage());

            return false;
        }
    }

    public static function normalizeDifficulty(int $difficulty): int
    {
        return max(1, min(self::MAX_DIFFICULTY, $difficulty));
    }
}
