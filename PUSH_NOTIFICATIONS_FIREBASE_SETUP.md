# Jadal — Push Notifications: Full Picture & Firebase Setup

**Audience:** whoever is setting up Firebase (console work + credential distribution).
**Status of the code:** the entire backend push pipeline is **already built, tested and
deployed to `stage`**. No backend code needs to be written. The only thing standing between
"built" and "users receive notifications" is a Firebase project and its credentials.

**What you need to produce, in one line:** a Firebase project, an Android app registration,
an iOS app registration with an APNs key uploaded, a service-account JSON for the server,
and two env vars set on the VPS.

---

## 1. How the system works (so you know what you're configuring)

```
Something happens in the app (debate announced, survey created, join request answered, …)
        ↓
DebateNotifier resolves WHO should be notified
        ↓
PushService looks up those users' registered devices (the `devices` table)
        ↓
For each device: picks Arabic or English copy based on that device's stored locale
        ↓
Mints a Google OAuth2 access token from the service-account JSON (cached ~55 min)
        ↓
POST https://fcm.googleapis.com/v1/projects/{FCM_PROJECT_ID}/messages:send
        ↓
FCM delivers → Android directly, iOS via APNs (Firebase forwards it for us)
```

Two important design facts:

1. **One pipeline for both platforms.** The backend only ever talks to FCM. FCM talks to
   APNs on our behalf for iOS. This is why the APNs key must be uploaded *into Firebase* —
   without it, iOS pushes silently fail while Android works fine, which is a confusing
   failure mode to debug.
2. **No FCM topics are used.** Every notification, including the two "all users" ones, is
   sent to individually stored device tokens. This means the mobile app does **not** need to
   subscribe or unsubscribe to anything — it just registers its token on login and removes
   it on logout.

### Safe-by-default behaviour

If `FCM_PROJECT_ID` or `FCM_CREDENTIALS_PATH` is missing, `PushService` logs
`"Push skipped — FCM is not configured"` and returns. It never throws. This is deliberate:
a missing credential must never break the debate/team/survey action that triggered the
notification. **So the site works fine today with no Firebase at all — notifications simply
don't arrive.**

---

## 2. What already exists on the backend

| Piece | Where | State |
|---|---|---|
| `devices` table (`user_id`, `token` unique, `platform`, `locale`) | migration `2026_07_30_000002` | Done |
| `POST /api/devices` / `DELETE /api/devices` | `DeviceController` | Done |
| FCM HTTP v1 client + OAuth2 token minting | `app/Services/Push/PushService.php` | Done |
| Recipient rules for all 8 types | `app/Services/Push/DebateNotifier.php` | Done |
| Arabic + English copy for all 8 types | `app/Notifications/PushType.php` | Done (draft copy) |
| All 8 triggers wired into real flows | controllers + `AdvanceDebatesLifecycle` | Done |
| Scheduled: prep reminder (every minute), weekly digest (Sat 18:00 Asia/Damascus) | `routes/console.php` | Done |
| Dead-token pruning on `UNREGISTERED` / `INVALID_ARGUMENT` | `PushService` | Done |
| **Firebase project + credentials** | — | **MISSING — this is your job** |

### The 8 notification types

| # | `type` | Data payload | Recipients |
|---|---|---|---|
| 1 | `debate_state_changed` | `debate_id` | all approved participants |
| 2 | `debate_accepted` | `debate_id` | selected participants only |
| 3 | `prep_reminder` | `debate_id` | debaters only (not judges) |
| 4 | `motion_revealed` | `debate_id` | all participants incl. judges |
| 5 | `debate_created` | `debate_id` | all active users |
| 6 | `survey_created` | `survey_id` | eligible users only |
| 7 | `team_join_result` | `team_id`, `result` | the applicant |
| 8 | `blog_weekly_digest` | *(none)* | all active users |

All data values are sent as **strings** (an FCM requirement). Every payload also carries
`type`, which the app uses for deep-link routing.

---

## 3. Ownership decision — settle this before creating anything

**Recommendation: the client/business owns the Firebase project**, with the backend and
frontend developers added as members.

Why it matters: the APNs authentication key comes from the client's Apple Developer account,
and the Firebase project ends up tied to the published app identity. If a contractor's
personal Google account owns it, transferring later is genuinely painful and sometimes
requires recreating the project (which invalidates every registered device token).

