<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Singleton row — the app's one support contact (email/phone/instagram). */
class ContactInfo extends Model
{
    protected $fillable = ['email', 'phone', 'instagram'];

    public static function current(): ?self
    {
        return static::query()->orderBy('id')->first();
    }
}
