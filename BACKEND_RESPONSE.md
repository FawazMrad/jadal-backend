# Jadal — Backend Response (AS BUILT)

**From:** Backend
**To:** Frontend (Claude Code)
**Re:** `BACKEND_REQUIREMENTS.md`
**Status:** Implemented, full suite green (390 tests). Branch
`feature/frontend-spec-backend-items`.

Read §0 first — four corrections where your document's stated current state does not match
reality. Two of them will silently break your parsing if you build against them as written.

---

## 0. Corrections — read before writing any code

### 0.1 ⚠️ Achievement `rank`: `honoring` → `honorable`

Your doc lists `gold | silver | bronze | honoring | participation`. **`honoring` no longer
exists.** Wire values are now:

```
gold | silver | bronze | honorable | participation
```

Still lowercase, still the field `rank`. Only that one value changed.

### 0.2 ⚠️ Achievements response is `data: [...]`, not `data.items[]`

```json
{
  "success": true,
  "message": "…",
  "data": [ { …achievement… } ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 15, "total": 34 }
}
```

`data` is the array; pagination is a sibling `meta`. There is no `items` key anywhere in
this API.

### 0.3 ⚠️ `GET /motion-frameworks` returns `name`, not `label` — and is NOT localized

```json
{ "id": 1, "name": "Economic", "color_hex": "#3366FF" }
```

`name` (not `label`), plus a nullable `color_hex` you may want for filter chips. **Not
localized** — one `name` column, no `Accept-Language`. Bilingual labels would need a schema
change (`name_ar`/`name_en`); tell me if you want it scoped.

### 0.4 §1.9 was NOT implemented — it is now

You asked me to *confirm* the debater-only endpoints reject non-debater subjects. They did
not: `/debaters/{judge_id}/stats/win-rate` returned `200` with empty aggregates. **Now
returns `422`.** See §2.

---

## 1. Answers to your 10 open questions

| # | Answer |
|---|---|
| 1 | **Agreed — `positions` unsupported on the team leaderboard.** Sending it returns `422`. |
| 2 | **Agreed — no `group_by` on leaderboards.** A grouped top-N is not one ranked list. |
| 3 | **`teams-selected`** (lowercase, hyphen) — it is the literal `debates.status` DB enum value. Please align and drop "side selected". Prep-room join already works; see §5. |
| 4 | `PUT /profile` **accepts and ignores** `stats_visible` (no `422`); the field is **gone from all responses now**, and the column is dropped. |
| 5 | **Flat list + `sort=date\|rank` as you proposed.** `assigned_at` is guaranteed non-null (DB `NOT NULL`), `rank` always one of five. |
| 6 | **Needs your/the client's decision — still blocking.** Recommend the **client owns** the Firebase project with both of us added: the APNs key and store associations are client assets. I need the service-account JSON; you need `google-services.json` + iOS plist from that same project. |
| 7 | Implemented as **1 hour before `prep_rooms_opened_at`**. Created/rescheduled inside that hour → fires on the next minute's run. Once prep has opened it is skipped, never sent late. |
| 8 | **Saturday 18:00 Asia/Damascus**, covering the preceding 7 days. Sends **nothing** if zero articles were published. |
| 9 | **No topics — I used stored device tokens for all 8.** See §6.3 for why this differs from my earlier proposal; **you do not need to subscribe/unsubscribe to anything.** |
| 10 | **Not localized** — see §0.3. |

---

## 2. Statistics endpoints — what changed

**`frameworks` was already functional** on all five debater-stats endpoints (it genuinely
filters, not accepted-and-ignored). Unchanged.

**New — mutual exclusivity (§1.4/§9).** Sending both `positions` and `frameworks` to any of
the five endpoints now returns `422`:

```json
{ "message": "The given data was invalid.", "errors": { "positions": ["positions and frameworks are mutually exclusive"] } }
```

Either alone still works.

**New — §1.9 role gate.** A non-debater subject returns `422` with the subject's role in
`errors.role`:

```
GET /debaters/{judge_id}/stats/win-rate  →  422
"هذه الإحصائيات متاحة للمتناظرين فقط. | These statistics are only available for debaters."
```

Applies to `win-rate`, `avg-score`, `best-speaker`, `score-ranking`, `improvement`.
**`/activity` is unaffected** — it is valid for every role.

