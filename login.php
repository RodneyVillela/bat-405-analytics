<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
if (currentUser() !== null) {
    header('Location: index.php', true, 302);
    exit;
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        http_response_code(400);
        $error = 'Your sign-in request expired. Please try again.';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = $_POST['password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '') {
            $error = 'Invalid email or password.';
        } else {
            try {
                require __DIR__ . '/db_connect.php';
                $query = $pdo->prepare(
                    'SELECT id, name, email, password_hash, role
                     FROM app_users
                     WHERE email = :email AND is_active = 1
                     LIMIT 1'
                );
                $query->execute([':email' => $email]);
                $user = $query->fetch();
                if ($user && password_verify($password, $user['password_hash']) && $user['role'] === 'program_chair') {
                    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                        $update = $pdo->prepare('UPDATE app_users SET password_hash = :hash WHERE id = :id');
                        $update->execute([
                            ':hash' => password_hash($password, PASSWORD_DEFAULT),
                            ':id' => $user['id'],
                        ]);
                    }
                    startAppSession();
                    session_regenerate_id(true);
                    $_SESSION['auth_user'] = [
                        'id' => (int) $user['id'],
                        'name' => $user['name'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                    ];
                    $_SESSION['last_activity'] = time();
                    unset($_SESSION['csrf_token']);
                    header('Location: index.php', true, 303);
                    exit;
                }
                $error = 'Invalid email or password.';
            } catch (Throwable $exception) {
                error_log('BAT405 sign-in error: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'Sign-in is unavailable. Check the database connection.';
            }
        }
    }
}

