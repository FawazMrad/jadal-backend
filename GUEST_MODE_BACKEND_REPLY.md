# Backend Reply — Guest Mode Round

**From:** Backend
**To:** Frontend
**Date:** 2026-08-14
**Re:** `GUEST_MODE_BACKEND_SPEC.md` (share-a-debate-by-link)
**Branch:** `feature/guest-mode` → `stage`

**All six items are done.** Suite green: **454 passed / 1507 assertions** (was 426 — 28 new
tests in `tests/Feature/GuestModeTest.php`). **One migration to run on deploy** (§10).

Base URL `https://jadal-platform.com/api`, standard `{success, message, data}` envelope.

---

## 0. Status at a glance

| # | Area | Status | What you most need to know |
|---|---|---|---|
| 1 | Shareable link + `.well-known` | ✅ Link done / ⏳ files are devops | `share_url` is on `live-state.debate`. **No `share_token`** — see §2 |
| 2 | Guest authorization model | ✅ Done — **Option A (public read)** | No credential to pass. But links **do expire** — see §4 |
| 3 | `live-state` guest projection | ✅ Done | Same keys/types. PII nulled. `share_url` **absent**, not null |
| 4 | `token?room=main` guest token | ✅ Done | subscribe-only **and genuinely invisible** — see §7 |
| 5 | New wire role `guest` | ✅ Done | Never returned for an authenticated caller (test-enforced) |
| 6 | Team roster for any auth user | ✅ Done | Roster now visible; **contact details withheld** from non-members |

**Four things to read even if you skim:**
1. **§7 — guests are genuinely invisible.** Your spec assumed "presence is inherently visible in
   LiveKit, so anonymous Guest is the honest option". That turned out to be **wrong in your
   favour**: LiveKit has a real `hidden` permission and we used it. **Do not build the
   anonymous-Guest row.**
2. **§4 — links expire.** Not a token expiry: a *state* window. Guest access dies 10 minutes
   after the debate ends. You need the `410` copy.
3. **§2 — there is no `share_token`.** Drop it from your parser; it appears nowhere.
4. **§6 — a guest sees the result once revealed**, exactly like a viewer.

---

## 1. Per-item status

### 1.1 Shareable link — ✅ done (link), ⏳ devops (`.well-known`)

`share_url` is on `data.debate` of `live-state`, built fully server-side, **stable per debate**
(a pure function of the id — nothing rotates, nothing is stored):

```
https://jadal-platform.com/d/42
```

Config-driven via a dedicated key (`config('app.frontend_share_base_url')`, env
`FRONTEND_SHARE_BASE_URL`), falling back to `APP_URL`. The public web domain can therefore change
without touching code, and independently of wherever the API is served.

Returned to **every authenticated role** (viewer, debater, judge, trainer, chair, admin).
**Omitted entirely for a guest** — see §3.2.

`.well-known` files: **not in this PR** — see §9.

### 1.2 Guest authorization — ✅ Option A (public read)

Decided by the project owner. `live-state` and `token?room=main` are readable **with no
credential at all**, subject only to the access window in §4. There is no share token to
generate, pass, store, or revoke.

**Nothing to attach to your requests.** Just omit the `Authorization` header — which your HTTP
layer already does when there's no stored token.

### 1.3 `live-state` guest projection — ✅ done

Same endpoint, same shape, same keys and types — your `LiveStateModel` parser is unchanged. See
§3 for the full real JSON.

### 1.4 `token?room=main` guest token — ✅ done

Same endpoint, same `RoomTokenModel` shape. See §5.

### 1.5 New wire role `guest` — ✅ done

`guest` is a possible value of `rooms.main.role_if_joined` (live-state) and `role_in_room`
(token). It is returned **only** on the tokenless path. A dedicated test asserts that chair,
debater, admin, viewer and trainer never receive `guest` on any room, on either endpoint.

### 1.6 Team roster for any authenticated user — ✅ done, with one narrowing

`GET /teams/{id}` no longer 403s for non-members. Any authenticated caller gets the roster,
**response shape byte-identical**. Inactive teams included (read-only) as you preferred.

