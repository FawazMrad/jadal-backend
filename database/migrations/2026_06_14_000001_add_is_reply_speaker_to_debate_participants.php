<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debate_participants', function (Blueprint $table) {
            // Only meaningful for debaters when the format has a reply speech.
            // Exactly one debater per side carries is_reply_speaker = true.
            $table->boolean('is_reply_speaker')->default(false)->after('judge_order');
        });
    }

    public function down(): void
    {
        Schema::table('debate_participants', function (Blueprint $table) {
            $table->dropColumn('is_reply_speaker');
        });
    }
};
