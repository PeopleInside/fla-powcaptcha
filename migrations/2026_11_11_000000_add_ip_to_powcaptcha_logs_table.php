<?php

use Flarum\Database\Migration;

return Migration::addColumns('powcaptcha_logs', [
    'ip' => ['string', 'length' => 45, 'nullable' => true],
]);
