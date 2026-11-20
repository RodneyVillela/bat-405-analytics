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
    <title>Sign in · BatStateU CICS Analytics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { font-family: Inter, "Segoe UI", system-ui, sans-serif; color: #111827; background: #f8fafc; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; background: #f8fafc; }
        .login-shell { display: grid; width: min(1120px, 100%); min-height: min(680px, calc(100vh - 48px)); grid-template-columns: minmax(340px, .92fr) 1.08fr; overflow: hidden; border: 1px solid #e5e7eb; border-radius: 20px; background: #fff; box-shadow: 0 18px 56px #11182712; }
        .form-side { display: flex; flex-direction: column; justify-content: center; padding: clamp(32px, 6vw, 72px); }
        .logo { width: 118px; height: auto; margin: 0 auto 22px; }
        h1 { margin: 0; text-align: center; color: #111827; font-size: 1.65rem; letter-spacing: -.04em; }
        .intro { margin: 8px 0 32px; color: #6b7280; text-align: center; font-size: .9rem; }
        label { display: block; margin: 16px 0 7px; color: #374151; font-size: .82rem; font-weight: 550; }
        input { width: 100%; height: 46px; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 13px; background: #fff; color: #111827; font: inherit; font-size: .9rem; }
        input::placeholder { color: #9ca3af; }
        input:focus { border-color: #4b5563; outline: 3px solid #6b728026; }
        .submit { width: 100%; min-height: 46px; margin-top: 24px; border: 0; border-radius: 8px; background: #111827; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        .submit:hover { background: #374151; }
        .submit:focus-visible, .back:focus-visible { outline: 3px solid #6b728080; outline-offset: 3px; }
        .error { margin: 0 0 16px; border: 1px solid #fecdd3; border-radius: 8px; background: #fff1f2; padding: 11px 13px; color: #9f1239; font-size: .85rem; }
        .back { display: block; margin-top: 20px; color: #4b5563; text-align: center; font-size: .85rem; text-decoration: none; }
        .back:hover { color: #111827; text-decoration: underline; }
        .welcome-side { position: relative; display: flex; flex-direction: column; justify-content: center; overflow: hidden; padding: clamp(36px, 6vw, 72px); background: linear-gradient(145deg, #111827, #374151); color: #fff; }
        .welcome-side::before, .welcome-side::after { position: absolute; width: 480px; height: 480px; border: 1px solid #ffffff16; border-radius: 50%; content: ""; }
        .welcome-side::before { top: -290px; left: -180px; }
        .welcome-side::after { right: -270px; bottom: -315px; }
        .welcome-copy, .mini-chart { position: relative; z-index: 1; }
        .eyebrow { color: #d1d5db; font-size: .75rem; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; }
        .welcome-side h2 { max-width: 470px; margin: 14px 0; font-size: clamp(2rem, 4vw, 3.2rem); font-weight: 650; letter-spacing: -.05em; line-height: 1.08; }
        .welcome-side p { max-width: 440px; margin: 0; color: #d1d5db; line-height: 1.65; }
        .mini-chart { margin-top: 48px; padding: 20px; border: 1px solid #ffffff2a; border-radius: 14px; background: #ffffff0d; }
        .chart-heading { display: flex; justify-content: space-between; gap: 12px; color: #f9fafb; font-size: .82rem; font-weight: 550; }
        .chart-heading span:last-child { color: #d1d5db; font-size: .75rem; font-weight: 400; }
        .chart-svg { display: block; width: 100%; height: auto; margin-top: 18px; }
        .chart-caption { display: flex; justify-content: space-between; margin-top: 8px; color: #d1d5db; font-size: .7rem; }
        @media (max-width: 760px) {
            body { padding: 12px; }
            .login-shell { min-height: auto; grid-template-columns: 1fr; }
            .form-side { padding: 36px 26px; }
            .welcome-side { min-height: 390px; padding: 34px 26px; }
            .mini-chart { margin-top: 28px; padding: 16px; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; } }
    </style>
</head>
<body>
<main class="login-shell">
    <section class="form-side" aria-labelledby="signInTitle">
        <img class="logo" src="assets/univance-logo.png" alt="Univance logo">
        <h1 id="signInTitle">Sign in</h1>
        <p class="intro">Welcome to the BatStateU CICS analytics dashboard.</p>
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
            <button class="submit" type="submit">Sign in to dashboard</button>
        </form>
        <a class="back" href="welcome.php">Back to welcome</a>
    </section>
    <aside class="welcome-side" aria-label="Dashboard information">
        <div class="welcome-copy">
            <div class="eyebrow">BatStateU CICS · BSIT</div>
            <h2>Welcome back.<br>See the whole picture.</h2>
            <p>Review academic performance, faculty evaluations, and student risk indicators in one secure workspace.</p>
        </div>
        <div class="mini-chart" aria-hidden="true">
            <div class="chart-heading"><span>Academic performance</span><span>Section averages</span></div>
            <svg class="chart-svg" viewBox="0 0 520 180" role="presentation">
                <defs>
                    <linearGradient id="areaOne" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0" stop-color="#f9fafb" stop-opacity=".48"/>
                        <stop offset="1" stop-color="#9ca3af" stop-opacity=".02"/>
                    </linearGradient>
                    <linearGradient id="areaTwo" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0" stop-color="#d1d5db" stop-opacity=".4"/>
                        <stop offset="1" stop-color="#9ca3af" stop-opacity=".02"/>
                    </linearGradient>
                </defs>
                <path d="M0 45 C48 96 76 148 140 140 S242 65 300 44 S401 48 520 126 L520 180 L0 180Z" fill="url(#areaOne)"/>
                <path d="M0 45 C48 96 76 148 140 140 S242 65 300 44 S401 48 520 126" fill="none" stroke="#f9fafb" stroke-width="2"/>
                <path d="M0 106 C61 113 99 154 157 151 S249 105 316 98 S411 101 520 151 L520 180 L0 180Z" fill="url(#areaTwo)"/>
                <path d="M0 106 C61 113 99 154 157 151 S249 105 316 98 S411 101 520 151" fill="none" stroke="#d1d5db" stroke-width="2"/>
                <path d="M0 171H520" stroke="#ffffff30" stroke-width="1"/>
            </svg>
            <div class="chart-caption"><span>4101</span><span>4102</span><span>4103</span><span>4104</span><span>4105</span></div>
        </div>
    </aside>
</main>
</body>
</html>
