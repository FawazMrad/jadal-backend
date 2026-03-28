<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motion_framework_pivot', function (Blueprint $table) {
            $table->foreignId('motion_id')->constrained('motions')->cascadeOnDelete();
            $table->foreignId('framework_id')->constrained('motion_frameworks')->cascadeOnDelete();
            $table->primary(['motion_id', 'framework_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motion_framework_pivot');
    }
};
