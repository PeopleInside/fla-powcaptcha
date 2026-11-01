<?php

namespace PeopleInside\PowCaptcha\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Client IP helpers.
 *
 * Security note: forwarded-for style headers (X-Forwarded-For, Client-IP, …) are
 * fully attacker-controlled unless a trusted proxy rewrites them, so they are
 * NEVER read here. We use exactly what Flarum core exposes (the `ipAddress`
 * request attribute, i.e. REMOTE_ADDR). Behind a reverse proxy / CDN, configure
 * the web server so that REMOTE_ADDR carries the real client address (nginx
 * `real_ip_header`, Apache `mod_remoteip`, …). See the README.
 */
final class Ip
{
    public static function fromRequest(?ServerRequestInterface $request): string
    {
        if ($request === null) {
            return '';
        }

        $attribute = $request->getAttribute('ipAddress');
        if (is_string($attribute) && filter_var($attribute, FILTER_VALIDATE_IP) !== false) {
            return $attribute;
        }

        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '';
    }

    /**
     * Whether the address is a routable public one. Private / reserved / loopback
     * addresses usually mean "behind a proxy without real-IP configuration": per-IP
     * features (rate limit, adaptive difficulty, IP binding) are switched off for
     * them, otherwise every visitor would share one bucket.
     */
    public static function isPublic(string $ip): bool
    {
        $ip = self::unmap($ip);
        if ($ip === '') {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Network bucket used for counters and binding: the address itself for IPv4,
     * the /64 prefix for IPv6 (a single subscriber normally owns a whole /64).
     */
    public static function bucket(string $ip): string
    {
        $ip = self::unmap($ip);
        $packed = $ip === '' ? false : @inet_pton($ip);

        if ($packed === false) {
            return '';
        }

        if (strlen($packed) === 4) {
            return $ip;
        }

        return bin2hex(substr($packed, 0, 8)) . '/64';
    }

    /** Converts IPv4-mapped IPv6 (::ffff:1.2.3.4) to plain IPv4. */
    private static function unmap(string $ip): string
    {
        $packed = $ip === '' ? false : @inet_pton($ip);

        if ($packed === false) {
            return '';
        }

        if (strlen($packed) === 16 && str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return (string) inet_ntop($packed);
    }
}
