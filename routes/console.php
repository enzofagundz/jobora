<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('job-posting-items:normalize')
    ->daily()
    ->withoutOverlapping();

Schedule::command('job-ia-attempts:prune')
    ->daily()
    ->withoutOverlapping();

Schedule::command('ai-operations:expire')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('discovery-runs:expire')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('captures:prune-raw')
    ->daily()
    ->withoutOverlapping();

Schedule::command('captures:reconcile-orphans')
    ->daily()
    ->withoutOverlapping();

Schedule::command('auth:clear-resets')->everyFifteenMinutes();
