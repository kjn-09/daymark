<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f5f9">
    <title>Email verified | Daymark</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #211a2b;
            background: #f7f5f9;
            font-synthesis: none;
            text-rendering: optimizeLegibility;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            background: radial-gradient(ellipse at 50% 0, #eee4fc 0, #f7f5f9 58%);
        }

        main {
            width: min(100%, 520px);
            padding: clamp(32px, 7vw, 56px);
            border: 1px solid #ebe5f1;
            border-radius: 20px;
            background: #fff;
            box-shadow: 0 24px 70px #321b4c12;
            text-align: center;
        }

        .brand {
            margin-bottom: 36px;
            color: #211a2b;
            font-size: 18px;
            font-weight: 750;
            letter-spacing: -.6px;
        }

        .brand span { color: #873ee8; }

        .success-icon {
            width: 68px;
            height: 68px;
            margin: 0 auto 24px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            color: #873ee8;
            background: #f2eaff;
        }

        .success-icon svg { width: 32px; height: 32px; }

        .eyebrow {
            margin: 0 0 10px;
            color: #873ee8;
            font-size: 11px;
            font-weight: 750;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(30px, 7vw, 42px);
            font-weight: 600;
            letter-spacing: -1px;
            line-height: 1.12;
        }

        .message {
            margin: 16px 0 30px;
            color: #777181;
            font-size: 15px;
            line-height: 1.65;
        }

        .back-link {
            min-height: 50px;
            padding: 0 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border-radius: 8px;
            color: #fff;
            background: #873ee8;
            box-shadow: 0 8px 22px #873ee831;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: background-color .15s ease, transform .15s ease;
        }

        .back-link:hover { background: #7532d0; transform: translateY(-1px); }
        .back-link:focus-visible { outline: 3px solid #c8a7f4; outline-offset: 3px; }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>
    <main>
        <div class="brand" aria-label="Daymark">daymark<span>.</span></div>
        <div class="success-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="m5 12.5 4.2 4.2L19 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <p class="eyebrow">Email verified</p>
        <h1>Your account has been verified.</h1>
        <p class="message">Your email address has been verified. Go back to Daymark to sign in to your account.</p>
        <a class="back-link" href="{{ $returnUrl }}">
            Go back to Daymark
            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none">
                <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
    </main>
</body>
</html>
