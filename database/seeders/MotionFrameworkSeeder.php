<?php

namespace Database\Seeders;

use App\Models\MotionFramework;
use Illuminate\Database\Seeder;

class MotionFrameworkSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $frameworks = [
                ['name' => 'Policy',                    'color_hex' => '#3B82F6'],
                ['name' => 'Value',                     'color_hex' => '#8B5CF6'],
                ['name' => 'الأخلاق التطبيقية',         'color_hex' => '#10B981'],
                ['name' => 'الحقوق والحريات',           'color_hex' => '#F59E0B'],
                ['name' => 'Comparative Advantage',     'color_hex' => '#EF4444'],
            ];

            foreach ($frameworks as $fw) {
                MotionFramework::firstOrCreate(['name' => $fw['name']], array_merge($fw, ['created_at' => now()]));
            }

            $this->command->info('✓ Motion frameworks seeded: ' . count($frameworks) . ' frameworks.');
        } catch (\Throwable $e) {
            $this->command->error('MotionFrameworkSeeder failed: ' . $e->getMessage());
        }
    }
}
