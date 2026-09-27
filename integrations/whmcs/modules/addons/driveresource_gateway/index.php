<?php
/**
 * Public landing page for the branded Elearning Stream Gateway hostname.
 *
 * The authenticated API lives under /api/. This page intentionally exposes no
 * provider, customer, quota, WHMCS, or infrastructure details.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Elearning Stream Gateway</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font: 16px/1.5 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f8fafc;
            color: #0f172a;
        }
        main {
            width: min(92vw, 560px);
            padding: 36px;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 18px 50px rgba(15, 23, 42, .08);
        }
        h1 { margin: 0 0 8px; font-size: 28px; }
        p { margin: 0; color: #475569; }
        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 22px;
            font-weight: 650;
            color: #166534;
        }
        .dot { width: 10px; height: 10px; border-radius: 50%; background: #16a34a; }
        @media (prefers-color-scheme: dark) {
            body { background: #020617; color: #f8fafc; }
            main { background: #0f172a; border-color: #1e293b; }
            p { color: #cbd5e1; }
            .status { color: #86efac; }
        }
    </style>
</head>
<body>
<main>
    <h1>Elearning Stream</h1>
    <p>Secure media gateway for connected learning platforms.</p>
    <div class="status"><span class="dot" aria-hidden="true"></span>Gateway online</div>
</main>
</body>
</html>
