<?php

namespace PeopleInside\PowCaptcha\Support;

use Psr\Http\Message\ServerRequestInterface;

class IpDetector
{
    /**
     * Resolve the client IP safely, taking into account trusted proxy headers
     * only if configured, to prevent IP spoofing / rate limit bypass.
     */
    public static function detect(ServerRequestInterface $request, $config = null): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = (string) ($serverParams['REMOTE_ADDR'] ?? '');

        // If flarum config specifies proxy headers to trust, we can check.
        // Flarum uses config.php 'proxy_headers' or 'proxy_all'.
        $trustProxy = false;
        if (!empty($config)) {
            $proxyHeaders = null;
            $proxyAll = null;
            if (is_array($config) || $config instanceof \ArrayAccess) {
                $proxyHeaders = $config['proxy_headers'] ?? null;
                $proxyAll = $config['proxy_all'] ?? null;
            } elseif (is_object($config)) {
                if (method_exists($config, 'get')) {
                    try {
                        $proxyHeaders = $config->get('proxy_headers');
                        $proxyAll = $config->get('proxy_all');
                    } catch (\Throwable) {
                        try {
                            $proxyHeaders = $config->proxy_headers ?? null;
                            $proxyAll = $config->proxy_all ?? null;
                        } catch (\Throwable) {}
                    }
                } else {
                    try {
                        $proxyHeaders = $config->proxy_headers ?? null;
                        $proxyAll = $config->proxy_all ?? null;
                    } catch (\Throwable) {}
                }
            }
            if (!empty($proxyHeaders) || !empty($proxyAll)) {
                $trustProxy = true;
            }
        }

        // Flarum's native resolved IP attribute
        $flarumIp = $request->getAttribute('ipAddress');
        if (is_string($flarumIp) && $flarumIp !== '') {
            if ($trustProxy) {
                return $flarumIp;
            }
            // If they don't trust proxies, be conservative and verify
            // that the flarumIp isn't different, or fallback to REMOTE_ADDR
            return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : $flarumIp;
        }

        if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return $remoteAddr;
        }

        return '';
    }
}
