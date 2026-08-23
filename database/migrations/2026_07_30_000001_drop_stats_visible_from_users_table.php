<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The share-statistics opt-out is removed: statistics are
// public for every user, unconditionally. Every permission check that read this
// column is gone (debater stats, activity stats, coach team summary, and the
// leaderboard exclusion), so the column itself is now dead weight.
//
// NOTE: this is destructive for users who had opted OUT — their preference
// cannot be recovered by rolling back, since down() can only restore the column
// with its default (true), not the per-user values. That is inherent to the
// product decision, not an oversight.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'stats_visible')) {
                $table->dropColumn('stats_visible');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'stats_visible')) {
                // Restored at the original default; prior opt-outs are not recoverable.
                $table->boolean('stats_visible')->default(true)->after('location');
            }
        });
    }
};
