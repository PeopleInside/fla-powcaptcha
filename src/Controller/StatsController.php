<?php

namespace PeopleInside\PowCaptcha\Controller;

use Composer\InstalledVersions;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use PeopleInside\PowCaptcha\Service\StatsTracker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/powcaptcha/stats (administrators only): data for the dashboard widget. */
class StatsController implements RequestHandlerInterface
{
    /** Flarum extension id (the one shown in the admin panel) and Composer package name. */
    private const EXTENSION_ID = 'peopleinside-fla-powcaptcha';
    private const PACKAGE = 'peopleinside/fla-powcaptcha';

    public function __construct(
        private readonly StatsTracker $stats,
        private readonly ExtensionManager $extensions
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $summary = $this->stats->summary();

        return new JsonResponse(
            [
                'ready' => $summary['ready'],
                'today' => $summary['today'],
                'week' => $summary['week'],
                'version' => $this->version(),
            ],
            200,
            ['Cache-Control' => 'no-store']
        );
    }

    /**
     * The version exactly as Flarum shows it in the extension settings (e.g. `3.10.3.x-dev`
     * or `v3.10.3`): it comes from Composer's installed packages, so nothing is hard-coded
     * in the extension. Empty string when it cannot be determined (the widget then hides it).
     */
    private function version(): string
    {
        try {
            $extension = $this->extensions->getExtension(self::EXTENSION_ID);
            $version = $extension ? $extension->getVersion() : null;

            if (is_string($version) && $version !== '') {
                return $version;
            }
        } catch (\Throwable) {
            // fall through to Composer
        }

        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)) {
                return (string) InstalledVersions::getPrettyVersion(self::PACKAGE);
            }
        } catch (\Throwable) {
            // no version available
        }

        return '';
    }
}
