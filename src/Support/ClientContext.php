<?php

namespace PeopleInside\PowCaptcha\Support;

/**
 * Request-scoped holder for the client IP and the header-based suspicion score.
 *
 * Flarum's login / forgot-password validators do not receive the HTTP request,
 * so a tiny middleware stores what the verifier needs here.
 */
final class ClientContext
{
    private string $ip = '';
    private int $headerScore = 0;

    public function setIp(string $ip): void
    {
        $this->ip = $ip;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function setHeaderScore(int $score): void
    {
        $this->headerScore = $score;
    }

    public function headerScore(): int
    {
        return $this->headerScore;
    }
}
