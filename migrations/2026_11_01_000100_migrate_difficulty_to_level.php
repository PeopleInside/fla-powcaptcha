<?php

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\Schema\Builder;

/**
 * Difficulty is now configured as "level" 1–3 instead of the raw leading-zero count
 * (3/4/5). Existing installations keep exactly the same real difficulty:
 *
 *   old 1 or 3 → level 1,   old 2 or 4 → level 2,   old 5 or more → level 3
 *
 * The legacy `difficulty` setting is intentionally left in place, so downgrading
 * to the previous release keeps working.
 */
return [
    'up' => function (Builder $schema) {
        $settings = resolve(SettingsRepositoryInterface::class);
        $prefix = 'peopleinside-powcaptcha.';

        if ($settings->get($prefix . 'level') === null) {
            $legacy = $settings->get($prefix . 'difficulty');
            $level = 2;

            if (is_numeric($legacy)) {
                $legacy = (int) $legacy;
                $level = $legacy <= 1 ? 1 : ($legacy === 2 ? 2 : ($legacy === 3 ? 1 : ($legacy === 4 ? 2 : 3)));
            }

            $settings->set($prefix . 'level', (string) $level);
        }

        $secret = $settings->get($prefix . 'secret');
        if (! is_string($secret) || strlen($secret) < 32) {
            $settings->set($prefix . 'secret', bin2hex(random_bytes(32)));
        }

        // Guard for the public /api/token endpoint: enabled on fresh installs, left OFF on
        // existing ones (which may have API clients that log in without a captcha).
        if ($settings->get($prefix . 'protect_token_endpoint') === null) {
            $existingInstall = ! empty($settings->get($prefix . 'installation_id'));
            $settings->set($prefix . 'protect_token_endpoint', $existingInstall ? '0' : '1');
        }
    },
    'down' => function (Builder $schema) {
        // Nothing to undo: the legacy `difficulty` setting was never modified.
    },
];
