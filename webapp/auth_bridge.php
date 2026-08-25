<?php
require_once __DIR__ . '/config/cherry_bootstrap.php';

if (!function_exists('sp_safe_local_target')) {
    function sp_safe_local_target(?string $target, string $default): string {
        $target = trim((string)$target);
        if ($target === '') return $default;
        if (preg_match('#^https?://#i', $target)) return $default;
        if ($target[0] !== '/') $target = '/' . $target;
        return $target;
    }
}

if (!function_exists('sp_app_base_path')) {
    function sp_app_base_path(): string {
        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/auth_bridge.php');
        $scriptName = '/' . ltrim($scriptName, '/');
        return ($scriptName === '/auth_bridge.php') ? '' : rtrim((string)dirname($scriptName), '/.');
    }
}

if (!function_exists('sp_default_dashboard_target')) {
    function sp_default_dashboard_target(): string {
        return sp_app_base_path() . '/pages/dashboard_report.php';
    }
}

if (!function_exists('sp_default_login_target')) {
    function sp_default_login_target(): string {
        return sp_app_base_path() . '/auth/login.php';
    }
}

$defaultTarget = sp_default_dashboard_target();
$target = sp_safe_local_target($_GET['return'] ?? $_POST['return'] ?? $defaultTarget, $defaultTarget);
$token = $_GET['cherry_token'] ?? $_POST['cherry_token'] ?? $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';

if (!empty($_SESSION['user']) && $token === '') {
    header('Location: ' . $target);
    exit;
}

if ($token !== '') {
    if (ch_apply_bridge_login_from_token($token)) {
        header('Location: ' . $target);
        exit;
    }
    $result = ch_validate_bridge_token($token);
    $error = (string)($result['error'] ?? 'invalid_token');
} elseif (!empty($_SESSION['user'])) {
    header('Location: ' . $target);
    exit;
} else {
    header('Location: ' . sp_default_login_target() . '?return=' . rawurlencode($target));
    exit;
}

$errorMap = [
    'missing_secret' => 'Apple/Cherry SSO secret is not configured on this server.',
    'missing_token' => 'Missing switch token.',
    'bad_signature' => 'Invalid switch token signature.',
    'bad_payload' => 'Invalid switch token payload.',
    'expired' => 'Switch token expired. Please sign in again.',
    'invalid_token' => 'Unable to complete Apple sign-in.',
];
$message = $errorMap[$error] ?? 'Unable to complete Apple sign-in.';
?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Apple sign-in bridge</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh;">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card shadow-sm border-0">
        <div class="card-body p-4 p-lg-5">
          <h2 class="h4 mb-3">Apple sign-in bridge</h2>
          <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
          <p class="text-muted mb-4">Il passaggio automatico da Cherry ad Apple non è riuscito. Verifica che la stessa <code>CHERRY_SSO_SECRET</code> sia configurata su entrambi i lati.</p>
          <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-primary" href="<?php echo htmlspecialchars(sp_default_login_target(), ENT_QUOTES, 'UTF-8'); ?>">Apri login Apple</a>
            <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars($target, ENT_QUOTES, 'UTF-8'); ?>">Apri destinazione</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
