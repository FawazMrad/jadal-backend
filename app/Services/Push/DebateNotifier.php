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
     * #5 debate_created — RETIRED. This never sends, under any condition.
     *
     * Product decision: restricting it to "open for registration" turned out to
     * be a no-op (debates are created as `scheduled`, which IS the
     * registration-open state), so every debate creation would still have
     * notified every active user. That volume is the most likely reason for a
     * user to disable push altogether, which would also cost them #2, #3 and #4
     * — the notifications that actually matter. So it was dropped outright
     * rather than narrowed.
     *
     * Kept as an inert method rather than deleted so that:
     *   - any existing or future caller is a guaranteed no-op, not a fatal;
     *   - the decision is documented where someone would look for it.
     *
     * PushType::DEBATE_CREATED and its copy are deliberately left in place —
     * unused copy is harmless and the constant may be referenced elsewhere.
     *
     * The frontend has been told explicitly not to build handling for this
     * type. Do not re-enable without telling them first.
     */
    public function debateCreated(Debate $debate): void
    {
        return;
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

    /**
     * #7 — a join request was accepted or refused. The applicant only.
     *
     * `result` is passed in the replacements as well as the data payload: it
     * selects which of the two copy rows PushType uses (handoff §4.3). The two
     * outcomes are separate messages rather than one templated sentence
     * because Arabic does not take a drop-in accepted/refused noun cleanly.
     * The DATA payload is unchanged.
     */
    public function teamJoinResult(Team $team, int $applicantId, bool $accepted): void
    {
        $result = $accepted ? 'accepted' : 'refused';

        $this->push->sendToUsers(
            [$applicantId],
            PushType::TEAM_JOIN_RESULT,
            ['team_id' => $team->id, 'result' => $result],
            ['team_name' => $team->name, 'result' => $result],
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
