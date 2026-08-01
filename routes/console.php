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

// Spec §7.2 #3 — polled every minute alongside debates:tick so the reminder
// time is re-derived from the debate's CURRENT data; rescheduling is therefore
// handled with no job to cancel or re-create. Idempotent via
// debates.prep_reminder_sent_at.
Schedule::command('push:prep-reminders')->everyMinute()->withoutOverlapping();

// Spec §7.2 #8 — weekly blog digest. Saturday 18:00 Asia/Damascus: the debate
// week here starts Sunday, so this lands the evening before. Sends nothing at
// all if no article was published in the preceding 7 days.
Schedule::command('push:blog-digest')->weeklyOn(6, '18:00')->timezone('Asia/Damascus');
