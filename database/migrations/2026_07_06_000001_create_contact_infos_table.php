<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sprinkles §9 (V1) — support contact shown in the app's nav drawer/login
// response. DB-backed singleton (was env-config, now moved to a seeded row so
// it's editable data rather than a deploy-time value).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_infos', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('instagram')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_infos');
    }
};
