<?php

namespace PeopleInside\PowCaptcha\Service;

use PeopleInside\PowCaptcha\Support\CacheGuard;
use PeopleInside\PowCaptcha\Support\ClientContext;
use PeopleInside\PowCaptcha\Support\Ip;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Issues and verifies stateless, signed Proof-of-Work challenges.
 *
 * Challenge format (no ':' inside, so `challenge:nonce` stays unambiguous):
 *
 *     v1.<rand16>.<issuedAt>.<difficulty>.<action>.<bound>.<mac>
 *
 * The MAC is an HMAC-SHA256 (keyed with a per-installation secret) over everything
 * before it plus, for "bound" challenges, the client network bucket. Therefore the
 * server stores nothing when it issues a challenge, the difficulty and the form the
 * challenge was issued for cannot be altered by the client, and the only cache write
 * on the success path is the single-use marker.
 *
 * The client must find a nonce so that SHA-256("<challenge>:<nonce>") starts with
 * <difficulty> hexadecimal zeros.
 */
class PowTokenVerifier
{
    public const VERSION = 'v1';
    public const TTL_SECONDS = 300;
    public const MAX_TOKEN_LENGTH = 200;

    /** Form → single-letter code embedded (and signed) in the challenge. */
    public const ACTIONS = ['login' => 'l', 'signup' => 's', 'forgot' => 'f', 'token' => 't'];

    /** Minimum seconds between challenge issue and submit ("time trap"). */
    private const MIN_AGE_SECONDS = ['signup' => 2];

    private const CHALLENGE_PATTERN = '/^v1\.([0-9a-f]{16})\.(\d{9,11})\.([3-9])\.([lsft])\.([01])\.([0-9a-f]{32})$/';

    public function __construct(
        private readonly PowSettings $settings,
        private readonly RiskTracker $risk,
        private readonly EventLogger $logger,
        private readonly CacheGuard $cache,
        private readonly ClientContext $context
    ) {
    }

    /**
     * @return array{challenge:string,difficulty:int,ttl:int}
     */
    public function issue(string $action, ?ServerRequestInterface $request = null): array
    {
        if (! isset(self::ACTIONS[$action])) {
            throw new \InvalidArgumentException('Unknown action');
        }

        $ip = $this->clientIp($request);
        $bump = $this->risk->bump($ip, $action);
        $difficulty = min($this->settings->maxDifficulty(), $this->settings->baseDifficulty() + $bump);

        // Challenges handed to networks that already look abusive are tied to that
        // network, so they cannot be requested from a clean IP and used from a dirty one.
        // Normal visitors are never bound: no false failures when the IP changes
        // between challenge and submit (dual-stack, mobile networks).
        $bound = $bump > 0 && Ip::isPublic($ip);

        $payload = implode('.', [
            self::VERSION,
            bin2hex(random_bytes(8)),
            $this->now(),
            $difficulty,
            self::ACTIONS[$action],
            $bound ? '1' : '0',
        ]);

        return [
            'challenge' => $payload . '.' . $this->mac($payload, $bound ? Ip::bucket($ip) : ''),
            'difficulty' => $difficulty,
            'ttl' => self::TTL_SECONDS,
        ];
    }

    /**
     * Verifies a `challenge:nonce` token for the given form, consuming it on success.
     * Every outcome feeds the anonymous event log and the abuse counters.
     */
    public function verify(string $token, string $action, ?ServerRequestInterface $request = null): VerifyResult
    {
        $ip = $this->clientIp($request);
        $result = $this->evaluate($token, $action, $ip);

        $this->finish($result, $action, $ip);

        return $result;
    }

    /** Records a honeypot hit as a failed attempt. */
    public function rejectHoneypot(string $action, ?ServerRequestInterface $request = null): VerifyResult
    {
        $result = new VerifyResult(false, 'honeypot');

        $this->finish($result, $action, $this->clientIp($request));

        return $result;
    }

    protected function now(): int
    {
        return time();
    }

    private function evaluate(string $token, string $action, string $ip): VerifyResult
    {
        if ($token === '') {
            return new VerifyResult(false, 'missing');
        }

        $separator = strrpos($token, ':');

        if (strlen($token) > self::MAX_TOKEN_LENGTH || $separator === false) {
            return new VerifyResult(false, 'malformed');
        }

        $challenge = substr($token, 0, $separator);
        $nonce = substr($token, $separator + 1);

        if (! preg_match(self::CHALLENGE_PATTERN, $challenge, $m)
            || $nonce === '' || strlen($nonce) > 12 || ! ctype_digit($nonce)
        ) {
            return new VerifyResult(false, 'malformed');
        }

        [, $rand, $issuedAt, $difficulty, $code, $bound, $mac] = $m;
        $difficulty = (int) $difficulty;

        if (! isset(self::ACTIONS[$action]) || $code !== self::ACTIONS[$action]) {
            return new VerifyResult(false, 'wrong_action', $difficulty);
        }

        $payload = substr($challenge, 0, -33); // strip ".<mac>"
        $bucket = $bound === '1' ? Ip::bucket($ip) : '';

        if (! hash_equals($this->mac($payload, $bucket), $mac)) {
            return new VerifyResult(false, $bound === '1' ? 'wrong_network' : 'bad_signature', $difficulty);
        }

        $age = $this->now() - (int) $issuedAt;

        if ($age > self::TTL_SECONDS) {
            return new VerifyResult(false, 'expired', $difficulty);
        }

        if ($age < 0 || $age < (self::MIN_AGE_SECONDS[$action] ?? 0)) {
            return new VerifyResult(false, 'too_fast', $difficulty);
        }

        if (! str_starts_with(hash('sha256', $challenge . ':' . $nonce), str_repeat('0', $difficulty))) {
            return new VerifyResult(false, 'bad_solution', $difficulty);
        }

        // Single use. Fail CLOSED on any cache problem: letting requests through when the
        // cache is down would let bots bypass the captcha by exhausting the cache.
        try {
            $first = $this->cache->once($this->risk->instancePrefix() . 'used:' . $rand, self::TTL_SECONDS + 60);
        } catch (\Throwable $e) {
            return new VerifyResult(false, 'cache_error', $difficulty);
        }

        return $first ? new VerifyResult(true, 'ok', $difficulty) : new VerifyResult(false, 'replayed', $difficulty);
    }

    private function finish(VerifyResult $result, string $action, string $ip): void
    {
        if ($result->ok) {
            $this->risk->recordSuccess($ip, $action);
        } else {
            $this->risk->recordFailure($ip);
        }

        $this->logger->record($action, $result->ok, $result->reason, $result->difficulty, $ip);
    }

    private function mac(string $payload, string $bucket): string
    {
        return substr(hash_hmac('sha256', $payload . '|' . $bucket, $this->settings->secret()), 0, 32);
    }

    private function clientIp(?ServerRequestInterface $request): string
    {
        $ip = Ip::fromRequest($request);

        return $ip !== '' ? $ip : $this->context->ip();
    }
}
