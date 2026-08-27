<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/print_engine.php';
require_once __DIR__ . '/../includes/pallet_report.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ps_out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ps_normalize_scan_code(string $raw): string {
    $code = strtoupper(trim(preg_replace('/[\x00-\x20\x7F]+/', '', $raw) ?? $raw));
    // Zebra/DataWedge may concatenate the same barcode twice.
    if (strlen($code) > 0 && strlen($code) % 2 === 0) {
        $half = intdiv(strlen($code), 2);
        if (substr($code, 0, $half) === substr($code, $half)) {
            $code = substr($code, 0, $half);
        }
    }
    // Case labels use U followed by seven digits. If the scanner appended
    // framing data, retain the single valid case serial.
    if (preg_match_all('/U\d{7}/', $code, $m) && !empty($m[0])) {
        $unique = array_values(array_unique($m[0]));
        if (count($unique) === 1) return $unique[0];
    }
    return $code;
}

$cfg = require __DIR__ . '/../config/pallets_shipping_app.php';
if (empty($cfg['enabled'])) ps_out(['ok'=>0, 'err'=>'App API disabled'], 503);
$provided = trim((string)($_SERVER['HTTP_X_APP_TOKEN'] ?? $_POST['token'] ?? ''));
if ($provided === '' || !hash_equals((string)$cfg['token'], $provided)) {
    ps_out(['ok'=>0, 'err'=>'Unauthorized'], 401);
}

$dbx = $pdo ?? $conn ?? $mysqli ?? null;
if (!$dbx) ps_out(['ok'=>0, 'err'=>'Database unavailable'], 503);

$input = $_POST;
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($decoded)) $input = $decoded;
}
$action = trim((string)($input['action'] ?? ''));
$uid = 0;

