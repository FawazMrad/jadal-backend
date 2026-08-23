<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * dev/staging data-seeding ask (no schema, just data): full profile
 * fields for enough users to verify the redesign without every field reading
 * null, achievement variety across the rank spectrum, and one confirmed
 * team-history swap so GET /users/{id}/teams/history has a non-empty row to
 * look at. Safe to re-run — every write is guarded (skips users/rows that
 * already have data).
 */
class DemoProfileDataSeeder extends Seeder
{
    private const LOCATIONS = ['Damascus', 'Aleppo', 'Homs', 'Latakia', 'Hama', 'Daraa', 'Tartus'];

    public function run(): void
    {
        $this->fillProfileFields();
        $this->seedAchievementVariety();
        $this->seedOneTeamHistorySwap();
    }

    private function fillProfileFields(): void
    {
        $users = User::whereIn('role', ['debater', 'judge', 'trainer'])
            ->where(fn ($q) => $q->whereNull('birth_date')->orWhereNull('location'))
            ->get();

        foreach ($users as $user) {
            $user->update([
                'birth_date' => $user->birth_date ?? now()->subYears(rand(18, 35))->subDays(rand(0, 365))->toDateString(),
                'location'   => $user->location ?? self::LOCATIONS[array_rand(self::LOCATIONS)],
            ]);
        }

        $this->command->info("✓ Filled birth_date/location for {$users->count()} users.");
    }

    private function seedAchievementVariety(): void
    {
        if (Achievement::count() > 0) {
            $this->command->info('✓ Achievements already seeded — skipping.');

            return;
        }

        $names = [
            'GOLD'          => 'Best Speaker — Regional Finals',
            'SILVER'        => 'Runner-up — Regional Finals',
            'BRONZE'        => 'Semi-Finalist',
            'HONORABLE'     => 'Outstanding Contribution',
            'PARTICIPATION' => 'Season Participant',
        ];

        // One catalog entry per type — assignments below reuse these, since
        // the achievement feature is now catalog + per-user assignment
        // rather than one row per earned instance.
        $catalog = [];
        foreach ($names as $type => $name) {
            $catalog[$type] = Achievement::create(['name' => $name, 'type' => $type]);
        }

        $admin    = User::where('role', 'admin')->first();
        $debaters = User::where('role', 'debater')->limit(6)->get();
        $types    = array_keys($names);
        $created  = 0;

        foreach ($debaters as $i => $debater) {
            foreach ($types as $j => $type) {
                // Uneven spread — not every debater gets every type.
                if (($i + $j) % 2 !== 0) {
                    continue;
                }
                $debater->achievements()->attach($catalog[$type]->id, [
                    'assigned_at' => now()->subMonths(rand(1, 12))->subDays(rand(0, 27)),
                    'assigned_by' => $admin?->id,
                ]);
                $created++;
            }
        }

        $this->command->info("✓ Seeded {$created} demo achievement assignments (5-entry catalog) across {$debaters->count()} debaters.");
    }

    private function seedOneTeamHistorySwap(): void
    {
        if (TeamMember::where('status', 'past')->exists()) {
            $this->command->info('✓ A team-history row already exists — skipping the swap.');

            return;
        }

        $swapUser = User::where('role', 'debater')->first();
        if (! $swapUser) {
            return;
        }

        $oldMembership = TeamMember::where('user_id', $swapUser->id)->where('status', 'current')->first();
        $newTeam = Team::where('is_random', false)
            ->when($oldMembership, fn ($q) => $q->where('id', '!=', $oldMembership->team_id))
            ->first();

        if (! $oldMembership || ! $newTeam) {
            $this->command->warn('✗ Could not perform the team-history swap — no eligible membership/second team found.');

            return;
        }

        $oldMembership->update(['status' => 'past']);
        TeamMember::create([
            'team_id'  => $newTeam->id,
            'user_id'  => $swapUser->id,
            'priority' => 99,
            'status'   => 'current',
        ]);

        $this->command->info(
            "✓ Team-history swap: user_id={$swapUser->id} ({$swapUser->name}) moved from " .
            "team_id={$oldMembership->team_id} to team_id={$newTeam->id}. Use this user id to verify " .
            'GET /users/{id}/teams/history.'
        );
    }
}
