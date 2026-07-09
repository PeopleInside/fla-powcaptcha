<?php

use Flarum\Settings\DatabaseSettingsRepository;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Same pattern used by Flarum core's own Flarum\Database\Migration::addSettings():
        // build the settings repository directly from the schema connection instead of
        // resolving it out of the app container, since migrations aren't guaranteed to run
        // with a fully bootstrapped container.
        $settings = new DatabaseSettingsRepository($schema->getConnection());

        $current = $settings->get('peopleinside-powcaptcha.difficulty');

        if ($current === null) {
            $settings->set('peopleinside-powcaptcha.difficulty', '4');
        } elseif ((int) $current < 3) {
            // Old level 1 → new level 3 (Easy), old level 2 → new level 4 (High).
            $legacyMap = [1 => '3', 2 => '4'];
            $newValue = $legacyMap[(int) $current] ?? '4';
            $settings->set('peopleinside-powcaptcha.difficulty', $newValue);
        }
    },
    'down' => function (Builder $schema) {
        // No specific rollback needed or downgrade difficulty
    }
];