function ps_pallet_detail($db, string $pid): array {
    $st = smp_tc26_pallet_status($db, $pid);
    if (empty($st['ok'])) return $st;
    $st['cases'] = smp_db_fetch_all($db,
        "SELECT id,case_serial,sku,variety,grower,lot,size,packaging,
                DATE_FORMAT(scanned_at,'%Y-%m-%d %H:%i:%s') scanned_at
         FROM pallet_cases WHERE pallet_id=? ORDER BY id DESC LIMIT 100", [$pid]);
    return $st;
}

function ps_shipment_detail($db, string $sid): array {
    $st = smp_tc26_shipment_status($db, $sid);
    if (empty($st['ok'])) return $st;
    $ship = smp_db_fetch_one($db,
        "SELECT shipment_id,status,po,customer_name,order_id,ship_date,
                DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') created_at,
                DATE_FORMAT(closed_at,'%Y-%m-%d %H:%i:%s') closed_at
         FROM shipments WHERE shipment_id=?", [$sid]);
    $st['shipment'] = $ship ?: [];
    $st['pallets'] = smp_db_fetch_all($db,
        "SELECT sp.id,sp.pallet_id,
                (SELECT COUNT(*) FROM pallet_cases pc WHERE pc.pallet_id=sp.pallet_id) cases_count
         FROM shipment_pallets sp WHERE sp.shipment_id=? ORDER BY sp.id DESC", [$sid]);
    return $st;
}

try {
    if ($action === 'ping') ps_out(['ok'=>1, 'server_time'=>date(DATE_ATOM)]);

    if ($action === 'pallet_new') {
        $pid = smp_tc26_open_pallet($dbx, $uid, '');
        ps_out(ps_pallet_detail($dbx, $pid));
    }
    if ($action === 'pallet_resume') {
        $pid = trim((string)($input['pallet_id'] ?? ''));
        $st = ps_pallet_detail($dbx, $pid);
        if (!empty($st['ok']) && strtoupper((string)($st['status'] ?? '')) !== 'PARTIAL') {
            ps_out(['ok'=>0, 'err'=>'The scanned pallet is not partial']);
        }
        smp_db_exec($dbx, "UPDATE pallets SET status='OPEN',is_partial=0,closed_at=NULL WHERE pallet_id=?", [$pid]);
        ps_out(ps_pallet_detail($dbx, $pid));
    }
    if ($action === 'pallet_status') {
        ps_out(ps_pallet_detail($dbx, trim((string)($input['pallet_id'] ?? ''))));
    }
    if ($action === 'pallet_scan_case') {
        $pid = trim((string)($input['pallet_id'] ?? ''));
        $serial = ps_normalize_scan_code((string)($input['case_serial'] ?? ''));
        $existing = smp_db_fetch_one($dbx,
            "SELECT pallet_id FROM pallet_cases WHERE case_serial=? LIMIT 1", [$serial]);
        // Idempotent scan: Zebra/DataWedge can occasionally deliver the same
        // barcode twice. A repeat on the current pallet is a successful no-op.
        if ($existing && (string)$existing['pallet_id'] === $pid) {
            $detail = ps_pallet_detail($dbx, $pid);
            $detail['duplicate_ignored'] = 1;
            ps_out($detail);
        }
        if ($existing) ps_out(['ok'=>0, 'err'=>'Case '.$serial.' already belongs to pallet '.$existing['pallet_id']]);
        $res = smp_tc26_add_case_to_pallet($dbx, $pid, $serial, $uid);
        if (empty($res['ok'])) {
            $res['scanned_code']=$serial;
            ps_out($res);
        }
        ps_out(ps_pallet_detail($dbx, $pid));
    }
    if ($action === 'pallet_remove_last') {
        $pid = ps_normalize_scan_code((string)($input['pallet_id'] ?? ''));
        $row = smp_db_fetch_one($dbx, "SELECT id FROM pallet_cases WHERE pallet_id=? ORDER BY id DESC LIMIT 1", [$pid]);
        if (!$row) ps_out(['ok'=>0, 'err'=>'No cases to remove']);
        $res = smp_tc26_remove_case($dbx, (int)$row['id'], $pid);
        if (empty($res['ok'])) ps_out($res);
        ps_out(ps_pallet_detail($dbx, $pid));
    }
    if ($action === 'pallet_partial') {
        $pid = trim((string)($input['pallet_id'] ?? ''));
        $res = smp_tc26_partial_pallet($dbx, $pid, $uid, 0);
        if (!empty($res['ok']) && $dbx instanceof mysqli) $res['report'] = ppr_print_report($dbx, $pid);
        ps_out($res);
    }
    if ($action === 'pallet_close') {
        $pid = trim((string)($input['pallet_id'] ?? ''));
        $res = smp_tc26_close_pallet($dbx, $pid, $uid, 0);
        if (!empty($res['ok']) && $dbx instanceof mysqli) $res['report'] = ppr_print_report($dbx, $pid);
        ps_out($res);
    }

    if ($action === 'shipment_new') {
        $sid = smp_tc26_open_shipment($dbx, $uid, '');
        ps_out(ps_shipment_detail($dbx, $sid));
    }
    if ($action === 'shipment_resume') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        $st = ps_shipment_detail($dbx, $sid);
        if (!empty($st['ok']) && strtoupper((string)($st['status'] ?? '')) !== 'OPEN') {
            ps_out(['ok'=>0, 'err'=>'The scanned shipment is not open']);
        }
        ps_out($st);
    }
    if ($action === 'shipment_set_order') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        smp_db_exec($dbx,
            "UPDATE shipments SET po=?,customer_name=?,order_id=?,ship_date=? WHERE shipment_id=?",
            [trim((string)($input['po']??'')), trim((string)($input['customer_name']??'')),
             (int)($input['order_id']??0) ?: null, date('Y-m-d'), $sid]);
        ps_out(ps_shipment_detail($dbx, $sid));
    }
    if ($action === 'verify_skip_po_password') {
        $password = (string)($input['password'] ?? '');
        $expected = strtolower(trim((string)($cfg['skip_po_password_sha256'] ?? '')));
        $valid = $expected !== '' && hash_equals($expected, hash('sha256', $password));
        ps_out($valid ? ['ok'=>1] : ['ok'=>0, 'err'=>'Incorrect password']);
    }
    if ($action === 'order_search') {
        $q = '%'.trim((string)($input['q'] ?? '')).'%';
        $rows = smp_db_fetch_all($dbx,
            "SELECT id,po,COALESCE(customer,'') customer_name,status
             FROM orders WHERE UPPER(COALESCE(status,'OPEN'))='OPEN'
               AND (po LIKE ? OR COALESCE(customer,'') LIKE ?)
             ORDER BY id DESC LIMIT 50", [$q,$q]);
        ps_out(['ok'=>1, 'orders'=>$rows]);
    }
    if ($action === 'shipment_scan_pallet') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        $pid = ps_normalize_scan_code((string)($input['pallet_id'] ?? ''));
        $existing = smp_db_fetch_one($dbx,
            "SELECT id FROM shipment_pallets WHERE shipment_id=? AND pallet_id=? LIMIT 1", [$sid,$pid]);
        if ($existing) {
            $detail = ps_shipment_detail($dbx, $sid);
            $detail['duplicate_ignored'] = 1;
            ps_out($detail);
        }
        $res = smp_tc26_add_pallet_to_shipment($dbx, $sid, $pid, $uid);
        if (empty($res['ok'])) ps_out($res);
        ps_out(ps_shipment_detail($dbx, $sid));
    }
    if ($action === 'shipment_remove_last') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        $row = smp_db_fetch_one($dbx, "SELECT id FROM shipment_pallets WHERE shipment_id=? ORDER BY id DESC LIMIT 1", [$sid]);
        if (!$row) ps_out(['ok'=>0, 'err'=>'No pallets to remove']);
        $res = smp_tc26_remove_pallet_from_shipment($dbx, (int)$row['id'], $sid);
        if (empty($res['ok'])) ps_out($res);
        ps_out(ps_shipment_detail($dbx, $sid));
    }
    if ($action === 'shipment_close') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        ps_out(smp_tc26_close_shipment($dbx, $sid, $uid, 0));
    }

    ps_out(['ok'=>0, 'err'=>'Unknown action'], 400);
} catch (Throwable $e) {
    ps_out(['ok'=>0, 'err'=>$e->getMessage()], 500);
}
