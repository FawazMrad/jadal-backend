<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Achievement feature redesign — from "one row = one user's earned
 * achievement" to a shared catalog + per-user junction:
 *   achievements             → becomes the CATALOG (name, type, image_url).
 *   achievement_assignments  → NEW junction (user_id, achievement_id,
 *                              assigned_at, assigned_by), unique per
 *                              (user_id, achievement_id) so a user can never
 *                              receive the same catalog achievement twice.
 *
 * Old `rank` values map onto the new static `type` enum 1:1, except
 * 'honoring' → 'HONORABLE' (the product spec renamed this tier — there is no
 * way to preserve the old word once the taxonomy itself changed). All other
 * values just change casing (gold → GOLD, etc).
 *
 * Data preservation note: this migration is LOSSLESS but does not
 * deduplicate — every pre-existing achievements row becomes its OWN catalog
 * entry (even if several users happened to have identically-named/ranked
 * rows before). Nothing is merged, so no historical assignment is dropped;
 * an admin can manually consolidate look-alike catalog entries afterward if
 * desired via the new admin API.
 */
return new class extends Migration
{
    private const TYPE_MAP = [
        'gold'          => 'GOLD',
        'silver'        => 'SILVER',
        'bronze'        => 'BRONZE',
        'honoring'      => 'HONORABLE',
        'participation' => 'PARTICIPATION',
    ];

    public function up(): void
    {
        Schema::create('achievement_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // restrictOnDelete: deleting a catalog achievement while it still
            // has assignments must fail at the DB level too (defense in depth
            // alongside the application-level check in the controller).
            $table->foreignId('achievement_id')->constrained('achievements')->restrictOnDelete();
            $table->timestamp('assigned_at');
            // Nullable: legacy rows backfilled below have no known awarding
            // admin (the old schema never recorded one).
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['user_id', 'achievement_id']);
        });

        Schema::table('achievements', function (Blueprint $table) {
            $table->string('type')->nullable()->after('name');
        });

        // Backfill: one achievement_assignments row per existing achievements
        // row, using the row's own id as achievement_id (each historical row
        // becomes its own catalog entry — see class doc above).
        DB::table('achievements')->orderBy('id')->each(function ($row) {
            DB::table('achievements')->where('id', $row->id)->update([
                'type' => self::TYPE_MAP[$row->rank] ?? 'PARTICIPATION',
            ]);

            DB::table('achievement_assignments')->insert([
                'user_id'        => $row->user_id,
                'achievement_id' => $row->id,
                'assigned_at'    => $row->awarded_at,
                'assigned_by'    => null,
            ]);
        });

        Schema::table('achievements', function (Blueprint $table) {
            // The original migration's composite index covers both columns
            // being dropped below — SQLite refuses to drop a column that a
            // surviving index still references, so this must go first.
            $table->dropIndex(['user_id', 'awarded_at']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['rank', 'awarded_at']);
            $table->string('type')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            $table->enum('rank', ['gold', 'silver', 'bronze', 'honoring', 'participation'])->nullable()->after('name');
            $table->timestamp('awarded_at')->nullable();
            $table->index(['user_id', 'awarded_at']);
        });

        // Best-effort reverse: one legacy-shaped row per assignment. A catalog
        // achievement assigned to N users after go-live duplicates into N
        // rows here — the old schema has no way to represent "shared" rows.
        $reverseMap = array_flip(self::TYPE_MAP);

        DB::table('achievement_assignments')
            ->join('achievements', 'achievements.id', '=', 'achievement_assignments.achievement_id')
            ->orderBy('achievement_assignments.id')
            ->select(
                'achievement_assignments.user_id',
                'achievement_assignments.assigned_at',
                'achievements.name',
                'achievements.type',
                'achievements.image_url'
            )
            ->each(function ($row) use ($reverseMap) {
                DB::table('achievements')->insert([
                    'user_id'    => $row->user_id,
                    'name'       => $row->name,
                    // `type` is still NOT NULL at this point (dropped only at
                    // the end of down()) — carry the old value through so the
                    // insert satisfies the constraint; it's discarded below.
                    'type'       => $row->type,
                    'rank'       => $reverseMap[$row->type] ?? 'participation',
                    'image_url'  => $row->image_url,
                    'awarded_at' => $row->assigned_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        // Drop the junction table FIRST — its rows have already been fully
        // reconstructed into achievements above, and the original catalog
        // rows below are still FK-referenced by it (restrictOnDelete) until
        // it's gone.
        Schema::dropIfExists('achievement_assignments');

        // The original catalog rows (now orphaned by the reconstruction
        // above) are no longer needed.
        DB::table('achievements')->whereNull('user_id')->delete();

        Schema::table('achievements', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
