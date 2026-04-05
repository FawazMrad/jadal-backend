<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        try {
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            $guardName = config('auth.defaults.guard', 'web'); // Use app's default guard

            $permissions = [
                // Debates
                'view-debates', 'create-debate', 'manage-debate', 'judge-debate',
                // Motions
                'view-motions', 'create-motion', 'manage-motions',
                // Teams
                'view-teams', 'create-team', 'manage-teams',
                // Blog
                'create-post', 'publish-post', 'review-post', 'manage-blog',
                // Users
                'manage-users', 'view-users',
                // Surveys
                'create-survey', 'manage-surveys', 'respond-survey',
                // Evaluations & Feedback
                'create-evaluation', 'view-evaluations',
                'create-feedback', 'view-feedbacks',
                // Results
                'submit-result', 'view-results',
                // Complaints
                'file-complaint', 'manage-complaints',
                // Reports
                'view-reports',
            ];

            foreach ($permissions as $perm) {
                Permission::firstOrCreate(
                    ['name' => $perm, 'guard_name' => $guardName]
                );
            }

            $rolePermissions = [
                'admin' => $permissions,

                'trainer' => [
                    'view-debates', 'view-motions', 'view-teams',
                    'create-evaluation', 'view-evaluations',
                    'create-feedback', 'view-feedbacks',
                    'create-post', 'respond-survey', 'view-users',
                    'file-complaint',
                ],

                'judge' => [
                    'view-debates', 'judge-debate', 'submit-result',
                    'view-results', 'create-feedback', 'view-feedbacks',
                    'view-motions', 'respond-survey', 'file-complaint',
                ],

                'debater' => [
                    'view-debates', 'view-motions', 'view-teams',
                    'create-team', 'create-feedback', 'respond-survey',
                    'create-post', 'file-complaint',
                ],
            ];

            foreach ($rolePermissions as $roleName => $perms) {
                $role = Role::firstOrCreate(
                    ['name' => $roleName, 'guard_name' => $guardName]
                );
                // Sync permissions with the same guard
                $role->syncPermissions(
                    Permission::whereIn('name', $perms)
                        ->where('guard_name', $guardName)
                        ->get()
                );
            }

            $this->command->info('✓ Roles and permissions seeded.');
        } catch (\Throwable $e) {
            $this->command->error('RolePermissionSeeder failed: ' . $e->getMessage());
        }
    }
}
