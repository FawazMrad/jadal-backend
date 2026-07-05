<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sprinkles §6.5 — STICKY attendance stamps for historical stats.
//
// The existing `is_attended` boolean is a LIVE presence flag: the webhook sets
// it on join and CLEARS it on leave (chair election depends on that). It can
// therefore never answer "did this person ever show up to this debate". These
// two timestamps are set once on first join and never cleared:
//   first_attended_at — first join to the MAIN (or result) room → judge/coach attendance
//   prep_attended_at  — first join to their prep (prop/opp) room → debater prep attendance
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debate_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('debate_participants', 'first_attended_at')) {
                $table->timestamp('first_attended_at')->nullable()->after('is_attended');
            }
            if (! Schema::hasColumn('debate_participants', 'prep_attended_at')) {
                $table->timestamp('prep_attended_at')->nullable()->after('first_attended_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('debate_participants', function (Blueprint $table) {
            $table->dropColumn(['first_attended_at', 'prep_attended_at']);
        });
    }
};
