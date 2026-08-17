<?php

namespace Tests\Feature;

use App\Models\Motion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchMotionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['role' => 'judge', 'status' => 'active']);
    }

    public function test_search_filters_by_text(): void
    {
        Motion::factory()->create(['text' => 'This house supports renewable energy']);
        Motion::factory()->create(['text' => 'This house would ban private cars']);

        $response = $this->actingAs($this->user())->getJson('/api/motions?search=renewable');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_no_search_returns_all(): void
    {
        Motion::factory()->count(3)->create();
        $response = $this->actingAs($this->user())->getJson('/api/motions');
        $this->assertCount(3, $response->json('data'));
    }

    public function test_nonexistent_returns_empty(): void
    {
        Motion::factory()->create(['text' => 'Something concrete']);
        $response = $this->actingAs($this->user())->getJson('/api/motions?search=zzzznope');
        $this->assertCount(0, $response->json('data'));
    }

    public function test_empty_search_not_filtered(): void
    {
        Motion::factory()->count(2)->create();
        $response = $this->actingAs($this->user())->getJson('/api/motions?search=');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_single_char_rejected(): void
    {
        $response = $this->actingAs($this->user())->getJson('/api/motions?search=a');
        $response->assertStatus(422);
    }

    /**
     * GET /motions is open to every authenticated user, so the motion author's
     * contact details must not ride along. This previously published the
     * admin's email, phone, birth date and location to any debater.
     */
    public function test_motion_author_contact_details_are_not_exposed(): void
    {
        $author = User::factory()->create([
            'role'  => 'admin',
            'email' => 'admin@example.test',
            'phone' => '0932238253',
        ]);

        Motion::factory()->create(['text' => 'This house tests privacy', 'added_by' => $author->id]);

        $addedBy = $this->actingAs($this->user())
            ->getJson('/api/motions')
            ->assertStatus(200)
            ->json('data.0.added_by');

        // Still identifiable …
        $this->assertSame($author->id, $addedBy['id']);
        $this->assertSame($author->name, $addedBy['name']);
        $this->assertArrayHasKey('avatar_url', $addedBy);

        // … but not contactable.
        $this->assertNull($addedBy['email']);
        $this->assertNull($addedBy['phone']);
        $this->assertNull($addedBy['birth_date']);
        $this->assertNull($addedBy['location']);
    }
}
