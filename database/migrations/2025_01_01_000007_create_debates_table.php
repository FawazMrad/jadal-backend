<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('format_id')->constrained('debate_formats')->cascadeOnDelete();
            $table->foreignId('motion_id')->constrained('motions')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['scheduled', 'announced', 'teams-selected', 'live', 'completed', 'cancelled'])->default('scheduled');
            $table->string('livekit_room_name')->unique();
            $table->string('recording_url')->nullable();
            $table->text('transcript')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debates');
    }
};
