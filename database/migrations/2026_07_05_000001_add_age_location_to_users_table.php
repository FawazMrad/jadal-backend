<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sprinkles §6.2 — public-profile fields. Both nullable: existing users have no value.
// birth_date is stored (not a raw age integer) so the returned `age` is always
// computed fresh and never goes stale.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('points');
            }
            if (! Schema::hasColumn('users', 'location')) {
                $table->string('location', 150)->nullable()->after('birth_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birth_date', 'location']);
        });
    }
};
