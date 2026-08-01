<?php

namespace App\Services\Push;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\PushType;

/**
 * Recipient resolution for the 8 push types (frontend spec §7.2).
 *
 * Keeping the "who gets this" rules here means each trigger site stays a single
 * call, and the rules are testable without going through a controller.
 *
 * Every method is best-effort by construction: PushService swallows delivery
 * failures, so a notification problem can never break the debate/survey/team
 * action that triggered it.
 */
class DebateNotifier
{
    public function __construct(private PushService $push) {}

    /** #1 — a debate changed lifecycle state. All participants. */
    public function debateStateChanged(Debate $debate, array $excludeUserIds = []): void
    {
        $this->push->sendToUsers(
            $this->participantIds($debate)->diff($excludeUserIds),
            PushType::DEBATE_STATE_CHANGED,
            ['debate_id' => $debate->id],
            ['debate_title' => $debate->title],
        );
    }

    /**
     * #2 — selected participants when a debate is announced.
     *
     * De-duplication rule (spec §4): the selected set gets #2 ONLY. Callers
     * that also fire #1 for the same transition must pass this same set as
     * $excludeUserIds, so nobody receives both for one event.
     */
    public function debateAccepted(Debate $debate, iterable $selectedUserIds): void
    {
        $this->push->sendToUsers(
            $selectedUserIds,
            PushType::DEBATE_ACCEPTED,
            ['debate_id' => $debate->id],
            ['debate_title' => $debate->title],
        );
    }

    /** #3 — one hour before prep opens. DEBATERS ONLY, explicitly not judges. */
    public function prepReminder(Debate $debate): void
    {
        $debaterIds = DebateParticipant::where('debate_id', $debate->id)
            ->where('role', 'debater')
            ->where('status', 'approved')
            ->pluck('user_id');

        $this->push->sendToUsers(
            $debaterIds,
            PushType::PREP_REMINDER,
            ['debate_id' => $debate->id],
            ['debate_title' => $debate->title],
        );
    }

    /** #4 — motion revealed. All participants INCLUDING judges. */
    public function motionRevealed(Debate $debate): void
    {
        $this->push->sendToUsers(
            $this->participantIds($debate),
            PushType::MOTION_REVEALED,
            ['debate_id' => $debate->id],
            ['debate_title' => $debate->title],
        );
    }

    /**
     * #5 — a new debate was created. All users.
     *
     * Sent to stored device tokens rather than an FCM topic: a topic would
     * require the app to subscribe/unsubscribe and would deliver to logged-out
     * installs too. See BACKEND_RESPONSE.md for the volume caveat on this one.
     */
    public function debateCreated(Debate $debate): void
    {
        $this->push->sendToUsers(
            User::where('status', 'active')->pluck('id'),
            PushType::DEBATE_CREATED,
            ['debate_id' => $debate->id],
            ['debate_title' => $debate->title],
        );
    }

    /**
     * #6 — a new survey. ONLY users eligible to see it: a team-targeted survey
     * notifies those teams' current members, a global one notifies everyone.
     */
    public function surveyCreated(int $surveyId, string $surveyTitle, array $targetRoles = [], array $teamIds = []): void
    {
        if (! empty($teamIds)) {
            $recipients = TeamMember::whereIn('team_id', $teamIds)
                ->where('status', 'current')
                ->pluck('user_id');
        } else {
            $recipients = User::where('status', 'active')
                ->when(! empty($targetRoles), fn ($q) => $q->whereIn('role', $targetRoles))
                ->pluck('id');
        }

        $this->push->sendToUsers(
            $recipients,
            PushType::SURVEY_CREATED,
            ['survey_id' => $surveyId],
            ['survey_title' => $surveyTitle],
        );
    }

    /** #7 — a join request was accepted or refused. The applicant only. */
    public function teamJoinResult(Team $team, int $applicantId, bool $accepted): void
    {
        $this->push->sendToUsers(
            [$applicantId],
            PushType::TEAM_JOIN_RESULT,
            ['team_id' => $team->id, 'result' => $accepted ? 'accepted' : 'refused'],
            [
                'team_name' => $team->name,
                'result_ar' => $accepted ? 'قبول' : 'رفض',
                'result_en' => $accepted ? 'accepted' : 'refused',
            ],
        );
    }

    /** #8 — weekly blog digest. All users; carries no entity id. */
    public function blogWeeklyDigest(int $articleCount): void
    {
        $this->push->sendToUsers(
            User::where('status', 'active')->pluck('id'),
            PushType::BLOG_WEEKLY_DIGEST,
            [],
            ['count' => $articleCount],
        );
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private function participantIds(Debate $debate)
    {
        return DebateParticipant::where('debate_id', $debate->id)
            ->where('status', 'approved')
            ->pluck('user_id')
            ->unique()
            ->values();
    }
}
