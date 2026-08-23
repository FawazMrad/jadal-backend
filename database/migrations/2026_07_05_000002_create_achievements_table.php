<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Achievements (read side + admin awarding).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->enum('rank', ['gold', 'silver', 'bronze', 'honoring', 'participation']);
            // Nullable — the FE substitutes its own default asset when null.
            $table->string('image_url')->nullable();
            $table->timestamp('awarded_at');
            $table->timestamps();

            $table->index(['user_id', 'awarded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
