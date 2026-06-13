<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            // Why a debate was cancelled. Values:
            //   no_participants_at_motion_reveal | no_judge_at_scheduled | manual
            $table->string('cancellation_reason')->nullable()->after('result_revealed_at');
        });
    }

    public function down(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
