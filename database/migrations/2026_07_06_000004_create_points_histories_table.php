<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Audit trail for every points award, per (subject, debate). Polymorphic
// subject so one table covers both User and Team point changes. Rows are kept
// indefinitely so a "why did my points change" breakdown can be built later
// without retrofitting the history.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('points_histories', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');   // App\Models\User | App\Models\Team
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('debate_id')->constrained('debates')->cascadeOnDelete();
            $table->integer('points_before');
            $table->integer('delta');
            $table->integer('points_after');
            $table->json('breakdown'); // { base, elo, elo_expected, opponent_rating, score_component }
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_id']);
            $table->unique(['subject_type', 'subject_id', 'debate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_histories');
    }
};
