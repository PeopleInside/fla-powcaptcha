<?php

namespace PeopleInside\PowCaptcha\Support;

/**
 * Request-scoped holder for the client IP.
 *
 * Flarum's login / forgot-password validators (and the Flarum 1.x user "Saving"
 * event) do not receive the HTTP request, so a tiny middleware stores the IP
 * here and the verifier reads it back.
 */
final class ClientContext
{
    private string $ip = '';

    public function setIp(string $ip): void
    {
        $this->ip = $ip;
    }

    public function ip(): string
    {
        return $this->ip;
    }
}
