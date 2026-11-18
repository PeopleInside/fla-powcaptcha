<?php

namespace PeopleInside\PowCaptcha\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Cheap, privacy-friendly behaviour heuristics. Nothing here is stored.
 *
 * Score >= REJECT_AT rejects the request; 1..REJECT_AT-1 only raises the adaptive
 * difficulty for that network. Real browsers always send a User-Agent, an
 * Accept-Language, a same-site Origin and `Sec-Fetch-Site: same-origin` on XHR;
 * scripts that talk to the API directly usually miss at least one of them.
 */
final class BehaviorAnalyzer
{
    public const REJECT_AT = 3;

    private const BOT_UA = '/curl|wget|python|aiohttp|httpx|libwww|scrapy|go-http|java\/|apache-http|postman|insomnia'
        . '|headless|phantom|selenium|puppeteer|playwright|webdriver|bot\b|spider|crawl/i';

    /** Score from the HTTP headers only. */
    public static function headerScore(ServerRequestInterface $request, string $siteHost = ''): int
    {
        $score = 0;
        $ua = trim($request->getHeaderLine('User-Agent'));

        if ($ua === '' || strlen($ua) < 10) {
            $score += 3;
        } elseif (preg_match(self::BOT_UA, $ua) === 1) {
            $score += 3;
        }

        if (trim($request->getHeaderLine('Accept-Language')) === '') {
            $score += 1;
        }

        $fetchSite = strtolower($request->getHeaderLine('Sec-Fetch-Site'));
        if ($fetchSite === 'cross-site') {
            $score += 3;
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== 'null') {
            $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $hosts = array_filter([strtolower($request->getUri()->getHost()), strtolower($siteHost)]);

            if ($originHost === '' || ! in_array($originHost, $hosts, true)) {
                $score += 3;
            }
        }

        return $score;
    }

    /**
     * Score from the client-side interaction summary "pointer.keys.focus.ms" sent by
     * the widget. Missing = +1 (old cached JS or direct API call); zero interaction
     * of any kind = +2; far too little time on the page = +1.
     */
    public static function interactionScore(?string $raw): int
    {
        if ($raw === null || $raw === '') {
            return 1;
        }

        if (! preg_match('/^(\d{1,6})\.(\d{1,6})\.(\d{1,6})\.(\d{1,9})$/', $raw, $m)) {
            return 2;
        }

        [, $pointer, $keys, $focus, $ms] = array_map('intval', $m);
        $score = 0;

        if ($pointer + $keys + $focus === 0) {
            $score += 2;
        }

        if ($ms < 1200) {
            $score += 1;
        }

        return $score;
    }
}
