<?php

namespace App\Console\Commands;

use App\Models\Debate;
use App\Services\Push\DebateNotifier;
use Illuminate\Console\Command;

/**
 * "one hour before preparation starts", debaters only.
 *
 * Implemented as a POLL rather than a per-debate scheduled job, deliberately:
 * a one-shot job would have to be cancelled and re-created every time a debate
 * is rescheduled or its offsets change (and this project has no queue worker —
 * QUEUE_CONNECTION=sync). Polling every minute alongside `debates:tick` derives
 * the reminder time from the debate's CURRENT data on every run, so
 * rescheduling is handled for free and a cancelled debate simply stops
 * matching.
 *
 * `prep_reminder_sent_at` is the idempotency guard — without it the poll would
 * re-send every minute for the whole window.
 *
 * Rescheduled to LESS than an hour out: the reminder fires on the next run
 * (the window is "past the reminder time, prep not yet open"), which matches
 * the agreed "send immediately if still in the future" rule. Once prep has
 * opened the debate no longer matches, so a debate created after that point is
 * skipped rather than sent late.
 *
 * Rescheduling is handled: AdminDebateController::update()
 * clears prep_reminder_sent_at whenever scheduled_at or format_id changes, so
 * a moved debate re-arms and sends a fresh reminder for its new time. Moving a
 * debate earlier re-arms too — the old reminder is equally invalid either way.
 */
class SendPrepReminders extends Command
{
    protected $signature = 'push:prep-reminders';

    protected $description = 'Send the one-hour-before-preparation push to debaters.';

    public function handle(DebateNotifier $notifier): int
    {
        $now = now();

        $debates = Debate::query()
            ->whereIn('status', ['announced', 'teams-selected'])
            ->whereNull('prep_rooms_opened_at')
            ->whereNull('prep_reminder_sent_at')
            ->with('format')
            ->get();

        $sent = 0;

        foreach ($debates as $debate) {
            $offsetHours = (float) ($debate->format->phase_config['prep_rooms_open_offset_hours'] ?? 0);
            $prepOpensAt = $debate->scheduled_at->copy()->subHours($offsetHours);
            $remindAt    = $prepOpensAt->copy()->subHour();

            // Reminder time has passed but prep has not opened yet.
            if ($now->lt($remindAt) || $now->gte($prepOpensAt)) {
                continue;
            }

            $notifier->prepReminder($debate);
            $debate->update(['prep_reminder_sent_at' => $now]);
            $sent++;

            $this->info("Debate {$debate->id}: prep reminder sent.");
        }

        $this->info("Prep reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
