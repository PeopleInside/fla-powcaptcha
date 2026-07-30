<?php

namespace PeopleInside\PowCaptcha\Support;

use Psr\Http\Message\ServerRequestInterface;

class IpDetector
{
    /**
     * Resolve the client IP safely, taking into account trusted proxy headers
     * only if configured, to prevent IP spoofing / rate limit bypass.
     */
    public static function detect(?ServerRequestInterface $request = null, $config = null): string
    {
        // Flarum's native resolved IP attribute takes absolute priority when available
        if ($request !== null) {
            $flarumIp = $request->getAttribute('ipAddress');
            if (is_string($flarumIp) && filter_var($flarumIp, FILTER_VALIDATE_IP)) {
                return $flarumIp;
            }
        }

        $serverParams = $request?->getServerParams() ?? $_SERVER;
        $remoteAddr = (string) ($serverParams['REMOTE_ADDR'] ?? '');

        // If flarum config specifies proxy headers to trust, we check.
        // Flarum uses config.php 'proxy_headers' or 'proxy_all'.
        $trustProxy = false;
        if (!empty($config) && (is_array($config) || $config instanceof \ArrayAccess)) {
            $proxyHeaders = $config['proxy_headers'] ?? null;
            $proxyAll = $config['proxy_all'] ?? null;
            if (!empty($proxyHeaders) || !empty($proxyAll)) {
                $trustProxy = true;
            }
        }

        // Standard proxy fallback parsing when request attribute is not available
        if ($trustProxy) {
            $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP'];
            foreach ($headers as $header) {
                if (!empty($serverParams[$header])) {
                    $ips = explode(',', $serverParams[$header]);
                    $ip = trim(reset($ips)); // Leftmost IP is the originating client
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return $remoteAddr;
        }

        return '';
    }
}
