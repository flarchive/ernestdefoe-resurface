<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// At most one plan per discussion: one bump at a set time, or a bump every
// `interval_hours` from then on, `remaining` times (null: until cancelled).
return Migration::createTable('resurface_schedules', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('discussion_id')->unique();
    $table->unsignedInteger('user_id');
    $table->dateTime('next_at')->index();
    $table->unsignedSmallInteger('interval_hours')->nullable();
    $table->unsignedSmallInteger('remaining')->nullable();
    $table->timestamps();

    $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
