<?php
/* ======================================================
   SM Produce LTD — Root entry point
   Auto-detects mobile → mobile_login.php
   Desktop → existing login.php (unchanged)
====================================================== */
$secure = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
);
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure'   => $secure, 'httponly' => true, 'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) session_start();

$ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMob = (bool) preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua);

$mode = trim($_GET['mode'] ?? '');
if ($mode === 'mobile')  $isMob = true;
if ($mode === 'desktop') $isMob = false;

if ($isMob) {
    header('Location: /auth/mobile_login.php');
} else {
    header(!empty($_SESSION['user'])
        ? 'Location: /pages/dashboard_report.php'
        : 'Location: /auth/login.php');
}
exit;