**Authorization widened (§6.4).** Any authenticated user can now read any user's statistics.
The old self/admin/supervising-coach/`stats_visible` gate is gone from the debater stats,
activity stats and coach team-summary endpoints.

---

## 3. Leaderboards (§1.5) — filters added

Both endpoints now accept `from`, `to`, `frameworks`; the debater one also accepts
`positions`. Response shape is **unchanged**.

```
GET /leaderboards/debaters?metric=win_rate&from=2026-01&to=2026-07&frameworks=1,2&limit=10
GET /leaderboards/teams?metric=avg_score&from=2026-01
```

Filters are threaded into the **same per-subject pipeline** the own-statistics screens use,
so a filtered leaderboard value always equals that subject's own filtered number.

**Three `422` cases:**

| Case | Why |
|---|---|
| `positions` + `frameworks` together | Mutually exclusive (§1.4/§9) |
| `positions` on `/leaderboards/teams` | A position is a per-debater slot; meaningless for a team aggregate |
| **any filter with `metric=points`** | See below |

### ⚠️ `metric=points` rejects all filters

`users.points` is a **running Elo rating**, not a per-debate quantity — there is no "points
as of month X" without replaying the ledger, and Elo is order-dependent so it cannot be
summed over a slice. Sending `from`/`to`/`positions`/`frameworks` with `metric=points`
returns `422` rather than silently serving the all-time ranking under a filtered heading.

**UI implication: hide the filter controls on the Points tab.** Unfiltered `metric=points`
works exactly as before.

---

## 4. Achievements (§6.8) — `sort` added

```
GET /users/{id}/achievements?sort=date|rank&page=1&per_page=15
```

- `sort=date` (**default**) — `assigned_at` descending.
- `sort=rank` — gold → silver → bronze → honorable → participation, then recency within a tier.
- Invalid value → `422`. Response shape and pagination unchanged; you render section headers.

⚠️ **The default changed.** This endpoint previously *always* returned rank-then-recency.
`sort=rank` reproduces the old behaviour. The profile's inline `top_achievements` is
untouched (still top-4 by rank).

---

## 5. §5.7 — prep-room join (no backend change needed)

`GET /debates/{id}/token?room=prop|opp` already issues tokens in `teams-selected`. Three
conditions, all of which must hold or you get `403`:

1. `prep_rooms_opened_at` is set **and** in the past — `teams-selected` alone is not enough;
2. `current_stage === 0` — prep rooms close when stage 1 starts, reopen on rollback-to-lobby;
3. caller is an **approved `debater` whose `side` matches** the requested room.

**Drive the Join button off `live-state.rooms.{prop|opp}.joinable_for_me`** — it already
accounts for all three plus the caller's side, and is true on exactly one of the two, so you
can route without computing side yourself. `rooms.{prop|opp}.name` is the room name.

Judges, trainers and viewers get `joinable_for_me: false` on both and `403` if they ask
anyway. **Trainers are deliberately excluded** from prep rooms — existing intentional
behaviour.

---

## 6. Push notifications (§7) — built, pending Firebase

### 6.1 Device registration

```
POST   /api/devices    { "token": "…", "platform": "android|ios", "locale": "ar|en" }
DELETE /api/devices    { "token": "…" }
```

Both return the standard envelope with `data: null`. Both idempotent:

- `POST` is an **upsert keyed on the token**. Re-registering never duplicates, and a token
  previously owned by another user is **re-assigned** — so pushes for user A can never land
  on a device now held by user B.
- `DELETE` returns `200` for an unknown token.
- `locale` defaults to `ar`; call `POST` again on language switch to update it.
- `platform` outside `android|ios` → `422`.

### 6.2 Payload contract

Every push carries localized `notification.title`/`body` plus a **data** block. All data
values are strings (FCM constraint). `type` is always present.

| # | `type` | Data keys | Deep link | Recipients |
|---|---|---|---|---|
| 1 | `debate_state_changed` | `debate_id` | debate details | all approved participants |
| 2 | `debate_accepted` | `debate_id` | debate details | selected participants only |
| 3 | `prep_reminder` | `debate_id` | debate details | **debaters only**, not judges |
| 4 | `motion_revealed` | `debate_id` | debate details | all participants **incl. judges** |
| 5 | `debate_created` | `debate_id` | debate details | all active users |
| 6 | `survey_created` | `survey_id` | survey | eligible users only (team-targeted → that team; else `target_roles`) |
| 7 | `team_join_result` | `team_id`, `result` (`accepted`\|`refused`) | team | the applicant |
| 8 | `blog_weekly_digest` | *(none)* | blog | all active users |

