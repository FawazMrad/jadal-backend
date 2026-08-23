<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "watched a debate as a non-participant" signal. Lightweight
// join-timestamp record, one row per (debate, user) — no sticky/missed
// tracking needed since there's no penalty for NOT viewing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debate_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debate_id')->constrained('debates')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('viewed_at');

            $table->unique(['debate_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debate_viewers');
    }
};
