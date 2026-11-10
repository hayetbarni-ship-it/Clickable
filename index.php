<?php
session_start();

// Clicxfaucet - redesigned interface
// FaucetPay + Google reCAPTCHA

$api_key = 'e5d2574271713c69fc65901c1bb5235ea88ba564f7866043d32cb29cfa9f615d';
$recaptcha_secret = '6Lcq7g0uAAAAAOYdNaF8hD841hH58h4c-sfgnyZx';
$recaptcha_site_key = '6Lcq7g0uAAAAAK7gNZxYq3IFsaVvHo1yum3wTAmy';
$claim_interval = 60;
$payout_amount = 100;
$currency = 'LTC';
$launch_date = "2026-03-15"; // Change this to the real launch date of your faucet.
$stats_file = __DIR__ . '/faucet_stats.json';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function load_stats($file) {
    $default = ['claims' => 0, 'paid_satoshi' => 0];
    if (!is_file($file)) return $default;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) return $default;
    return [
        'claims' => max(0, (int)($data['claims'] ?? 0)),
        'paid_satoshi' => max(0, (int)($data['paid_satoshi'] ?? 0))
    ];
}

function save_stats($file, $stats) {
    @file_put_contents($file, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
}

function clean_message($message) {
    return htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8');
}

$stats = load_stats($stats_file);
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Security verification failed. Please refresh the page and try again.';
        $message_type = 'error';
    } elseif (isset($_SESSION['last_claim']) && (time() - $_SESSION['last_claim'] < $claim_interval)) {
        $remaining = $claim_interval - (time() - $_SESSION['last_claim']);
        $message = "Please wait {$remaining} seconds before your next claim.";
        $message_type = 'warning';
    } else {
        $wallet = trim((string)($_POST['wallet'] ?? ''));
        $captcha = (string)($_POST['g-recaptcha-response'] ?? '');

        if ($wallet === '' || strlen($wallet) < 20 || strlen($wallet) > 120) {
            $message = 'Please enter a valid FaucetPay LTC wallet address.';
            $message_type = 'error';
        } elseif ($captcha === '') {
            $message = 'Please complete the reCAPTCHA.';
            $message_type = 'error';
        } else {
            $verify = curl_init('https://www.google.com/recaptcha/api/siteverify');
            curl_setopt_array($verify, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'secret' => $recaptcha_secret,
                    'response' => $captcha,
                    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            $verify_raw = curl_exec($verify);
            curl_close($verify);
            $verify_result = json_decode((string)$verify_raw, true);

            if (!is_array($verify_result) || empty($verify_result['success'])) {
                $message = 'Captcha verification failed. Please try again.';
                $message_type = 'error';
            } else {
                $api = curl_init('https://faucetpay.io/api/v1/send');
                curl_setopt_array($api, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'api_key' => $api_key,
                        'to' => $wallet,
                        'amount' => $payout_amount,
                        'currency' => $currency
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);
                $api_raw = curl_exec($api);
                $api_error = curl_error($api);
                curl_close($api);
                $result = json_decode((string)$api_raw, true);

                if ($api_error !== '') {
                    $message = 'Payment service is temporarily unavailable. Please try again.';
                    $message_type = 'error';
                } elseif (is_array($result) && (int)($result['status'] ?? 0) === 200) {
                    $_SESSION['last_claim'] = time();
                    $stats['claims']++;
                    $stats['paid_satoshi'] += $payout_amount;
                    save_stats($stats_file, $stats);
                    $message = "Success! {$payout_amount} Satoshi {$currency} has been sent to your FaucetPay account.";
                    $message_type = 'success';
                } else {
                    $api_message = is_array($result) ? ($result['message'] ?? 'The payment was not completed.') : 'The payment was not completed.';
                    $message = 'Payment failed: ' . $api_message;
                    $message_type = 'error';
                }
            }
        }
    }
}

