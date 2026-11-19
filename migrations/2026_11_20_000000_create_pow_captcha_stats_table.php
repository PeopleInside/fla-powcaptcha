<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Daily aggregate counters for the admin dashboard widget: one row per UTC day with the
// number of passed and blocked checks. No IP, no user agent, no personal data. Rows older
// than 90 days are deleted automatically.
return Migration::createTable('powcaptcha_stats', function (Blueprint $table) {
    $table->date('day')->primary();
    $table->unsignedBigInteger('passed')->default(0);
    $table->unsignedBigInteger('blocked')->default(0);
});