**The narrowing:** for a caller who is not the coach, the leader, or a current member, the
`email` / `phone` / `birth_date` / `age` / `location` / `email_verified_at` fields of every
embedded user come back `null`. Keys and types are unchanged — only values are withheld. Your
§6 explicitly allowed keeping contact details restricted, and widening a roster to the whole
userbase should not also broadcast everyone's phone number. `name` and `avatar_url` — the two
things the screen actually renders — are always present.

Members/leaders/coaches see exactly what they saw before. A test asserts both views have
identical key sets.

⚠️ **This changed two existing tests** (`TeamShowTest`): past members and unrelated users used
to assert `403` and now assert `200` + a visible roster. That was the point of the change, but
flagging it since it is a deliberate contract break.

---

## 2. Decisions §7 — answered by number

| # | Question | Answer |
|---|---|---|
| **1** | Auth model | **(A) Public read.** No share token. Nothing to pass — just send no `Authorization` header. |
| **2** | Link format | `https://jadal-platform.com/d/{id}` — path prefix **`/d/`**, exactly your preference. On `live-state.debate.share_url`. Stable per debate, forever. |
| **3** | `.well-known` files | **Not in this PR.** Separate devops task, owner will do it on the VPS. See §9 for exactly what to send. |
| **4** | Expiry / revocation | The **link** never expires and is never revoked. **Guest access does**: readable while the debate is `live`, plus **10 minutes** after it reaches `completed` or `cancelled`. After that both guest endpoints return **`410 Gone`**. Before the debate goes live, guests get `410` too. See §4. |
| **5** | Guest identity | `guest-{uuid}` — e.g. `guest-792cd504-7277-40a0-8a7e-1fbb36804b2b`. A **fresh UUID per token request**, never reused. **No `metadata`** attached. Non-numeric by construction, so it can never collide with a numeric user id. |
| **6** | Guest + result | **Yes** — after `result_revealed_at`, a guest sees the same public result summary a viewer sees. `null` before reveal, same rule as any non-judge. The result's `judge` object is PII-stripped. |
| **7** | Guest visibility | **Not listed at all — real invisibility.** LiveKit's `hidden` permission. **Do not build the anonymous-Guest row.** Full detail in §7. |
| **8** | PII stripping | **Confirmed.** `email`, `phone`, `points` are `null` on **every** user object — judges, members, speakers, and the result judge. We also null `birth_date`, `age`, `location`, `email_verified_at`. |
| **9** | Rooms locked | **Confirmed.** `prop` / `opp` / `result` → **`403`** for a guest, always, regardless of debate state. Guests only ever get a `main` token. |
| **10** | Viewer cap | **No cap.** Unlimited simultaneous guests per room. No capacity or queueing logic exists — you never need a "room is full" state. |

---

## 3. `GET /debates/{id}/live-state` — the guest payload

Real captured output, not hand-written.

### 3.1 What changes vs. an authenticated viewer

Exactly three things:

1. `data.debate.share_url` — **key absent entirely**
2. every user object — `email`/`phone`/`points` (+ `birth_date`/`age`/`location`/`email_verified_at`) are `null`
3. `data.rooms` — `main.role_if_joined: "guest"`; `prop`/`opp`/`result` have `joinable_for_me: false` and `role_if_joined: null`

Everything else — `format`, `motion`, `stages`, `speaking_order`, `server_now`, all timer fields,
`current_stage`, `current_stage_started_at` — is identical to a viewer's. Your timer works
unchanged for guests.

### 3.2 Populated example (live, motion revealed, result not yet revealed)

Trimmed to one speaker/member per side for readability; the real payload lists all three.

