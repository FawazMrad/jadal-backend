<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Turn silent mass-assignment discards into loud exceptions.
         *
         * Motivation: `prep_reminder_sent_at` was missing from Debate::$fillable,
         * so SendPrepReminders' idempotency stamp was silently dropped on every
         * write. The guard never armed and the reminder re-sent once a minute
         * for an hour. Nothing failed, nothing logged — the value just vanished.
         * This makes that class of bug impossible to miss.
         *
         * `testing` is included so CI catches this class of bug automatically —
         * the suite runs as APP_ENV=testing, so without it every test run would
         * have the check switched off and report a false all-clear.
         *
         * NEVER enabled in production: a discard that is merely a latent bug in
         * development would become a 500 for a live user. Local, staging and
         * testing get the exception; production keeps Laravel's silent default.
         */
        Model::preventSilentlyDiscardingAttributes(
            app()->environment('local', 'staging', 'testing')
        );
    }
}
