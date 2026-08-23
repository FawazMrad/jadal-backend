<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FCM device registry for push delivery.
//
// `token` is UNIQUE, not (user_id, token): an FCM token identifies a device
// installation, and the same device can be handed to a different user (logout
// then login). Registration is an upsert on the token, which re-assigns it to
// the current user and guarantees a push for user A never lands on a device now
// held by user B.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->enum('platform', ['android', 'ios']);
            // Drives per-device localization of the push copy; refreshed by the
            // app whenever the user switches app language.
            $table->string('locale', 5)->default('ar');
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