```jsonc
{
  "success": true,
  "message": "Live state retrieved.",
  "data": {
    "debate": {
      "id": 42,
      "title": "مناظرة فريق البيان ضد فريق الحجة",
      "tag": "بطولة",
      "status": "live",
      "scheduled_at": "2026-08-14T17:00:00+00:00",
      "started_at": "2026-08-14T17:00:00+00:00",
      "ended_at": null,
      "motion_revealed_at": "2026-08-13T17:00:00+00:00",
      "prep_rooms_opened_at": "2026-08-14T16:00:00+00:00",
      "result_revealed_at": null,
      "cancellation_reason": null,
      "current_stage": 2,
      "current_stage_started_at": "2026-08-14T17:12:00+00:00",
      "speeches_completed_at": null,
      "live_started_at": "2026-08-14T17:02:00+00:00",
      "server_now": "2026-08-14T18:12:23+00:00",
      "timer_is_paused": false,
      "timer_paused_elapsed_seconds": 0
      // NOTE: no "share_url" key at all. No "share_token" anywhere, ever.
    },
    "format": {
      "id": 1,
      "name": "بريطاني ثلاثي — بدون رد",
      "description": "ثلاثة متحدثين لكل فريق، ست مراحل.",
      "phase_config": {
        "speech_time_seconds": 420, "has_reply_speech": false, "reply_time_seconds": null,
        "motion_reveal_offset_hours": 24, "prep_rooms_open_offset_hours": 1
      },
      "speech_time_seconds": 420,
      "has_reply_speech": false,
      "reply_time_seconds": null,
      "motion_reveal_offset_hours": 24,
      "prep_rooms_open_offset_hours": 1,
      "speakers_per_side": 3,
      "total_stages": 6
    },
    "motion": {
      "id": 1,
      "text": "هذا المجلس يرى أن التعليم عن بعد يجب أن يكون خياراً دائماً في الجامعات",
      "tags": ["Policy"],
      "frameworks": [{ "id": 1, "name": "Policy", "color_hex": "#3E7BFA" }]
    },
    "rooms": {
      "main":   { "name": "debate-42-main",   "open": true,  "joinable_for_me": true,  "role_if_joined": "guest" },
      "prop":   { "name": "debate-42-prop",   "open": false, "joinable_for_me": false, "role_if_joined": null },
      "opp":    { "name": "debate-42-opp",    "open": false, "joinable_for_me": false, "role_if_joined": null },
      "result": { "name": "debate-42-result", "open": false, "joinable_for_me": false, "role_if_joined": null }
    },
    "judges": [
      {
        "id": 7,
        "user": {
          "id": 13, "name": "سارة الأنصاري", "role": "judge", "avatar_url": null,
          "points": null,
          "created_at": "2026-08-14T18:12:23+00:00"
        },
        "judge_order": 1, "is_chair": true, "is_attended": true
      }
    ],
    "proposition": {
      "team": {
        "id": 1, "name": "فريق البيان", "status": "active", "is_random": 0,
        "members_count": 3,
        "members": [ { "id": 1, "user_id": 5, "priority": 1, "status": "current",
                       "joined_at": "2026-08-14T18:12:23+00:00" } ],
        "current_members": null,
        "created_at": "2026-08-14T18:12:23+00:00",
        "updated_at": "2026-08-14T18:12:23+00:00"
      },
      "is_random": false,
      "members": [
        { "id": 5, "name": "لينا الخطيب", "role": "debater", "avatar_url": null,
          "points": null, "created_at": "2026-08-14T18:12:23+00:00" }
      ],
      "speakers": [
        {
          "id": 1,
          "user": {
            "id": 5, "name": "لينا الخطيب",
            "email": null, "phone": null, "points": null,
            "role": "debater", "status": "active", "avatar_url": null,
            "birth_date": null, "age": null, "location": null,
            "lang": null, "theme": null, "email_verified_at": null,
            "created_at": "2026-08-14T18:12:23+00:00"
          },
          "team_id": 1, "team_name": "فريق البيان",
          "role": "debater", "side": "proposition", "status": "approved",
          "is_chair": false, "is_attended": true,
          "speaking_phase_order": 1, "is_reply_speaker": false
        }
      ],
      "speaking_order": [
        { "phase_order": 1, "user_id": 5,  "participant_id": 1 },
        { "phase_order": 2, "user_id": 6,  "participant_id": 2 },
        { "phase_order": 3, "user_id": 7,  "participant_id": 3 }
      ]
    },
    "opposition": { /* identical shape */ },
    "stages": [
      {
        "id": 1, "order_index": 1, "name": "Proposition 1", "role": null, "is_reply": false,
        "duration_seconds": 420, "status": "completed",
        "participant_id": 1, "speaker_user_id": 5,
        "started_at": "2026-08-14T17:11:00+00:00", "ended_at": "2026-08-14T17:12:00+00:00",
        "poi_raised_count": 2, "poi_answered_count": 1,
        "audio_url": null, "speech_text": null
      }
      /* … 5 more … */
    ],
    "result": null
  }
}
```

### 3.3 Edge case — motion not yet revealed

Byte-identical to the above except:

```jsonc
"debate": { "motion_revealed_at": null, /* … */ },
"motion": null
```