Minimum roles needed:
- **Owner** — the client's Google account
- **Editor** — backend dev (needs to generate the service-account key)
- **Editor** — frontend/mobile dev (needs the app config files)

---

## 4. Step-by-step Firebase setup

### 4.1 Create the project

1. Go to <https://console.firebase.google.com> and sign in as the owning account.
2. **Add project** → name it (e.g. `jadal-platform`). Google Analytics is **not required**
   for push; enabling it is harmless but adds consent/config overhead. Recommend **off**
   unless the client wants analytics.
3. Once created, open **Project settings** (gear icon, top-left) and note the
   **Project ID** — this is *not* the display name. It looks like `jadal-platform` or
   `jadal-platform-4f2a1`. **This exact string is `FCM_PROJECT_ID`.**

### 4.2 Register the Android app → `google-services.json`

1. Project settings → **Your apps** → **Add app** → Android.
2. **Android package name** must match the app's `applicationId` exactly (from the Flutter
   project's `android/app/build.gradle`). Ask the mobile dev — guessing here causes silent
   delivery failure.
3. Download **`google-services.json`**.
4. → **Give this file to the frontend/mobile developer.** The backend does not use it.

### 4.3 Register the iOS app → `GoogleService-Info.plist`

1. Project settings → **Your apps** → **Add app** → iOS.
2. **iOS bundle ID** must match the app's bundle identifier exactly (from Xcode / the
   Flutter `ios/Runner.xcodeproj`). Again — ask, don't guess.
3. Download **`GoogleService-Info.plist`**.
4. → **Give this file to the frontend/mobile developer.**

### 4.4 ⚠️ Upload the APNs key — the step that is most often missed

Without this, **Android push works and iOS push silently does nothing.** Budget time for it:
it needs Apple Developer Program access, which the developers may not have.

1. Go to <https://developer.apple.com/account> → **Certificates, Identifiers & Profiles** →
   **Keys** → **+**.
2. Name it (e.g. `Jadal FCM`), tick **Apple Push Notifications service (APNs)**, Continue,
   Register.
3. **Download the `.p8` file.** Apple lets you download it **exactly once** — losing it means
   revoking and creating a new key. Store it in the client's password manager.
4. Note the **Key ID** (shown on that page) and the **Team ID** (top-right of the Apple
   Developer account, or under Membership).
5. Back in Firebase: Project settings → **Cloud Messaging** tab → **Apple app configuration**
   → **APNs Authentication Key** → **Upload**, and provide the `.p8`, the Key ID, and the
   Team ID.

An APNs *key* (`.p8`) is preferred over an APNs *certificate* — keys don't expire, work for
both sandbox and production, and cover all apps on the team. If you're offered the
certificate route, don't take it.

### 4.5 Generate the service-account key → backend credential

1. Project settings → **Service accounts** tab.
2. **Generate new private key** → confirm → a JSON file downloads.
3. → **Give this file to the backend developer**, over a secure channel.

⚠️ **This JSON is a full server credential.** Anyone holding it can send push notifications
as your app. It must never be committed to git, pasted into a chat log, or placed anywhere
web-servable. If it leaks, revoke it in the same Service accounts screen and generate a new
one.

Note the Firebase Cloud Messaging API (V1) is enabled automatically for new projects. If
sending later returns `403 SERVICE_DISABLED`, enable "Firebase Cloud Messaging API" in the
Google Cloud console for that same project.

---

## 5. Server configuration (backend dev, after receiving the JSON)

Place the file outside the web root and outside git:

```bash
# on the VPS
sudo mv service-account.json /var/www/jadal-backend/storage/app/fcm-service-account.json
sudo chown www-data:www-data /var/www/jadal-backend/storage/app/fcm-service-account.json
sudo chmod 600 /var/www/jadal-backend/storage/app/fcm-service-account.json
```

`storage/app/` is already git-ignored and is not web-servable, so this is a safe location.

Add to `.env`:

```
FCM_PROJECT_ID=your-exact-project-id
FCM_CREDENTIALS_PATH=/var/www/jadal-backend/storage/app/fcm-service-account.json
```

Then rebuild config — **required**, because config is cached in production:

