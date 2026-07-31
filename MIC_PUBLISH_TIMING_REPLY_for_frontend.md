# Backend reply — microphone publish timing gap

Reply to `MIC_PUBLISH_TIMING_ANSWER_for_backend.md`. Answers your blocking
question, corrects one assumption about what you were observing, and states
what is now implemented vs. still open for agreement.

---

## 1. Your blocking question: does one failed stage poison the rest?

**No. It is already isolated per stage.** Verified in
`LiveDebateController::nextStage()` (lines 255–275):

```php
$egressId = null;
if (! $isPastLastSpeech && $speakerParticipant) {
    try {
        $egressId = app(LiveKitService::class)->startTrackEgressForParticipant(...);
    } catch (\Throwable $e) {
        // Non-fatal — egress is best-effort — but make it observable.
        Log::error('Track egress failed to start', [...]);
    }
}
```

`$egressId` starts as `null`, the call is wrapped in `try/catch (\Throwable)`,
and the catch only logs. Execution continues straight into the DB transaction.
So a mic-lookup failure means:

- the stage advance itself **still succeeds** — the debate is never blocked;
- that one phase is written with `egress_id = null` — it has no recording;
- the next stage runs a completely fresh lookup and is **entirely unaffected**.

There is no shared state, no session-level egress, and no early return. One bad
speaker cannot cascade. No change was needed here.

## 2. Correcting what you were probably seeing

> "We suspect this may explain debates where recordings are missing for stages
> *after* the one with the problematic speaker."

That symptom is real, but the cause is almost certainly **not** a cascade — it
was a separate backend bug, now fixed.

`onEgressEnded()` read `egress_id` and `file_results` from the **top level** of
LiveKit's webhook payload, but LiveKit nests both under `egress_info`. So the
handler silently returned on **every** webhook and **never wrote
`debate_phases.audio_url` for any stage** — even for stages where egress ran
perfectly and the `.mp3` was sitting on disk. From the app's side every
recording looked missing, which is exactly the pattern you described.

Fixed and merged to `stage` (commit `67dc0fa`). If you re-test against current
`stage`, expect `audio_url` to populate for stages that record successfully.
Worth re-checking your "missing later recordings" reports against that before
attributing anything to the mic race.

## 3. What we implemented now

Branch `fix/egress-mic-track-race-retry` — a bounded retry in
`findMicrophoneTrackId()`:

- Up to **5 attempts**, **400ms** apart → worst case ~**1.6s** of added wait.
- **Zero added latency in the common case.** The loop returns on the first
  attempt and only ever sleeps *after* a miss, never after the final attempt.
  There is a test that configures a deliberately absurd 30-second delay, hits
  on the first attempt, and asserts the call still completes in well under a
  second — it runs in ~0.03s.
- Log levels give you the three states you asked to distinguish: `debug` for
  found-on-first-attempt, `info` for found-after-retrying (recovering from a
  real race is worth noticing), `warning` before the throw for never-found.
- Both knobs are env-tunable (`LIVEKIT_MIC_TRACK_ATTEMPTS`,
  `LIVEKIT_MIC_TRACK_RETRY_DELAY_MS`) so we can adjust without a deploy.

Tests cover the exact logged incident: miss, miss, then the track appears —
and the egress starts against the late-arriving track SID.

## 4. Agreeing with your framing: the retry is not the fix

Your point stands and we are not arguing it. Given the app joins muted and only
publishes on a manual tap, the wait is **human reaction time — unbounded**. A
1.6s retry would not have saved your logged incident (8s gap), and lengthening
it is not an option: it would delay every normal stage advance to cover a case
it still could not reliably catch.

So the retry only closes the millisecond-scale window LiveKit's logs showed
(check and publish landing in the same second). It is worth having, it is cheap,
and it is now in — but the real fix is the gate you proposed. We agree with your
split.

## 5. On the server-side gate — one concern before we build it

You suggested we optionally validate speaker mic state at `POST /next-stage` and
reject. We are happy to, **but not as a hard block**, and we would like to agree
the behavior first:

A hard server-side rejection means a debate **cannot advance** while a speaker's
mic is unavailable. That is unrecoverable from the chair's side if the speaker
has a broken mic, denied OS permission, dropped off, or simply left — the
debate would be stuck with no way forward. Losing one stage's recording is bad;
being unable to continue a live debate is worse.

Our proposal:

- **Frontend gate stays advisory and primary** — your blocking "waiting for
  {speaker} to enable their microphone" overlay is the right UX, since the chair
  can see exactly who is holding things up.
- **Backend adds an explicit override rather than a hard gate**: `next-stage`
  rejects with a clear, surfaceable error when the speaker's mic is not live,
  **unless** the request carries something like `force: true`. The chair's UI
  shows "Start anyway without recording?" after a sensible wait. That way the
  common case is protected, and a stuck debate is always recoverable.
- Alternatively, if you would rather not add a param: we return the mic state in
  the existing live-state payload and leave enforcement entirely to your gate,
  with no `next-stage` change at all.

Tell us which of those two you prefer (or push back on both) and we will
implement it. We have not built either yet — as you said, agreeing the split
first.

## 6. Summary

| Item | Status |
|---|---|
| Failure isolated per stage (your blocking question) | Already true — verified, no change needed |
| "Missing later recordings" | Different bug (`audio_url` never saved), fixed in `67dc0fa` |
| Bounded retry for the millisecond race | Implemented, `fix/egress-mic-track-race-retry` |
| Zero added latency in the normal case | Implemented + asserted in tests |
| Frontend gate on next-stage | Yours — we agree it is the real fix |
| Server-side validation of mic state | **Not built.** Needs your call on hard-block vs. force-override vs. state-only |