Everything else (rooms, judges, teams, speakers, stages, timers) is unchanged and still
populated. Same rule as every other role — guests are not special-cased here.

### 3.4 After `result_revealed_at` (§Q6)

`data.result` becomes:

```jsonc
"result": {
  "id": 1,
  "debate_id": 42,
  "judge": {
    "id": 13, "name": "سارة الأنصاري",
    "email": null, "phone": null, "points": null,
    "role": "judge", "status": "active", "avatar_url": null,
    "birth_date": null, "age": null, "location": null,
    "lang": null, "theme": null, "email_verified_at": null,
    "created_at": "2026-08-14T18:12:23+00:00"
  },
  "contributing_judges": [ { "user_id": 13, "judge_order": 1, "is_chair": true } ],
  "winning_side": "proposition",
  "scores": {
    "stages": [ { "stage_order": 1, "participant_id": 1, "user_id": 1, "score": 78 } ],
    "notes": "قرار الهيئة بعد المداولة."
  },
  "summary_notes": "قرار الهيئة بعد المداولة.",
  "submitted_at": "2026-08-14T18:30:00+00:00"
}
```

⚠️ Note the window: a revealed result is typically revealed *at* close-room, which is the same
moment the debate becomes `completed` — so in practice a guest has **10 minutes** to see it
before the window shuts. If you want guests to linger on a result screen, tell us and we'll
reconsider the rule for `completed`.

---

## 4. The access window (§Q4) — the one behavioural surprise

Guest access is gated on debate **state**, not on a token:

| Debate status | Guest `live-state` / `token?room=main` |
|---|---|
| `scheduled`, `announced`, `teams-selected` | **`410 Gone`** — no room to watch yet |
| `live` | **`200`** |
| `completed` or `cancelled`, ≤ 10 min | **`200`** |
| `completed` or `cancelled`, > 10 min | **`410 Gone`** |

**This gates guests only.** A logged-in user gets their real role from the normal token flow at
every point in the lifecycle, including years later. Test-enforced in both directions.

`cancelled` was **not** in your spec — the owner extrapolated the same 10-minute rule to it as
the other terminal status. Flagging it as an inferred extension in case you want it revisited.

**UI implication:** treat `410` as "this link is no longer valid" and show the message verbatim.
It is not an auth failure — do **not** prompt for login on a `410`, since logging in genuinely
would give access, but only to someone who already had it.

---

## 5. `GET /debates/{id}/token?room=main` — the guest token

Response shape unchanged (`RoomTokenModel` as-is):

```jsonc
{
  "success": true,
  "message": "تم إنشاء رمز الوصول. | Access token generated.",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJndWVzdC03OTJjZDUwNC03Mjc3LTQwYTAtOGE3ZS0xZmJiMzY4MDRiMmIiLCJqdGkiOiJndWVzdC03OTJjZDUwNC03Mjc3LTQwYTAtOGE3ZS0xZmJiMzY4MDRiMmIiLCJleHAiOjE3ODY3MzgzNDMsIm5iZiI6MTc4NjczMTE0MywiaWF0IjoxNzg2NzMxMTQzLCJpc3MiOiJBUElhYmMxMjMiLCJ2aWRlbyI6eyJyb29tSm9pbiI6dHJ1ZSwicm9vbSI6ImRlYmF0ZS00Mi1tYWluIiwiY2FuUHVibGlzaCI6ZmFsc2UsImNhblN1YnNjcmliZSI6dHJ1ZSwiY2FuUHVibGlzaERhdGEiOmZhbHNlLCJoaWRkZW4iOnRydWV9fQ.HwRx0MFQj_vc--yuBT-OoHVemzB1F9C2tGpwznm2xZ8",
    "url": "wss://livekit.jadal-platform.com",
    "room_name": "debate-42-main",
    "role_in_room": "guest"
  }
}
```

Decoded `video` grant (the bytes LiveKit actually reads):

```jsonc
{
  "roomJoin": true,
  "room": "debate-42-main",
  "canPublish": false,       // no mic, no camera — ever
  "canSubscribe": true,
  "canPublishData": false,   // no POI / timer / chat data events
  "hidden": true             // §Q7 — invisible to other participants
}
```

