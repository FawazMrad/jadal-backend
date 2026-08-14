<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Guest mode §Q4 — the moment a debate reached a TERMINAL status
// (completed or cancelled). Needed because no existing column marks it:
//
//   ended_at  — stamped by nextStage() the instant the speeches finish, while
//               the debate is still `live` (the result phase has not even
//               started). closeMain/closeRoom only backfill it when null, so in
//               the normal flow ended_at == speeches_completed_at, minutes-to-
//               hours BEFORE the debate actually completes. It is also never
//               written on ANY cancellation path.
//   updated_at — moves on every later touch, so it cannot anchor a fixed window.
//
// Nullable with no backfill: pre-existing terminal debates keep NULL and the
// guest-window check falls back to updated_at for them (they are long past the
// 10-minute window regardless, so the fallback is never load-bearing in practice).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            if (! Schema::hasColumn('debates', 'finalized_at')) {
                $table->timestamp('finalized_at')->nullable()->after('speeches_completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('debates', function (Blueprint $table) {
            if (Schema::hasColumn('debates', 'finalized_at')) {
                $table->dropColumn('finalized_at');
            }
        });
    }
};
