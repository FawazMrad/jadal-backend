<?php

namespace Database\Seeders;

use App\Models\ContactInfo;
use Illuminate\Database\Seeder;

class ContactInfoSeeder extends Seeder
{
    public function run(): void
    {
        ContactInfo::updateOrCreate(
            ['id' => 1],
            [
                'email'     => 'support@jadal.app',
                'phone'     => '+963-11-1234567',
                'instagram' => 'https://instagram.com/jadal.platform',
            ]
        );
    }
}
