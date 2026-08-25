<?php
/* ======================================================
   APP CHOOSER — SM Produce LTD
   Shown right after login: Apple App vs Cherry App
====================================================== */
require_once __DIR__ . '/config/user_functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user']) && empty($_SESSION['logged_in'])) {
    header('Location: /auth/login.php'); exit;
}

$fullName = htmlspecialchars($_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$avatar   = htmlspecialchars($_SESSION['user']['avatar'] ?? '', ENT_QUOTES, 'UTF-8');

if (!function_exists('ch_front_env')) {
    function ch_front_env(string $key, $default = '') {
        $value = getenv($key);
        if ($value === false || $value === null || $value === '') return $default;
        return $value;
    }
}
if (!function_exists('ch_front_b64u')) {
    function ch_front_b64u(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
if (!function_exists('ch_front_make_cherry_token')) {
    function ch_front_make_cherry_token(array $user, int $ttl = 120): string {
        $secret = (string) ch_front_env('CHERRY_SSO_SECRET', 'CHANGE-ME-CHERRY-SSO-SECRET');
        $now = time();
        $payload = [
            'iat' => $now,
            'exp' => $now + max(30, $ttl),
            'user' => [
                'id' => (int)($user['id'] ?? 0),
                'username' => (string)($user['username'] ?? ''),
                'full_name' => (string)($user['full_name'] ?? ''),
                'avatar' => (string)($user['avatar'] ?? ($user['avatar_url'] ?? '')),
                'avatar_url' => (string)($user['avatar_url'] ?? ($user['avatar'] ?? '')),
                'role' => (string)($user['role'] ?? 'viewer'),
                'permissions' => array_values(array_filter((array)($user['permissions'] ?? []), 'is_string')),
            ],
        ];
        $payload64 = ch_front_b64u(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig64 = ch_front_b64u(hash_hmac('sha256', $payload64, $secret, true));
        return $payload64 . '.' . $sig64;
    }
}
require_once __DIR__ . '/config/app.php';
$canBridge = !empty($_SESSION['user']) && function_exists('sp_cherry_bridge_url') && function_exists('ch_sso_secret_ready') && ch_sso_secret_ready();
$needsCherryCredentials = !$canBridge;
$cherryApiLoginUrl = ch_build_public_url(CHERRY_APP_BASE_PATH . '/auth/api_login.php');
if ($canBridge) {
    $cherryHref = sp_cherry_bridge_url($_SESSION['user'] ?? [], sp_cherry_entry_target());
    $timeHref   = sp_cherry_bridge_url($_SESSION['user'] ?? [], CHERRY_APP_BASE_PATH . '/time/');
} else {
    $cherryHref = '#';
    $timeHref   = '#';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SM Produce — Choose App</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
  width: 100%; height: 100%;
  font-family: 'Inter', system-ui, sans-serif;
  overflow: hidden;
}

/* ── Animated gradient background ── */
body {
  background: linear-gradient(135deg, #0a0f1e 0%, #101827 40%, #0d1e12 70%, #1a0a0f 100%);
  background-size: 400% 400%;
  animation: bgShift 18s ease infinite;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  position: relative;
}
@keyframes bgShift {
  0%   { background-position: 0% 50%; }
  50%  { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}

/* ── Starfield dots ── */
body::before {
  content: '';
  position: fixed; inset: 0;
  background-image:
    radial-gradient(1px 1px at 20%  15%, rgba(255,255,255,.25) 0%, transparent 100%),
    radial-gradient(1px 1px at 80%  10%, rgba(255,255,255,.18) 0%, transparent 100%),
    radial-gradient(1px 1px at 50%  60%, rgba(255,255,255,.12) 0%, transparent 100%),
    radial-gradient(1.5px 1.5px at 30% 80%, rgba(255,255,255,.20) 0%, transparent 100%),
    radial-gradient(1px 1px at 70%  40%, rgba(255,255,255,.15) 0%, transparent 100%),
    radial-gradient(1.5px 1.5px at 10% 55%, rgba(255,255,255,.10) 0%, transparent 100%),
    radial-gradient(1px 1px at 90%  75%, rgba(255,255,255,.14) 0%, transparent 100%),
    radial-gradient(2px 2px at 60%  25%, rgba(255,255,255,.08) 0%, transparent 100%);
  pointer-events: none;
  z-index: 0;
}

/* ── Main wrapper ── */
.chooser-wrap {
  position: relative; z-index: 10;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0;
  width: 100%;
  max-width: 1000px;
  padding: 20px 24px 30px;
}

/* ── Header: logo + title ── */
.ch-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 40px;
  animation: fadeSlideDown .7s ease both;
}
.ch-logo {
  height: 100px;
  width: auto;
  margin-bottom: 14px;
  filter: drop-shadow(0 4px 20px rgba(0,0,0,.5));
}
.ch-title {
  font-size: 28px;
  font-weight: 900;
  color: #f1f5f9;
  letter-spacing: -0.5px;
  text-shadow: 0 2px 12px rgba(0,0,0,.4);
}
.ch-subtitle {
  font-size: 14px;
  color: #64748b;
  margin-top: 6px;
}
.ch-welcome {
  font-size: 13px;
  color: #475569;
  margin-top: 4px;
}

/* ── Cards row ── */
.ch-cards {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 22px;
  align-items: stretch;
  width: 100%;
  max-width: 1080px;
}
@media (max-width: 1100px) {
  .ch-cards { grid-template-columns: 1fr; max-width: 420px; }
}

/* ── Single card ── */
.ch-card {
  position: relative;
  width: 100%;
  min-height: 380px;
  border-radius: 28px;
  cursor: pointer;
  text-decoration: none;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px 30px 36px;
  overflow: hidden;
  transition: transform .25s cubic-bezier(.34,1.56,.64,1),
              box-shadow .25s ease;
  animation: fadeSlideUp .65s ease both;
}
.ch-card:nth-child(1) { animation-delay: .1s; }
.ch-card:nth-child(2) { animation-delay: .22s; }
.ch-card:nth-child(3) { animation-delay: .34s; }

.ch-card:hover {
  transform: translateY(-10px) scale(1.03);
}

/* ── Apple card — green theme ── */
.ch-card.apple {
  background: linear-gradient(145deg, #0d3320 0%, #14532d 40%, #166534 100%);
  border: 1.5px solid rgba(34,197,94,.25);
  box-shadow:
    0 20px 60px rgba(0,0,0,.5),
    0 0 0 1px rgba(34,197,94,.1),
    inset 0 1px 0 rgba(255,255,255,.06);
}
.ch-card.apple:hover {
  box-shadow:
    0 30px 80px rgba(0,0,0,.55),
    0 0 40px rgba(34,197,94,.25),
    0 0 0 1px rgba(34,197,94,.3),
    inset 0 1px 0 rgba(255,255,255,.08);
}

/* ── Cherry card — red theme ── */
.ch-card.cherry {
  background: linear-gradient(145deg, #2d0a14 0%, #4c0519 40%, #7f1d1d 100%);
  border: 1.5px solid rgba(248,113,113,.2);
  box-shadow:
    0 20px 60px rgba(0,0,0,.5),
    0 0 0 1px rgba(248,113,113,.1),
    inset 0 1px 0 rgba(255,255,255,.06);
}
.ch-card.cherry:hover {
  box-shadow:
    0 30px 80px rgba(0,0,0,.55),
    0 0 40px rgba(239,68,68,.25),
    0 0 0 1px rgba(248,113,113,.3),
    inset 0 1px 0 rgba(255,255,255,.08);
}

/* ── Time card — blue theme ── */
.ch-card.time {
  background: linear-gradient(145deg, #0b1733 0%, #1e3a8a 45%, #2563eb 100%);
  border: 1.5px solid rgba(96,165,250,.24);
  box-shadow:
    0 20px 60px rgba(0,0,0,.5),
    0 0 0 1px rgba(96,165,250,.1),
    inset 0 1px 0 rgba(255,255,255,.06);
}
.ch-card.time:hover {
  box-shadow:
    0 30px 80px rgba(0,0,0,.55),
    0 0 40px rgba(59,130,246,.25),
    0 0 0 1px rgba(96,165,250,.3),
    inset 0 1px 0 rgba(255,255,255,.08);
}

/* ── Card glow ring ── */
.ch-card::before {
  content: '';
  position: absolute;
  inset: -2px;
  border-radius: 30px;
  opacity: 0;
  transition: opacity .3s ease;
  z-index: -1;
}
.ch-card.apple::before  { background: radial-gradient(ellipse at 50% 0%, rgba(34,197,94,.3) 0%, transparent 60%); }
.ch-card.cherry::before { background: radial-gradient(ellipse at 50% 0%, rgba(239,68,68,.3) 0%, transparent 60%); }
.ch-card.time::before   { background: radial-gradient(ellipse at 50% 0%, rgba(59,130,246,.3) 0%, transparent 60%); }
.ch-card:hover::before  { opacity: 1; }

/* ── Emoji fruit ── */
.ch-fruit {
  font-size: 150px;
  line-height: 1;
  margin-bottom: 20px;
  filter: drop-shadow(0 8px 24px rgba(0,0,0,.4));
  transition: transform .25s cubic-bezier(.34,1.56,.64,1);
  user-select: none;
}
.ch-card:hover .ch-fruit {
  transform: scale(1.15) rotate(-4deg);
}

/* ── Card text ── */
.ch-card-title {
  font-size: 22px;
  font-weight: 800;
  color: #f1f5f9;
  text-align: center;
  margin-bottom: 10px;
  letter-spacing: -0.3px;
}
.ch-card-desc {
  font-size: 13px;
  color: rgba(241,245,249,.55);
  text-align: center;
  line-height: 1.6;
  margin-bottom: 28px;
  max-width: 240px;
}

/* ── Card CTA button ── */
.ch-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 13px 28px;
  border-radius: 999px;
  font-size: 14px;
  font-weight: 700;
  letter-spacing: .3px;
  border: none;
  cursor: pointer;
  transition: all .2s ease;
  text-decoration: none;
  position: relative;
  overflow: hidden;
}
.ch-btn::after {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,0);
  transition: background .15s;
  border-radius: inherit;
}
.ch-btn:hover::after { background: rgba(255,255,255,.12); }

.ch-card.apple  .ch-btn { background: #16a34a; color: #fff; box-shadow: 0 4px 16px rgba(22,163,74,.4); }
.ch-card.cherry .ch-btn { background: #dc2626; color: #fff; box-shadow: 0 4px 16px rgba(220,38,38,.4); }
.ch-card.time   .ch-btn { background: #2563eb; color: #fff; box-shadow: 0 4px 16px rgba(37,99,235,.4); }

/* ── Arrow icon ── */
.ch-arrow {
  font-size: 16px;
  transition: transform .2s ease;
}
.ch-card:hover .ch-arrow { transform: translateX(4px); }

/* ── Badge "New" for cherry ── */
.ch-badge-new {
  position: absolute;
  top: 20px; right: 20px;
  background: linear-gradient(135deg, #f97316, #dc2626);
  color: #fff;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: .5px;
  text-transform: uppercase;
  padding: 4px 10px;
  border-radius: 999px;
  box-shadow: 0 2px 8px rgba(0,0,0,.3);
  animation: pulseBadge 2.5s ease-in-out infinite;
}
@keyframes pulseBadge {
  0%,100% { opacity:1; transform:scale(1); }
  50%      { opacity:.85; transform:scale(1.06); }
}

/* ── Card tag (top-left small label) ── */
.ch-card-tag {
  position: absolute;
  top: 20px; left: 20px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .5px;
  text-transform: uppercase;
  border-radius: 8px;
  padding: 4px 9px;
}
.ch-card.apple  .ch-card-tag { background: rgba(34,197,94,.15); color: #4ade80; }
.ch-card.cherry .ch-card-tag { background: rgba(248,113,113,.15); color: #fca5a5; }
.ch-card.time   .ch-card-tag { background: rgba(96,165,250,.15); color: #93c5fd; }

/* ── Features list inside card ── */
.ch-features {
  list-style: none;
  margin: 0 0 24px;
  padding: 0;
  width: 100%;
  max-width: 240px;
}
.ch-features li {
  font-size: 12px;
  color: rgba(241,245,249,.5);
  padding: 4px 0;
  display: flex;
  align-items: center;
  gap: 8px;
}
.ch-features li::before {
  content: '';
  width: 5px; height: 5px;
  border-radius: 50%;
  flex-shrink: 0;
}
.ch-card.apple  .ch-features li::before { background: #4ade80; }
.ch-card.cherry .ch-features li::before { background: #fca5a5; }
.ch-card.time   .ch-features li::before { background: #93c5fd; }

/* ── Divider ── */
.ch-divider {
  width: 1px;
  height: 340px;
  background: linear-gradient(to bottom, transparent 0%, rgba(255,255,255,.08) 30%, rgba(255,255,255,.08) 70%, transparent 100%);
  align-self: center;
  flex-shrink: 0;
}
@media (max-width: 640px) {
  .ch-divider { width: 280px; height: 1px; }
}

/* ── Footer ── */
.ch-footer {
  margin-top: 36px;
  display: flex;
  align-items: center;
  gap: 16px;
  animation: fadeSlideUp .7s .35s ease both;
}
.ch-footer-logout {
  font-size: 12px;
  color: #334155;
  text-decoration: none;
  padding: 6px 14px;
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 999px;
  transition: all .15s;
}
.ch-footer-logout:hover {
  color: #94a3b8;
  border-color: rgba(255,255,255,.18);
  background: rgba(255,255,255,.04);
}
.ch-footer-dot { width: 3px; height: 3px; background: #1e293b; border-radius: 50%; }
.ch-footer-copy { font-size: 11px; color: #1e293b; }

/* ── Animations ── */
@keyframes fadeSlideDown {
  from { opacity:0; transform: translateY(-20px); }
  to   { opacity:1; transform: translateY(0); }
}
@keyframes fadeSlideUp {
  from { opacity:0; transform: translateY(24px); }
  to   { opacity:1; transform: translateY(0); }
}
.ch-modal[hidden]{display:none!important}
.ch-modal{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px}
.ch-modal-backdrop{position:absolute;inset:0;background:rgba(2,6,23,.72);backdrop-filter:blur(8px)}
.ch-modal-card{position:relative;z-index:1;width:100%;max-width:480px;border-radius:28px;padding:28px;background:linear-gradient(180deg,rgba(15,23,42,.96),rgba(17,24,39,.98));border:1px solid rgba(255,255,255,.12);box-shadow:0 30px 90px rgba(0,0,0,.45)}
.ch-modal-close{position:absolute;top:14px;right:16px;border:none;background:transparent;color:#cbd5e1;font-size:30px;cursor:pointer}
.ch-modal-kicker{font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#fca5a5;margin-bottom:10px}
.ch-modal-title{margin:0 0 10px;font-size:28px;line-height:1.1;color:#f8fafc}
.ch-modal-text{margin:0 0 18px;color:#94a3b8;font-size:14px;line-height:1.6}
.ch-modal-error{margin:0 0 14px;padding:12px 14px;border-radius:14px;background:rgba(127,29,29,.35);border:1px solid rgba(248,113,113,.35);color:#fee2e2;font-size:14px}
.ch-modal-label{display:block;margin:14px 0 8px;color:#e2e8f0;font-size:14px;font-weight:700}
.ch-modal-input{width:100%;padding:15px 16px;border-radius:16px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#fff;font-size:17px;outline:none}
.ch-modal-input:focus{border-color:rgba(239,68,68,.45);box-shadow:0 0 0 4px rgba(239,68,68,.12)}
.ch-modal-btn{width:100%;margin-top:18px;padding:15px 18px;border:none;border-radius:16px;background:linear-gradient(135deg,#be123c,#9f1239);color:#fff;font-size:17px;font-weight:800;cursor:pointer;box-shadow:0 16px 38px rgba(159,18,57,.34)}
.ch-modal-btn:disabled{opacity:.72;cursor:wait}
</style>
</head>
<body>

<div class="chooser-wrap">

  <!-- ── Header ── -->
  <div class="ch-header">
    <img src="/logo/logo.png" alt="SM Produce" class="ch-logo">
    <div class="ch-title">SM Produce LTD</div>
    <div class="ch-subtitle">Production Management System</div>
    <div class="ch-welcome">👋 Welcome back, <strong style="color:#94a3b8"><?= $fullName ?></strong> — choose your module</div>
  </div>

  <!-- ── Cards ── -->
  <div class="ch-cards">

    <!-- ── APPLE CARD ── -->
    <a href="/pages/dashboard_report.php" class="ch-card apple">
      <div class="ch-fruit">🍎</div>
      <div class="ch-card-title">Apple Packing</div>
      <div class="ch-card-desc">Open the Apple packing web app with the current shared login.</div>
      <ul class="ch-features"></ul>
      <span class="ch-btn">
        Enter Apple Module
        <span class="ch-arrow">→</span>
      </span>
    </a>

    <!-- ── CHERRY CARD ── -->
    <a href="<?= $cherryHref ?>" class="ch-card cherry<?= $needsCherryCredentials ? ' js-cherry-auth' : '' ?>"<?= $needsCherryCredentials ? ' data-module="cherry" data-target="' . h(sp_cherry_entry_target()) . '"' : '' ?>>
      <div class="ch-fruit">🍒</div>
      <div class="ch-card-title">Cherry Packing</div>
      <div class="ch-card-desc">Open the Cherry packing web app directly from the shared login.</div>
      <ul class="ch-features"></ul>
      <span class="ch-btn">
        Enter Cherry Module
        <span class="ch-arrow">→</span>
      </span>
    </a>

    <!-- ── TIME CARD ── -->
    <a href="<?= $timeHref ?>" class="ch-card time<?= $needsCherryCredentials ? ' js-cherry-auth' : '' ?>"<?= $needsCherryCredentials ? ' data-module="time" data-target="' . h(CHERRY_APP_BASE_PATH . '/time/') . '"' : '' ?>>
      <div class="ch-fruit">⏱️</div>
      <div class="ch-card-title">Time</div>
      <div class="ch-card-desc">Use Cherry credentials to open the Time web app directly.</div>
      <ul class="ch-features"></ul>
      <span class="ch-btn">
        Enter Time Module
        <span class="ch-arrow">→</span>
      </span>
    </a>

  </div><!-- /cards -->

  <!-- ── Footer ── -->
  <div class="ch-footer">
    <a href="/auth/logout.php" class="ch-footer-logout">⬅ Logout</a>
    <span class="ch-footer-dot"></span>
    <span class="ch-footer-copy">© <?= date('Y') ?> SM Produce LTD</span>
  </div>

</div><!-- /chooser-wrap -->

<?php if ($needsCherryCredentials): ?>
<div id="cherryAuthModal" class="ch-modal" hidden>
  <div class="ch-modal-backdrop"></div>
  <div class="ch-modal-card" role="dialog" aria-modal="true" aria-labelledby="cherryAuthTitle">
    <button type="button" class="ch-modal-close" id="cherryAuthClose" aria-label="Close">×</button>
    <div class="ch-modal-kicker">Cherry access</div>
    <h2 id="cherryAuthTitle" class="ch-modal-title">Apri Cherry o Time senza una seconda pagina di login</h2>
    <p class="ch-modal-text">Inserisci qui le credenziali Cherry. Se sono corrette, il modulo selezionato si apre direttamente.</p>
    <div id="cherryAuthError" class="ch-modal-error" hidden></div>
    <form id="cherryAuthForm">
      <input type="hidden" id="cherryAuthReturn" value="">
      <input type="hidden" id="cherryAuthModule" value="">
      <label class="ch-modal-label" for="cherryAuthUsername">Username Cherry</label>
      <input class="ch-modal-input" id="cherryAuthUsername" autocomplete="username">
      <label class="ch-modal-label" for="cherryAuthPassword">Password Cherry</label>
      <input class="ch-modal-input" id="cherryAuthPassword" type="password" autocomplete="current-password">
      <button class="ch-modal-btn" type="submit" id="cherryAuthSubmit">Apri modulo</button>
    </form>
  </div>
</div>
<script>
(function(){
  const modal = document.getElementById('cherryAuthModal');
  const closeBtn = document.getElementById('cherryAuthClose');
  const form = document.getElementById('cherryAuthForm');
  const errorBox = document.getElementById('cherryAuthError');
  const submitBtn = document.getElementById('cherryAuthSubmit');
  const returnField = document.getElementById('cherryAuthReturn');
  const moduleField = document.getElementById('cherryAuthModule');
  const userField = document.getElementById('cherryAuthUsername');
  const passField = document.getElementById('cherryAuthPassword');
  const apiUrl = <?= json_encode($cherryApiLoginUrl) ?>;

  function openModal(moduleName, target){
    moduleField.value = moduleName || '';
    returnField.value = target || '';
    errorBox.hidden = true;
    errorBox.textContent = '';
    passField.value = '';
    modal.hidden = false;
    setTimeout(() => userField.focus(), 20);
  }

  function closeModal(){
    modal.hidden = true;
  }

  document.querySelectorAll('.js-cherry-auth').forEach(link => {
    link.addEventListener('click', function(e){
      e.preventDefault();
      openModal(this.getAttribute('data-module'), this.getAttribute('data-target'));
    });
  });

  closeBtn.addEventListener('click', closeModal);
  modal.querySelector('.ch-modal-backdrop').addEventListener('click', closeModal);
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && !modal.hidden) closeModal();
  });

  form.addEventListener('submit', async function(e){
    e.preventDefault();
    const username = userField.value.trim();
    const password = passField.value || '';
    if (!username || !password) {
      errorBox.textContent = 'Inserisci username e password Cherry.';
      errorBox.hidden = false;
      return;
    }

    submitBtn.disabled = true;
    const oldLabel = submitBtn.textContent;
    submitBtn.textContent = 'Apertura in corso...';

    try {
      const body = new URLSearchParams();
      body.set('username', username);
      body.set('password', password);
      body.set('return', returnField.value || '');

      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/json'},
        body: body.toString(),
        credentials: 'same-origin'
      });

      const data = await res.json().catch(() => ({}));
      if (data && data.ok && data.redirect) {
        window.location.href = data.redirect;
        return;
      }

      errorBox.textContent = (data && data.message) ? data.message : 'Login Cherry non riuscito.';
      errorBox.hidden = false;
    } catch (err) {
      errorBox.textContent = 'Impossibile contattare il login Cherry.';
      errorBox.hidden = false;
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = oldLabel;
    }
  });
})();
</script>
<?php endif; ?>

</body>
</html>
