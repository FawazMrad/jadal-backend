# Jadal — Backend Response

**From:** Backend
**To:** Frontend (Claude Code)
**Re:** `BACKEND_REQUIREMENTS.md`

Everything below marked **VERIFIED** was checked against the current `stage` code, not
assumed. Read §0 first — it contains four corrections where your document's stated current
state does not match reality, and two of them will break your models if you build against
them as written.

---

## 0. Corrections — read before you write any code

### 0.1 ⚠️ Achievement `rank` value changed: `honoring` → `honorable`

Your doc lists `gold | silver | bronze | honoring | participation`. **`honoring` no longer
exists.** Achievements were restructured into a shared catalog + per-user assignment, and
the tier taxonomy was renamed at the same time. The wire values are now:

```
gold | silver | bronze | honorable | participation
```

The mobile field is still called `rank` and is still lowercase, so only that one value
changed. If your enum parses `honoring` it will fail on live data.

### 0.2 ⚠️ Achievements response is `data: [...]`, not `data.items[]`

Your doc shows `data.items[]`. The actual envelope for paginated endpoints in this API is:

```json
{
  "success": true,
  "message": "…",
  "data": [ { …achievement… } ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 15, "total": 34 }
}
```

`data` is the array itself and pagination lives in a sibling `meta` block. There is no
`items` key anywhere.

### 0.3 ⚠️ `GET /motion-frameworks` returns `name`, not `label` — and is NOT localized

Actual shape (**VERIFIED**, `MotionFrameworkResource`):

```json
{ "id": 1, "name": "Economic", "color_hex": "#3366FF" }
```

- The field is **`name`**, not `label`.
- There is a bonus **`color_hex`** (nullable) you may want for the filter chips.
- **Not localized.** `motion_frameworks` has a single `name` column and the endpoint does
  not read `Accept-Language`. Whatever an admin typed is what you get, in one language.
  If you need ar/en labels, that is a schema change (`name_ar` / `name_en`) — tell me and
  I will scope it, but it is not a small change because existing rows have one value only.

### 0.4 ⚠️ §1.9 role-gating is NOT currently enforced

You asked me to confirm the debater-only stats endpoints reject non-debater subject ids.
**They do not** (**VERIFIED** — `DebaterStatsController::canView()` checks viewer
permission only, never that the *subject* is a debater). Requesting
`/debaters/{judge_id}/stats/win-rate` today returns `200` with empty/zero aggregates, not
`403`.

So §1.9 is real backend work, not a confirmation. Your UI hiding the option is currently
the *only* thing preventing it. Listed in §3 below.

---

## 1. Answers to your 10 open questions

| # | Question | Answer |
|---|---|---|
| 1 | `positions` on teams leaderboard | **Agreed — not supported.** A position is a per-debater slot; it has no meaning for a team aggregate. `/leaderboards/teams` will accept `from`/`to`/`frameworks` only, and `422` on `positions`. Hide the option in team scope as you proposed. |
| 2 | `group_by` on leaderboards | **Agreed — no `group_by`.** A grouped top-10 is not one ranked list. Date range covers the "this month/year" use case. |
| 3 | Canonical state name | **`teams-selected`** (lowercase, hyphen). **VERIFIED** — it is the literal value in the `debates.status` DB enum, so it cannot change cheaply. Please align the frontend to `teams-selected` and drop "side selected" everywhere. Prep-room join: **already works**, with conditions — see §2. |
| 4 | `stats_visible` transition | `PUT /profile` will **accept and ignore** it (no `422`), and it will be **dropped from `GET /profile` / `GET /users/{id}` in the same release**. Time your model change to that release; sending it stays harmless indefinitely. |
| 5 | Achievements sort contract | **Accepted as you proposed** — flat list, `sort=date\|rank`, pagination unchanged, you render section headers. And **yes, `assigned_at` is guaranteed non-null** (**VERIFIED** — `timestamp()` NOT NULL in the migration), `rank` is always one of the five (DB-constrained). |
| 6 | Firebase project ownership | **Needs your/the client's decision — I cannot decide this.** My recommendation: **the client owns the Firebase project**, and both of us are added. Reason: the APNs auth key and Play/App Store association are client assets; if either of us owns it, handover later is painful. I need the service-account JSON; you need `google-services.json` + the iOS plist from that same project. **Nothing on push can start until this exists.** |
| 7 | `prep_reminder` anchor | Proposed: fire **1 hour before `prep_rooms_opened_at`** (the moment prep rooms actually open — that is the real "preparation is about to start" event). If the debate is created/rescheduled with **less than 1 hour** to that moment: **send immediately** if the moment is still in the future, **skip entirely** if it has already passed. Confirm or correct. |
| 8 | Weekly digest schedule | Proposed: **Saturday 18:00 Asia/Damascus** (server tz), covering articles published in the preceding 7 days. Saturday evening because the debate week here starts Sunday. Confirm or pick another slot. |
| 9 | FCM topics for #5/#8 | Proposed: **yes, use a topic** for the two all-user sends. Topic name **`all-users`**. That means the **app must subscribe on login and unsubscribe on logout** — that is a frontend contract item, so flag it in your implementation. Per-user sends (#1,2,3,4,6,7) go to stored device tokens, not topics. |
| 10 | Framework labels localized | **No** — see §0.3. Single `name`, no `Accept-Language`. |

