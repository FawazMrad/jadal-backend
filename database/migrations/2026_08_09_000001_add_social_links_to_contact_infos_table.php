<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MF_FU §2 — the drawer footer became tappable, so each contact entry needs a
// value the client can build a URI from. The three existing columns stay
// untouched (email/phone/instagram are still what the drawer prints); these are
// the additional channels, all nullable because the drawer hides whatever is
// empty.
//
// phone_e164 and instagram_url are deliberately NOT columns — they are derived
// from `phone` / `instagram` at read time (ContactInfo::toPayload), so they are
// correct for the row that already exists without anyone re-entering data.
return new class extends Migration
{
    private const COLUMNS = ['whatsapp', 'website', 'telegram', 'x', 'facebook', 'youtube'];

    public function up(): void
    {
        Schema::table('contact_infos', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('contact_infos', $column)) {
                    $table->string($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('contact_infos', function (Blueprint $table) {
            $existing = array_values(array_filter(
                self::COLUMNS,
                fn ($c) => Schema::hasColumn('contact_infos', $c)
            ));

            if (! empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
