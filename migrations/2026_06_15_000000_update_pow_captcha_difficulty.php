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
        } elseif ((int) $current <= 3) {
            $db->table('settings')
                ->where('key', 'peopleinside-powcaptcha.difficulty')
                ->update(['value' => '4']);
        }
    },
    'down' => function (Builder $schema) {
        // No specific rollback needed or downgrade difficulty
    }
];
