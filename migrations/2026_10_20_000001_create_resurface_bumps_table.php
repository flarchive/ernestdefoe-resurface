<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// The bump log: one row per bump. Daily limits count a member's rows from the
// last 24 hours; staff read it as the log.
return Migration::createTable('resurface_bumps', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('discussion_id');
    $table->unsignedInteger('user_id')->nullable();
    $table->string('source', 10);
    $table->dateTime('created_at');

    $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
    $table->index(['user_id', 'created_at']);
    $table->index(['discussion_id', 'created_at']);
    $table->index('created_at');
});
