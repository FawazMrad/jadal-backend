<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->string('question_text', 1000);
            $table->enum('type', ['mcq', 'rating', 'open_text']);
            $table->json('options')->nullable();
            $table->integer('order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_questions');
    }
};