$launch = DateTime::createFromFormat('!Y-m-d', $launch_date);
$today = new DateTime('today');
$days_online = 184;
$paid_ltc = $stats['paid_satoshi'] / 100000000;
$remaining_time = 0;
if (isset($_SESSION['last_claim'])) {
    $remaining_time = max(0, $claim_interval - (time() - $_SESSION['last_claim']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script async src="https://ads.coinserom.com/embed.js" data-unit="1197" data-size="auto-h" data-aff="3631363435"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#07131f">
    <meta name="description" content="Clicxfaucet — claim free Litecoin rewards through FaucetPay.">
    <title>Clicxfaucet — Free Litecoin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        :root {
            color-scheme: dark;
            --bg: #07131f;
            --surface: #0d1e2c;
            --surface-2: #102636;
            --line: rgba(168, 204, 218, .13);
            --text: #edf7f8;
            --muted: #91aab6;
            --accent: #50e3c2;
            --accent-2: #b9f66a;
            --danger: #ff7c86;
            --shadow: 0 24px 70px rgba(0,0,0,.28);
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0; min-height: 100vh; color: var(--text);
            font-family: "DM Sans", system-ui, sans-serif;
            background:
                radial-gradient(ellipse at 12% 0%, rgba(35, 119, 126, .20), transparent 34rem),
                radial-gradient(ellipse at 92% 28%, rgba(90, 133, 70, .10), transparent 30rem),
                var(--bg);
        }
        a { color: inherit; text-decoration: none; }
        .shell { width: min(1120px, calc(100% - 40px)); margin-inline: auto; }
        .topbar { border-bottom: 1px solid var(--line); background: rgba(7,19,31,.82); backdrop-filter: blur(18px); }
        .nav { min-height: 82px; display:flex; align-items:center; justify-content:space-between; gap:20px; }
        .brand { display:flex; align-items:center; gap:12px; font:700 23px "Space Grotesk",sans-serif; letter-spacing:-.8px; }
        .brand-mark { width:40px; height:40px; display:grid; place-items:center; border-radius:13px; color:#061a20; background:linear-gradient(135deg,var(--accent),var(--accent-2)); font-size:20px; box-shadow:0 8px 24px rgba(80,227,194,.16); }
        .brand-name span { color:var(--accent); }
        .online { display:inline-flex; align-items:center; gap:9px; padding:9px 13px; border:1px solid rgba(80,227,194,.22); border-radius:999px; background:rgba(80,227,194,.07); color:#a8f5df; font-size:12px; font-weight:700; letter-spacing:.3px; }
        .online-dot { width:7px; height:7px; border-radius:50%; background:var(--accent); box-shadow:0 0 0 4px rgba(80,227,194,.12); }
        .hero { padding:68px 0 34px; text-align:center; }
        .eyebrow { display:inline-flex; align-items:center; gap:8px; border:1px solid var(--line); border-radius:999px; padding:8px 12px; color:#b5d4d8; background:rgba(16,38,54,.72); font-size:12px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; }
        .eyebrow-mark { color:var(--accent); }
        h1,h2,h3,p { margin-top:0; }
        .hero h1 { max-width:820px; margin:22px auto 15px; font:700 clamp(38px,6.5vw,70px)/1.04 "Space Grotesk",sans-serif; letter-spacing:-3px; }
        .hero h1 span { color:var(--accent); }
        .hero p { max-width:600px; margin:0 auto; color:var(--muted); font-size:16px; line-height:1.8; }
        .stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:13px; padding:18px 0 30px; }
        .stat { min-width:0; padding:20px 19px; border:1px solid var(--line); border-radius:17px; background:linear-gradient(145deg,rgba(16,38,54,.94),rgba(10,27,41,.92)); box-shadow:0 10px 35px rgba(0,0,0,.10); }
        .stat-top { display:flex; align-items:center; justify-content:space-between; gap:8px; color:var(--muted); font-size:12px; font-weight:600; }
        .stat-icon { width:30px; height:30px; display:grid; place-items:center; border-radius:10px; color:var(--accent); background:rgba(80,227,194,.09); font-size:15px; }
        .stat strong { display:block; margin-top:14px; overflow-wrap:anywhere; font:700 clamp(20px,2.3vw,27px) "Space Grotesk",sans-serif; letter-spacing:-.7px; }
        .stat strong small { font:600 12px "DM Sans",sans-serif; color:var(--muted); letter-spacing:0; }
        .main-grid { display:grid; grid-template-columns:minmax(0,1.45fr) minmax(260px,.75fr); gap:18px; align-items:stretch; padding-bottom:34px; }
        .panel { border:1px solid var(--line); border-radius:22px; background:linear-gradient(155deg,rgba(15,35,50,.98),rgba(10,26,39,.98)); box-shadow:var(--shadow); }
        .claim-panel { padding:clamp(22px,4vw,36px); }
        .panel-heading { display:flex; align-items:flex-start; gap:14px; margin-bottom:25px; }
        .heading-icon { flex:0 0 46px; width:46px; height:46px; display:grid; place-items:center; border-radius:15px; background:rgba(80,227,194,.10); color:var(--accent); font-size:22px; }
        .panel h2 { margin:0 0 7px; font:700 24px "Space Grotesk",sans-serif; letter-spacing:-.6px; }
        .sub { margin:0; color:var(--muted); font-size:14px; line-height:1.65; }
        .reward { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:22px; margin:0 0 25px; border:1px solid rgba(80,227,194,.22); border-radius:17px; background:linear-gradient(115deg,rgba(80,227,194,.12),rgba(185,246,106,.045)); }
        .reward-label { display:block; margin-bottom:6px; color:#a8c4cb; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; }
        .reward-amount { font:700 clamp(28px,4vw,38px) "Space Grotesk",sans-serif; letter-spacing:-1px; }
        .reward-amount em { color:var(--accent); font-style:normal; font-size:14px; letter-spacing:0; }
        .coin-tag { display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid var(--line); border-radius:12px; color:#d9e9ed; font-size:12px; font-weight:700; white-space:nowrap; }
        .coin-symbol { width:27px; height:27px; display:grid; place-items:center; border-radius:50%; background:#3459a5; color:white; font-weight:800; }
        .field { margin:0 0 18px; }
        .field label { display:block; margin-bottom:9px; color:#d9e8eb; font-size:13px; font-weight:700; }
        .field input { width:100%; padding:15px 16px; border:1px solid rgba(168,204,218,.20); border-radius:12px; outline:none; background:#081824; color:var(--text); font:500 14px "DM Sans",sans-serif; transition:border-color .2s,box-shadow .2s; }
        .field input::placeholder { color:#607987; }
        .field input:focus { border-color:var(--accent); box-shadow:0 0 0 4px rgba(80,227,194,.09); }
        .captcha { display:flex; justify-content:center; margin:22px 0; min-height:78px; overflow-x:auto; }
        .btn { width:100%; min-height:54px; display:flex; align-items:center; justify-content:center; gap:10px; padding:15px 20px; border:0; border-radius:13px; background:linear-gradient(100deg,var(--accent),var(--accent-2)); color:#061a20; font:800 15px "DM Sans",sans-serif; cursor:pointer; box-shadow:0 12px 30px rgba(80,227,194,.13); transition:transform .2s,filter .2s; }
        .btn:hover:not(:disabled) { transform:translateY(-2px); filter:brightness(1.05); }
        .btn:disabled { opacity:.55; cursor:not-allowed; }
        .secure-note { display:flex; justify-content:center; align-items:center; gap:7px; margin:15px 0 0; color:#78939e; font-size:11px; text-align:center; }
        .message { padding:14px 16px; margin-bottom:18px; border-radius:12px; font-size:13px; line-height:1.6; font-weight:600; }
        .success { background:rgba(80,227,194,.10); color:#a7f3d8; border:1px solid rgba(80,227,194,.22); }
        .error { background:rgba(255,124,134,.09); color:#ffadb4; border:1px solid rgba(255,124,134,.24); }
        .warning { background:rgba(255,199,94,.09); color:#ffdc98; border:1px solid rgba(255,199,94,.22); }
        .side-panel { padding:27px; display:flex; flex-direction:column; }
        .side-label { color:var(--accent); font-size:11px; font-weight:800; letter-spacing:1.5px; text-transform:uppercase; }
        .side-panel h2 { margin:12px 0 10px; font-size:23px; }
        .side-panel > p { margin-bottom:24px; color:var(--muted); font-size:13px; line-height:1.8; }
        .steps { display:grid; gap:20px; margin-top:3px; }
        .step { display:grid; grid-template-columns:35px 1fr; gap:12px; align-items:start; }
        .step-num { width:35px; height:35px; display:grid; place-items:center; border:1px solid rgba(80,227,194,.2); border-radius:11px; color:var(--accent); background:rgba(80,227,194,.07); font:700 13px "Space Grotesk",sans-serif; }
        .step h3 { margin:1px 0 5px; font-size:13px; }
        .step p { margin:0; color:var(--muted); font-size:12px; line-height:1.65; }
        .features { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; padding:0 0 58px; }
        .feature { padding:23px; border:1px solid var(--line); border-radius:17px; background:rgba(13,30,44,.72); }
        .feature-icon { width:38px; height:38px; display:grid; place-items:center; margin-bottom:16px; border-radius:12px; color:var(--accent); background:rgba(80,227,194,.09); font-size:18px; }
        .feature h3 { margin-bottom:8px; font:700 16px "Space Grotesk",sans-serif; }
        .feature p { margin:0; color:var(--muted); font-size:12px; line-height:1.8; }
        .footer { border-top:1px solid var(--line); padding:25px 0; color:#6f8b98; font-size:12px; }
        .footer-inner { display:flex; justify-content:space-between; align-items:center; gap:15px; }
        .footer strong { color:#cfe3e7; font:700 14px "Space Grotesk",sans-serif; }
        .footer-accent { color:var(--accent); }
        @media(max-width:850px) { .main-grid { grid-template-columns:1fr; } .side-panel { padding:24px; } .stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media(max-width:600px) { .shell { width:min(100% - 28px,1120px); } .nav { min-height:70px; } .brand { font-size:20px; } .brand-mark { width:36px;height:36px; } .online { padding:8px 10px;font-size:10px; } .hero { padding:48px 0 24px; } .hero h1 { letter-spacing:-2px; } .hero p { font-size:14px; } .stats { gap:9px; padding-top:12px; } .stat { padding:15px 13px; } .stat strong { margin-top:11px; } .stat-top { font-size:11px; } .stat-icon { width:27px;height:27px; } .claim-panel { padding:21px 17px; } .reward { padding:17px; } .coin-tag { padding:8px; font-size:11px; } .features { grid-template-columns:1fr; gap:10px; padding-bottom:38px; } .feature { padding:19px; } .feature-icon { margin-bottom:10px; } .footer-inner { flex-direction:column; text-align:center; } }
        @media(prefers-reduced-motion:reduce) { *,*::before,*::after { scroll-behavior:auto!important; transition:none!important; } }
    </style>
</head>
<body>
<header class="topbar">
    <div class="shell nav">
        <a class="brand" href="#" aria-label="Clicxfaucet home">
            <span class="brand-mark">₿</span>
            <span class="brand-name">Clicx<span>faucet</span></span>
        </a>
        <div class="online"><span class="online-dot"></span> FAUCET ONLINE</div>
    </div>
</header>
    
<section class="hero shell">
    <div class="eyebrow"><span class="eyebrow-mark">✦</span> SIMPLE. FAST. SECURE.</div>
    <h1>Your daily dose of<br><span>free Litecoin.</span></h1>
    <p>Claim your LTC reward in a few simple steps. Every successful payout is sent directly to your FaucetPay account.</p>
</section>
    
<section class="stats shell" aria-label="Faucet statistics">
    <div class="stat"><div class="stat-top"><span>Reward per claim</span><span class="stat-icon">↗</span></div><strong><?= number_format($payout_amount) ?> <small>SAT</small></strong></div>
    <div class="stat"><div class="stat-top"><span>Total claims</span><span class="stat-icon">◎</span></div><strong><?= number_format($stats['claims']) ?></strong></div>
    <div class="stat"><div class="stat-top"><span>Total paid</span><span class="stat-icon">◈</span></div><strong><?= rtrim(rtrim(number_format($paid_ltc, 8, '.', ''), '0'), '.') ?> <small>LTC</small></strong></div>
    
    <div class="stat"><div class="stat-top"><span>Days online</span><span class="stat-icon">◷</span></div><strong><?= number_format($days_online) ?> <small>DAYS</small></strong></div>
</section>

<main class="shell main-grid">
    <section class="panel claim-panel">
        <div class="panel-heading">
            <div class="heading-icon">↯</div>
            <div><h2>Claim your reward</h2><p class="sub">Enter your Litecoin FaucetPay address to get started.</p></div>
            
        </div>
        <?php if ($message !== ''): ?><div class="message <?= clean_message($message_type) ?>" role="status"><?= clean_message($message) ?></div><?php endif; ?>
        <div class="reward">
            <div><span class="reward-label">Your current reward</span><div class="reward-amount"><?= number_format($payout_amount) ?> <em>SAT</em></div></div>
            <div class="coin-tag"><span class="coin-symbol">Ł</span> Litecoin · LTC</div>
        </div>
        
        <form method="POST" id="claimForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= clean_message($_SESSION['csrf_token']) ?>">
            <div class="field"><label for="wallet">FaucetPay LTC wallet address</label><input id="wallet" type="text" name="wallet" placeholder="Paste your LTC wallet address" maxlength="120" required></div>
            <div class="captcha"><div class="g-recaptcha" data-sitekey="<?= clean_message($recaptcha_site_key) ?>"></div></div>
            <button class="btn" type="submit" <?= $remaining_time > 0 ? 'disabled' : '' ?>><?= $remaining_time > 0 ? 'Please wait ' . $remaining_time . 's' : 'Claim free LTC  →' ?></button>
            <p class="secure-note"><span>◆</span> Protected with reCAPTCHA and secure request verification</p>
        </form>
    </section>

    <aside class="panel side-panel">
        <span class="side-label">HOW IT WORKS</span>
        <h2>Three steps.<br>One easy claim.</h2>
        <p>Getting your Litecoin reward is designed to be straightforward.</p>
        <div class="steps">
            <div class="step"><span class="step-num">01</span><div><h3>Enter your wallet</h3><p>Use your Litecoin address registered with FaucetPay.</p></div></div>
            <div class="step"><span class="step-num">02</span><div><h3>Verify you're human</h3><p>Complete the security check before submitting your claim.</p></div></div>
            <div class="step"><span class="step-num">03</span><div><h3>Receive your reward</h3><p>Your claim is processed through the FaucetPay payout service.</p></div></div>
        </div>
    </aside>
</main>
    
<section class="shell features" aria-label="Platform features">
    <article class="feature"><div class="feature-icon">ϟ</div><h3>Quick claims</h3><p>A simple claim flow with a short waiting period between successful requests.</p></article>
    <article class="feature"><div class="feature-icon">⌑</div><h3>Security first</h3><p>Human verification and request protection help keep claims safer.</p></article>
    <article class="feature"><div class="feature-icon">◈</div><h3>FaucetPay payouts</h3><p>Rewards are requested in LTC through the FaucetPay payment service.</p></article>
</section>

<footer class="footer"><div class="shell footer-inner"><strong>Clicx<span class="footer-accent">faucet</span></strong><span>Free Litecoin rewards · Powered by FaucetPay</span><span>Built for a simpler claim experience.</span></div></footer>
</body>
</html>
