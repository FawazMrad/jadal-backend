<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Gemini post-processing cleanup layer — never overwrites the raw Whisper
// output in debate_phases.speech_text. This is a second, optional column
// holding the cleaned version; stays null unless/until cleanup succeeds.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debate_phases', function (Blueprint $table) {
            if (! Schema::hasColumn('debate_phases', 'speech_text_cleaned')) {
                $table->longText('speech_text_cleaned')->nullable()->after('speech_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('debate_phases', function (Blueprint $table) {
            $table->dropColumn('speech_text_cleaned');
        });
    }
};
