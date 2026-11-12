<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('servers:mark-unreachable')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('resources:reconcile')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('applications:reconcile')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('metrics:prune')
    ->dailyAt('03:15')
    ->withoutOverlapping(30);

Schedule::command('queue:prune-failed', ['--hours' => 168])
    ->dailyAt('03:45')
    ->withoutOverlapping(30);
