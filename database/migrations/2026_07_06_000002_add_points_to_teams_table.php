<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// V2 §3 — team-level points, same Elo-style rating used for the debater
// `users.points` column (default 0, same baseline, so cross-side Elo
// expected-score comparisons are meaningful from a team's very first debate).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (! Schema::hasColumn('teams', 'points')) {
                $table->integer('points')->default(0)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('points');
        });
    }
};
