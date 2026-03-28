<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debate_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debate_id')->constrained('debates')->cascadeOnDelete();
            $table->foreignId('judge_id')->constrained('users')->cascadeOnDelete();
            $table->enum('winning_side', ['proposition', 'opposition', 'draw']);
            $table->json('scores');
            $table->text('summary_notes')->nullable();
            $table->timestamp('submitted_at');
            $table->unique('debate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debate_results');
    }
};