Identity is the `sub` claim: `guest-792cd504-7277-40a0-8a7e-1fbb36804b2b`. **No `name` claim** —
there is no display label to render, and none is needed since they are hidden.

TTL is 2 hours (unchanged from every other token). Call the endpoint again for a fresh one; each
call mints a **new** UUID identity, so two tabs are two distinct participants.

---

## 6. `GET /teams/{id}` — the roster change

Unchanged shape. For a caller with no stake in the team:

```jsonc
{
  "id": 3, "name": "Team Alpha", "status": "active", "is_random": false,
  "leader":     { "id": 4, "name": "Lina",       "avatar_url": null, "email": null, "phone": null, /* … */ },
  "created_by": { "id": 9, "name": "Coach Omar", "avatar_url": null, "email": null, "phone": null, /* … */ },
  "members_count": 3,
  "members": [
    { "id": 1, "user_id": 4, "priority": 1, "status": "current",
      "joined_at": "…",
      "user": { "id": 4, "name": "Lina", "avatar_url": null, "email": null, "phone": null, /* … */ } }
  ],
  "current_members": [ /* same shape */ ],
  "created_at": "…", "updated_at": "…"
}
```

Coach / leader / current members continue to receive the fully-populated version, unchanged.

---

## 7. ⚠️ Guest visibility (§Q7) — your assumption was wrong, in your favour

Your spec said: *"Since presence is inherently visible in LiveKit, 'anonymous Guest' is the
honest option."*

**That is not true for this setup, and we did better.** LiveKit has a first-class
`hidden` participant permission — the same mechanism it uses for recorder/egress participants:

- `VideoGrant::setHidden()` — `agence104/livekit-server-sdk` (installed, `^1.3`)
- protobuf `ParticipantPermission.hidden`, field 7, documented *"indicates that it's hidden to others"*

