<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Persistent team chat with per-message read receipts.
// Chat is scoped per (debate, team); never cross-team. Kept indefinitely
// (no pruning) until a product decision says otherwise.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debate_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debate_id')->constrained('debates')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->timestamps();

            $table->index(['debate_id', 'team_id', 'id']);
        });

        Schema::create('debate_chat_message_reads', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained('debate_chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->primary(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debate_chat_message_reads');
        Schema::dropIfExists('debate_chat_messages');
    }
};
