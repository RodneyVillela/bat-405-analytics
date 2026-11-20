<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
if (currentUser() !== null) {
    header('Location: index.php', true, 302);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Welcome · BatStateU CICS Analytics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { color-scheme: light; font-family: Inter, "Segoe UI", system-ui, sans-serif; color: #111827; background: #f3f4f6; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; }
        main { width: min(100%, 560px); padding: 48px; border: 1px solid #e5e7eb; border-radius: 20px; background: #fff; text-align: center; box-shadow: 0 16px 48px #11182712; animation: enter .55s ease-out both; }
        img { display: block; width: min(180px, 60%); height: auto; margin: 0 auto 24px; }
        h1 { margin: 0; color: #111827; font-size: clamp(1.5rem, 4vw, 2rem); letter-spacing: -.04em; }
        p { margin: 12px 0 28px; color: #6b7280; line-height: 1.6; }
        a { display: inline-flex; min-height: 46px; align-items: center; justify-content: center; padding: 0 24px; border-radius: 8px; background: #111827; color: #fff; font-weight: 600; text-decoration: none; }
        a:hover, a:focus-visible { background: #374151; }
        a:focus-visible { outline: 3px solid #6b728080; outline-offset: 3px; }
        @keyframes enter { from { opacity: 0; transform: translateY(10px) scale(.99); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; scroll-behavior: auto !important; } }
    </style>
</head>
<body>
<main>
    <img src="assets/univance-logo.png" alt="Univance logo">
    <h1>BatStateU CICS Analytics</h1>
    <p>Academic performance and student risk insights for authorized BSIT Program Chairs.</p>
    <a href="login.php">Continue to sign in</a>
</main>
</body>
</html>
