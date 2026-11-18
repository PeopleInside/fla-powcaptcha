<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Registrations per network, used for the "max N sign-ups per 24 h" limit.
// Only a keyed hash (HMAC) of the network bucket is stored, never the IP itself,
// and rows older than 48 hours are deleted automatically.
return Migration::createTable('powcaptcha_signups', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->string('ip_hash', 32);
    $table->timestamp('created_at')->nullable();

    $table->index(['ip_hash', 'created_at']);
    $table->index('created_at');
});
