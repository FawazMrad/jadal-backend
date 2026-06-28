<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent (guard each column) — same reasoning as the V3 migration:
        // the columns may already exist on an environment that ran an earlier
        // copy, so re-running must be a harmless no-op.
        Schema::table('debates', function (Blueprint $table) {
            // V11 §1 — intro phase. Set when the chair takes the room "live" from
            // the open lobby while current_stage is still 0 (chair welcome, no
            // speech yet). A late joiner reads this to tell intro from open-lobby:
            //   current_stage >= 1            → a speech is running
            //   current_stage == 0 && set     → intro (live, pre-speech)
            //   current_stage == 0 && null    → open lobby
            if (! Schema::hasColumn('debates', 'live_started_at')) {
                $table->timestamp('live_started_at')->nullable()->after('speeches_completed_at');
            }

            // V11 §0 — server-authoritative timer. The clock is owned by the
            // server; clients render `paused ? paused_elapsed : server_now -
            // current_stage_started_at` and keep only a cosmetic local tick.
            if (! Schema::hasColumn('debates', 'timer_is_paused')) {
                $table->boolean('timer_is_paused')->default(false)->after('live_started_at');
            }
            if (! Schema::hasColumn('debates', 'timer_paused_elapsed_seconds')) {
                $table->unsignedInteger('timer_paused_elapsed_seconds')->default(0)->after('timer_is_paused');
            }
        });
    }

    public function down(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['live_started_at', 'timer_is_paused', 'timer_paused_elapsed_seconds'],
                fn ($c) => Schema::hasColumn('debates', $c)
            ));

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
