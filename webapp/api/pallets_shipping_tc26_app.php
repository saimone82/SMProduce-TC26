<?php
declare(strict_types=1);

// TC26 wrapper: for New Pallet, reuse only an OPEN pallet with zero cases.
// All other actions continue through the existing pallets_shipping_app.php API.

$action = trim((string)($_POST['action'] ?? ''));
if ($action !== 'pallet_new') {
    require __DIR__ . '/pallets_shipping_app.php';
    exit;
}

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/print_engine.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function tc26_new_out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $cfg = require __DIR__ . '/../config/pallets_shipping_app.php';
    if (empty($cfg['enabled'])) tc26_new_out(['ok'=>0,'err'=>'App API disabled'],503);
    $provided = trim((string)($_SERVER['HTTP_X_APP_TOKEN'] ?? $_POST['token'] ?? ''));
    if ($provided === '' || !hash_equals((string)$cfg['token'], $provided)) {
        tc26_new_out(['ok'=>0,'err'=>'Unauthorized'],401);
    }

    $dbx = $pdo ?? $conn ?? $mysqli ?? null;
    if (!$dbx) tc26_new_out(['ok'=>0,'err'=>'Database unavailable'],503);

    // IMPORTANT: never resume an OPEN pallet that already contains cases.
    // Prefer the oldest available empty OPEN pallet; otherwise create a fresh pallet.
    $empty = smp_db_fetch_one($dbx,
        "SELECT p.pallet_id
           FROM pallets p
           LEFT JOIN pallet_cases pc ON pc.pallet_id=p.pallet_id
          WHERE UPPER(COALESCE(NULLIF(TRIM(p.status),''),'OPEN'))='OPEN'
          GROUP BY p.pallet_id
         HAVING COUNT(pc.id)=0
          ORDER BY MIN(p.created_at) ASC,p.pallet_id ASC
          LIMIT 1");

    $reused = !empty($empty['pallet_id']);
    $pid = $reused ? (string)$empty['pallet_id'] : (string)smp_tc26_open_pallet($dbx, 0, '');

    $st = smp_tc26_pallet_status($dbx, $pid);
    if (empty($st['ok'])) tc26_new_out($st);
    $st['cases'] = [];
    $st['cases_count'] = 0;
    $st['reused_empty_open_pallet'] = $reused ? 1 : 0;
    tc26_new_out($st);
} catch (Throwable $e) {
    tc26_new_out(['ok'=>0,'err'=>$e->getMessage()],500);
}
