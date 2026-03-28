<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motion_frameworks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color_hex', 7)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motion_frameworks');
    }
};
