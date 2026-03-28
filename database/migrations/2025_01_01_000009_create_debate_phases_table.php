<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debate_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debate_id')->constrained('debates')->cascadeOnDelete();
            $table->string('name');
            $table->integer('order_index');
            $table->integer('duration_seconds');
            $table->enum('status', ['pending', 'active', 'completed'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debate_phases');
    }
};
