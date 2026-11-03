<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Anonymous event log (no IP, no user agent, no personal data). Kept tiny: the
// extension itself prunes it to the latest 30 events per outcome.
return Migration::createTable('powcaptcha_logs', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->timestamp('created_at')->nullable();
    $table->string('action', 12);
    $table->string('result', 8);
    $table->string('reason', 24);
    $table->unsignedTinyInteger('difficulty')->default(0);

    $table->index(['result', 'id']);
});
