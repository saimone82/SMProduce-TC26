<?php
// config/user_functions.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_remote.php'; // must define $conn = new mysqli(...)

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Normalize permission keys.
 * Standard: page:<basename.php>
 * Legacy supported:
 *   - "settings" -> also checks "page:settings.php"
 *   - "orders.php" -> treated as "page:orders.php"
 */
function sp_normalize_perm($permission) {
    $p = trim((string)$permission);
    if ($p === '') return '';

    if (stripos($p, 'page:') === 0) {
        $x = substr($p, 5);
        $x = basename($x);
        if (stripos($x, '.php') === false) $x .= '.php';
        return 'page:' . $x;
    }

    // if user passes a page filename such as "orders.php"
    if (stripos($p, '.php') !== false) {
        return 'page:' . basename($p);
    }

    // legacy "settings" style
    return $p;
}

/* =========================
   USERS
========================= */

function getUserByUsername($username) {
    global $conn;
    $sql = "SELECT id, username, password_hash, full_name, avatar_url, role, is_active
            FROM users WHERE username = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param("s", $username);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function getUserById($id) {
    global $conn;
    $sql = "SELECT id, username, full_name, avatar_url, role, is_active
            FROM users WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function updateUserLastLogin($user_id) {
    global $conn;
    $sql = "UPDATE users SET updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }
}

function getUserPermissions($user_id) {
    global $conn;
    $sql = "SELECT permission FROM user_permissions WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $perms = [];
    while ($row = $result->fetch_assoc()) {
        $perms[] = (string)$row['permission'];
    }
    return $perms;
}

function authenticate($username, $password) {
    $user = getUserByUsername($username);
    if (!$user) return false;
    if (empty($user['is_active'])) return false;
    if (!password_verify($password, $user['password_hash'])) return false;

    // attach permissions in session
    $user['permissions'] = getUserPermissions((int)$user['id']);
    $_SESSION['user'] = $user;

    updateUserLastLogin((int)$user['id']);
    return $user;
}

function requireLogin() {
    if (!isset($_SESSION['user'])) {
        header("Location: /auth/login.php");
        exit;
    }
}

function userHasRole($role) {
    return isset($_SESSION['user']) && (($_SESSION['user']['role'] ?? null) === $role);
}

/**
 * Main permission checker
 * - admin: always true
 * - non-admin: checks exact perm + normalized page perm variants
 */
function userHasPermission($permission) {
    if (!isset($_SESSION['user'])) return false;

    if (($_SESSION['user']['role'] ?? '') === 'admin') return true;

    if (!isset($_SESSION['user']['permissions']) || !is_array($_SESSION['user']['permissions'])) {
        $_SESSION['user']['permissions'] = getUserPermissions((int)$_SESSION['user']['id']);
    }

    $perms = $_SESSION['user']['permissions'];

    $p = sp_normalize_perm($permission);
    if ($p === '') return false;

    // direct match
    if (in_array($p, $perms, true)) return true;

    // legacy mapping: "settings" => also accept page:settings.php if requested was "settings"
    if (strpos($p, 'page:') !== 0 && preg_match('/^[a-z0-9_\-]+$/i', $p)) {
        $alt = 'page:' . $p . '.php';
        if (in_array($alt, $perms, true)) return true;
    }

    return false;
}

function userLogout() {
    session_unset();
    session_destroy();
}

/* Backward-compatible aliases */
function user_has_role($role) { return userHasRole($role); }
function user_has_permission($p) { return userHasPermission($p); }



function require_permission($perm) {
    if (!userHasPermission($perm)) {
        http_response_code(403);
        die('Forbidden');
    }
}
/* =========================
   PERMISSION CATALOG (A)
   - Generates "page:<file.php>" permissions from /pages
========================= */

function sp_pages_dir() {
    return realpath(__DIR__ . '/../pages');
}

function available_permissions() {
    $dir = sp_pages_dir();
    $out = [];

    if (!$dir || !is_dir($dir)) {
        return $out;
    }

    $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
    sort($files);

    foreach ($files as $f) {
        $base = basename($f);

        // exclude internal helpers or deprecated stuff if any
        if (in_array($base, ['save_scanner_settings.php'], true)) continue;

        // You can choose to hide users.php from non-admin by default
        // but still keep it as permission
        $key = 'page:' . $base;

        // Label is pretty: "Production Summary (production_summary.php)"
        $label = ucwords(str_replace(['_', '-'], ' ', pathinfo($base, PATHINFO_FILENAME))) . " ({$base})";
        $out[$key] = $label;
    }

    // Extra "capability" permissions if you ever need them later:
    // $out['cap:manage_users'] = 'Manage Users';
    return $out;
}

/**
 * Check if current user can see a page (by basename).
 * Example: sp_can_access_page('settings.php')
 */
function sp_can_access_page($pageBasename) {
    $b = basename((string)$pageBasename);
    if ($b === '') return false;
    return userHasPermission('page:' . $b);
}

/* =========================
   SECTION-LEVEL PERMISSIONS
   Format: "section:{key}:read"  | "section:{key}:write" | "section:{key}:admin"
   Presence of any of the above means the section is visible in the sidebar.
========================= */

/**
 * Return the permission level ('read','write','admin') for a section,
 * or '' if the user has no access.
 * Admin role always returns 'admin'.
 */
function user_get_section_level(string $sectionKey): string {
    if (!isset($_SESSION['user'])) return '';
    if (($_SESSION['user']['role'] ?? '') === 'admin') return 'admin';

    if (!isset($_SESSION['user']['permissions']) || !is_array($_SESSION['user']['permissions'])) {
        $_SESSION['user']['permissions'] = getUserPermissions((int)$_SESSION['user']['id']);
    }
    $perms = $_SESSION['user']['permissions'];
    $k = 'section:' . $sectionKey . ':';
    foreach (['admin','write','read'] as $lvl) {
        if (in_array($k . $lvl, $perms, true)) return $lvl;
    }
    return '';
}

/**
 * Returns true if the user can see/access the given sidebar section.
 */
function user_has_section_perm(string $sectionKey): bool {
    return user_get_section_level($sectionKey) !== '';
}

/**
 * Returns true if the user has at least write access to the section.
 */
function user_can_write_section(string $sectionKey): bool {
    $lvl = user_get_section_level($sectionKey);
    return in_array($lvl, ['write','admin'], true);
}

/**
 * Returns true if the user has admin access to the section.
 */
function user_is_section_admin(string $sectionKey): bool {
    return user_get_section_level($sectionKey) === 'admin';
}

/* =========================
   sp_allow() — unified access check
   Returns true if:
     • user is admin (always passes), OR
     • user role is in $allowedRoles, OR
     • user has any section permission for $sectionKey
   Use this instead of raw in_array($role, [...]) guards.
========================= */
function sp_allow(string $sectionKey, array $allowedRoles = []): bool {
    if (!isset($_SESSION['user'])) return false;
    $role = strtolower((string)($_SESSION['user']['role'] ?? ''));
    if ($role === 'admin') return true;
    if (!empty($allowedRoles) && in_array($role, $allowedRoles, true)) return true;
    return user_has_section_perm($sectionKey);
}
