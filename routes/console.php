<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Operator cron entry:
// * * * * * cd /var/www/jadal-backend && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('debates:tick')->everyMinute();
