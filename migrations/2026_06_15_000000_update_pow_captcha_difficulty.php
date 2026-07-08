<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Read/write the setting directly via the schema's connection instead of
        // resolving SettingsRepositoryInterface out of the container: migrations
        // run before the app container is guaranteed to be fully bootstrapped, so
        // avoiding resolve()/app() here keeps this migration safe regardless of
        // Flarum's bootstrap ordering.
        $connection = $schema->getConnection();

        $current = $connection->table('settings')
            ->where('key', 'peopleinside-powcaptcha.difficulty')
            ->value('value');

        if ($current === null) {
            $connection->table('settings')->updateOrInsert(
                ['key' => 'peopleinside-powcaptcha.difficulty'],
                ['value' => '4']
            );
        } elseif ((int) $current < 3) {
            // Old level 1 → new level 3 (Easy), old level 2 → new level 4 (High).
            $legacyMap = [1 => '3', 2 => '4'];
            $newValue = $legacyMap[(int) $current] ?? '4';

            $connection->table('settings')
                ->where('key', 'peopleinside-powcaptcha.difficulty')
                ->update(['value' => $newValue]);
        }
    },
    'down' => function (Builder $schema) {
        // No specific rollback needed or downgrade difficulty
    }
];
