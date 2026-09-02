<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:purge-expired-knowledge-ingestions')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('app:requeue-stalled-knowledge-ingestions')
    ->everyFiveMinutes()
    ->withoutOverlapping();
