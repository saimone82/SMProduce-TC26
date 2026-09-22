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

function ps_direct_casecode_lookup($db, string $serial): ?array {
    $sql = "SELECT * FROM casecodes WHERE UPPER(TRIM(serial))=UPPER(TRIM(?)) LIMIT 1";
    if ($db instanceof PDO) {
        $st = $db->prepare($sql);
        $st->execute([$serial]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
    if ($db instanceof mysqli) {
        $st = $db->prepare($sql);
        if (!$st) return null;
        $st->bind_param('s', $serial);
        $st->execute();
        $res = $st->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $st->close();
        return is_array($row) ? $row : null;
    }
    return null;
}

function ps_case_on_pallet($db, string $palletId, string $serial): bool {
    $sql="SELECT id FROM pallet_cases WHERE pallet_id=? AND case_serial=? LIMIT 1";
    if($db instanceof PDO){$st=$db->prepare($sql);$st->execute([$palletId,$serial]);return (bool)$st->fetchColumn();}
    if($db instanceof mysqli){$st=$db->prepare($sql);if(!$st)return false;$st->bind_param('ss',$palletId,$serial);$st->execute();$res=$st->get_result();$found=$res&&$res->num_rows>0;$st->close();return $found;}
    return false;
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

function ps_shipment_meta(array $input, int $uid): array {
    return [
        'device_id'=>substr(trim((string)($input['device_id'] ?? '')),0,190),
        'device_model'=>substr(trim((string)($input['device_model'] ?? '')),0,190),
        'operator_id'=>$uid,
        'operator_name'=>substr(trim((string)($input['operator_name'] ?? 'APP USER')),0,190),
        'app_version'=>substr(trim((string)($input['app_version'] ?? '')),0,60),
    ];
}

function ps_multi_init($db): void {
    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS tc26_shipment_device_sessions (
        shipment_id VARCHAR(100) NOT NULL, device_id VARCHAR(190) NOT NULL,
        device_model VARCHAR(190) NOT NULL DEFAULT '', operator_id INT NOT NULL DEFAULT 0,
        operator_name VARCHAR(190) NOT NULL DEFAULT '', app_version VARCHAR(60) NOT NULL DEFAULT '',
        first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (shipment_id,device_id), KEY idx_tc26_shipment_seen (shipment_id,last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS tc26_shipment_pallet_devices (
        shipment_id VARCHAR(100) NOT NULL, pallet_id VARCHAR(100) NOT NULL, device_id VARCHAR(190) NOT NULL,
        added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (shipment_id,pallet_id), KEY idx_tc26_shipment_device (shipment_id,device_id,added_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS tc26_shipment_takeovers (
        shipment_id VARCHAR(100) NOT NULL PRIMARY KEY, device_id VARCHAR(190) NOT NULL,
        device_model VARCHAR(190) NOT NULL DEFAULT '', operator_id INT NOT NULL DEFAULT 0,
        operator_name VARCHAR(190) NOT NULL DEFAULT '', taken_over_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ps_shipment_touch($db,string $sid,array $meta): void {
    $deviceId=trim((string)($meta['device_id']??'')); if($sid===''||$deviceId==='') return;
    smp_db_exec($db,"INSERT INTO tc26_shipment_device_sessions(shipment_id,device_id,device_model,operator_id,operator_name,app_version)
        VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE device_model=VALUES(device_model),operator_id=VALUES(operator_id),
        operator_name=VALUES(operator_name),app_version=VALUES(app_version),last_seen=CURRENT_TIMESTAMP",
        [$sid,$deviceId,(string)($meta['device_model']??''),(int)($meta['operator_id']??0),(string)($meta['operator_name']??''),(string)($meta['app_version']??'')]);
}

function ps_shipment_other_devices($db,string $sid,string $deviceId): array {
    if($deviceId==='') return [];
    return smp_db_fetch_all($db,"SELECT device_id,device_model,operator_name,last_seen
        FROM tc26_shipment_device_sessions WHERE shipment_id=? AND device_id<>?
        AND last_seen>=DATE_SUB(NOW(),INTERVAL 15 MINUTE) ORDER BY last_seen DESC",[$sid,$deviceId]);
}

function ps_shipment_takeover_row($db,string $sid): ?array {
    return smp_db_fetch_one($db,"SELECT shipment_id,device_id,device_model,operator_name FROM tc26_shipment_takeovers WHERE shipment_id=? LIMIT 1",[$sid]) ?: null;
}

function ps_shipment_takeover_guard($db,string $sid,array $meta): ?array {
    $row=ps_shipment_takeover_row($db,$sid); if(!$row) return null;
    $deviceId=trim((string)($meta['device_id']??''));
    if($deviceId!==''&&hash_equals((string)$row['device_id'],$deviceId)) return null;
    return ['ok'=>0,'err'=>'This shipment is being taken over on another Zebra. Shipment actions are temporarily blocked.','shipment_taken_over'=>1];
}

function ps_shipment_takeover_password_valid(array $cfg,string $password): bool {
    $expected=strtolower(trim((string)($cfg['shipment_takeover_password_sha256'] ?? $cfg['skip_po_password_sha256'] ?? '')));
    return $expected!==''&&hash_equals($expected,hash('sha256',$password));
}

function ps_shipment_device_pallets($db,string $sid,string $deviceId,bool $private): array {
    $sql="SELECT sp.id,sp.pallet_id,(SELECT COUNT(*) FROM pallet_cases pc WHERE pc.pallet_id=sp.pallet_id) cases_count
          FROM shipment_pallets sp";
    $args=[$sid];
    if($private&&$deviceId!==''){
        $sql.=" JOIN tc26_shipment_pallet_devices d ON d.shipment_id=sp.shipment_id AND d.pallet_id=sp.pallet_id
                WHERE sp.shipment_id=? AND d.device_id=? ORDER BY d.added_at DESC,sp.id DESC";
        $args[]=$deviceId;
    } else $sql.=" WHERE sp.shipment_id=? ORDER BY sp.id DESC";
    return smp_db_fetch_all($db,$sql,$args);
}

function ps_lock_new_pallet($db): void {
    if(!($db instanceof PDO)) return;
    $st=$db->prepare('SELECT GET_LOCK(?,5)');$st->execute(['smproduce-pallet-new']);
    if((int)$st->fetchColumn()!==1) ps_out(['ok'=>0,'err'=>'Another Zebra is creating a pallet. Please retry.'],409);
    register_shutdown_function(static function()use($db){try{$x=$db->prepare('SELECT RELEASE_LOCK(?)');$x->execute(['smproduce-pallet-new']);}catch(Throwable $e){}});
}

function ps_pallet_detail($db, string $pid): array {
    $st = smp_tc26_pallet_status($db, $pid);
    if (empty($st['ok'])) return $st;
    $st['cases'] = smp_db_fetch_all($db,
        "SELECT id,case_serial,sku,variety,grower,lot,size,packaging,
                DATE_FORMAT(scanned_at,'%Y-%m-%d %H:%i:%s') scanned_at
         FROM pallet_cases WHERE pallet_id=? ORDER BY id DESC LIMIT 100", [$pid]);
    return $st;
}

function ps_shipment_detail($db, string $sid, ?array $meta=null, bool $privateView=false): array {
    $st = smp_tc26_shipment_status($db, $sid);
    if (empty($st['ok'])) return $st;
    $meta=$meta??[]; $deviceId=trim((string)($meta['device_id']??''));
    if($deviceId!=='') $privateView=true;
    $ship = smp_db_fetch_one($db,
        "SELECT shipment_id,status,po,customer_name,order_id,ship_date,
                DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') created_at,
                DATE_FORMAT(closed_at,'%Y-%m-%d %H:%i:%s') closed_at
         FROM shipments WHERE shipment_id=?", [$sid]);
    $pallets=ps_shipment_device_pallets($db,$sid,$deviceId,$privateView);
    $st['shipment']=$ship?:[]; $st['pallets']=$pallets;
    $st['pallet_count']=count($pallets); $st['cases_count']=array_sum(array_map(static fn(array $p):int=>(int)($p['cases_count']??0),$pallets));
    $other=ps_shipment_other_devices($db,$sid,$deviceId);
    $st['other_devices_active']=count($other); $st['shipment_in_progress_elsewhere']=$other?1:0;
    $st['other_devices_message']=$other?'Shipment already in progress on another Zebra. Your pallets and scans remain private.':'';
    $takeover=ps_shipment_takeover_row($db,$sid);
    $st['shipment_taken_over']=$takeover?1:0;
    $st['takeover_by_this_device']=$takeover&&$deviceId!==''&&hash_equals((string)$takeover['device_id'],$deviceId)?1:0;
    $st['private_device_view']=$privateView?1:0;
    return $st;
}

try {
    if (substr($action,0,9)==='shipment_') {
        ps_multi_init($dbx);
        $shipmentMeta=ps_shipment_meta($input,$uid);
        $activeShipmentId=trim((string)($input['shipment_id']??''));
        if($activeShipmentId!==''&&$action!=='shipment_open_list'){
            ps_shipment_touch($dbx,$activeShipmentId,$shipmentMeta);
            if(in_array($action,['shipment_set_order','shipment_scan_pallet','shipment_remove_last','shipment_close'],true)){
                $blocked=ps_shipment_takeover_guard($dbx,$activeShipmentId,$shipmentMeta);
                if($blocked) ps_out($blocked,423);
            }
        }
    }
    if ($action === 'pallet_new') ps_lock_new_pallet($dbx);
    if ($action === 'ping') ps_out(['ok'=>1, 'api_version'=>'1.4.2', 'server_time'=>date(DATE_ATOM)]);

    if ($action === 'case_check') {
        $serial = ps_normalize_scan_code((string)($input['case_serial'] ?? ''));
        $row = ps_direct_casecode_lookup($dbx, $serial);
        ps_out(['ok'=>$row?1:0, 'api_version'=>'1.4.2', 'scanned_code'=>$serial,
            'found'=>$row?1:0, 'sku'=>$row['SKU']??$row['sku']??null,
            'variety'=>$row['variety']??null, 'grower'=>$row['grower']??null,
            'err'=>$row?null:'Case not found in casecodes']);
    }

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
        $caseRow = ps_direct_casecode_lookup($dbx, $serial);
        if (!$caseRow) ps_out(['ok'=>0, 'err'=>'Case '.$serial.' not found in casecodes', 'scanned_code'=>$serial]);
        if (ps_case_on_pallet($dbx,$pid,$serial))
            ps_out(['ok'=>0,'err'=>'Case already scanned on this pallet','scanned_code'=>$serial]);
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
        $res = smp_tc26_add_case_to_pallet($dbx, $pid, $serial, [
            'user_id'=>$uid,
            'sku'=>(string)($caseRow['SKU']??$caseRow['sku']??''),
            'variety'=>(string)($caseRow['variety']??$caseRow['Variety']??''),
            'grower'=>(string)($caseRow['grower']??$caseRow['Grower']??''),
            'size'=>(string)($caseRow['size']??$caseRow['Size']??''),
            'packaging'=>(string)($caseRow['packaging']??$caseRow['Packaging']??''),
            'crop'=>(string)($caseRow['crop']??$caseRow['Crop']??''),
            'lot'=>(string)($caseRow['lot']??$caseRow['Lot']??''),
            'pack_date'=>(string)($caseRow['pack_date']??$caseRow['PackDate']??''),
        ]);
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
        $res['label_printed'] = !empty($res['ok']) && smp_tc26_print_pallet_label($dbx, $pid, 0, true) ? 1 : 0;
        if (!empty($res['ok']) && empty($res['label_printed'])) $res['print_warning']='Pallet saved, but label was not sent. Check the printer selected in Pallets Manage.';
        if (!empty($res['ok']) && $dbx instanceof mysqli) $res['report'] = ppr_print_report($dbx, $pid);
        ps_out($res);
    }
    if ($action === 'pallet_close') {
        $pid = trim((string)($input['pallet_id'] ?? ''));
        $res = smp_tc26_close_pallet($dbx, $pid, $uid, 0);
        $res['label_printed'] = !empty($res['ok']) && smp_tc26_print_pallet_label($dbx, $pid, 0, false) ? 1 : 0;
        if (!empty($res['ok']) && empty($res['label_printed'])) $res['print_warning']='Pallet closed, but label was not sent. Check the printer selected in Pallets Manage.';
        if (!empty($res['ok']) && $dbx instanceof mysqli) $res['report'] = ppr_print_report($dbx, $pid);
        ps_out($res);
    }

    if ($action === 'shipment_new') {
        $sid = smp_tc26_open_shipment($dbx, $uid, '');
        $shipmentMeta=ps_shipment_meta($input,$uid); ps_shipment_touch($dbx,$sid,$shipmentMeta);
        ps_out(ps_shipment_detail($dbx, $sid, $shipmentMeta, true));
    }
    if ($action === 'shipment_resume') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        $st = ps_shipment_detail($dbx, $sid, $shipmentMeta, true);
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
        ps_out(ps_shipment_detail($dbx, $sid, $shipmentMeta, true));
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
            $detail = ps_shipment_detail($dbx, $sid, $shipmentMeta, true);
            $detail['duplicate_ignored'] = 1;
            ps_out($detail);
        }
        $res = smp_tc26_add_pallet_to_shipment($dbx, $sid, $pid, $uid);
        if (empty($res['ok'])) ps_out($res);
        $deviceId=(string)($shipmentMeta['device_id']??'');
        if($deviceId!=='') smp_db_exec($dbx,"INSERT INTO tc26_shipment_pallet_devices(shipment_id,pallet_id,device_id) VALUES(?,?,?)
            ON DUPLICATE KEY UPDATE device_id=VALUES(device_id),added_at=CURRENT_TIMESTAMP",[$sid,$pid,$deviceId]);
        ps_out(ps_shipment_detail($dbx, $sid, $shipmentMeta, true));
    }
    if ($action === 'shipment_remove_last') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        $deviceId=(string)($shipmentMeta['device_id']??'');
        $row=$deviceId!==''?smp_db_fetch_one($dbx,"SELECT sp.id,sp.pallet_id FROM shipment_pallets sp JOIN tc26_shipment_pallet_devices d ON d.shipment_id=sp.shipment_id AND d.pallet_id=sp.pallet_id WHERE sp.shipment_id=? AND d.device_id=? ORDER BY d.added_at DESC,sp.id DESC LIMIT 1",[$sid,$deviceId]):smp_db_fetch_one($dbx,"SELECT id,pallet_id FROM shipment_pallets WHERE shipment_id=? ORDER BY id DESC LIMIT 1",[$sid]);
        if (!$row) ps_out(['ok'=>0, 'err'=>'No pallets added by this Zebra to remove']);
        $res = smp_tc26_remove_pallet_from_shipment($dbx, (int)$row['id'], $sid);
        if (empty($res['ok'])) ps_out($res);
        smp_db_exec($dbx,"DELETE FROM tc26_shipment_pallet_devices WHERE shipment_id=? AND pallet_id=?",[$sid,(string)$row['pallet_id']]);
        ps_out(ps_shipment_detail($dbx, $sid, $shipmentMeta, true));
    }
    if ($action === 'shipment_take_over') {
        $sid=trim((string)($input['shipment_id']??''));
        if($sid==='') ps_out(['ok'=>0,'err'=>'Missing shipment_id'],400);
        if(!ps_shipment_takeover_password_valid($cfg,(string)($input['password']??''))) ps_out(['ok'=>0,'err'=>'Incorrect take-over password'],403);
        $meta=ps_shipment_meta($input,$uid); $deviceId=trim((string)($meta['device_id']??''));
        if($deviceId==='') ps_out(['ok'=>0,'err'=>'Device ID is required for take over'],400);
        ps_shipment_touch($dbx,$sid,$meta);
        smp_db_exec($dbx,"INSERT INTO tc26_shipment_takeovers(shipment_id,device_id,device_model,operator_id,operator_name)
            VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE device_id=VALUES(device_id),device_model=VALUES(device_model),operator_id=VALUES(operator_id),operator_name=VALUES(operator_name),taken_over_at=CURRENT_TIMESTAMP",
            [$sid,$deviceId,(string)($meta['device_model']??''),(int)($meta['operator_id']??0),(string)($meta['operator_name']??'')]);
        $detail=ps_shipment_detail($dbx,$sid,$meta,true);$detail['takeover_completed']=1;ps_out($detail);
    }
    if ($action === 'shipment_close') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        $other=ps_shipment_other_devices($dbx,$sid,(string)($shipmentMeta['device_id']??''));
        $takeover=ps_shipment_takeover_row($dbx,$sid);
        $owns=$takeover&&(string)($shipmentMeta['device_id']??'')!==''&&hash_equals((string)$takeover['device_id'],(string)$shipmentMeta['device_id']);
        if($other&&!$owns) ps_out(['ok'=>0,'err'=>'Another Zebra is active on this shipment. Use Take Over with the password before closing.','requires_take_over'=>1],409);
        $result=smp_tc26_close_shipment($dbx, $sid, $uid, 0);
        if(!empty($result['ok'])){smp_db_exec($dbx,"DELETE FROM tc26_shipment_takeovers WHERE shipment_id=?",[$sid]);smp_db_exec($dbx,"DELETE FROM tc26_shipment_device_sessions WHERE shipment_id=?",[$sid]);}
        ps_out($result);
    }

    ps_out(['ok'=>0, 'err'=>'Unknown action'], 400);
} catch (Throwable $e) {
    ps_out(['ok'=>0, 'err'=>$e->getMessage()], 500);
}
