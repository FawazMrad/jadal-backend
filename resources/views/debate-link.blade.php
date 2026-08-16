{{--
    Share-link fallback for GET /d/{id}.

    Reached whenever the OS did NOT hand the link to the installed app:
      - Android App Links not verified yet (fresh install, or assetlinks.json
        missing/unreachable),
      - an in-app browser (WhatsApp / Instagram / Telegram / Facebook) that
        ignores verified App Links — the most common path for a link shared
        into a chat, which is this feature's whole purpose,
      - iOS before the AASA file exists,
      - genuinely no app installed.

    Renders NO debate data: every id produces identical bytes, so this leaks
    nothing to an unauthenticated visitor and is not an id-enumeration oracle.
    Keep that property — the guest API projection is the only guest read path.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
            background: #ffffff;
            color: #16181d;
        }
        .card {
            max-width: 26rem;
            width: 100%;
            border: 1px solid #e3e6ea;
            border-radius: 0.875rem;
            padding: 1.75rem;
            text-align: center;
        }
        h1 { font-size: 1.125rem; margin: 0 0 0.625rem; }
        p  { margin: 0 0 0.5rem; line-height: 1.6; }
        .muted { color: #61697a; font-size: 0.875rem; }
        .ltr   { direction: ltr; unicode-bidi: isolate; }
        .btn {
            display: block;
            margin: 1.25rem 0 0.5rem;
            padding: 0.8rem 1.25rem;
            border-radius: 0.625rem;
            background: #1f6feb;
            color: #ffffff;
            font-weight: 600;
            text-decoration: none;
        }
        .btn:hover { background: #1a5fd0; }
        .store { display: inline-block; margin-top: 0.75rem; color: #1f6feb; font-size: 0.875rem; }
        /* Hidden until the inline script confirms the platform. Without JS the
           Android button stays hidden and the text + store link still work, so
           the page is never a dead end in any configuration. */
        [data-platform] { display: none; }
        @media (prefers-color-scheme: dark) {
            body   { background: #16181d; color: #f3f4f6; }
            .card  { border-color: #2c313a; }
            .muted { color: #9aa3af; }
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>{{ config('app.name') }}</h1>

        <p>لمتابعة النقاش، افتح هذا الرابط في تطبيق {{ config('app.name') }}.</p>
        <p class="muted ltr">Open this link in the {{ config('app.name') }} app to watch the debate.</p>

        {{-- Android: intent:// opens the app regardless of App Links verification. --}}
        <a class="btn" href="{{ $intentUri }}" data-platform="android">
            افتح في تطبيق {{ config('app.name') }} · Open in the {{ config('app.name') }} app
        </a>

        {{-- iOS: no equivalent web-triggerable deep link without a custom scheme.
             Once the AASA file is live the OS intercepts the URL before this page
             is ever reached, so a button here would be redundant anyway. --}}
        <p class="muted ltr" data-platform="ios" style="margin-top:1.25rem;">
            If the app is installed, reopen this link from Messages or Safari.
        </p>

        @if ($androidStore || $iosStore)
            <p class="muted" style="margin-top:1rem;">لا تملك التطبيق؟ · Don't have the app?</p>
            @if ($androidStore)
                <a class="store ltr" href="{{ $androidStore }}" data-platform="android">Get it on Google Play</a>
            @endif
            @if ($iosStore)
                <a class="store ltr" href="{{ $iosStore }}" data-platform="ios">Download on the App Store</a>
            @endif
        @endif

        <p class="muted ltr" style="margin-top:1.25rem;">Debate #{{ $debateId }}</p>
    </main>

    <script>
        // Platform detection is client-side ON PURPOSE: doing it server-side
        // would make the response vary by User-Agent and require a Vary header,
        // defeating the shared cache on a page that is otherwise identical for
        // everyone. Elements opt in via data-platform and stay hidden without JS.
        (function () {
            var ua = navigator.userAgent || '';
            var platform = /android/i.test(ua)
                ? 'android'
                : (/iPad|iPhone|iPod/.test(ua) ? 'ios' : null);

            if (!platform) return;

            document.querySelectorAll('[data-platform="' + platform + '"]')
                .forEach(function (el) {
                    el.style.display = el.classList.contains('store') ? 'inline-block' : 'block';
                });
        })();
    </script>
</body>
</html>
