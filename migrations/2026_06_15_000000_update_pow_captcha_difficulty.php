<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        
        $current = $db->table('settings')
            ->where('key', 'peopleinside-powcaptcha.difficulty')
            ->value('value');
            
        if ($current === null) {
            $db->table('settings')->insertOrIgnore([
                'key' => 'peopleinside-powcaptcha.difficulty',
                'value' => '4',
            ]);
        } elseif ((int) $current < 3) {
            // Old level 1 → new level 3 (Easy), old level 2 → new level 4 (High).
            $legacyMap = [1 => '3', 2 => '4'];
            $newValue = $legacyMap[(int) $current] ?? '4';
            $db->table('settings')
                ->where('key', 'peopleinside-powcaptcha.difficulty')
                ->update(['value' => $newValue]);
        }
    },
    'down' => function (Builder $schema) {
        // No specific rollback needed or downgrade difficulty
    }
];
