<?php

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\Schema\Builder;

/**
 * The standard protection level is now 3 (was 2). Installations still on the old
 * default (level 2) or without a stored level are moved to 3; any other value an
 * administrator has chosen is left alone.
 */
return [
    'up' => function (Builder $schema) {
        $settings = resolve(SettingsRepositoryInterface::class);
        $key = 'peopleinside-powcaptcha.level';
        $current = $settings->get($key);

        if ($current === null || (string) $current === '2') {
            $settings->set($key, '3');
        }
    },
    'down' => function (Builder $schema) {
        // Nothing to undo.
    },
];
