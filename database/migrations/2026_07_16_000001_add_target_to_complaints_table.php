<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A complaint can now name WHO it is about and in what
// capacity. Both nullable: every pre-existing row stays valid ("unattributed"
// — excluded from per-person accountability figures but still counted in the
// platform-wide unattributed total).
//
// target_user_id gets its index automatically from the FK constraint (InnoDB
// creates one for every foreign key that isn't already leftmost-covered), so
// no separate ->index() call is needed here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            if (! Schema::hasColumn('complaints', 'target_user_id')) {
                $table->foreignId('target_user_id')
                    ->nullable()
                    ->after('debate_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('complaints', 'target_role')) {
                // 'chair' is deliberately part of this enum even though it is
                // not a debate_participants.role value — a chair is a judge
                // row with is_chair = true; the stats module maps it.
                $table->enum('target_role', ['debater', 'trainer', 'judge', 'chair'])
                    ->nullable()
                    ->after('target_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            if (Schema::hasColumn('complaints', 'target_user_id')) {
                $table->dropForeign(['target_user_id']);
            }

            $columns = array_values(array_filter(
                ['target_user_id', 'target_role'],
                fn ($c) => Schema::hasColumn('complaints', $c)
            ));

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
