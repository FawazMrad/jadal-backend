<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            // Admin pre-declares which team plays each side, before registration
            // closes, so side is known in advance of roster selection.
            $table->unsignedBigInteger('proposition_team_id')->nullable()->after('motion_id');
            $table->unsignedBigInteger('opposition_team_id')->nullable()->after('proposition_team_id');

            $table->foreign('proposition_team_id')->references('id')->on('teams')->nullOnDelete();
            $table->foreign('opposition_team_id')->references('id')->on('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            $table->dropForeign(['proposition_team_id']);
            $table->dropForeign(['opposition_team_id']);
            $table->dropColumn(['proposition_team_id', 'opposition_team_id']);
        });
    }
};
