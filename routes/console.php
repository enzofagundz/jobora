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

Schedule::command('auth:clear-resets')->everyFifteenMinutes();