$token = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#9b1631">
    <title>Sign in · SpartanInsight360</title>
    <style>
        :root { color-scheme: light; font-family: "Segoe UI", Arial, sans-serif; color: #334155; background: #f4f6f9; --maroon: #a51634; --red: #9b1631; --green: #138a5b; --line: #e5eaf0; --muted: #64748b; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; background: radial-gradient(circle at 92% 8%, #fff0f2 0, transparent 28%), #f4f6f9; }
        .login-shell { display: grid; width: min(1120px, 100%); min-height: min(680px, calc(100vh - 48px)); grid-template-columns: minmax(340px, .92fr) 1.08fr; overflow: hidden; border: 1px solid var(--line); border-radius: 18px; background: #fff; box-shadow: 0 18px 52px rgb(51 65 85 / 9%); }
        .form-side { display: flex; flex-direction: column; justify-content: center; padding: clamp(32px, 6vw, 72px); }
        .brand-lockup { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 0 0 25px; }
        .brand-mark { display: grid; width: 40px; height: 40px; place-items: center; border-radius: 10px; background: linear-gradient(145deg, var(--red), var(--maroon)); color: #fff; font-size: 13px; font-weight: 800; letter-spacing: -.04em; }
        .brand-name { color: var(--red); font-size: 14px; font-weight: 800; letter-spacing: .01em; }
        .brand-subtitle { margin-top: 2px; color: var(--muted); font-size: 10px; }
        h1 { margin: 0; text-align: center; color: #334155; font-size: 1.65rem; letter-spacing: -.04em; }
        .intro { margin: 8px 0 28px; color: #64748b; text-align: center; font-size: .9rem; }
        label { display: block; margin: 16px 0 7px; color: #475569; font-size: .82rem; font-weight: 650; }
        input { width: 100%; height: 46px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 13px; background: #fff; color: #334155; font: inherit; font-size: .9rem; }
        input::placeholder { color: #94a3b8; }
        input:focus { border-color: var(--maroon); outline: 3px solid rgb(165 22 52 / 12%); }
        .submit { width: 100%; min-height: 46px; margin-top: 24px; border: 1px solid var(--red); border-radius: 8px; background: var(--red); color: #fff; font: inherit; font-weight: 700; cursor: pointer; transition: background .16s, box-shadow .16s; }
        .submit:hover { background: #7e1229; box-shadow: 0 7px 16px rgb(155 22 49 / 17%); }
        .submit:focus-visible, .back:focus-visible { outline: 3px solid rgb(165 22 52 / 25%); outline-offset: 3px; }
        .error { margin: 0 0 16px; border: 1px solid #f2c7d0; border-radius: 8px; background: #fff0f2; padding: 11px 13px; color: #8d142f; font-size: .85rem; }
        .back { display: block; margin-top: 20px; color: var(--red); text-align: center; font-size: .85rem; font-weight: 650; text-decoration: none; }
        .back:hover { color: #7e1229; text-decoration: underline; }
        .welcome-side { position: relative; display: flex; flex-direction: column; justify-content: center; overflow: hidden; padding: clamp(36px, 6vw, 72px); border-left: 1px solid #f2dce1; background: linear-gradient(150deg, #fff8f9, #fff 74%); }
        .welcome-side::before { position: absolute; top: -185px; right: -160px; width: 410px; height: 410px; border: 1px solid rgb(165 22 52 / 8%); border-radius: 50%; content: ""; }
        .welcome-copy, .mini-chart { position: relative; z-index: 1; }
        .eyebrow { color: var(--red); font-size: .72rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
        .welcome-side h2 { max-width: 470px; margin: 13px 0; color: var(--red); font-size: clamp(2rem, 4vw, 3rem); font-weight: 780; letter-spacing: -.05em; line-height: 1.08; }
        .welcome-side p { max-width: 440px; margin: 0; color: #64748b; line-height: 1.65; }
        .mini-chart { margin-top: 34px; padding: 18px; border: 1px solid var(--line); border-radius: 12px; background: #fff; box-shadow: 0 8px 26px rgb(51 65 85 / 5%); }
        .chart-heading { display: flex; justify-content: space-between; gap: 12px; color: #475569; font-size: .8rem; font-weight: 750; }
        .chart-heading span:last-child { color: var(--muted); font-size: .7rem; font-weight: 500; }
        .chart-svg { display: block; width: 100%; height: auto; margin-top: 16px; }
        .chart-grid { stroke: #e5eaf0; stroke-width: 1; }
        .chart-axis-label { fill: #64748b; font-family: "Segoe UI", Arial, sans-serif; font-size: 10px; }
        .chart-legend { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 10px; color: #64748b; font-size: .68rem; }
        .legend-item { display: inline-flex; align-items: center; gap: 6px; }
        .legend-line { width: 13px; height: 3px; border-radius: 4px; background: var(--maroon); }
        .legend-line.green { background: var(--green); }
        .chart-note { margin-top: 9px; color: #8994a4; font-size: .64rem; line-height: 1.45; }
        @media (max-width: 760px) {
            body { padding: 12px; }
            .login-shell { min-height: auto; grid-template-columns: 1fr; }
            .form-side { padding: 36px 26px; }
            .welcome-side { min-height: 390px; padding: 34px 26px; border-top: 1px solid #f2dce1; border-left: 0; }
            .mini-chart { margin-top: 25px; padding: 15px; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; } }
    </style>
</head>
<body>
<main class="login-shell">
    <section class="form-side" aria-labelledby="signInTitle">
        <div class="brand-lockup" aria-label="SpartanInsight360">
            <span class="brand-mark" aria-hidden="true">SI</span>
            <span><span class="brand-name">SpartanInsight360</span><br><span class="brand-subtitle">BAT 405 · CICS Program Chair Analytics</span></span>
        </div>
        <h1 id="signInTitle">Sign in</h1>
        <p class="intro">Sign in to your CICS Program Chair workspace.</p>
        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= escapeHtml($error) ?></p>
        <?php endif; ?>
        <form method="post" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="username" required maxlength="254"
                   value="<?= escapeHtml($email) ?>" placeholder="name@example.edu">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="1024">
            <button class="submit" type="submit">Sign in to SpartanInsight</button>
        </form>
        <a class="back" href="welcome.php">Back to welcome</a>
    </section>
    <aside class="welcome-side" aria-label="SpartanInsight dashboard preview">
        <div class="welcome-copy">
            <div class="eyebrow">SpartanInsight · CICS</div>
            <h2>Welcome back.<br>See the whole picture.</h2>
            <p>Review section performance, SETE participation, and early academic support indicators in one secure workspace.</p>
        </div>
        <div class="mini-chart" aria-hidden="true">
            <div class="chart-heading"><span>Passing rate by section</span><span>Dashboard preview</span></div>
            <svg class="chart-svg" viewBox="0 0 520 180" role="presentation">
                <defs>
                    <linearGradient id="passFill" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0" stop-color="#a51634" stop-opacity=".15"/>
                        <stop offset="1" stop-color="#a51634" stop-opacity="0"/>
                    </linearGradient>
                    <linearGradient id="seteFill" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0" stop-color="#138a5b" stop-opacity=".12"/>
                        <stop offset="1" stop-color="#138a5b" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                <path class="chart-grid" d="M0 28H520 M0 65H520 M0 102H520 M0 139H520"/>
                <path d="M0 71 C55 60 95 83 146 69 S237 39 289 54 S374 83 417 58 S479 38 520 45 L520 145 L0 145Z" fill="url(#passFill)"/>
                <path d="M0 71 C55 60 95 83 146 69 S237 39 289 54 S374 83 417 58 S479 38 520 45" fill="none" stroke="#a51634" stroke-width="3" stroke-linecap="round"/>
                <path d="M0 111 C58 101 99 120 146 107 S237 80 289 94 S374 115 417 99 S477 78 520 88 L520 145 L0 145Z" fill="url(#seteFill)"/>
                <path d="M0 111 C58 101 99 120 146 107 S237 80 289 94 S374 115 417 99 S477 78 520 88" fill="none" stroke="#138a5b" stroke-width="2.5" stroke-linecap="round"/>
                <text class="chart-axis-label" x="0" y="166">1101</text><text class="chart-axis-label" x="122" y="166">2101</text><text class="chart-axis-label" x="246" y="166">3101</text><text class="chart-axis-label" x="370" y="166">4101</text><text class="chart-axis-label" x="486" y="166">4105</text>
            </svg>
            <div class="chart-legend"><span class="legend-item"><i class="legend-line" aria-hidden="true"></i>Passing rate</span><span class="legend-item"><i class="legend-line green" aria-hidden="true"></i>SETE response</span></div>
            <div class="chart-note">Illustrative preview. Current dashboard values appear after sign-in.</div>
        </div>
    </aside>
</main>
</body>
</html>