---

## 2. §5.7 — prep-room join in `teams-selected` (VERIFIED, already works)

`GET /debates/{id}/token?room=prop|opp` **does** issue tokens while the debate is in
`teams-selected`. But there are three additional conditions, and you will get a `403` if
any is unmet (**VERIFIED**, `LiveKitController::resolvePrepRoom()`):

1. `debate.prep_rooms_opened_at` is set **and** in the past. Prep rooms open on a time
   offset — `teams-selected` alone is **not** sufficient.
2. `debate.current_stage === 0`. Prep rooms close the moment the chair starts stage 1, and
   reopen on rollback-to-lobby.
3. The caller is an **approved `debater` whose `side` matches the requested room**.

**Which fields to read** — all present in `GET /debates/{id}/live-state`:

- `rooms.prop.open` / `rooms.opp.open` → are prep rooms open right now
- `rooms.prop.joinable_for_me` / `rooms.opp.joinable_for_me` → **use this one** to decide
  whether to show the Join button; it already accounts for all three conditions above plus
  the caller's side
- `rooms.prop.name` / `rooms.opp.name` → the room name
- The caller's own side comes from their participant record; `joinable_for_me` is true on
  exactly one of the two, so you can route off that alone without computing side yourself.

**Non-team callers:** a judge, a trainer, or a viewer gets `joinable_for_me: false` on both
prep rooms and a **`403`** if they request the token anyway. Note **trainers are
deliberately excluded** from prep rooms — that is intentional existing behaviour, not an
oversight.

So for §5.7 you need **no backend change** — just render the Join button off
`rooms.{prop|opp}.joinable_for_me` and align the state name to `teams-selected`.

---

## 3. Status of each requested item

### Already true — no work needed (VERIFIED)

| Item | Finding |
|---|---|
| 2.1.1 `frameworks` filter functional on all 5 debater-stats endpoints | **Yes, genuinely implemented** — `DebaterStatsService::participationRows()` intersects each row's framework ids against the filter and drops non-matches. Not accepted-and-ignored. |
| 2.5 prep-room join in `teams-selected` | Works — see §2 for the conditions. |
| 2.7 data guarantees | `assigned_at` NOT NULL; `rank` DB-constrained to the five values. |

### Small — I can do these next, they are unambiguous

| Item | Work |
|---|---|
| 2.1.2 `positions` + `frameworks` mutual exclusivity | Add a `422` to `StatsFilterRequest` when both are present. Currently both are accepted and silently AND-ed. |
| 2.7 achievements `sort=date\|rank` | Add the param. Today the list is **always** rank-then-recency, so `sort=rank` is the current behaviour and `sort=date` is the new one — note your **default is `date`**, which means the default ordering *changes*. |
| 2.3 attendance endpoints | I will **deprecate, not delete** — keep them routed but return `410 Gone`, for one release, so an un-updated app gets a clear signal rather than a confusing `404`. Then delete. Activity endpoints untouched. |
| §1.9 role-gate debater-only stats | Return `422` when the subject user is not a debater (see §0.4 — this is new work). |

### Medium — needs a decision from you first

| Item | Concern |
|---|---|
| 2.6 remove `stats_visible` | Mechanically easy (**VERIFIED** — 5 call sites + leaderboard exclusion + the column). But this makes **every user's statistics readable by every authenticated user, permanently and irreversibly for existing users who deliberately opted out**. Some of them opted out on purpose. This is a privacy posture change, not a refactor — I want explicit sign-off from the product owner, not just the frontend spec, before I remove it. Say the word and it is a small change. |

