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

    /**
     * Every phase below is guarded so this migration can safely RESUME after
     * a partial failure — MariaDB has no transactional DDL, so a failure
     * midway (see the dropForeign/dropIndex ordering note) leaves real
     * schema changes in place while `migrations` still shows this file as
     * pending, and a naive re-run would immediately re-collide with
     * whatever already succeeded (CREATE TABLE, duplicate column, duplicate
     * unique-key insert, ...).
     */
    public function up(): void
    {
        if (! Schema::hasTable('achievement_assignments')) {
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
        }

        if (! Schema::hasColumn('achievements', 'type')) {
            Schema::table('achievements', function (Blueprint $table) {
                $table->string('type')->nullable()->after('name');
            });
        }

        // The old columns are only present until the cleanup step at the
        // bottom of this block succeeds — their presence IS the "backfill/
        // cleanup not finished yet" signal, so this whole block is safe to
        // skip entirely once a previous run already got past it.
        if (Schema::hasColumn('achievements', 'user_id')) {
            // Backfill: one achievement_assignments row per existing
            // achievements row, using the row's own id as achievement_id
            // (each historical row becomes its own catalog entry — see class
            // doc above). Both writes are individually guarded so resuming
            // after a partial run never re-sets an already-correct type or
            // hits the (user_id, achievement_id) unique constraint on a row
            // that made it through before the earlier failure.
            DB::table('achievements')->orderBy('id')->each(function ($row) {
                if ($row->type === null) {
                    DB::table('achievements')->where('id', $row->id)->update([
                        'type' => self::TYPE_MAP[$row->rank] ?? 'PARTICIPATION',
                    ]);
                }

                $alreadyMigrated = DB::table('achievement_assignments')
                    ->where('user_id', $row->user_id)
                    ->where('achievement_id', $row->id)
                    ->exists();

                if (! $alreadyMigrated) {
                    DB::table('achievement_assignments')->insert([
                        'user_id'        => $row->user_id,
                        'achievement_id' => $row->id,
                        'assigned_at'    => $row->awarded_at,
                        'assigned_by'    => null,
                    ]);
                }
            });

            Schema::table('achievements', function (Blueprint $table) {
                // MariaDB/InnoDB refuses to drop an index that a foreign key
                // still depends on — and the composite (user_id, awarded_at)
                // index is exactly what satisfies the user_id FK here (no
                // separate single-column index was ever created for it, since
                // this composite one already covered it at CREATE TABLE
                // time). The FK must go first. SQLite doesn't enforce this,
                // which is why this ordering bug wasn't caught in dev.
                $table->dropForeign(['user_id']);
                $table->dropIndex(['user_id', 'awarded_at']);
                $table->dropColumn(['user_id', 'rank', 'awarded_at']);
            });
        }

        Schema::table('achievements', function (Blueprint $table) {
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
