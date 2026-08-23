<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Idempotency guard for the prep reminder. The reminder is a poll (see
// SendPrepReminders for why), so without a "already sent" stamp it would fire
// every minute for the whole hour before prep opens.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            if (! Schema::hasColumn('debates', 'prep_reminder_sent_at')) {
                $table->timestamp('prep_reminder_sent_at')->nullable()->after('prep_rooms_opened_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            if (Schema::hasColumn('debates', 'prep_reminder_sent_at')) {
                $table->dropColumn('prep_reminder_sent_at');
            }
        });
    }
};
