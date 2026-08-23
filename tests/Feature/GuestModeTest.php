<?php

namespace Tests\Feature;

use Agence104\LiveKit\AccessToken;
use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebateResult;
use App\Models\Motion;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tokenless spectator access to a live debate via share link.
 *
 * The load-bearing invariant across this file: a guest is defined ONLY by the
 * absence of a bearer token, and no guest rule may ever alter what an
 * authenticated caller sees. Several tests assert both halves of that.
 */
class GuestModeTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST_GONE_STATUS = 410;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.livekit.key', 'test-api-key');
        config()->set('services.livekit.secret', 'test-api-secret-at-least-32-chars-long');
        config()->set('services.livekit.url', 'wss://livekit.test');
        config()->set('services.livekit.host', 'http://livekit.test');
        config()->set('app.frontend_share_base_url', 'https://jadal-platform.com');

        // Stub ONLY the outbound LiveKit HTTP calls. Unlike the usual
        // `$this->mock(LiveKitService::class, …)` in this suite, token
        // generation must stay REAL here — these tests decode the JWT and
        // assert the actual grants (canPublish/canPublishData/hidden), which a
        // full mock would render meaningless. A subclass keeps the real
        // constructor (so the config above is picked up) and the real
        // generateRoomToken().
        $this->app->instance(LiveKitService::class, new class extends LiveKitService {
            public function createRoomIfMissing(string $roomName, int $emptyTimeout = 600): void {}

            public function deleteRoomIfExists(string $roomName): void {}

            public function sendDataToRoom(string $roomName, array $payload, ?array $destinationIdentities = null): void {}
        });
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function makeFormat(): DebateFormat
    {
        return DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds'          => 300,
                'has_reply_speech'             => false,
                'reply_time_seconds'           => 0,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);
    }

    /**
     * A live debate with two teams, three sided debaters each, and two judges —
     * enough that every user-bearing branch of the projection is populated.
     */
    private function makeLiveDebate(array $overrides = []): Debate
    {
        // Room names are unique-constrained, so they must differ per debate —
        // several tests build more than one.
        $slug = 'debate-guest-' . str_replace('.', '', uniqid('', true));

        $debate = Debate::factory()->create(array_merge([
            'format_id'          => $this->makeFormat()->id,
            'motion_id'          => Motion::factory()->create()->id,
            'status'             => 'live',
            'current_stage'      => 0,
            'livekit_room_name'  => "{$slug}-main",
            'prop_room_name'     => "{$slug}-prop",
            'opp_room_name'      => "{$slug}-opp",
            'result_room_name'   => "{$slug}-result",
            'motion_revealed_at' => null,
            'result_revealed_at' => null,
            'ended_at'           => null,
            'finalized_at'       => null,
        ], $overrides));

        foreach (['proposition', 'opposition'] as $side) {
            $team = Team::factory()->create(['is_random' => false]);

            foreach (range(1, 3) as $slot) {
                // email is unique-constrained and several tests build more than
                // one debate — let the factory generate it.
                $user = User::factory()->create([
                    'role'   => 'debater',
                    'status' => 'active',
                    'phone'  => '+962700000' . $slot,
                    'points' => 100 + $slot,
                ]);

                TeamMember::create([
                    'team_id'  => $team->id,
                    'user_id'  => $user->id,
                    'priority' => $slot,
                    'status'   => 'current',
                ]);

                DebateParticipant::create([
                    'debate_id'            => $debate->id,
                    'user_id'              => $user->id,
                    'team_id'              => $team->id,
                    'role'                 => 'debater',
                    'side'                 => $side,
                    'status'               => 'approved',
                    'speaking_phase_order' => $slot,
                ]);
            }

            $debate->update(
                $side === 'proposition'
                    ? ['proposition_team_id' => $team->id]
                    : ['opposition_team_id' => $team->id]
            );
        }

        foreach (range(1, 2) as $order) {
            $judge = User::factory()->create([
                'role'   => 'judge',
                'status' => 'active',
                'phone'  => '+96279000000' . $order,
                'points' => 50 + $order,
            ]);

            DebateParticipant::create([
                'debate_id'   => $debate->id,
                'user_id'     => $judge->id,
                'role'        => 'judge',
                'side'        => 'judge',
                'status'      => 'approved',
                'is_chair'    => $order === 1,
                'judge_order' => $order,
            ]);
        }

        return $debate->fresh();
    }

    private function guestState(Debate $debate)
    {
        return $this->getJson("/api/debates/{$debate->id}/live-state");
    }

    /**
     * The decoded JWT claim set. Asserting the raw claims (rather than SDK
     * accessors) is deliberate: these are the exact bytes the LiveKit server
     * will read, so a change in SDK object shape can never mask a wrong grant.
     */
    private function decodeToken(string $jwt): array
    {
        // Signature validity is asserted separately via AccessToken::fromJwt,
        // which throws on a bad signature.
        (new AccessToken('test-api-key', 'test-api-secret-at-least-32-chars-long'))->fromJwt($jwt);

        $payload = explode('.', $jwt)[1];

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    }

    /** Every user object the guest projection can contain, flattened. */
    private function allUserObjects(array $data): array
    {
        $users = [];

        foreach ($data['judges'] ?? [] as $judge) {
            if (! empty($judge['user'])) {
                $users[] = $judge['user'];
            }
        }

        foreach (['proposition', 'opposition'] as $side) {
            foreach ($data[$side]['members'] ?? [] as $member) {
                $users[] = $member;
            }
            foreach ($data[$side]['speakers'] ?? [] as $speaker) {
                if (! empty($speaker['user'])) {
                    $users[] = $speaker['user'];
                }
            }
        }

        if (! empty($data['result']['judge'])) {
            $users[] = $data['result']['judge'];
        }

        return $users;
    }

    // ── live-state: PII stripping ─────────────────────────────────

    public function test_guest_live_state_strips_pii_from_every_user_object(): void
    {
        $debate = $this->makeLiveDebate();

        $data = $this->guestState($debate)->assertStatus(200)->json('data');

        $users = $this->allUserObjects($data);

        // Guard the guard: if the projection ever stops populating these, the
        // assertions below would vacuously pass.
        $this->assertGreaterThanOrEqual(
            14, // 2 judges + 6 members + 6 speakers
            count($users),
            'Expected judges, members and speakers to be populated.'
        );

        foreach ($users as $user) {
            $this->assertNull($user['email'] ?? null, 'email leaked to guest');
            $this->assertNull($user['phone'] ?? null, 'phone leaked to guest');
            $this->assertNull($user['points'] ?? null, 'points leaked to guest');
            // Names and avatars are the whole point of the roster — keep them.
            $this->assertArrayHasKey('name', $user);
            $this->assertNotNull($user['name']);
            $this->assertArrayHasKey('avatar_url', $user);
        }
    }

    public function test_authenticated_viewer_still_receives_pii(): void
    {
        // The mirror of the test above: PII stripping must be guest-only.
        $debate = $this->makeLiveDebate();
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $data = $this->actingAs($viewer)
            ->getJson("/api/debates/{$debate->id}/live-state")
            ->assertStatus(200)
            ->json('data');

        $speakers = $data['proposition']['speakers'];
        $this->assertNotEmpty($speakers);
        $this->assertNotNull($speakers[0]['user']['email']);
        $this->assertNotNull($speakers[0]['user']['phone']);
    }

    // ── live-state: share_url ─────────────────────────────────

    public function test_share_url_present_for_authenticated_and_absent_for_guest(): void
    {
        $debate = $this->makeLiveDebate();
        $user   = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        // The guest request MUST come first: actingAs() sets the authenticated
        // user for the remainder of the test method, so a "tokenless" call made
        // after it would silently still be authenticated.
        $guest = $this->guestState($debate)->assertStatus(200)->json('data.debate');

        // Absent entirely, not null — a guest must not be able to re-share.
        $this->assertArrayNotHasKey('share_url', $guest);
        // And no share token exists anywhere (Q1 = public read).
        $this->assertArrayNotHasKey('share_token', $guest);

        $authed = $this->actingAs($user)
            ->getJson("/api/debates/{$debate->id}/live-state")
            ->assertStatus(200)
            ->json('data.debate');

        $this->assertSame("https://jadal-platform.com/d/{$debate->id}", $authed['share_url']);
        $this->assertArrayNotHasKey('share_token', $authed);
    }

    public function test_share_url_falls_back_to_app_url_when_unset(): void
    {
        config()->set('app.frontend_share_base_url', null);
        config()->set('app.url', 'https://fallback.test/');

        $debate = $this->makeLiveDebate();
        $user   = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson("/api/debates/{$debate->id}/live-state")
            ->assertStatus(200)
            // Trailing slash on the base must not produce a double slash.
            ->assertJsonPath('data.debate.share_url', "https://fallback.test/d/{$debate->id}");
    }

    // ── live-state: result visibility ───────────────────────────────────

    public function test_guest_result_is_null_before_reveal_and_present_after(): void
    {
        $debate = $this->makeLiveDebate();
        $chair  = $debate->participants->firstWhere('is_chair', true);

        DebateResult::create([
            'debate_id'     => $debate->id,
            'judge_id'      => $chair->user_id,
            'winning_side'  => 'proposition',
            'scores'        => ['stages' => [], 'notes' => 'n/a'],
            'summary_notes' => 'Proposition carried the burden.',
            'submitted_at'  => now(),
        ]);

        $this->guestState($debate)->assertStatus(200)->assertJsonPath('data.result', null);

        $debate->update(['result_revealed_at' => now()]);

        $data = $this->guestState($debate)->assertStatus(200)->json('data');

        $this->assertNotNull($data['result']);
        $this->assertSame('proposition', $data['result']['winning_side']);
        // The revealed result is public, but the judge's PII is not.
        $this->assertNull($data['result']['judge']['email'] ?? null);
        $this->assertNull($data['result']['judge']['phone'] ?? null);
    }

    // ── live-state: rooms + guest role ────────────────────────

    public function test_guest_rooms_expose_only_main_as_joinable(): void
    {
        $debate = $this->makeLiveDebate();

        $rooms = $this->guestState($debate)->assertStatus(200)->json('data.rooms');

        $this->assertTrue($rooms['main']['open']);
        $this->assertTrue($rooms['main']['joinable_for_me']);
        $this->assertSame('guest', $rooms['main']['role_if_joined']);

        foreach (['prop', 'opp', 'result'] as $room) {
            $this->assertFalse($rooms[$room]['joinable_for_me'], "{$room} must not be joinable");
            $this->assertNull($rooms[$room]['role_if_joined'], "{$room} must expose no role");
        }
    }

    public function test_guest_role_never_appears_for_an_authenticated_caller(): void
    {
        $debate = $this->makeLiveDebate();

        $chairParticipant = $debate->participants->firstWhere('is_chair', true);
        $speaker          = $debate->participants
            ->firstWhere(fn ($p) => $p->role === 'debater' && $p->side === 'proposition');

        $callers = [
            'chair'         => User::find($chairParticipant->user_id),
            'debater'       => User::find($speaker->user_id),
            'admin'         => User::factory()->create(['role' => 'admin', 'status' => 'active']),
            'viewer'        => User::factory()->create(['role' => 'debater', 'status' => 'active']),
            'trainer'       => User::factory()->create(['role' => 'trainer', 'status' => 'active']),
        ];

        foreach ($callers as $label => $user) {
            $rooms = $this->actingAs($user)
                ->getJson("/api/debates/{$debate->id}/live-state")
                ->assertStatus(200)
                ->json('data.rooms');

            foreach (['main', 'prop', 'opp', 'result'] as $room) {
                $this->assertNotSame(
                    'guest',
                    $rooms[$room]['role_if_joined'],
                    "role_if_joined leaked 'guest' to authenticated {$label} on {$room}"
                );
            }

            // And the token endpoint must never label them a guest either.
            $roleInRoom = $this->actingAs($user)
                ->getJson("/api/debates/{$debate->id}/token?room=main")
                ->json('data.role_in_room');

            $this->assertNotSame('guest', $roleInRoom, "token role_in_room leaked 'guest' to {$label}");
        }
    }

    // ── token: guest main-room grants ──────────────────────────

    public function test_guest_main_token_is_subscribe_only_hidden_and_uuid_identified(): void
    {
        $debate = $this->makeLiveDebate();

        $data = $this->getJson("/api/debates/{$debate->id}/token?room=main")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('guest', $data['role_in_room']);
        $this->assertSame($debate->livekit_room_name, $data['room_name']);
        $this->assertSame('wss://livekit.test', $data['url']);

        $claims = $this->decodeToken($data['token']);
        $video  = $claims['video'];

        $this->assertMatchesRegularExpression(
            '/^guest-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $claims['sub'],
            'Guest identity must be guest-{uuid}'
        );

        // The LiveKit video grant spells the room name `room` (VideoGrant::$room).
        $this->assertSame($debate->livekit_room_name, $video['room']);
        $this->assertTrue($video['roomJoin'], 'guest must be allowed to join');
        $this->assertTrue($video['canSubscribe'], 'guest must be able to subscribe');
        $this->assertFalse($video['canPublish'], 'guest must never publish media');
        $this->assertFalse($video['canPublishData'], 'guest must never publish data');
        // Q7 — real invisibility via LiveKit's ParticipantPermission.hidden.
        $this->assertTrue($video['hidden'], 'guest must be hidden from other participants');
        // No display name to label them by — they are hidden regardless.
        $this->assertArrayNotHasKey('name', $claims);
    }

    public function test_authenticated_main_token_is_never_hidden(): void
    {
        // The mirror of the hidden assertion: real participants must stay
        // visible to each other.
        $debate  = $this->makeLiveDebate();
        $speaker = $debate->participants
            ->firstWhere(fn ($p) => $p->role === 'debater' && $p->side === 'proposition');

        $token = $this->actingAs(User::find($speaker->user_id))
            ->getJson("/api/debates/{$debate->id}/token?room=main")
            ->assertStatus(200)
            ->json('data.token');

        $claims = $this->decodeToken($token);

        $this->assertSame((string) $speaker->user_id, $claims['sub']);
        $this->assertArrayNotHasKey('hidden', $claims['video']);
    }

    public function test_each_guest_token_request_mints_a_fresh_identity(): void
    {
        $debate = $this->makeLiveDebate();

        $identities = collect(range(1, 3))->map(function () use ($debate) {
            $token = $this->getJson("/api/debates/{$debate->id}/token?room=main")
                ->assertStatus(200)
                ->json('data.token');

            return $this->decodeToken($token)['sub'];
        });

        $this->assertCount(3, $identities->unique(), 'guest identities must never be reused');
    }

    public function test_guest_is_denied_prep_and_result_rooms(): void
    {
        $debate = $this->makeLiveDebate([
            'prep_rooms_opened_at'  => now()->subMinutes(5),
            'speeches_completed_at' => now()->subMinute(),
        ]);

        foreach (['prop', 'opp', 'result'] as $room) {
            $this->getJson("/api/debates/{$debate->id}/token?room={$room}")
                ->assertStatus(403)
                ->assertJsonPath(
                    'message',
                    'هذه الغرفة غير متاحة للضيوف. | This room is not available to guests.'
                );
        }
    }

    public function test_guest_main_token_denied_when_room_not_open(): void
    {
        // teams-selected: within no guest window at all, so the guest is told
        // the link is not available rather than being handed a dead token.
        $debate = $this->makeLiveDebate(['status' => 'teams-selected']);

        $this->getJson("/api/debates/{$debate->id}/token?room=main")
            ->assertStatus(self::GUEST_GONE_STATUS);
    }

    // ── Expiry window ───────────────────────────────────────────────────

    public function test_guest_access_open_while_live(): void
    {
        $debate = $this->makeLiveDebate();

        $this->guestState($debate)->assertStatus(200);
        $this->getJson("/api/debates/{$debate->id}/token?room=main")->assertStatus(200);
    }

    public static function terminalStatusProvider(): array
    {
        return [
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
        ];
    }

    #[DataProvider('terminalStatusProvider')]
    public function test_guest_access_open_within_ten_minutes_of_terminal_state(string $status): void
    {
        $debate = $this->makeLiveDebate();
        $debate->update(['status' => $status, 'finalized_at' => now()->subMinutes(9)]);

        $this->guestState($debate)->assertStatus(200);
    }

    #[DataProvider('terminalStatusProvider')]
    public function test_guest_access_closed_past_ten_minutes_of_terminal_state(string $status): void
    {
        $debate = $this->makeLiveDebate();
        $debate->update(['status' => $status, 'finalized_at' => now()->subMinutes(11)]);

        $this->guestState($debate)
            ->assertStatus(self::GUEST_GONE_STATUS)
            ->assertJsonPath(
                'message',
                'لم يعد هذا النقاش متاحًا للضيوف. | This debate is no longer available to guests.'
            );

        $this->getJson("/api/debates/{$debate->id}/token?room=main")
            ->assertStatus(self::GUEST_GONE_STATUS);
    }

    public function test_guest_access_closed_before_debate_ever_goes_live(): void
    {
        foreach (['scheduled', 'announced', 'teams-selected'] as $status) {
            $debate = $this->makeLiveDebate(['status' => $status]);

            $this->guestState($debate)
                ->assertStatus(self::GUEST_GONE_STATUS, "status {$status} must be closed to guests");
        }
    }

    #[DataProvider('terminalStatusProvider')]
    public function test_authenticated_access_is_unaffected_past_the_guest_window(string $status): void
    {
        // The whole point of Q4: the window gates guests ONLY.
        $debate = $this->makeLiveDebate();
        $debate->update(['status' => $status, 'finalized_at' => now()->subDays(30)]);

        $user = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson("/api/debates/{$debate->id}/live-state")
            ->assertStatus(200)
            ->assertJsonPath('data.rooms.main.role_if_joined', 'viewer');
    }

    public function test_terminal_transitions_stamp_finalized_at(): void
    {
        // close-room with no result → cancelled; with a result → completed.
        // Both must anchor the guest window.
        $cancelled = $this->makeLiveDebate(['speeches_completed_at' => now()->subMinute()]);
        $chair     = $cancelled->participants->firstWhere('is_chair', true);

        $this->actingAs(User::find($chair->user_id))
            ->postJson("/api/debates/{$cancelled->id}/close-room")
            ->assertStatus(200);

        $cancelled->refresh();
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->finalized_at, 'cancelled path must stamp finalized_at');

        $completed  = $this->makeLiveDebate(['speeches_completed_at' => now()->subMinute()]);
        $chair2     = $completed->participants->firstWhere('is_chair', true);

        DebateResult::create([
            'debate_id'    => $completed->id,
            'judge_id'     => $chair2->user_id,
            'winning_side' => 'opposition',
            'scores'       => ['stages' => [], 'notes' => 'n/a'],
            'submitted_at' => now(),
        ]);

        $this->actingAs(User::find($chair2->user_id))
            ->postJson("/api/debates/{$completed->id}/close-room")
            ->assertStatus(200);

        $completed->refresh();
        $this->assertSame('completed', $completed->status);
        $this->assertNotNull($completed->finalized_at, 'completed path must stamp finalized_at');
    }

    // ── Optional auth must not weaken authenticated access ────────────────────

    public function test_invalid_bearer_token_is_rejected_rather_than_downgraded_to_guest(): void
    {
        $debate = $this->makeLiveDebate();

        // A stale/garbage token must 401, NOT silently return the guest
        // projection — otherwise an expired session looks like a broken app.
        $this->getJson("/api/debates/{$debate->id}/live-state", [
            'Authorization' => 'Bearer definitely-not-a-real-token',
        ])->assertStatus(401);
    }

    public function test_other_debate_routes_still_require_authentication(): void
    {
        $debate = $this->makeLiveDebate();

        // Only live-state and token are guest-reachable; every other route must
        // still reject a tokenless caller.
        //
        // NOTE: the asserted invariant is "rejected as unauthenticated", not a
        // specific status. `auth:sanctum` raises AuthenticationException, which
        // has no getStatusCode(), so the app's API exception handler
        // (bootstrap/app.php) currently renders it as 500 rather than 401.
        // That is a PRE-EXISTING bug on every guarded route, unrelated to guest
        // mode and deliberately not changed here — see the PR description.
        // Asserting the message rather than 500 keeps this test honest and
        // means it will not break when that bug is fixed.
        foreach ([
            ['get',  "/api/debates/{$debate->id}"],
            ['get',  "/api/debates/{$debate->id}/chat"],
            ['post', "/api/debates/{$debate->id}/close-room"],
        ] as [$verb, $url]) {
            $response = $verb === 'get' ? $this->getJson($url) : $this->postJson($url);

            $this->assertNotSame(200, $response->status(), "{$url} must not be guest-readable");
            $response->assertJsonPath('message', 'Unauthenticated.');
        }
    }

    // ── — the /d/{id} browser fallback ───────────────────────────────────

    public function test_share_link_route_resolves_in_a_browser(): void
    {
        $debate = $this->makeLiveDebate();

        $this->get("/d/{$debate->id}")
            ->assertStatus(200)
            ->assertSee('Debate #' . $debate->id);

        // Renders for any id — deliberately not an existence oracle.
        $this->get('/d/99999999')->assertStatus(200);
    }

    // ── PART F — team roster visible to any authenticated user ────────────────

    public function test_non_member_receives_team_roster_with_contact_details_withheld(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = Team::factory()->create([
            'is_random'  => false,
            'created_by' => $trainer->id,
            'leader_id'  => $leader->id,
            'status'     => 'active',
        ]);

        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $leader->id,
            'priority' => 1, 'status' => 'current',
        ]);

        $outsider = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $data = $this->actingAs($outsider)
            ->getJson("/api/teams/{$team->id}")
            ->assertStatus(200)
            ->json('data');

        // The roster itself is now visible — that is the change.
        $this->assertNotEmpty($data['members']);
        $this->assertSame($leader->id, $data['members'][0]['user']['id']);
        $this->assertSame($leader->name, $data['members'][0]['user']['name']);
        $this->assertArrayHasKey('avatar_url', $data['members'][0]['user']);

        // Contact details are not.
        $this->assertNull($data['members'][0]['user']['email']);
        $this->assertNull($data['members'][0]['user']['phone']);
        $this->assertNull($data['leader']['email']);
        $this->assertNull($data['created_by']['email']);
    }

    public function test_member_response_shape_matches_non_member_shape(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = Team::factory()->create([
            'is_random'  => false,
            'created_by' => $trainer->id,
            'leader_id'  => $leader->id,
            'status'     => 'active',
        ]);

        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $leader->id,
            'priority' => 1, 'status' => 'current',
        ]);

        $outsider = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $memberView   = $this->actingAs($leader)->getJson("/api/teams/{$team->id}")->json('data');
        $outsiderView = $this->actingAs($outsider)->getJson("/api/teams/{$team->id}")->json('data');

        // Identical keys at every level the client parses — only values differ.
        $this->assertSame(array_keys($memberView), array_keys($outsiderView));
        $this->assertSame(
            array_keys($memberView['members'][0]['user']),
            array_keys($outsiderView['members'][0]['user'])
        );

        // The member keeps their own view unchanged.
        $this->assertSame($leader->email, $memberView['members'][0]['user']['email']);
    }

    public function test_inactive_team_remains_viewable_by_any_authenticated_user(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = Team::factory()->create([
            'is_random'  => false,
            'created_by' => $trainer->id,
            'leader_id'  => $leader->id,
            'status'     => 'inactive',
        ]);

        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $leader->id,
            'priority' => 1, 'status' => 'current',
        ]);

        $outsider = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($outsider)
            ->getJson("/api/teams/{$team->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_team_endpoint_still_requires_authentication(): void
    {
        $team = Team::factory()->create(['is_random' => false]);

        // PART F widened this to any AUTHENTICATED user — not to guests.
        // See the note in test_other_debate_routes_still_require_authentication
        // about the pre-existing 401-rendered-as-500 handler bug.
        $response = $this->getJson("/api/teams/{$team->id}");

        $this->assertNotSame(200, $response->status(), 'teams must not be guest-readable');
        $response->assertJsonPath('message', 'Unauthenticated.');
    }
}