We verified it survives into the signed JWT (`"hidden": true` in the decoded grant above — the
SDK's serializer strips only nulls, so `false` flags and `hidden` both make it through).

**What this means for you:**

- A guest **does not appear** in any other participant's participant list.
- No participant-connected event fires for them on other clients.
- **Do not build the anonymous "Guest" row.** Do not build a client-side `guest-` prefix filter
  either — there is nothing to filter, and a filter would silently mask a regression if the flag
  ever stopped being applied.
- Your "who's in this debate" list needs **no guest handling at all**.

**Two honest caveats:**

1. We can verify the *SDK* from source, but not the *deployed LiveKit server version* from here.
   `hidden` has been supported by LiveKit server for a long time and is standard, but it should
   be confirmed once against staging — join a room as a guest and check the roster from a second
   client. If the deployed server were old enough to ignore the flag, guests would become visible
   as an unnamed participant with a `guest-…` identity. **Please include this in your first
   integration pass.**
2. Invisibility is about the *participant list*. Anyone with LiveKit server credentials can still
   enumerate room state server-side. This hides guests from other **users**, which is what was
   asked; it is not an anonymity guarantee against the platform operator.

---

## 8. Error codes — every guest failure path

| Situation | Status | `message` |
|---|---|---|
| Guest, debate outside the access window (never live, or >10 min past terminal) | **410** | `لم يعد هذا النقاش متاحًا للضيوف. \| This debate is no longer available to guests.` |
| Guest requests `room=prop`, `opp` or `result` | **403** | `هذه الغرفة غير متاحة للضيوف. \| This room is not available to guests.` |
| Guest requests `room=main` while in-window but the main room isn't open | **403** | `غير مصرح لك بالانضمام إلى هذه الغرفة. \| You are not authorised to join this room.` |
| Invalid `room` value | **422** | `Invalid room. Must be one of: main, prop, opp, result.` |
| LiveKit unreachable | **503** | `Failed to provision LiveKit room: …` |
| **Expired/invalid** bearer token sent | **401** | `انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى. \| Session expired. Please sign in again.` |
| Room full | — | **Does not exist.** No cap (§Q10). |

All use the standard `{success: false, message, errors: []}` envelope.

**On the 403 for a not-yet-open main room:** that is deliberately the *same* denial any
non-joinable authenticated caller gets — a guest is not given a bespoke error there (your PART C
asked for exactly this). Note the ordering: the room-lock `403` is checked **before** the window
`410`, so asking for a prep room always tells you the honest reason.

**On the `401`:** if you send an `Authorization` header at all, it must be valid. We do **not**
silently downgrade a stale token to a guest session — that would make an expired login look like
a broken app. Omit the header entirely for guest mode.

---

## 9. `.well-known` files — separate devops task

**Not in this PR**, by the project owner's decision. They will place both files on the VPS
directly once you send the real values. Nothing was generated with placeholder data.

**Send these in one go:**

| For | We need from you |
|---|---|
| Android `assetlinks.json` | `sha256_cert_fingerprints` — **both** release and debug. Package name confirmed as `com.jadalplatform.app` |
| iOS `apple-app-site-association` | Your **Team ID**, to build `appID` = `TEAMID.com.jadalplatform.app` |

**Path prefix to register on your side: `/d/*`** (e.g. `https://jadal-platform.com/d/42`). That
is what the AASA `paths` will scope to and what the Android intent-filter should match.

**The URL already resolves** (your §1.3 concern): `GET /d/{id}` returns a **200** minimal
bilingual HTML page saying "open this link on a device with the app installed", plus the debate
number. It is deliberately *not* a landing page and deliberately does **not** look the debate up —
so it leaks nothing to an unauthenticated visitor and is not an id-enumeration oracle (every id,
existing or not, renders the same page). Replace with something nicer whenever product wants.

---

## 10. Deploy notes

**One migration:**

```
php artisan migrate     # 2026_08_14_000001_add_finalized_at_to_debates_table
```

Adds a nullable `debates.finalized_at`. Additive, no backfill, no downtime.

**Why it was needed** (this is the one place we deviated from the brief): the plan was to compute
the 10-minute window from `debates.ended_at`. That column is unusable for this, twice over:

- it is **never set on any `cancelled` path** (all four of them write only `status` + `cancellation_reason`);
- on the `completed` path it is stamped by `next-stage` the instant the **speeches** finish, while
  the debate is still `live` — so it marks the *start of the result phase*, often long before the
  debate actually completes. Using it would have cut guests off mid-deliberation.

`finalized_at` is now stamped at all six terminal transitions (close-room ×2, close-main,
and the three auto-cancel paths in the lifecycle command). Legacy rows fall back to `updated_at`,
which for any pre-existing terminal debate resolves to "closed" — correct by construction.

**Optional env** (defaults to `APP_URL` if unset):

```
FRONTEND_SHARE_BASE_URL=https://jadal-platform.com
```

---

## 11. Our own flagged concerns

1. **§7 invisibility must be smoke-tested against the real LiveKit server.** We could verify the
   SDK and the JWT bytes from source; the deployed server's honouring of `hidden` is the one link
   in the chain we cannot confirm from code (and `LIVEKIT_*` is unset in local env, so it could
   not be exercised end-to-end here). This is the single highest-value thing to check on your
   first integration pass.

2. **`cancelled` sharing the 10-minute rule is an inferred extension**, not something the spec
   asked for. Easy to change.

3. **The result window is tight.** Reveal usually coincides with completion, so §Q6's "guests see
   the revealed result" holds for only about 10 minutes in practice. If the product intent is "a
   guest can open a link and read the outcome later", the rule needs revisiting — say the word.

4. **Public read means enumerable.** Option A was chosen deliberately, but it does mean anyone can
   walk `/api/debates/{1..N}/live-state` and read the roster + motion of any **currently live**
   debate without an account. The window limits the blast radius to live debates only, and PII is
   stripped, but team names, member names, avatars and the motion are public for that period. If
   that is not acceptable, the signed-token model (your original recommendation (B)) is the fix
   and is a contained change — the projection and window logic would be untouched.

5. **Pre-existing bug, unrelated to this round, worth a ticket:** unauthenticated requests to
   *any* guarded route return **`500`** with `{"message": "Unauthenticated."}` instead of `401`.
   `AuthenticationException` has no `getStatusCode()`, so the API exception handler falls through
   to 500. We did **not** change it here — it affects every route in the API and belongs in its
   own PR. Note the inconsistency this creates: our new guest-path 401 (invalid token) is a
   correct `401`, while every other route's is a `500`.

6. **Cosmetic, pre-existing:** `team.is_random` serialises as `0`/`1` inside the nested
   `TeamResource`, while the sibling `proposition.is_random` is a proper boolean. Harmless if you
   parse loosely; flagging in case your parser is strict.
