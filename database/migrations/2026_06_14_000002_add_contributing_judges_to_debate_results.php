<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debate_results', function (Blueprint $table) {
            // Snapshot of every attended approved judge at result-submission time.
            // Shape: [{ "user_id": 3, "judge_order": 1, "is_chair": true }, ...]
            $table->json('contributing_judges')->nullable()->after('judge_id');
        });
    }

    public function down(): void
    {
        Schema::table('debate_results', function (Blueprint $table) {
            $table->dropColumn('contributing_judges');
        });
    }
};