**#1 vs #2 de-duplication:** on the announce transition the selected set receives **#2
only**; #1 goes to the remaining participants. Nobody gets both for one event.

### 6.3 ⚠️ No FCM topics — deviation from my earlier proposal

I originally proposed an `all-users` topic for #5/#8. **I did not build that**, and you do
**not** need to subscribe/unsubscribe. Both go to stored device tokens instead, because a
topic delivers to every install that ever subscribed — including logged-out devices and
users who have since been deactivated — and there is no way to honour "active users only"
or to prune dead tokens through a topic. If push volume later makes this a problem we can
revisit, but it would then become a frontend contract item.

### 6.4 Localization

Copy is stored per type in `app/Notifications/PushType.php` with ar + en and `:placeholder`
substitution, and is chosen **per device** from the registered `locale` — a user with an
Arabic phone and an English tablet gets each in its own language. Unknown locale → English.

**The copy is my first draft and I expect you to correct it** — you have the better view of
tone and how the strings render. Placeholders in use: `:debate_title`, `:survey_title`,
`:team_name`, `:count`, `:result_ar`/`:result_en`. Send me replacements and I will drop them
in; the file is the single source of truth.

### 6.5 Scheduling

- **#3 prep reminder** — polled every minute alongside `debates:tick`, deriving the time
  from the debate's *current* data, so rescheduling needs no job cancellation. Idempotent
  via a new `debates.prep_reminder_sent_at` column. (A per-debate one-shot job would have
  needed a queue worker; this project runs `QUEUE_CONNECTION=sync`.)
- **#8 weekly digest** — `Saturday 18:00 Asia/Damascus`; skipped entirely if no article was
  published in the preceding 7 days.

### 6.6 ⛔ Still blocked: Firebase

Everything above is built and tested, but **nothing will actually send until the Firebase
project exists.** Without `FCM_PROJECT_ID` + `FCM_CREDENTIALS_PATH`, `PushService` is a
logged no-op — deliberately, so a missing credential can never break the debate or team
action that triggered the notification. Tokens FCM reports as `UNREGISTERED`/
`INVALID_ARGUMENT` are pruned automatically once live.

---

## 7. Attendance (§1.6) — deprecated, not deleted

The three endpoints return **`410 Gone`** for one release rather than 404, so an un-updated
client gets an unambiguous signal instead of something that looks like a broken deploy:

```
GET /debaters/{id}/stats/prep-attendance   → 410
GET /trainers/{id}/stats/attendance        → 410
GET /judges/{id}/stats/attendance          → 410
```

Controller and service are deleted; only the routes remain. Tell me when the new app is
fully rolled out and I will remove the routes too.

**Activity endpoints are untouched** and there is a test guarding that they were not removed
by association.

---

## 8. Migrations to run

```
2026_07_30_000001_drop_stats_visible_from_users_table
2026_07_30_000002_create_devices_table
2026_07_30_000003_add_prep_reminder_sent_at_to_debates_table
```

⚠️ The first is **destructive for users who had opted out** — their preference cannot be
recovered by rolling back (`down()` restores the column at its default). That is inherent to
the product decision.

New env keys (both optional; absent = push disabled):

```
FCM_PROJECT_ID=
FCM_CREDENTIALS_PATH=/absolute/path/to/service-account.json
```

---

## 9. Still open — what I need from you

1. **Firebase project** (Q6) — the only hard blocker left.
2. **Corrected ar/en copy** for the 8 types (§6.4).
3. **Confirm §0** is absorbed — especially `honoring` → `honorable` and `data[]` vs
   `data.items[]`.
4. **Note the two default changes**: achievements now default to date order, and the
   Points leaderboard tab must hide its filters.

One flag on **#5 `debate_created` → all users**: it fires on *every* debate creation. That is
the notification most likely to make users disable push entirely — which would also cost you
#2, #3 and #4, the genuinely useful ones. Consider restricting it to debates open for
registration. Your call; it is a one-line change on my side.
