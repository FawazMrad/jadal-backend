# Backend Reply — Guest share links do not open the app

**From:** Backend
**To:** Frontend / DevOps
**Date:** 2026-08-16
**Re:** `Failed_guest_link_issue.md` — the `.well-known` item deferred in `GUEST_MODE_BACKEND_REPLY.md` §9
**Branch:** `feature/app-links-wellknown` → `stage`

**Your diagnosis is correct and complete.** Missing `assetlinks.json` → verification fails →
browser opens → dead-end page. Nothing in the app needed to change, and nothing did.

**Two of the three things you asked for are done in code. The third is a deploy, and I cannot
do it from here — read §1 before anything else.**

Suite green: **461 passed / 1530 assertions** (was 454 — 7 new tests in `tests/Feature/AppLinksTest.php`).

---

## 0. Status at a glance

| # | Item | Status |
|---|---|---|
| §4.1 | `assetlinks.json` with your fingerprint | ✅ **Written and committed** — ⏳ **not yet deployed** (§1) |
| §4.2 | `apple-app-site-association` | ⛔ **Blocked on your Team ID** — nothing written, by design (§4) |
| §5 | "Open in the Jadal app" intent button | ✅ **Done** |
| §5 | Store link | ✅ Done, but **hidden until a store URL is configured** (§3.2) |
| §6 | Your five questions | ✅ Answered in §6 — **including one I cannot answer honestly** |

---

## 1. ⚠️ Read this first — the file is committed, not published

I can write files into the repository. I **cannot** deploy to the VPS, and I have not.

**Right now `https://jadal-platform.com/.well-known/assetlinks.json` still returns 404.** It will
keep returning 404 until this branch is merged and deployed. Please do not re-test until the
deploy lands — you would just reproduce the same failure and we would both lose a cycle.

Concretely, what exists now:

```
public/.well-known/assetlinks.json     ← committed on feature/app-links-wellknown
```

**Owner action required:**

1. Merge `feature/app-links-wellknown` → `stage`, deploy to the VPS.
2. Confirm the deploy actually copied the dot-directory (see §2.2 — this is the step most likely
   to fail silently).
3. Run the `curl -i` from §6.1 and paste the output back to frontend, with the timestamp.

This is also why §6.1 is the one question I have answered with "cannot confirm" rather than a
pasted response. I am not going to invent a `curl` output for a file that is not live yet.

---

## 2. What was published — Android (§4.1)

### 2.1 The file

Byte-for-byte what you specified, at `public/.well-known/assetlinks.json`:

```json
[
  {
    "relation": ["delegate_permission/common.handle_all_urls"],
    "target": {
      "namespace": "android_app",
      "package_name": "com.jadalplatform.app",
      "sha256_cert_fingerprints": [
        "14:AE:C4:4B:AA:B3:41:2D:7C:8F:51:6E:BE:52:EC:64:D9:3F:4A:2B:8A:1E:8D:73:50:4D:41:11:87:E5:9E:80"
      ]
    }
  }
]
```

I verified the fingerprint is a well-formed SHA-256 before committing it: 32 colon-separated
uppercase hex octets, no malformed values. (I can only check the *shape* — that it matches the
cert your APK is actually signed with is your side of the assertion.)

**Your append-only note is respected and enforced.** `sha256_cert_fingerprints` is an array, this
file is the single source of truth, and a test asserts every entry matches
`^(?:[0-9A-F]{2}:){31}[0-9A-F]{2}$`. When you send the release-keystore and Play-signing
fingerprints, they get **appended** — the debug one stays so in-flight test installs keep working.

### 2.2 How it is served — and the one thing to check on the VPS

**Primary path: a static file, served by the webserver, PHP never involved.**

`public/.htaccess` sends a request to Laravel only when the target does not exist on disk:

```apache
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

That `!-f` is exactly why you saw a *Laravel* 404 rather than an Apache one: the file did not
exist, so the request fell through to the front controller. Once the file is on disk, Apache
serves it directly and Laravel never sees it. `.json` maps to `application/json` in Apache's
default mime types, so the content type is correct without extra config.

The trailing-slash 301 rule above it only fires for URIs ending in `/`, which this one does not —
**no redirect**, as required.

⚠️ **The two things that can still break this on the VPS, neither of which I can see from the
repo (no server config is version-controlled):**

1. **A dot-path deny rule.** Apache's stock `<FilesMatch "^\.ht">` only blocks `.ht*` and is
   fine. But hardened configs and most nginx boilerplate use a blanket `location ~ /\. { deny
   all; }`, which would return **403** for `/.well-known/*`. If your stack is nginx, it needs the
   well-known carve-out:
   ```nginx
   location ~ /\.(?!well-known).* { deny all; }
   ```
2. **A deploy step that skips dot-directories.** rsync with certain filters, `zip` without
   `-r` on hidden dirs, or a build artifact that only copies known subfolders will silently drop
   `public/.well-known/`. This is the single most likely cause if the file is still 404 after
   deploying.

**Safety net for exactly that second case:** I added a Laravel route at
`/.well-known/assetlinks.json` that reads *the same file* and serves it with an explicit
`Content-Type: application/json`. It does **not** fire in the healthy case (the static file wins),
it does not duplicate the JSON (one place to append fingerprints), and it 404s honestly if the
file is genuinely absent rather than inventing a response. It exists because this failure is
silent and blocking — if the webroot copy goes missing, the app-level route still answers Google's
verifier instead of everything quietly breaking.

It does **not** rescue you from failure mode 1 — a webserver-level dot-path `deny` returns 403
before PHP is reached. Please check that explicitly.

---

## 3. The fallback page is no longer a dead end (§5)

You were right that this matters more than it looks: links shared into WhatsApp / Instagram /
Telegram open in in-app browsers that routinely ignore verified App Links, so this page is
arguably the *most* common path for this feature, not the edge case.

### 3.1 The intent button

`GET /d/{id}` now renders an **"Open in the Jadal app"** button firing exactly the URI you
specified, built server-side with the real id:

```
intent://jadal-platform.com/d/42#Intent;scheme=https;package=com.jadalplatform.app;S.browser_fallback_url=https%3A%2F%2Fjadal-platform.com%2Fd%2F42;end
```

Host and scheme come from `config('app.frontend_share_base_url')`, package from
`config('app.android_package')` — no hardcoding. `browser_fallback_url` is percent-encoded,
since `;` and `&` terminate intent parameters and a raw URL would truncate the intent.

A test asserts the **complete** string including the `#Intent;` separator and the `;end`
terminator, not loose fragments — a malformed intent URI is silently ignored by Android, which
would reproduce the exact dead end this change fixes.

**This works regardless of App Links verification**, so it covers both your cases: in-app
browsers, and the post-install verification lag.

### 3.2 Store links — deliberately hidden for now

Implemented, but **rendered only when a store URL is configured**, and both default to `null`:

```
ANDROID_STORE_URL=    # unset → no Google Play link rendered
IOS_STORE_URL=        # unset → no App Store link rendered
```

You noted the app is still signed with the debug certificate, which means it is not on Play yet.
Shipping a hardcoded `play.google.com/store/apps/details?id=…` today would hand users a **404 on
the Play Store** — a worse dead end than the current text. Set the env var the day the listing
goes live and the link appears with no code change.

### 3.3 Platform handling and what stayed the same

- The Android button and the iOS hint are shown by a tiny inline script keyed on User-Agent.
  This is client-side **on purpose**: server-side UA branching would make the response vary per
  device and require `Vary: User-Agent`, defeating the shared cache on a page that is otherwise
  identical for everyone. Without JS, the page still shows the explanatory text and any store
  link — never a dead end in any configuration.
- **The no-lookup property you asked us to keep is kept**, and is now test-enforced: `/d/42` and
  `/d/99999999` render byte-identical output apart from the id. No debate is queried, nothing
  leaks, and it is not an existence oracle.
- No iOS "open app" button: there is no web-triggerable deep link on iOS without a custom URL
  scheme (which the app does not register), and once the AASA file exists the OS intercepts the
  URL before this page is ever reached, so it would be redundant. iOS gets a short hint instead.

---

## 4. iOS — blocked on you, nothing written (§4.2)

**No `apple-app-site-association` file was created.** The project owner's standing instruction
for this round was not to generate these files with placeholder data, and `TEAMID` is precisely
that — a wrong `appID` fails verification just as silently as a missing file, and would be
harder to diagnose because the file *appears* present.

Send the Team ID and it is a one-line change. For when that lands, the exact content is:

```json
{
  "applinks": {
    "apps": [],
    "details": [
      { "appID": "TEAMID.com.jadalplatform.app", "paths": ["/d/*"] }
    ]
  }
}
```

⚠️ **One extra serving requirement for this one, which does not apply to Android:** the file has
**no extension**, so Apache will not infer a content type and will serve it as
`application/octet-stream` — Apple rejects that. It needs an explicit directive:

```apache
<Files "apple-app-site-association">
    ForceType application/json
</Files>
```

(nginx equivalent: `location = /.well-known/apple-app-site-association { default_type application/json; }`)

Agreed on priority: **Android first, iOS when the Apple account exists.**

---

## 5. Path prefix — confirmed unchanged

`/d/*` — exactly what your manifest `pathPrefix="/d/"` and the AASA `paths` expect. The
`share_url` in `live-state` is unchanged and still `https://jadal-platform.com/d/{id}`. Nothing
about the URL scheme moved in this round.

---

## 6. Your five questions

### 6.1 Are both files live, and what exact bytes are served?

**Android: not yet — the file is committed but not deployed. iOS: not written (blocked, §4).**

I cannot paste `curl -i` output, because from here I can only write to the repository; publishing
is a deploy step the owner performs on the VPS. Inventing that output is the one thing that would
actually waste your day, so: **the owner will run this immediately after deploying and send you
the result.** What you should see:

```
HTTP/1.1 200 OK
Content-Type: application/json
```

with **no** `Location:` header and no 30x anywhere in the chain. Worth running with `-L` too, to
prove no redirect is being followed silently.

### 6.2 Static file or Laravel route?

**Static file**, at `public/.well-known/assetlinks.json`, served off disk by the webserver — the
catch-all cannot shadow it, because `public/.htaccess` only rewrites to `index.php` when the file
does **not** exist (`RewriteCond %{REQUEST_FILENAME} !-f`).

There is *also* an app-level route on the same path as a safety net for a deploy that drops the
dot-directory. It reads the same file, so there is no second copy to keep in sync, and it never
executes while the static file is present. Full reasoning in §2.2.

### 6.3 Is there a WAF / Cloudflare / bot protection in front of the domain?

**I cannot determine this from the codebase** — no server, proxy, or CDN configuration is
version-controlled in this repo (no nginx conf, no Dockerfile, no deploy scripts). This is a
question about the VPS and DNS, which the owner will need to confirm directly.

What I can tell you is what the evidence implies: your 404 was **Laravel's** HTML 404 page, not a
CDN or WAF error page. That means the request reached PHP-FPM, so nothing in front of the domain
is currently blocking `/.well-known/*` — it was a genuine "file not found", which is consistent
with the file simply never having existed. That is a good sign, but it is not proof that Google's
verifier (fetching from Google's own IPs, not a phone) sees the same thing. **If verification
still fails after deploy with the file returning 200 to your curl, a bot-protection challenge on
Google's ranges is the next thing to rule out.**

### 6.4 Will you add the "Open in the Jadal app" intent button?

**Yes — done**, exactly as specified. See §3.1. You do not need to plan around in-app browsers
separately.

### 6.5 Exact time the file goes live

The owner will report this with the deploy confirmation in §6.1. I cannot timestamp an event I
cannot perform.

---

## 7. Deploy checklist

```bash
git merge feature/app-links-wellknown        # → stage, then deploy

# 1. Confirm the dot-directory actually made it to the webroot:
ls -la /path/to/webroot/public/.well-known/

# 2. Confirm what is served (this is the output frontend needs):
curl -i https://jadal-platform.com/.well-known/assetlinks.json

# 3. Confirm Google's verifier agrees:
curl "https://digitalassetlinks.googleapis.com/v1/statements:list?source.web.site=https://jadal-platform.com&relation=delegate_permission/common.handle_all_urls"
```

Expect `200` + `Content-Type: application/json` + no redirect on step 2, and a statement listing
`com.jadalplatform.app` on step 3.

**No migration in this round.** Optional env, both safe to leave unset for now:

```
ANDROID_STORE_URL=      # unset = no Play link on the fallback page
IOS_STORE_URL=          # unset = no App Store link
ANDROID_PACKAGE_NAME=   # defaults to com.jadalplatform.app
```

---

## 8. Flagged concerns

1. **The blocking step is a deploy, not a code change.** Everything in this round is inert until
   `feature/app-links-wellknown` reaches the VPS. Please confirm the deploy before re-testing.

2. **`public/.well-known/` is a dot-directory** and is the most likely thing to be silently
   dropped by a deploy pipeline. Verify with `ls -la` on the server, not by assuming the merge
   was enough. The safety-net route covers this case, but only if the webserver is not itself
   denying dot-paths.

3. **The fingerprint will change twice** — release keystore, then again if you publish through
   Play (Play re-signs). Both times links break silently with no server-side error. Send the new
   values and they get appended; the array is designed for exactly that, and the format test will
   catch a malformed paste.

4. **`config('app.android_package')` and the `package_name` in `assetlinks.json` must agree** —
   the first drives the intent URI, the second drives verification, and they are separate values.
   A test asserts they match, so a drift fails CI rather than shipping a button that silently
   does nothing.

5. **iOS is fully blocked on the Team ID**, and unlike Android there is no partial step worth
   taking now. It is one file plus one Apache directive once you have it.
