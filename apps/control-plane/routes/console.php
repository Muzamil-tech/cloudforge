<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('servers:mark-unreachable')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('resources:reconcile')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('metrics:prune')
    ->dailyAt('03:15')
    ->withoutOverlapping();

Schedule::command('queue:prune-failed', ['--hours' => 168])
    ->dailyAt('03:45')
    ->withoutOverlapping();
