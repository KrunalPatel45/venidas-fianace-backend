<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('finance:monthly-report')
    ->monthlyOn(11, '08:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();