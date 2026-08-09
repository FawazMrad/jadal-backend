<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Singleton row — the app's one support contact, served on login. */
class ContactInfo extends Model
{
    protected $fillable = [
        'email', 'phone', 'instagram',
        'whatsapp', 'website', 'telegram', 'x', 'facebook', 'youtube',
    ];

    public static function current(): ?self
    {
        return static::query()->orderBy('id')->first();
    }

    /**
     * MF_FU §2 — the shape both login and GET /admin/contact-info return.
     *
     * The three original keys are passed through verbatim: the drawer prints
     * them as-is today and a mid-rollout client must keep seeing what it saw.
     * Everything else is additive and nullable — the client hides empty entries,
     * so a null is a real answer, never a placeholder. Empty strings are
     * normalized to null for exactly that reason.
     *
     * `phone_e164` and `instagram_url` are DERIVED, not stored, so they are
     * already correct for the seeded row. That matters because the live data
     * does not match what the client assumed: `phone` is human-formatted with
     * dashes, and `instagram` holds a full URL rather than a bare handle.
     */
    public static function payload(?self $contact): array
    {
        return [
            // Unchanged, verbatim.
            'email'     => self::blankToNull($contact?->email),
            'phone'     => self::blankToNull($contact?->phone),
            'instagram' => self::blankToNull($contact?->instagram),

            // Derived.
            'phone_e164'       => self::toE164($contact?->phone),
            'instagram_url'    => self::instagramUrl($contact?->instagram),
            'instagram_handle' => self::instagramHandle($contact?->instagram),

            // Stored, additive.
            'whatsapp' => self::toE164($contact?->whatsapp),
            'website'  => self::blankToNull($contact?->website),
            'telegram' => self::blankToNull($contact?->telegram),
            'x'        => self::blankToNull($contact?->x),
            'facebook' => self::blankToNull($contact?->facebook),
            'youtube'  => self::blankToNull($contact?->youtube),
        ];
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Strict E.164: '+' then digits only. Returns null rather than guessing when
     * the stored number has no country code — a `tel:` URI built from a local
     * number dials the wrong place, so no value beats a wrong value.
     */
    private static function toE164(?string $raw): ?string
    {
        $raw = self::blankToNull($raw);
        if ($raw === null) {
            return null;
        }

        $hasPlus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits  = preg_replace('/\D/', '', $raw);

        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        if (! $hasPlus || $digits === '' || strlen($digits) < 8) {
            return null;
        }

        return '+' . $digits;
    }

    /** Bare handle, whether the column holds a handle, an @handle or a full URL. */
    private static function instagramHandle(?string $raw): ?string
    {
        $raw = self::blankToNull($raw);
        if ($raw === null) {
            return null;
        }

        if (preg_match('~instagram\.com/([^/?\#\s]+)~i', $raw, $m)) {
            $raw = $m[1];
        }

        $handle = ltrim(trim($raw, '/'), '@');

        return $handle === '' ? null : $handle;
    }

    private static function instagramUrl(?string $raw): ?string
    {
        $handle = self::instagramHandle($raw);

        return $handle === null ? null : "https://instagram.com/{$handle}";
    }
}
