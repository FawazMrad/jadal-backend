{{--
    Guest mode §1.3 — browser fallback for GET /d/{id}.

    Only reached when the OS did NOT hand the link to the installed app (no app,
    or App Links / Universal Links not yet verified). Renders no debate data at
    all — see the routing comment in routes/web.php for why.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            box-sizing: border-box;
            font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
            background: #ffffff;
            color: #16181d;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #16181d; color: #f3f4f6; }
            .card { border-color: #2c313a !important; }
            .muted { color: #9aa3af !important; }
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
        p { margin: 0 0 0.5rem; line-height: 1.6; }
        .muted { color: #61697a; font-size: 0.875rem; }
        .ltr { direction: ltr; unicode-bidi: isolate; }
    </style>
</head>
<body>
    <main class="card">
        <h1>{{ config('app.name') }}</h1>

        <p>افتح هذا الرابط على جهاز مثبَّت عليه تطبيق {{ config('app.name') }} لعرض النقاش.</p>
        <p class="muted ltr">Open this link on a device with the {{ config('app.name') }} app installed to view this debate.</p>

        <p class="muted ltr" style="margin-top:1.25rem;">Debate #{{ $debateId }}</p>
    </main>
</body>
</html>