### Large — real projects, not tickets

| Item | Estimate / blocker |
|---|---|
| 2.2 leaderboard filters | **Smaller than expected for 4 of 5 metrics — but one metric cannot support the date filter at all.** See §3.1 below. |
| 2.8 push notifications | **Blocked** on the Firebase project (Q6). Beyond that: `devices` table + 2 endpoints, an FCM HTTP v1 client, 8 trigger points wired into existing flows, **scheduler infrastructure for #3 and #8** (this project currently has `QUEUE_CONNECTION=sync` and only one scheduled command, so per-debate one-shot jobs that survive rescheduling need a real queue + job records), token pruning on FCM invalid-token responses, and ar/en copy for 8 types. This is the single biggest item in your document by a wide margin. |

### 3.1 ⚠️ `metric=points` cannot honour `from`/`to` — decide what it should do

I initially assumed the leaderboards were a separate all-time aggregate that would need
rebuilding. **That was wrong** — I checked. For `win_rate`, `avg_score`, `best_speaker`
and `improvement`, `LeaderboardService` **already** runs each candidate through the exact
same `participationRows($user, $filter)` pipeline as own-statistics, just with an empty
filter (`StatsFilter::fromArray([])`). Passing a populated filter through is close to a
one-liner, and it comes with zero drift risk because it is literally the same code path
that produces the per-user numbers. Good news: **4 of the 5 debater metrics and all 4 team
metrics are cheap to filter.**

**But `metric=points` is different and cannot be fixed cheaply.** It reads
`users.points` directly — a *running Elo total*, not something derived per debate. There
is no "points as of month X" without replaying the whole `points_histories` ledger, and
the Elo path is order-dependent so it cannot be summed over a slice. Options:

- **(a) Reject** — `422` if `from`/`to`/`positions`/`frameworks` are sent with
  `metric=points`. Honest, and the UI hides the filters in that tab. **My recommendation.**
- **(b) Ignore** — accept the filters and silently return the all-time ranking. Cheapest,
  but it shows the user a filtered heading over unfiltered data. I would avoid this.
- **(c) Reconstruct** from `points_histories` — accurate but the most expensive option
  here, and it changes what "points" means (delta-in-window rather than current rating).

Tell me which. Until then I will assume **(a)**.

**One pre-existing caveat while I am here:** the non-`points` metrics loop every candidate
debater and run the full pipeline per user on every request. That is an N+1-shaped cost
that already exists today (the service's own comment flags it), and filtering does not make
it worse — but if these leaderboards get real traffic they will need caching. Not blocking,
just so it is on your radar rather than surfacing later as "the leaderboard got slow."

---

## 4. On the notification triggers — three things to settle

1. **#1 vs #2 de-duplication.** Proposed rule: on the announce transition, compute the
   selected-participant set first; those users get **#2 only**, everyone else who
   participates gets **#1**. No user ever receives both for the same transition. Confirm.

2. **#5 `debate_created` to all users.** This fires on *every* debate creation. With an
   active platform that is a lot of pushes, and it is the most likely notification to make
   users disable push entirely — which would also cost you #2, #3 and #4, which are the
   genuinely useful ones. Recommend either restricting it to debates that are actually open
   for registration, or making it opt-out client-side. Your call, but flagging it.

3. **Copy.** Yes please — **you draft the ar/en copy** and I will wire it. You have better
   context on tone and on how the strings render in the UI. I need all 8 × 2 strings with
   the placeholders marked (e.g. `{debate_title}`).

---

## 5. What I need from you to proceed

1. **Sign-off on `stats_visible` removal** from the product owner (§3, medium).
2. **Firebase project** created and both parties added (Q6) — hard blocker for all of §2.8.
3. **Confirm Q7, Q8, Q9** (prep-reminder anchor, digest schedule, topic strategy).
4. **Decide `metric=points` + filters** (§3.1) — reject, ignore, or reconstruct.
5. **The ar/en copy** for the 8 notification types.
6. **Confirm you have absorbed §0** — especially `honoring` → `honorable` and
   `data[]` vs `data.items[]`, since those two will silently break your parsing.

**You are not blocked on me for most of your work.** Everything in §1–§6 of your spec that
is frontend-only, plus §5.7 (which needs no backend change), can start now. The only items
genuinely gated on backend delivery are the leaderboard filters (2.2) and push (2.8).

Tell me which of the "small" items you want first and I will ship them in one pass.
