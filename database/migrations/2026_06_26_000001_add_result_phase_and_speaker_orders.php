<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: this migration may already have run on an environment that
        // was later reverted in code (the columns persist in the DB even though
        // the migration file was removed). Guard each column so re-running this is
        // a harmless no-op and never throws "column already exists".
        Schema::table('debates', function (Blueprint $table) {
            // Marks the moment the chair advanced past the last speech. The debate
            // stays `live` (result phase) until close-room finalises it. Its
            // presence (while status is still `live`) is the canonical
            // "speeches done / result room open" signal for the frontend.
            if (! Schema::hasColumn('debates', 'speeches_completed_at')) {
                $table->timestamp('speeches_completed_at')->nullable()->after('result_revealed_at');
            }

            // Per-side ordered speaking assignment as an array of user_ids
            // (length = format speeches per side). Duplicates ARE allowed so a
            // single debater can cover multiple speaking slots (multi-role teams).
            if (! Schema::hasColumn('debates', 'prop_speaker_order')) {
                $table->json('prop_speaker_order')->nullable()->after('speeches_completed_at');
            }
            if (! Schema::hasColumn('debates', 'opp_speaker_order')) {
                $table->json('opp_speaker_order')->nullable()->after('prop_speaker_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['speeches_completed_at', 'prop_speaker_order', 'opp_speaker_order'],
                fn ($c) => Schema::hasColumn('debates', $c)
            ));

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
