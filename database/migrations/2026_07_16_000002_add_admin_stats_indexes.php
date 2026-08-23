<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Covering indexes for the platform-wide aggregate
// queries (all of them filter on "completed debates in a scheduled_at window"
// and group participants by user/role).
//
// Deliberately NOT added (already indexed by their FK constraints, adding a
// second index would only slow writes):
//   debates.motion_id, debates.created_by, complaints.target_user_id,
//   motion_framework_pivot.framework_id (FK auto-index — the composite PK
//   (motion_id, framework_id) doesn't cover framework_id-first lookups, so
//   InnoDB created a dedicated one when the FK was declared).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            if (! Schema::hasIndex('debates', 'debates_status_scheduled_at_index')) {
                $table->index(['status', 'scheduled_at']);
            }
        });

        Schema::table('debate_participants', function (Blueprint $table) {
            if (! Schema::hasIndex('debate_participants', 'debate_participants_user_id_role_side_index')) {
                $table->index(['user_id', 'role', 'side']);
            }
            if (! Schema::hasIndex('debate_participants', 'debate_participants_debate_id_team_id_index')) {
                $table->index(['debate_id', 'team_id']);
            }
        });

        Schema::table('complaints', function (Blueprint $table) {
            if (! Schema::hasIndex('complaints', 'complaints_status_created_at_index')) {
                $table->index(['status', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            if (Schema::hasIndex('debates', 'debates_status_scheduled_at_index')) {
                $table->dropIndex(['status', 'scheduled_at']);
            }
        });

        Schema::table('debate_participants', function (Blueprint $table) {
            if (Schema::hasIndex('debate_participants', 'debate_participants_user_id_role_side_index')) {
                $table->dropIndex(['user_id', 'role', 'side']);
            }
            if (Schema::hasIndex('debate_participants', 'debate_participants_debate_id_team_id_index')) {
                $table->dropIndex(['debate_id', 'team_id']);
            }
        });

        Schema::table('complaints', function (Blueprint $table) {
            if (Schema::hasIndex('complaints', 'complaints_status_created_at_index')) {
                $table->dropIndex(['status', 'created_at']);
            }
        });
    }
};
