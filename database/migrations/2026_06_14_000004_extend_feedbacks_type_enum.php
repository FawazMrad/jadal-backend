<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add two rating types to the feedbacks.type enum and make `content` nullable
     * (rating notes are optional). Done driver-aware because SQLite (used in tests)
     * stores enums as a CHECK constraint that cannot be ALTERed in place.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE feedbacks MODIFY COLUMN type "
                . "ENUM('judge_to_debater','trainer_to_debater','debater_on_session','rating_debate','rating_judgement') NOT NULL"
            );
            DB::statement('ALTER TABLE feedbacks MODIFY COLUMN content TEXT NULL');

            return;
        }

        // SQLite & others: rebuild `type` as a plain string (dropping the CHECK
        // constraint) and make `content` nullable. Data is moved through temp
        // columns so existing rows survive.
        Schema::table('feedbacks', function (Blueprint $table) {
            $table->string('type_new')->nullable();
            $table->text('content_new')->nullable();
        });

        DB::statement('UPDATE feedbacks SET type_new = type, content_new = content');

        Schema::table('feedbacks', function (Blueprint $table) {
            $table->dropColumn(['type', 'content']);
        });

        Schema::table('feedbacks', function (Blueprint $table) {
            $table->string('type')->default('debater_on_session');
            $table->text('content')->nullable();
        });

        DB::statement('UPDATE feedbacks SET type = type_new, content = content_new');

        Schema::table('feedbacks', function (Blueprint $table) {
            $table->dropColumn(['type_new', 'content_new']);
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Revert to the original three-value enum. Rows using the new values
            // would violate the constraint, so callers must clean those first.
            DB::statement(
                "ALTER TABLE feedbacks MODIFY COLUMN type "
                . "ENUM('judge_to_debater','trainer_to_debater','debater_on_session') NOT NULL"
            );
            DB::statement('ALTER TABLE feedbacks MODIFY COLUMN content TEXT NOT NULL');
        }
        // SQLite: leaving `type` as a plain string on rollback is harmless.
    }
};
