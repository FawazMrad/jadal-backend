<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── debates ───────────────────────────────────────────────────────────
        Schema::table('debates', function (Blueprint $table) {
            $table->integer('current_stage')->default(0)->after('status');
            $table->string('prop_room_name')->nullable()->unique()->after('livekit_room_name');
            $table->string('opp_room_name')->nullable()->unique()->after('prop_room_name');
            $table->string('result_room_name')->nullable()->unique()->after('opp_room_name');
            $table->timestamp('motion_revealed_at')->nullable()->after('ended_at');
            $table->timestamp('prep_rooms_opened_at')->nullable()->after('motion_revealed_at');
            $table->timestamp('result_revealed_at')->nullable()->after('prep_rooms_opened_at');
        });

        // ── debate_phases ─────────────────────────────────────────────────────
        Schema::table('debate_phases', function (Blueprint $table) {
            $table->unsignedBigInteger('participant_id')->nullable()->after('debate_id');
            $table->foreign('participant_id')
                  ->references('id')->on('debate_participants')
                  ->nullOnDelete();
            $table->string('audio_url')->nullable()->after('ended_at');
            $table->longText('speech_text')->nullable()->after('audio_url');
            $table->integer('poi_raised_count')->default(0)->after('speech_text');
            $table->integer('poi_answered_count')->default(0)->after('poi_raised_count');
            $table->boolean('is_reply')->default(false)->after('poi_answered_count');
            $table->string('egress_id')->nullable()->after('is_reply');
            $table->index(['debate_id', 'order_index']);
        });

        // ── debate_participants ───────────────────────────────────────────────
        Schema::table('debate_participants', function (Blueprint $table) {
            $table->integer('judge_order')->nullable()->after('speaking_phase_order');
        });
    }

    public function down(): void
    {
        Schema::table('debate_participants', function (Blueprint $table) {
            $table->dropColumn('judge_order');
        });

        Schema::table('debate_phases', function (Blueprint $table) {
            $table->dropIndex(['debate_id', 'order_index']);
            $table->dropForeign(['participant_id']);
            $table->dropColumn([
                'participant_id', 'audio_url', 'speech_text',
                'poi_raised_count', 'poi_answered_count', 'is_reply', 'egress_id',
            ]);
        });

        Schema::table('debates', function (Blueprint $table) {
            $table->dropColumn([
                'current_stage', 'prop_room_name', 'opp_room_name', 'result_room_name',
                'motion_revealed_at', 'prep_rooms_opened_at', 'result_revealed_at',
            ]);
        });
    }
};