```bash
cd /var/www/jadal-backend
php artisan config:clear && php artisan config:cache
sudo systemctl reload php8.3-fpm
```

No deploy or code change is needed. The pipeline activates the moment these two values
resolve.

---

## 6. Verifying it actually works

### 6.1 Confirm the backend now considers itself configured

```bash
php artisan tinker --execute="var_dump(app(App\Services\Push\PushService::class)->isConfigured());"
```

Expect `true`. If `false`, the env vars aren't reaching the app — re-check `config:cache`.

### 6.2 Confirm the credential is valid (mints a real Google token)

```bash
php artisan tinker --execute="
\$m = new ReflectionMethod(App\Services\Push\PushService::class, 'accessToken');
\$m->setAccessible(true);
\$t = \$m->invoke(app(App\Services\Push\PushService::class));
echo \$t ? 'TOKEN OK (' . strlen(\$t) . ' chars)' : 'FAILED — check laravel.log';
"
```

`TOKEN OK` proves the JSON is well-formed, the clock is sane, and Google accepted the
service account. Failures are logged with a specific reason (`credentials file not found`,
`malformed`, `token exchange failed`).

### 6.3 End-to-end, with a real device

1. Mobile dev logs into the app on a physical device (push does not work on the iOS
   Simulator — a real device is required for APNs).
2. Confirm the token registered:
   ```bash
   php artisan tinker --execute="print_r(DB::table('devices')->latest()->limit(3)->get()->toArray());"
   ```
3. Send a real notification to that user:
   ```bash
   php artisan tinker --execute="
   app(App\Services\Push\PushService::class)->sendToUsers(
       [USER_ID_HERE],
       App\Notifications\PushType::DEBATE_CREATED,
       ['debate_id' => 1],
       ['debate_title' => 'Test debate']
   );
   "
   ```
4. Watch for failures: `tail -f storage/logs/laravel.log | grep -i push`

Test **both** an Android and an iOS device. Android succeeding tells you nothing about
whether the APNs key was uploaded correctly.

---

## 7. Troubleshooting

| Symptom | Cause |
|---|---|
| Log says `Push skipped — FCM is not configured` | Env vars missing or `config:cache` not rebuilt |
| `FCM credentials file not found` | Wrong path, or not readable by `www-data` |
| `FCM token exchange failed` | Malformed/revoked JSON, or severe server clock skew (JWTs are time-sensitive — check `timedatectl`) |
| Android works, **iOS silent** | APNs key not uploaded to Firebase, or wrong Key ID / Team ID — §4.4 |
| `404` / token pruned immediately | App registered under a different Firebase project than the server is sending to, or a stale token — re-register on device |
| `403 SERVICE_DISABLED` | Enable "Firebase Cloud Messaging API" in Google Cloud console for this project |
| Nothing arrives, no errors logged | User has no row in `devices` — the app never registered, or logged out |

---

## 8. What each person needs when this is done

| Person | Artifact | Source |
|---|---|---|
| **Backend dev** | `service-account.json` + the Project ID | §4.5, §4.1 |
| **Mobile dev** | `google-services.json` | §4.2 |
| **Mobile dev** | `GoogleService-Info.plist` | §4.3 |
| **Client password manager** | the APNs `.p8`, its Key ID, the Team ID | §4.4 — the `.p8` is unrecoverable if lost |

The mobile app must also request notification permission on iOS (and Android 13+), register
its FCM token via `POST /api/devices` on login and on token rotation, call
`DELETE /api/devices` on logout, and re-`POST` when the user switches app language so the
stored `locale` stays current. That is already specified for the frontend in
`BACKEND_RESPONSE.md` §6.

---

## 9. Notes / open items unrelated to setup

- **Notification copy** is a backend first draft in `app/Notifications/PushType.php`
  (Arabic + English, with `:debate_title`-style placeholders). The frontend team is expected
  to send corrected wording; it's a text-only change.
- **`debate_created` currently notifies every active user on every debate creation.** This is
  the notification most likely to make users disable push altogether — which would also cost
  the genuinely useful ones (accepted into debate, prep reminder, motion revealed). Worth a
  product decision on whether to narrow it. One-line change.
- **Token hygiene is automatic** once live: FCM reporting `UNREGISTERED` or
  `INVALID_ARGUMENT` causes the row to be deleted, so the `devices` table self-cleans.
