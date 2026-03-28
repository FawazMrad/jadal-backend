<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Run: php artisan db:seed
     * Fresh run: php artisan migrate:fresh --seed
     */
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════╗');
        $this->command->info('║     Jadal Platform Database Seed     ║');
        $this->command->info('╚══════════════════════════════════════╝');
        $this->command->info('');

        $this->call([
            // 1. RBAC: roles & permissions (must run before users get assigned roles)
            RolePermissionSeeder::class,

            // 2. Users (depends on roles)
            UserSeeder::class,

            // 3. Independent lookup tables
            DebateFormatSeeder::class,
            MotionFrameworkSeeder::class,

            // 4. Motions (depends on users + motion_frameworks)
            MotionSeeder::class,

            // 5. Teams and members (depends on users)
            TeamSeeder::class,

            // 6. Debates (depends on formats, motions, users)
            DebateSeeder::class,

            // 7. Debate sub-tables (depend on debates + users + teams)
            DebateParticipantSeeder::class,
            DebatePhaseSeeder::class,
            DebateResultSeeder::class,

            // 8. Feedback & evaluations (depend on completed debates + users)
            FeedbackSeeder::class,
            EvaluationSeeder::class,

            // 9. Notifications (depends on users + debates)
            NotificationSeeder::class,

            // 10. Complaints (depends on users + debates)
            ComplaintSeeder::class,

            // 11. Blog (depends on users)
            BlogSeeder::class,

            // 12. Surveys (depends on users)
            SurveySeeder::class,

            // 13. Audit logs (depends on everything else)
            AuditLogSeeder::class,
        ]);

        $this->command->info('');
        $this->command->info('✅ All seeders completed successfully!');
        $this->command->info('');
        $this->command->info('Default admin credentials:');
        $this->command->info('  Email:    admin@jadal.sa');
        $this->command->info('  Password: password');
        $this->command->info('');
    }
}
