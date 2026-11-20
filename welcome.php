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
    <meta name="theme-color" content="#9b1631">
    <title>SpartanInsight360 · CICS Program Chair Analytics</title>
    <style>
        :root { color-scheme: light; font-family: "Segoe UI", Arial, sans-serif; color: #334155; background: #f4f6f9; --maroon: #a51634; --red: #9b1631; --green: #138a5b; --line: #e5eaf0; --muted: #64748b; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 28px; background: radial-gradient(circle at 8% 4%, #fff0f2 0, transparent 30%), #f4f6f9; }
        .welcome-shell { width: min(1080px, 100%); overflow: hidden; border: 1px solid var(--line); border-radius: 20px; background: #fff; box-shadow: 0 18px 52px rgb(51 65 85 / 9%); animation: enter .45s ease-out both; }
        .brand { display: flex; align-items: center; gap: 12px; padding: 24px 34px; border-bottom: 1px solid var(--line); }
        .brand-mark { display: grid; width: 42px; height: 42px; place-items: center; border-radius: 11px; background: linear-gradient(145deg, var(--red), var(--maroon)); color: #fff; font-size: 14px; font-weight: 800; letter-spacing: -.04em; box-shadow: 0 5px 14px rgb(155 22 49 / 20%); }
        .brand-name { color: var(--red); font-size: 16px; font-weight: 800; letter-spacing: .015em; }
        .brand-subtitle { margin-top: 2px; color: var(--muted); font-size: 11px; }
        .hero { display: grid; grid-template-columns: minmax(0, 1fr) minmax(360px, .92fr); align-items: center; gap: clamp(28px, 6vw, 72px); padding: clamp(30px, 6vw, 66px); }
        .eyebrow { display: inline-flex; align-items: center; gap: 8px; color: var(--red); font-size: 10px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
        .eyebrow::before { width: 20px; height: 2px; background: var(--maroon); content: ""; }
        h1 { max-width: 520px; margin: 15px 0 12px; color: #334155; font-size: clamp(2rem, 4.3vw, 3.25rem); font-weight: 780; letter-spacing: -.055em; line-height: 1.08; }
        h1 span { color: var(--red); }
        .hero-copy { max-width: 500px; margin: 0; color: #64748b; font-size: 15px; line-height: 1.7; }
        .feature-list { display: grid; gap: 10px; margin: 24px 0 29px; padding: 0; list-style: none; color: #536176; font-size: 12px; }
        .feature-list li { display: flex; align-items: center; gap: 9px; }
        .feature-check { display: grid; width: 19px; height: 19px; flex: 0 0 auto; place-items: center; border-radius: 50%; background: #fff0f2; color: var(--red); font-size: 11px; font-weight: 800; }
        .proceed { display: inline-flex; min-height: 46px; align-items: center; justify-content: center; gap: 10px; padding: 0 20px; border: 1px solid var(--red); border-radius: 9px; background: var(--red); color: #fff; font-size: 13px; font-weight: 750; text-decoration: none; transition: background .16s, box-shadow .16s, transform .16s; }
        .proceed:hover { transform: translateY(-1px); background: #7e1229; box-shadow: 0 8px 18px rgb(155 22 49 / 20%); }
        .proceed:focus-visible { outline: 3px solid rgb(165 22 52 / 25%); outline-offset: 3px; }
        .preview { padding: 19px; border: 1px solid var(--line); border-radius: 14px; background: #fff; box-shadow: 0 8px 26px rgb(51 65 85 / 5%); }
        .preview-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .preview-title { color: #475569; font-size: 13px; font-weight: 750; }
        .preview-caption { margin-top: 3px; color: var(--muted); font-size: 10px; }
        .preview-tag { padding: 5px 8px; border-radius: 99px; background: #fff0f2; color: var(--red); font-size: 9px; font-weight: 750; white-space: nowrap; }
        .chart { display: block; width: 100%; height: auto; margin-top: 19px; overflow: visible; }
        .chart-label { fill: #64748b; font-family: "Segoe UI", Arial, sans-serif; font-size: 10px; }
        .chart-grid { stroke: #e5eaf0; stroke-width: 1; }
        .legend { display: flex; flex-wrap: wrap; gap: 15px; margin-top: 13px; color: #64748b; font-size: 10px; }
        .legend-item { display: inline-flex; align-items: center; gap: 6px; }
        .legend-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--maroon); }
        .legend-dot.green { background: var(--green); }
        .preview-note { margin-top: 12px; padding-top: 10px; border-top: 1px solid #edf0f4; color: #8994a4; font-size: 9px; line-height: 1.5; }
        .footer { padding: 14px 34px; border-top: 1px solid var(--line); color: #8090a5; font-size: 10px; }
        @keyframes enter { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
        @media (max-width: 760px) { body { padding: 14px; } .brand { padding: 18px 20px; } .hero { grid-template-columns: 1fr; padding: 28px 20px; } .preview { max-width: 560px; } .footer { padding: 13px 20px; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
    </style>
</head>
<body>
<main class="welcome-shell">
    <header class="brand" aria-label="SpartanInsight360">
        <span class="brand-mark" aria-hidden="true">SI</span>
        <span><span class="brand-name">SpartanInsight360</span><br><span class="brand-subtitle">BAT 405 · CICS Program Chair Analytics</span></span>
    </header>
    <section class="hero" aria-labelledby="welcomeTitle">
        <div>
            <div class="eyebrow">Program Chair Workspace</div>
            <h1 id="welcomeTitle">A clearer view of <span>CICS performance.</span></h1>
            <p class="hero-copy">Review section outcomes, SETE participation, and academic support indicators from one dashboard built for CICS Program Chairs.</p>
            <ul class="feature-list">
                <li><span class="feature-check" aria-hidden="true">✓</span> Section-level academic performance and follow-up</li>
                <li><span class="feature-check" aria-hidden="true">✓</span> Faculty SETE coverage and semester trends</li>
                <li><span class="feature-check" aria-hidden="true">✓</span> Early-warning support tools with responsible-use guidance</li>
            </ul>
            <a class="proceed" href="login.php">Proceed to login <span aria-hidden="true">→</span></a>
        </div>
        <aside class="preview" aria-label="Illustrative dashboard chart preview">
            <div class="preview-head">
                <div><div class="preview-title">CICS Section Trends</div><div class="preview-caption">Passing rate and SETE response</div></div>
                <span class="preview-tag">Dashboard preview</span>
            </div>
            <svg class="chart" viewBox="0 0 520 230" role="img" aria-label="Illustrative crimson passing rate and green SETE response trend lines across CICS sections">
                <defs>
                    <linearGradient id="passArea" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#a51634" stop-opacity=".15"/><stop offset="1" stop-color="#a51634" stop-opacity="0"/></linearGradient>
                    <linearGradient id="seteArea" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#138a5b" stop-opacity=".12"/><stop offset="1" stop-color="#138a5b" stop-opacity="0"/></linearGradient>
                </defs>
                <path class="chart-grid" d="M42 35H510 M42 78H510 M42 121H510 M42 164H510"/>
                <path d="M42 91 C95 76 118 104 160 88 S222 51 274 67 S349 101 390 72 S463 47 510 58 L510 164 L42 164Z" fill="url(#passArea)"/>
                <path d="M42 91 C95 76 118 104 160 88 S222 51 274 67 S349 101 390 72 S463 47 510 58" fill="none" stroke="#a51634" stroke-width="3" stroke-linecap="round"/>
                <path d="M42 130 C96 117 120 138 160 124 S223 93 274 108 S350 133 390 116 S463 91 510 103 L510 164 L42 164Z" fill="url(#seteArea)"/>
                <path d="M42 130 C96 117 120 138 160 124 S223 93 274 108 S350 133 390 116 S463 91 510 103" fill="none" stroke="#138a5b" stroke-width="2.5" stroke-linecap="round"/>
                <text class="chart-label" x="42" y="190">1101</text><text class="chart-label" x="150" y="190">2101</text><text class="chart-label" x="260" y="190">3101</text><text class="chart-label" x="369" y="190">4101</text><text class="chart-label" x="484" y="190">4105</text>
            </svg>
            <div class="legend"><span class="legend-item"><i class="legend-dot" aria-hidden="true"></i>Passing rate</span><span class="legend-item"><i class="legend-dot green" aria-hidden="true"></i>SETE response</span></div>
            <div class="preview-note">Illustrative preview. Sign in to view the dashboard and its current section data.</div>
        </aside>
    </section>
    <footer class="footer">Restricted workspace · Authorized CICS Program Chair access</footer>
</main>
</body>
</html>
