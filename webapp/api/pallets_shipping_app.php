<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/print_engine.php';
require_once __DIR__ . '/../includes/pallet_report.php';
require_once __DIR__ . '/../includes/shipping_pallet_summary.php';

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
    $direct = smp_db_fetch_one($db,"SELECT * FROM casecodes WHERE serial=? LIMIT 1",[$serial]);
    if ($direct) return $direct;
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

function ps_limit_text(mixed $value, int $max = 190): string {
    $value = trim((string)$value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

function ps_client_meta(array $input, int $uid): array {
    return [
        'device_id' => ps_limit_text($input['device_id'] ?? '', 190),
        'device_model' => ps_limit_text($input['device_model'] ?? '', 190),
        'operator_id' => $uid,
        'operator_name' => ps_limit_text($input['operator'] ?? ($input['operator_name'] ?? ('APP USER '.$uid)), 190),
        'app_version' => ps_limit_text($input['app_version'] ?? '', 60),
        'remote_ip' => ps_limit_text($_SERVER['REMOTE_ADDR'] ?? '', 64),
    ];
}

function ps_sku_override_password_valid(array $cfg, string $password): bool {
    $expected = strtolower(trim((string)($cfg['pallet_sku_override_password_sha256'] ?? '')));
    // Dedicated Pallet SKU Override password: Apples2424. The clear text is
    // intentionally not stored in the PHP file; config can override this hash.
    if ($expected === '') $expected = '06727da8681e44e269eb26f378f188b9b88ec80912d6187a1aa92f3c38dd3a80';
    return hash_equals($expected, hash('sha256', $password));
}

function ps_normalize_sku(mixed $sku): string {
    return strtoupper(trim(preg_replace('/\s+/', '', (string)$sku) ?? (string)$sku));
}

function ps_case_product(array $row): array {
    return [
        'case_serial' => (string)($row['case_serial'] ?? $row['serial'] ?? ''),
        'sku' => (string)($row['sku'] ?? $row['SKU'] ?? ''),
        'variety' => (string)($row['variety'] ?? $row['Variety'] ?? ''),
        'grower' => (string)($row['grower'] ?? $row['Grower'] ?? ''),
        'lot' => (string)($row['lot'] ?? $row['Lot'] ?? ''),
        'size' => (string)($row['size'] ?? $row['Size'] ?? ''),
        'packaging' => (string)($row['packaging'] ?? $row['Packaging'] ?? ''),
        'crop' => (string)($row['crop'] ?? $row['Crop'] ?? ''),
        'pack_date' => (string)($row['pack_date'] ?? $row['PackDate'] ?? ''),
    ];
}

function ps_pallet_reference($db, string $pid): ?array {
    $row = smp_db_fetch_one($db,
        "SELECT id,case_serial,sku,variety,grower,lot,size,packaging
         FROM pallet_cases WHERE pallet_id=? ORDER BY id ASC LIMIT 1", [$pid]);
    return $row ? ps_case_product($row) : null;
}

function ps_product_mismatch(?array $reference, array $case): bool {
    if (!$reference) return false;
    $correctSku = ps_normalize_sku($reference['sku'] ?? '');
    $caseSku = ps_normalize_sku($case['sku'] ?? '');
    if ($correctSku !== '' || $caseSku !== '') return $correctSku !== $caseSku;

    foreach (['variety','size','packaging'] as $field) {
        $expected = strtoupper(trim((string)($reference[$field] ?? '')));
        $actual = strtoupper(trim((string)($case[$field] ?? '')));
        if ($expected !== $actual) return true;
    }
    return false;
}

function ps_guard_init($db): void {
    smp_db_exec($db, "CREATE TABLE IF NOT EXISTS tc26_pallet_sessions (
        device_id VARCHAR(190) NOT NULL, pallet_id VARCHAR(60) NOT NULL,
        operator_name VARCHAR(190) NOT NULL DEFAULT '',
        opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(device_id), KEY idx_tps_pallet(pallet_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    smp_db_exec($db, "CREATE TABLE IF NOT EXISTS tc26_pallet_sku_audit (
        id BIGINT NOT NULL AUTO_INCREMENT, pallet_id VARCHAR(60) NOT NULL,
        case_serial VARCHAR(100) NOT NULL, correct_sku VARCHAR(100) NOT NULL DEFAULT '',
        different_sku VARCHAR(100) NOT NULL DEFAULT '', decision VARCHAR(16) NOT NULL,
        decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        device_id VARCHAR(190) NOT NULL DEFAULT '', device_model VARCHAR(190) NOT NULL DEFAULT '',
        operator_id INT NOT NULL DEFAULT 0, operator_name VARCHAR(190) NOT NULL DEFAULT '',
        app_version VARCHAR(60) NOT NULL DEFAULT '', remote_ip VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY(id), KEY idx_tpsa_pallet(pallet_id), KEY idx_tpsa_case(case_serial),
        KEY idx_tpsa_time(decided_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ps_pallet_session_get($db, string $deviceId): ?string {
    if ($deviceId === '') return null;
    $row = smp_db_fetch_one($db,
        "SELECT s.pallet_id FROM tc26_pallet_sessions s
         JOIN pallets p ON p.pallet_id=s.pallet_id
         WHERE s.device_id=? AND UPPER(COALESCE(p.status,'OPEN'))='OPEN' LIMIT 1", [$deviceId]);
    if ($row) return (string)$row['pallet_id'];
    smp_db_exec($db, "DELETE FROM tc26_pallet_sessions WHERE device_id=?", [$deviceId]);
    return null;
}

function ps_pallet_session_set($db, string $deviceId, string $pid, string $operator): void {
    if ($deviceId === '') return;
    smp_db_exec($db,
        "INSERT INTO tc26_pallet_sessions(device_id,pallet_id,operator_name)
         VALUES(?,?,?) ON DUPLICATE KEY UPDATE pallet_id=VALUES(pallet_id),
         operator_name=VALUES(operator_name),updated_at=CURRENT_TIMESTAMP",
        [$deviceId,$pid,$operator]);
}

function ps_pallet_session_clear($db, string $pid): void {
    if ($pid !== '') smp_db_exec($db, "DELETE FROM tc26_pallet_sessions WHERE pallet_id=?", [$pid]);
}

function ps_log_sku_decision($db, string $pid, string $serial, array $reference,
                             array $case, string $decision, array $meta): void {
    smp_db_exec($db,
        "INSERT INTO tc26_pallet_sku_audit
         (pallet_id,case_serial,correct_sku,different_sku,decision,device_id,
          device_model,operator_id,operator_name,app_version,remote_ip)
         VALUES(?,?,?,?,?,?,?,?,?,?,?)",
        [$pid,$serial,(string)($reference['sku'] ?? ''),(string)($case['sku'] ?? ''),$decision,
         $meta['device_id'],$meta['device_model'],$meta['operator_id'],$meta['operator_name'],
         $meta['app_version'],$meta['remote_ip']]);
}

function ps_add_case($db, string $pid, string $serial, array $case, int $uid): array {
    return smp_tc26_add_case_to_pallet($db, $pid, $serial, [
        'user_id'=>$uid,
        'sku'=>(string)($case['sku'] ?? ''),
        'variety'=>(string)($case['variety'] ?? ''),
        'grower'=>(string)($case['grower'] ?? ''),
        'size'=>(string)($case['size'] ?? ''),
        'packaging'=>(string)($case['packaging'] ?? ''),
        'crop'=>(string)($case['crop'] ?? ''),
        'lot'=>(string)($case['lot'] ?? ''),
        'pack_date'=>(string)($case['pack_date'] ?? ''),
    ]);
}

function ps_multi_init($db): void {
    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS tc26_shipment_orders (
        id BIGINT NOT NULL AUTO_INCREMENT, shipment_id VARCHAR(60) NOT NULL,
        order_id INT NOT NULL, po VARCHAR(100) NOT NULL DEFAULT '',
        customer_name VARCHAR(150) NOT NULL DEFAULT '', sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id),
        UNIQUE KEY uniq_shipment_order(shipment_id,order_id), KEY idx_so_shipment(shipment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS tc26_shipment_case_allocations (
        id BIGINT NOT NULL AUTO_INCREMENT, shipment_id VARCHAR(60) NOT NULL,
        order_id INT NOT NULL, po VARCHAR(100) NOT NULL DEFAULT '', pallet_id VARCHAR(60) NOT NULL,
        case_serial VARCHAR(100) NOT NULL, sku VARCHAR(100) NOT NULL DEFAULT '',
        variety VARCHAR(150) NOT NULL DEFAULT '', size VARCHAR(150) NOT NULL DEFAULT '',
        packaging VARCHAR(150) NOT NULL DEFAULT '', allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id), UNIQUE KEY uniq_shipment_case(shipment_id,case_serial),
        KEY idx_sca_order(shipment_id,order_id), KEY idx_sca_pallet(pallet_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Presence is informational only: several Zebra devices can work on the
    // same shipment at once.
    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS tc26_shipment_device_sessions (
        shipment_id VARCHAR(60) NOT NULL, device_id VARCHAR(190) NOT NULL,
        device_model VARCHAR(190) NOT NULL DEFAULT '', operator_id INT NOT NULL DEFAULT 0,
        operator_name VARCHAR(190) NOT NULL DEFAULT '', opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(shipment_id,device_id), KEY idx_tsds_last_seen(shipment_id,last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Lets each device receive only the pallets it added to a shared shipment.
    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS tc26_shipment_pallet_devices (
        shipment_id VARCHAR(60) NOT NULL, pallet_id VARCHAR(60) NOT NULL,
        device_id VARCHAR(190) NOT NULL DEFAULT '', added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(shipment_id,pallet_id), KEY idx_tspd_device(shipment_id,device_id,added_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // This is an exceptional control action, distinct from normal shared work.
    // It protects shipment actions only and never blocks standalone palletizing.
    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS tc26_shipment_takeovers (
        shipment_id VARCHAR(60) NOT NULL, device_id VARCHAR(190) NOT NULL,
        device_model VARCHAR(190) NOT NULL DEFAULT '', operator_id INT NOT NULL DEFAULT 0,
        operator_name VARCHAR(190) NOT NULL DEFAULT '', taken_over_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(shipment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ps_shipment_meta(array $input, int $uid): array { return ps_client_meta($input,$uid); }

function ps_shipment_touch($db,string $sid,array $meta): void {
    $deviceId=trim((string)($meta['device_id']??''));if($sid===''||$deviceId==='')return;
    smp_db_exec($db,"INSERT INTO tc26_shipment_device_sessions
        (shipment_id,device_id,device_model,operator_id,operator_name) VALUES(?,?,?,?,?)
        ON DUPLICATE KEY UPDATE device_model=VALUES(device_model),operator_id=VALUES(operator_id),
        operator_name=VALUES(operator_name),last_seen_at=CURRENT_TIMESTAMP",
        [$sid,$deviceId,(string)($meta['device_model']??''),(int)($meta['operator_id']??0),(string)($meta['operator_name']??'')]);
}
function ps_shipment_other_devices($db,string $sid,string $deviceId=''): array {
    $sql="SELECT device_id FROM tc26_shipment_device_sessions WHERE shipment_id=?
          AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 MINUTE)";$params=[$sid];
    if($deviceId!==''){$sql.=" AND device_id<>?";$params[]=$deviceId;}
    return smp_db_fetch_all($db,$sql,$params);
}
function ps_shipment_takeover_row($db,string $sid): ?array {
    return smp_db_fetch_one($db,"SELECT shipment_id,device_id,DATE_FORMAT(taken_over_at,'%Y-%m-%d %H:%i:%s') taken_over_at
        FROM tc26_shipment_takeovers WHERE shipment_id=? LIMIT 1",[$sid]);
}
function ps_shipment_takeover_password_valid(array $cfg,string $password): bool {
    $expected=strtolower(trim((string)($cfg['shipment_takeover_password_sha256']??'')));
    if($expected==='')$expected=strtolower(trim((string)($cfg['skip_po_password_sha256']??'')));
    return $expected!==''&&hash_equals($expected,hash('sha256',$password));
}
function ps_shipment_takeover_guard($db,string $sid,array $meta): ?array {
    $takeover=ps_shipment_takeover_row($db,$sid);if(!$takeover)return null;
    $deviceId=trim((string)($meta['device_id']??''));
    if($deviceId!==''&&hash_equals((string)$takeover['device_id'],$deviceId))return null;
    return ['ok'=>0,'err'=>'Shipment has been taken over by another Zebra. Palletizing remains available, but shipment actions are blocked.','shipment_taken_over'=>1];
}
function ps_shipment_private_pallets($db,string $sid,string $deviceId,bool $privateView): array {
    if(!$privateView||$deviceId==='')return smp_db_fetch_all($db,"SELECT sp.id,sp.pallet_id,
        (SELECT COUNT(*) FROM pallet_cases pc WHERE pc.pallet_id=sp.pallet_id) cases_count
        FROM shipment_pallets sp WHERE sp.shipment_id=? ORDER BY sp.id DESC",[$sid]);
    return smp_db_fetch_all($db,"SELECT sp.id,sp.pallet_id,(SELECT COUNT(*) FROM pallet_cases pc WHERE pc.pallet_id=sp.pallet_id) cases_count
        FROM shipment_pallets sp JOIN tc26_shipment_pallet_devices d ON d.shipment_id=sp.shipment_id AND d.pallet_id=sp.pallet_id
        WHERE sp.shipment_id=? AND d.device_id=? ORDER BY d.added_at DESC,sp.id DESC",[$sid,$deviceId]);
}

function ps_multi_status($db,string $sid): array {
    ps_multi_init($db);
    require_once __DIR__ . '/../config/orders_sql_lib.php';
    $selected=smp_db_fetch_all($db,"SELECT * FROM tc26_shipment_orders WHERE shipment_id=? ORDER BY sort_order,id",[$sid]);
    $lines=[];$allOk=!empty($selected);$totalRequired=0;$totalLoaded=0;
    foreach($selected as $so){
        $orderId=(int)$so['order_id'];$po=(string)$so['po'];
        $orderLines=orders_sql_ready()?orders_fetch_lines_sql($orderId):[];
        foreach($orderLines as $ol){
            $sku=trim((string)($ol['sku_code']??$ol['sku_id']??''));$required=(int)($ol['quantity']??0);
            $got=smp_db_fetch_one($db,"SELECT COUNT(*) c FROM tc26_shipment_case_allocations WHERE shipment_id=? AND order_id=? AND sku=?",[$sid,$orderId,$sku]);
            $loaded=(int)($got['c']??0);$complete=$required>0&&$loaded===$required;
            if(!$complete)$allOk=false;$totalRequired+=$required;$totalLoaded+=$loaded;
            $lines[]=['order_id'=>$orderId,'po'=>$po,'sku'=>$sku,
                'variety'=>(string)($ol['variety']??''),'size'=>(string)($ol['size']??''),
                'packaging'=>(string)($ol['packaging']??$ol['packaging_preset']??''),
                'required'=>$required,'loaded'=>$loaded,'remaining'=>max(0,$required-$loaded),
                'complete'=>$complete,'over'=>$loaded>$required,'extra'=>false];
        }
    }
    $unallocated=smp_db_fetch_one($db,
        "SELECT COUNT(*) c FROM shipment_pallets sp JOIN pallet_cases pc ON pc.pallet_id=sp.pallet_id
         LEFT JOIN tc26_shipment_case_allocations a ON a.shipment_id=sp.shipment_id AND a.case_serial=pc.case_serial
         WHERE sp.shipment_id=? AND a.id IS NULL",[$sid]);
    $extra=(int)($unallocated['c']??0);if($extra>0)$allOk=false;
    return ['ok'=>1,'multi_po'=>count($selected)>1?1:0,'orders'=>$selected,'sku_lines'=>$lines,
        'po_qty'=>$totalRequired,'ship_qty'=>$totalLoaded,'unallocated_cases'=>$extra,'all_ok'=>$allOk];
}

function ps_allocate_shipment_cases($db,string $sid): array {
    ps_multi_init($db);
    require_once __DIR__ . '/../config/orders_sql_lib.php';
    $orders=smp_db_fetch_all($db,"SELECT * FROM tc26_shipment_orders WHERE shipment_id=? ORDER BY sort_order,id",[$sid]);
    if(!$orders)return ps_multi_status($db,$sid);
    $needs=[];
    foreach($orders as $so){
        foreach((orders_sql_ready()?orders_fetch_lines_sql((int)$so['order_id']):[]) as $ol){
            $sku=trim((string)($ol['sku_code']??$ol['sku_id']??''));
            $got=smp_db_fetch_one($db,"SELECT COUNT(*) c FROM tc26_shipment_case_allocations WHERE shipment_id=? AND order_id=? AND sku=?",[$sid,(int)$so['order_id'],$sku]);
            $remaining=max(0,(int)$ol['quantity']-(int)($got['c']??0));
            if($remaining>0)$needs[]=['order_id'=>(int)$so['order_id'],'po'=>(string)$so['po'],'sku'=>$sku,'remaining'=>$remaining];
        }
    }
    $cases=smp_db_fetch_all($db,
        "SELECT sp.pallet_id,pc.case_serial,
          COALESCE(NULLIF(CAST(pc.sku AS CHAR),''),CAST(cc.SKU AS CHAR),'') sku,
          COALESCE(NULLIF(pc.variety,''),cc.variety,'') variety,
          COALESCE(NULLIF(pc.size,''),cc.size,'') size,
          COALESCE(NULLIF(pc.packaging,''),cc.packaging,'') packaging
         FROM shipment_pallets sp JOIN pallet_cases pc ON pc.pallet_id=sp.pallet_id
         LEFT JOIN casecodes cc ON cc.serial=pc.case_serial
         LEFT JOIN tc26_shipment_case_allocations a ON a.shipment_id=sp.shipment_id AND a.case_serial=pc.case_serial
         WHERE sp.shipment_id=? AND a.id IS NULL ORDER BY sp.id,pc.id",[$sid]);
    foreach($cases as $c){
        $sku=trim((string)$c['sku']);
        foreach($needs as &$need){
            if($need['remaining']>0&&$need['sku']===$sku){
                smp_db_exec($db,"INSERT IGNORE INTO tc26_shipment_case_allocations
                    (shipment_id,order_id,po,pallet_id,case_serial,sku,variety,size,packaging)
                    VALUES(?,?,?,?,?,?,?,?,?)",[$sid,$need['order_id'],$need['po'],$c['pallet_id'],$c['case_serial'],$sku,$c['variety'],$c['size'],$c['packaging']]);
                $need['remaining']--;break;
            }
        }unset($need);
    }
    return ps_multi_status($db,$sid);
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

function ps_pallet_detail($db, string $pid, ?array $knownReference = null, ?array $knownStatus = null, bool $compact = false): array {
    $st = $knownStatus ?? smp_tc26_pallet_status($db, $pid);
    if (empty($st['ok'])) return $st;
    $caseColumns = $compact ? 'id,case_serial' : "id,case_serial,sku,variety,grower,lot,size,packaging,DATE_FORMAT(scanned_at,'%Y-%m-%d %H:%i:%s') scanned_at";
    $st['cases'] = smp_db_fetch_all($db,"SELECT $caseColumns FROM pallet_cases WHERE pallet_id=? ORDER BY id DESC LIMIT 100",[$pid]);
    $reference = $knownReference ?? ps_pallet_reference($db, $pid);
    $st['reference_case'] = $reference ?: null;
    $st['reference_sku'] = (string)($reference['sku'] ?? '');
    $st['reference_variety'] = (string)($reference['variety'] ?? '');
    $st['reference_size'] = (string)($reference['size'] ?? '');
    $st['reference_packaging'] = (string)($reference['packaging'] ?? '');
    return $st;
}

function ps_normalize_shipping_pallet(string $raw): string {
    $code = strtoupper(preg_replace('/[\x00-\x20\x7F]+/', '', $raw) ?? $raw);
    if ($code !== '' && strlen($code) % 2 === 0) {
        $half = intdiv(strlen($code), 2);
        if (substr($code, 0, $half) === substr($code, $half)) $code = substr($code, 0, $half);
    }
    return $code;
}

function ps_count_shipping_pallets(array $pallets): int {
    $ids = [];
    foreach ($pallets as $pallet) {
        $pid = strtoupper(trim((string)($pallet['pallet_id'] ?? '')));
        if (str_starts_with($pid, 'P')) $ids[$pid] = true;
    }
    return count($ids);
}

function ps_shipment_detail($db,string $sid,?array $meta=null,bool $privateView=false): array {
    $st = smp_tc26_shipment_status($db, $sid);
    if (empty($st['ok'])) return $st;
    $meta=$meta??[];$deviceId=trim((string)($meta['device_id']??''));
    // All current TC26 clients already send device_id.  Make their view
    // device-private by default; a desktop or legacy client without it keeps
    // the full historical view.
    if($deviceId!=='')$privateView=true;
    $ship = smp_db_fetch_one($db,
        "SELECT shipment_id,status,po,customer_name,order_id,ship_date,
                DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') created_at,
                DATE_FORMAT(closed_at,'%Y-%m-%d %H:%i:%s') closed_at
         FROM shipments WHERE shipment_id=?", [$sid]);
    $st['shipment'] = $ship ?: [];
    $allPallets = smp_db_fetch_all($db,
        "SELECT sp.id,sp.pallet_id,
                (SELECT COUNT(*) FROM pallet_cases pc WHERE pc.pallet_id=sp.pallet_id) cases_count
         FROM shipment_pallets sp WHERE sp.shipment_id=? ORDER BY sp.id DESC", [$sid]);
    $st['pallets']=ps_shipment_private_pallets($db,$sid,$deviceId,$privateView);
    // Count physical P labels accepted into this shipment, independent of case,
    // SKU, PO allocation and any aggregate returned by the legacy status helper.
    $st['pallet_count'] = ps_count_shipping_pallets((array)$st['pallets']);
    $st['global_pallet_count']=ps_count_shipping_pallets($allPallets);
    $st['shipment']['pallet_count'] = $st['pallet_count'];
    $st['shipment']['global_pallet_count']=$st['global_pallet_count'];
    $other=ps_shipment_other_devices($db,$sid,$deviceId);
    $st['other_devices_active']=count($other);
    $st['shipment_in_progress_elsewhere']=count($other)>0?1:0;
    $st['other_devices_message']=count($other)>0?'Shipment already in progress on other device(s).':'';
    if($other)$st['message']=$st['other_devices_message'];
    $takeover=ps_shipment_takeover_row($db,$sid);
    $st['shipment_taken_over']=$takeover?1:0;
    $st['takeover_by_this_device']=$takeover&&$deviceId!==''&&hash_equals((string)$takeover['device_id'],$deviceId)?1:0;
    $st['private_device_view']=$privateView?1:0;
    try{$st['shipment_orders']=smp_db_fetch_all($db,"SELECT order_id AS id,po,customer_name,sort_order FROM tc26_shipment_orders WHERE shipment_id=? ORDER BY sort_order,id",[$sid]);}catch(Throwable $_e){$st['shipment_orders']=[];}
    return $st;
}

try {
    if (str_starts_with($action, 'bin_labels_')) {
        require_once __DIR__ . '/../includes/shipping_bin_labels.php';
        if (!($pdo instanceof PDO)) ps_out(['ok'=>0,'err'=>'Orders database unavailable'],503);
        $meta = ps_client_meta($input,$uid);
        ps_out(sbl_dispatch($pdo,$action,$input,'APK '.$meta['device_id'],true));
    }
    if ($action === 'ping') ps_out([
        'ok'=>1, 'api_version'=>'1.6.0', 'min_app_version'=>'2.0.6',
        'server_time'=>date(DATE_ATOM)
    ]);

    // Schema initialization must remain inside the JSON error boundary.
    // Otherwise a database/DDL error produces an empty HTTP response and the
    // Android client can only report "End of input at character 0".
    if (str_starts_with($action, 'shipment_')) ps_multi_init($dbx);
    // Creating/checking the support tables on every barcode adds avoidable
    // metadata locking and latency. Scan/status/remove operations only use the
    // already-existing pallet tables, so initialize the guard schema solely on
    // the few actions that need session or audit storage.
    if (in_array($action, [
        'pallet_new','pallet_resume','pallet_edit_open','pallet_sku_decision',
        'pallet_delete','pallet_partial','pallet_close','pallet_save_no_print'
    ], true)) ps_guard_init($dbx);

    // Serialize mutations for one pallet, including the count check and Complete
    // transition. Different pallets remain independent. Released even on ps_out().
    if ($dbx instanceof PDO && in_array($action,[
        'pallet_scan_case','pallet_sku_decision','pallet_remove_last','pallet_remove_case',
        'pallet_partial','pallet_close','pallet_save_no_print','pallet_resume','pallet_edit_open','pallet_delete'
    ],true)) {
        $lockPid=trim((string)($input['pallet_id']??''));
        if($lockPid!=='') {
            $lockName='smp-pallet-'.substr(hash('sha256',$lockPid),0,48);
            $lockStmt=$dbx->prepare('SELECT GET_LOCK(?,5)');$lockStmt->execute([$lockName]);
            if((int)$lockStmt->fetchColumn()!==1)ps_out(['ok'=>0,'err'=>'This pallet is busy on another device. Please retry.'],409);
            register_shutdown_function(static function()use($dbx,$lockName){try{$st=$dbx->prepare('SELECT RELEASE_LOCK(?)');$st->execute([$lockName]);}catch(Throwable $e){}});
        }
    }
    if($dbx instanceof PDO&&$action==='pallet_new'){
        $lockStmt=$dbx->prepare('SELECT GET_LOCK(?,5)');$lockStmt->execute(['smp-open-next-pallet']);
        if((int)$lockStmt->fetchColumn()!==1)ps_out(['ok'=>0,'err'=>'Could not reserve a new pallet. Please retry.'],409);
        register_shutdown_function(static function()use($dbx){try{$st=$dbx->prepare('SELECT RELEASE_LOCK(?)');$st->execute(['smp-open-next-pallet']);}catch(Throwable $e){}});
    }
    // This is a millisecond DB mutex only. It prevents races while 4–5 Zebra
    // devices add/remove/close, but does not lock the shipment for operators.
    if($dbx instanceof PDO&&in_array($action,['shipment_set_order','shipment_set_orders','shipment_scan_pallet','shipment_remove_last','shipment_close','shipment_take_over','shipment_release_take_over'],true)){
        $lockSid=trim((string)($input['shipment_id']??''));
        if($lockSid!==''){$lockName='smp-shipment-'.substr(hash('sha256',$lockSid),0,48);
            $lockStmt=$dbx->prepare('SELECT GET_LOCK(?,5)');$lockStmt->execute([$lockName]);
            if((int)$lockStmt->fetchColumn()!==1)ps_out(['ok'=>0,'err'=>'Shipment is processing another request. Please retry.'],409);
            register_shutdown_function(static function()use($dbx,$lockName){try{$st=$dbx->prepare('SELECT RELEASE_LOCK(?)');$st->execute([$lockName]);}catch(Throwable $e){}});
        }
    }
    // Older APKs completed SKU overrides only on the device and cannot write
    // the required server audit. Once this API is deployed, pallet operations
    // therefore require the paired v2.0.6-or-newer APK.
    if (str_starts_with($action, 'pallet_')) {
        $appVersion = trim((string)($input['app_version'] ?? ''));
        if (!preg_match('/^(2\.[0-9]+\.[0-9]+)(?:$|[-+])/', $appVersion, $versionMatch)
            || version_compare($versionMatch[1], '2.0.6', '<')) {
            ps_out(['ok'=>0, 'err'=>'App update required. Install TC26 Pallets / Shipping v2.0.6 or newer.']);
        }
    }

    // The integrated scanner only reads existing rows. Its password is separate
    // from pallet edit/PO override configuration and is never stored on the device.
    if (in_array($action, ['scanner_unlock','scanner_case_lookup','scanner_pallet_lookup'], true)) {
        $passwordHash = 'aa82088246685c17ebf16d48877686b831ed384ffdc42e76494283c271704d7a';
        if (!hash_equals($passwordHash, hash('sha256', (string)($input['password'] ?? '')))) {
            ps_out(['ok'=>0,'err'=>'Incorrect scanner PIN']);
        }
        if ($action === 'scanner_unlock') ps_out(['ok'=>1]);
        if ($action === 'scanner_case_lookup') {
            $serial = ps_normalize_scan_code((string)($input['case_serial'] ?? ''));
            if ($serial === '') ps_out(['ok'=>0,'err'=>'Missing case_serial'], 400);
            $row = smp_db_fetch_one($dbx,
                "SELECT pallet_id FROM pallet_cases WHERE case_serial=? ORDER BY id DESC LIMIT 1", [$serial]);
            ps_out(['ok'=>1,'found'=>$row ? 1 : 0,'case_serial'=>$serial,'pallet_id'=>(string)($row['pallet_id'] ?? '')]);
        }
        $pid = ps_normalize_scan_code((string)($input['pallet_id'] ?? ''));
        if ($pid === '') ps_out(['ok'=>0,'err'=>'Missing pallet_id'], 400);
        $pallet = smp_db_fetch_one($dbx, "SELECT pallet_id FROM pallets WHERE pallet_id=? LIMIT 1", [$pid]);
        $rows = $pallet ? smp_db_fetch_all($dbx,
            "SELECT case_serial FROM pallet_cases WHERE pallet_id=? ORDER BY id", [$pid]) : [];
        $cases = array_map(static fn(array $r): string => (string)$r['case_serial'], $rows);
        ps_out(['ok'=>1,'found'=>$pallet ? 1 : 0,'pallet_id'=>$pid,'case_count'=>count($cases),'cases'=>$cases]);
    }

    if ($action === 'case_check') {
        $serial = ps_normalize_scan_code((string)($input['case_serial'] ?? ''));
        $row = ps_direct_casecode_lookup($dbx, $serial);
        ps_out(['ok'=>$row?1:0, 'api_version'=>'1.6.0', 'scanned_code'=>$serial,
            'found'=>$row?1:0, 'sku'=>$row['SKU']??$row['sku']??null,
            'variety'=>$row['variety']??null, 'grower'=>$row['grower']??null,
            'err'=>$row?null:'Case not found in casecodes']);
    }

    if ($action === 'pallet_new') {
        $meta = ps_client_meta($input, $uid);
        $forceNew = !empty($input['force_new']);

        // The Modify Pallet workflow can leave an OPEN pallet associated with
        // this Zebra's device session.  When the operator explicitly chooses
        // New Pallet, release only the per-device session mapping; never alter
        // or delete the previously modified pallet itself.
        if ($forceNew && $meta['device_id'] !== '') {
            smp_db_exec($dbx, "DELETE FROM tc26_pallet_sessions WHERE device_id=?", [$meta['device_id']]);
        }

        $currentPid = $forceNew
            ? ''
            : ps_normalize_scan_code((string)($input['current_pallet_id'] ?? ''));
        if ($currentPid !== '') {
            $current = smp_db_fetch_one($dbx,
                "SELECT pallet_id,status FROM pallets WHERE pallet_id=? LIMIT 1", [$currentPid]);
            if ($current && strtoupper(trim((string)($current['status'] ?? 'OPEN'))) === 'OPEN') {
                ps_pallet_session_set($dbx, $meta['device_id'], $currentPid, $meta['operator_name']);
                $detail = ps_pallet_detail($dbx, $currentPid);
                $detail['reused_open_pallet'] = 1;
                $detail['message'] = 'An open pallet already exists on this device';
                ps_out($detail);
            }
            // The app may retain a pallet that was closed or deleted elsewhere.
            // Remove only that stale session mapping before finding or creating the
            // authoritative open pallet for this device.
            ps_pallet_session_clear($dbx, $currentPid);
        }
        $openPid = ps_pallet_session_get($dbx, $meta['device_id']);
        if ($openPid) {
            $detail = ps_pallet_detail($dbx, $openPid);
            $detail['reused_open_pallet'] = 1;
            $detail['message'] = 'An open pallet already exists on this device';
            ps_out($detail);
        }
        $pid = smp_tc26_open_pallet($dbx, $uid, '');
        ps_pallet_session_set($dbx, $meta['device_id'], $pid, $meta['operator_name']);
        ps_out(ps_pallet_detail($dbx, $pid));
    }
    if ($action === 'pallet_resume') {
        $pid = trim((string)($input['pallet_id'] ?? ''));
        $st = ps_pallet_detail($dbx, $pid);
        if (!empty($st['ok']) && strtoupper((string)($st['status'] ?? '')) !== 'PARTIAL') {
            ps_out(['ok'=>0, 'err'=>'The scanned pallet is not partial']);
        }
        smp_db_exec($dbx, "UPDATE pallets SET status='OPEN',is_partial=0,closed_at=NULL WHERE pallet_id=?", [$pid]);
        $meta = ps_client_meta($input, $uid);
        ps_pallet_session_set($dbx, $meta['device_id'], $pid, $meta['operator_name']);
        ps_out(ps_pallet_detail($dbx, $pid));
    }
    if ($action === 'pallet_edit_open') {
        $pid = ps_normalize_scan_code((string)($input['pallet_id'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $expected = strtolower(trim((string)($cfg['skip_po_password_sha256'] ?? '')));
        if ($expected === '' || !hash_equals($expected, hash('sha256', $password))) {
            ps_out(['ok'=>0,'err'=>'Incorrect password']);
        }
        $pallet = smp_db_fetch_one($dbx,
            "SELECT pallet_id,status FROM pallets WHERE pallet_id=? LIMIT 1", [$pid]);
        if (!$pallet) ps_out(['ok'=>0,'err'=>'Pallet not found']);
        $shipped = smp_db_fetch_one($dbx,
            "SELECT s.shipment_id FROM shipment_pallets sp
             JOIN shipments s ON s.shipment_id=sp.shipment_id
             WHERE sp.pallet_id=? AND UPPER(COALESCE(s.status,''))='CLOSED' LIMIT 1", [$pid]);
        if ($shipped) ps_out(['ok'=>0,'err'=>'This pallet belongs to closed shipment '.$shipped['shipment_id'].' and cannot be modified']);
        smp_db_exec($dbx,
            "UPDATE pallets SET status='OPEN',is_partial=0,closed_at=NULL WHERE pallet_id=?", [$pid]);
        $meta = ps_client_meta($input, $uid);
        ps_pallet_session_set($dbx, $meta['device_id'], $pid, $meta['operator_name']);
        ps_out(ps_pallet_detail($dbx, $pid));
    }
    if ($action === 'pallet_complete_context') {
        if (!($dbx instanceof PDO)) ps_out(['ok'=>0,'err'=>'Pallet rules require the orders database connection.'],503);
        require_once __DIR__.'/../includes/pallet_complete_rules.php';
        pcr_init($dbx);
        if (!smp_ownership_load_orders_lib()) ps_out(['ok'=>0,'err'=>'Customer ownership data is unavailable.'],503);
        ps_out(pcr_context($dbx,trim((string)($input['pallet_id']??'')),$input));
    }
    if ($action === 'pallet_status') {
        ps_out(ps_pallet_detail($dbx, trim((string)($input['pallet_id'] ?? ''))));
    }
    if ($action === 'pallet_scan_case') {
        $compact = !empty($input['compact_scan']);
        $pid = trim((string)($input['pallet_id'] ?? ''));
        $serial = ps_normalize_scan_code((string)($input['case_serial'] ?? ''));
        $caseRow = ps_direct_casecode_lookup($dbx, $serial);
        if (!$caseRow) ps_out(['ok'=>0, 'err'=>'Case '.$serial.' not found in casecodes', 'scanned_code'=>$serial]);
        $case = ps_case_product($caseRow);
        $case['case_serial'] = $serial;
        $existing = smp_db_fetch_one($dbx,
            "SELECT pallet_id FROM pallet_cases WHERE case_serial=? LIMIT 1", [$serial]);
        // Idempotent scan: Zebra/DataWedge can occasionally deliver the same
        // barcode twice. A repeat on the current pallet is a successful no-op.
        if ($existing && (string)$existing['pallet_id'] === $pid) {
            $detail = ps_pallet_detail($dbx, $pid, null, null, $compact);
            $detail['case']=$case;$detail['sku']=$case['sku'];
            $detail['duplicate_ignored'] = 1;
            ps_out($detail);
        }
        if ($existing) ps_out(['ok'=>0, 'err'=>'Case '.$serial.' already belongs to pallet '.$existing['pallet_id']]);
        $reference = ps_pallet_reference($dbx, $pid);
        if (ps_product_mismatch($reference, $case)) {
            $detail = ps_pallet_detail($dbx, $pid, $reference, null, $compact);
            $detail['sku_mismatch'] = 1;
            $detail['requires_override'] = 1;
            $detail['scanned_code'] = $serial;
            $detail['case'] = $case;
            $detail['expected_case'] = $reference;
            $detail['correct_sku'] = (string)($reference['sku'] ?? '');
            $detail['different_sku'] = (string)($case['sku'] ?? '');
            $detail['err'] = 'This case does not belong to this pallet';
            ps_out($detail);
        }
        $res = ps_add_case($dbx, $pid, $serial, $case, $uid);
        if (empty($res['ok'])) {
            $res['scanned_code']=$serial;
            ps_out($res);
        }
        // Reuse the reference already read for this scan. For the first case,
        // the accepted case becomes the reference immediately.
        $detail = ps_pallet_detail($dbx, $pid, $reference ?: $case, $res, $compact);
        $detail['case']=$case;$detail['sku']=$case['sku'];
        $detail['sku_mismatch'] = 0;
        ps_out($detail);
    }

    if ($action === 'pallet_sku_decision') {
        $pid = ps_normalize_scan_code((string)($input['pallet_id'] ?? ''));
        $serial = ps_normalize_scan_code((string)($input['case_serial'] ?? ''));
        $decision = strtoupper(trim((string)($input['decision'] ?? '')));
        $password = (string)($input['password'] ?? '');
        if (!in_array($decision, ['OVERRIDE','REMOVE'], true)) {
            ps_out(['ok'=>0,'err'=>'Invalid SKU decision']);
        }
        if (!ps_sku_override_password_valid($cfg, $password)) {
            ps_out(['ok'=>0,'err'=>'Incorrect password']);
        }
        $caseRow = ps_direct_casecode_lookup($dbx, $serial);
        if (!$caseRow) ps_out(['ok'=>0,'err'=>'Case '.$serial.' not found in casecodes']);
        $case = ps_case_product($caseRow);
        $case['case_serial'] = $serial;
        $reference = ps_pallet_reference($dbx, $pid);
        if (!$reference) ps_out(['ok'=>0,'err'=>'Pallet has no reference case']);
        if (!ps_product_mismatch($reference, $case)) {
            ps_out(['ok'=>0,'err'=>'The case SKU now matches this pallet; scan it again']);
        }

        $existing = smp_db_fetch_one($dbx,
            "SELECT id,pallet_id FROM pallet_cases WHERE case_serial=? LIMIT 1", [$serial]);
        if ($existing && (string)$existing['pallet_id'] !== $pid) {
            ps_out(['ok'=>0,'err'=>'Case '.$serial.' already belongs to pallet '.$existing['pallet_id']]);
        }

        if ($decision === 'OVERRIDE' && !$existing) {
            $res = ps_add_case($dbx, $pid, $serial, $case, $uid);
            if (empty($res['ok'])) ps_out($res);
        }
        if ($decision === 'REMOVE' && $existing) {
            $res = smp_tc26_remove_case($dbx, (int)$existing['id'], $pid);
            if (empty($res['ok'])) ps_out($res);
        }

        $meta = ps_client_meta($input, $uid);
        ps_log_sku_decision($dbx, $pid, $serial, $reference, $case, $decision, $meta);
        $detail = ps_pallet_detail($dbx, $pid);
        $detail['sku_decision'] = $decision;
        $detail['audit_logged'] = 1;
        ps_out($detail);
    }
    if ($action === 'pallet_remove_last') {
        $pid = ps_normalize_scan_code((string)($input['pallet_id'] ?? ''));
        $row = smp_db_fetch_one($dbx, "SELECT id FROM pallet_cases WHERE pallet_id=? ORDER BY id DESC LIMIT 1", [$pid]);
        if (!$row) ps_out(['ok'=>0, 'err'=>'No cases to remove']);
        $res = smp_tc26_remove_case($dbx, (int)$row['id'], $pid);
        if (empty($res['ok'])) ps_out($res);
        ps_out(ps_pallet_detail($dbx, $pid));
    }
    if ($action === 'pallet_remove_case') {
        $pid = ps_normalize_scan_code((string)($input['pallet_id'] ?? ''));
        $serial = ps_normalize_scan_code((string)($input['case_serial'] ?? ''));
        $row = smp_db_fetch_one($dbx,
            "SELECT id FROM pallet_cases WHERE pallet_id=? AND case_serial=? LIMIT 1", [$pid,$serial]);
        if (!$row) ps_out(['ok'=>0,'err'=>'Case '.$serial.' is not on this pallet']);
        $res = smp_tc26_remove_case($dbx, (int)$row['id'], $pid);
        if (empty($res['ok'])) ps_out($res);
        ps_out(ps_pallet_detail($dbx, $pid));
    }
    if ($action === 'pallet_delete') {
        $pid = ps_normalize_scan_code((string)($input['pallet_id'] ?? ''));
        $pallet = smp_db_fetch_one($dbx,
            "SELECT pallet_id FROM pallets WHERE pallet_id=? LIMIT 1", [$pid]);
        if (!$pallet) ps_out(['ok'=>0,'err'=>'Pallet not found']);
        $count = smp_db_fetch_one($dbx,
            "SELECT COUNT(*) c FROM pallet_cases WHERE pallet_id=?", [$pid]);
        if ((int)($count['c'] ?? 0) !== 0) {
            ps_out(['ok'=>0,'err'=>'Only an empty pallet can be deleted']);
        }
        $shipment = smp_db_fetch_one($dbx,
            "SELECT shipment_id FROM shipment_pallets WHERE pallet_id=? LIMIT 1", [$pid]);
        if ($shipment) ps_out(['ok'=>0,'err'=>'Pallet belongs to shipment '.$shipment['shipment_id']]);
        smp_db_exec($dbx, "DELETE FROM pallets WHERE pallet_id=?", [$pid]);
        ps_pallet_session_clear($dbx, $pid);
        ps_out(['ok'=>1,'deleted'=>1,'pallet_id'=>$pid]);
    }
    if ($action === 'pallet_partial') {
        $pid = trim((string)($input['pallet_id'] ?? ''));
        $res = smp_tc26_partial_pallet($dbx, $pid, $uid, 0);
        $res['label_printed'] = !empty($res['ok']) && smp_tc26_print_pallet_label($dbx, $pid, 0, true) ? 1 : 0;
        if (!empty($res['ok']) && empty($res['label_printed'])) $res['print_warning']='Pallet saved, but label was not sent. Check the printer selected in Pallets Manage.';
        if (!empty($res['ok']) && $dbx instanceof mysqli) $res['report'] = ppr_print_report($dbx, $pid);
        if (!empty($res['ok'])) ps_pallet_session_clear($dbx, $pid);
        ps_out($res);
    }
    if ($action === 'pallet_close' || $action === 'pallet_save_no_print') {
        $pid = trim((string)($input['pallet_id'] ?? ''));
        // Legacy close calls still print by default. An explicit zero suppresses
        // both the label and the pallet report, including the dedicated safe action.
        $printRequested = $action !== 'pallet_save_no_print'
            && (!array_key_exists('print_label', $input)
                || filter_var($input['print_label'], FILTER_VALIDATE_BOOLEAN));
        if (!($dbx instanceof PDO)) ps_out(['ok'=>0,'err'=>'Pallet rules require the orders database connection.'],503);
        require_once __DIR__.'/../includes/pallet_complete_rules.php';
        $meta=ps_client_meta($input,$uid);
        $res=pcr_close($dbx,$pid,$input,$meta['operator_name'].' / '.$meta['device_id']);
        if(empty($res['ok']))ps_out($res);
        if(!empty($res['already_closed']))$printRequested=false;
        $res['label_printed'] = 0;
        $res['print_skipped'] = $printRequested ? 0 : 1;
        if ($printRequested && !empty($res['ok'])) {
            $res['label_printed'] = smp_tc26_print_pallet_label($dbx, $pid, 0, false) ? 1 : 0;
            if (empty($res['label_printed'])) $res['print_warning']='Pallet closed, but label was not sent. Check the printer selected in Pallets Manage.';
            if ($dbx instanceof mysqli) $res['report'] = ppr_print_report($dbx, $pid);
        } elseif (!$printRequested) {
            $res['print_error'] = '';
            unset($res['print_warning']);
        }
        if (!empty($res['ok'])) ps_pallet_session_clear($dbx, $pid);
        ps_out($res);
    }

    if ($action === 'shipment_new') {
        $meta=ps_shipment_meta($input,$uid);
        $sid = smp_tc26_open_shipment($dbx, $uid, '');
        ps_shipment_touch($dbx,$sid,$meta);
        ps_out(ps_shipment_detail($dbx,$sid,$meta,!empty($input['private_device_view'])));
    }
    if ($action === 'shipment_open_list') {
        $meta=ps_shipment_meta($input,$uid);
        $rows = smp_db_fetch_all($dbx,
            "SELECT s.shipment_id,s.po,s.customer_name,s.order_id,
                    DATE_FORMAT(s.created_at,'%Y-%m-%d %H:%i') created_at,
                    (SELECT COUNT(DISTINCT CASE WHEN UPPER(TRIM(sp.pallet_id)) LIKE 'P%'
                              THEN UPPER(TRIM(sp.pallet_id)) END) FROM shipment_pallets sp
                     WHERE sp.shipment_id=s.shipment_id) pallet_count,
                    (SELECT COUNT(*) FROM shipment_pallets sp
                     JOIN pallet_cases pc ON pc.pallet_id=sp.pallet_id
                     WHERE sp.shipment_id=s.shipment_id) cases_count
             FROM shipments s
             WHERE UPPER(COALESCE(s.status,'OPEN'))='OPEN'
             ORDER BY s.created_at DESC,s.shipment_id DESC LIMIT 50");
        foreach($rows as &$row){$other=ps_shipment_other_devices($dbx,(string)$row['shipment_id'],(string)($meta['device_id']??''));$row['in_progress_elsewhere']=count($other)>0?1:0;$row['other_devices_active']=count($other);}unset($row);
        ps_out(['ok'=>1,'shipments'=>$rows ?: []]);
    }
    if ($action === 'shipment_resume') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        $meta=ps_shipment_meta($input,$uid);ps_shipment_touch($dbx,$sid,$meta);
        $st = ps_shipment_detail($dbx,$sid,$meta,!empty($input['private_device_view']));
        if (!empty($st['ok']) && strtoupper((string)($st['status'] ?? '')) !== 'OPEN') {
            ps_out(['ok'=>0, 'err'=>'The scanned shipment is not open']);
        }
        ps_out($st);
    }
    if ($action === 'shipment_save') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        if ($sid === '') ps_out(['ok'=>0,'err'=>'Missing shipment_id'],400);
        $meta=ps_shipment_meta($input,$uid);ps_shipment_touch($dbx,$sid,$meta);
        $st = ps_shipment_detail($dbx,$sid,$meta,!empty($input['private_device_view']));
        if (empty($st['ok'])) ps_out($st);
        $status = strtoupper(trim((string)($st['status'] ?? ($st['shipment']['status'] ?? 'OPEN'))));
        if ($status !== 'OPEN') ps_out(['ok'=>0,'err'=>'Only an OPEN shipment can be saved for later']);
        // Shipment rows, PO links and pallet links are already central DB data.
        // Keep it explicitly OPEN and return the authoritative server state so
        // every Zebra can immediately find/resume the same shipment.
        smp_db_exec($dbx,"UPDATE shipments SET status='OPEN',closed_at=NULL WHERE shipment_id=?",[$sid]);
        $st=ps_shipment_detail($dbx,$sid,$meta,!empty($input['private_device_view']));
        $st['saved_for_later']=1;
        $st['shared_between_devices']=1;
        ps_out($st);
    }
    if ($action === 'shipment_set_order') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        $meta=ps_shipment_meta($input,$uid);ps_shipment_touch($dbx,$sid,$meta);
        if($blocked=ps_shipment_takeover_guard($dbx,$sid,$meta))ps_out($blocked,423);
        $oid = (int)($input['order_id'] ?? 0);
        $selection = smp_validate_order_ids_for_shipment($dbx, $sid, [$oid]);
        if (empty($selection['ok'])) ps_out($selection);
        $ord = $selection['orders'][0];
        smp_db_exec($dbx,"DELETE FROM tc26_shipment_orders WHERE shipment_id=?",[$sid]);
        smp_db_exec($dbx,"DELETE FROM tc26_shipment_case_allocations WHERE shipment_id=?",[$sid]);
        smp_db_exec($dbx,
            "INSERT INTO tc26_shipment_orders(shipment_id,order_id,po,customer_name,sort_order) VALUES(?,?,?,?,0)",
            [$sid,(int)$ord['id'],(string)$ord['po'],(string)$ord['customer_name']]);
        smp_db_exec($dbx,
            "UPDATE shipments SET po=?,customer_name=?,order_id=?,ship_date=DATE(created_at) WHERE shipment_id=?",
            [(string)$ord['po'],(string)$ord['customer_name'],(int)$ord['id'],$sid]);
        ps_out(ps_shipment_detail($dbx,$sid,$meta,!empty($input['private_device_view'])));
    }
    if ($action === 'shipment_set_orders') {
        $sid=trim((string)($input['shipment_id']??''));
        $meta=ps_shipment_meta($input,$uid);ps_shipment_touch($dbx,$sid,$meta);
        if($blocked=ps_shipment_takeover_guard($dbx,$sid,$meta))ps_out($blocked,423);
        $ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($input['order_ids']??''))))));
        if(!$ids)ps_out(['ok'=>0,'err'=>'Select at least one PO']);
        require_once __DIR__ . '/../config/orders_sql_lib.php';
        if(!orders_sql_ready())ps_out(['ok'=>0,'err'=>'Orders database unavailable']);
        $selection=smp_validate_order_ids_for_shipment($dbx,$sid,$ids);
        if(empty($selection['ok']))ps_out($selection);
        smp_db_exec($dbx,"DELETE FROM tc26_shipment_orders WHERE shipment_id=?",[$sid]);
        smp_db_exec($dbx,"DELETE FROM tc26_shipment_case_allocations WHERE shipment_id=?",[$sid]);
        $poList=[];$customers=[];$sort=0;
        foreach((array)$selection['orders'] as $ord){
            $oid=(int)$ord['id'];$poList[]=(string)$ord['po'];$customers[]=(string)$ord['customer_name'];
            smp_db_exec($dbx,"INSERT INTO tc26_shipment_orders(shipment_id,order_id,po,customer_name,sort_order) VALUES(?,?,?,?,?)",[$sid,$oid,$ord['po'],$ord['customer_name'],$sort++]);
        }
        if(!$poList)ps_out(['ok'=>0,'err'=>'Selected orders not found']);
        smp_db_exec($dbx,"UPDATE shipments SET po=?,customer_name=?,order_id=?,ship_date=DATE(created_at) WHERE shipment_id=?",[implode(', ',$poList),implode(' / ',array_values(array_unique($customers))),(int)$selection['orders'][0]['id'],$sid]);
        $detail=ps_shipment_detail($dbx,$sid,$meta,!empty($input['private_device_view']));$detail['multi']=ps_allocate_shipment_cases($dbx,$sid);ps_out($detail);
    }
    if ($action === 'shipment_multi_status') {
        $sid=trim((string)($input['shipment_id']??''));$meta=ps_shipment_meta($input,$uid);ps_shipment_touch($dbx,$sid,$meta);
        ps_out(ps_allocate_shipment_cases($dbx,$sid));
    }
    if ($action === 'verify_skip_po_password') {
        $password = (string)($input['password'] ?? '');
        $expected = strtolower(trim((string)($cfg['skip_po_password_sha256'] ?? '')));
        $valid = $expected !== '' && hash_equals($expected, hash('sha256', $password));
        ps_out($valid ? ['ok'=>1] : ['ok'=>0, 'err'=>'Incorrect password']);
    }
    if ($action === 'order_search') {
        require_once __DIR__ . '/../config/orders_sql_lib.php';
        if (!orders_sql_ready()) ps_out(['ok'=>0,'err'=>'Orders database unavailable']);
        orders_sql_init();
        $q = '%'.trim((string)($input['q'] ?? '')).'%';
        $rows = orders_fetch_all(
            "SELECT o.id,o.po,o.client_id,COALESCE(c.client_name,o.customer,'') customer_name,o.status
             FROM orders o LEFT JOIN order_clients c ON c.id=o.client_id
             WHERE UPPER(COALESCE(o.status,'OPEN'))='OPEN'
               AND (o.po LIKE ? OR COALESCE(c.client_name,o.customer,'') LIKE ?)
             ORDER BY o.ship_date ASC,o.id DESC LIMIT 50", [$q,$q]);
        foreach($rows as &$orderRow){
            $policy=orders_client_grower_policy_sql((int)($orderRow['client_id']??0));
            $orderRow['grower_restricted']=!empty($policy['restricted'])?1:0;
            $orderRow['grower_ready']=(empty($policy['restricted'])||(int)$policy['grower_count']>0)?1:0;
            $orderRow['allowed_growers']=array_values(array_map(
                static fn(array $g):string => trim((string)($g['label_code']??''))!==''
                    ? trim((string)$g['label_code'])
                    : trim((string)($g['name']??'')),
                (array)$policy['growers']
            ));

            // PO preview for the TC26 New Shipment selector. Return the
            // actual order lines in the same AND/OR grouping used by webapp.
            $previewLines=[];
            $totalCases=0;
            foreach(orders_fetch_lines_sql((int)$orderRow['id']) as $line){
                $qty=max(0,(int)($line['quantity']??0));
                $totalCases+=$qty;
                $members=[];
                foreach((array)($line['allowed_skus']??[]) as $member){
                    $members[]=[
                        'sku'=>(string)($member['sku_code']??$member['sku_id']??''),
                        'variety'=>(string)($member['variety']??''),
                        'size'=>(string)($member['size']??''),
                        'packaging'=>(string)($member['packaging']??''),
                    ];
                }
                $previewLines[]=[
                    'quantity'=>$qty,
                    'is_mix'=>!empty($line['is_mix'])?1:0,
                    'line_type'=>!empty($line['is_mix'])?'OR':'AND',
                    'sku_display'=>(string)($line['sku_display']??''),
                    'variety_display'=>(string)($line['variety_display']??''),
                    'size_display'=>(string)($line['size_display']??$line['size']??''),
                    'packaging_display'=>(string)($line['packaging_display']??$line['packaging']??''),
                    'description_display'=>(string)($line['description_display']??''),
                    'size'=>(string)($line['size']??''),
                    'packaging_preset'=>(string)($line['packaging_preset']??''),
                    'allowed_skus'=>$members,
                ];
            }
            $orderRow['lines']=$previewLines;
            $orderRow['line_count']=count($previewLines);
            $orderRow['total_cases']=$totalCases;
            $orderRow['has_mix']=count(array_filter(
                $previewLines,
                static fn(array $line):bool=>!empty($line['is_mix'])
            ))>0?1:0;
        }
        unset($orderRow);
        ps_out(['ok'=>1, 'orders'=>$rows]);
    }
    if ($action === 'shipment_scan_pallet') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        $meta=ps_shipment_meta($input,$uid);ps_shipment_touch($dbx,$sid,$meta);
        if($blocked=ps_shipment_takeover_guard($dbx,$sid,$meta))ps_out($blocked,423);
        $pid = ps_normalize_shipping_pallet((string)($input['pallet_id'] ?? ''));
        if (!str_starts_with($pid, 'P')) {
            ps_out(['ok'=>0, 'err'=>'Scan a pallet code starting with P. Pallet count unchanged.'], 400);
        }
        $existing = smp_db_fetch_one($dbx,
            "SELECT id FROM shipment_pallets WHERE shipment_id=? AND pallet_id=? LIMIT 1", [$sid,$pid]);
        if ($existing) {
            $detail = ps_shipment_detail($dbx,$sid,$meta,!empty($input['private_device_view']));
            $detail['duplicate_ignored'] = 1;
            $detail['message']='This pallet is already part of the shipment.';
            ps_out($detail);
        }
        $res = smp_tc26_add_pallet_to_shipment($dbx, $sid, $pid, $uid);
        if (empty($res['ok'])) ps_out($res);
        smp_db_exec($dbx,"INSERT INTO tc26_shipment_pallet_devices(shipment_id,pallet_id,device_id) VALUES(?,?,?)
            ON DUPLICATE KEY UPDATE device_id=VALUES(device_id),added_at=CURRENT_TIMESTAMP",[$sid,$pid,(string)($meta['device_id']??'')]);
        $detail = ps_shipment_detail($dbx,$sid,$meta,!empty($input['private_device_view']));
        $detail['multi'] = ps_allocate_shipment_cases($dbx, $sid);
        $detail['scanned_pallet'] = smp_shipping_pallet_summary($dbx, $pid);
        ps_out($detail);
    }
    if ($action === 'shipment_remove_last') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        $meta=ps_shipment_meta($input,$uid);ps_shipment_touch($dbx,$sid,$meta);
        if($blocked=ps_shipment_takeover_guard($dbx,$sid,$meta))ps_out($blocked,423);
        $deviceId=(string)($meta['device_id']??'');
        $row=$deviceId!==''?smp_db_fetch_one($dbx,"SELECT sp.id,sp.pallet_id FROM shipment_pallets sp JOIN tc26_shipment_pallet_devices d ON d.shipment_id=sp.shipment_id AND d.pallet_id=sp.pallet_id WHERE sp.shipment_id=? AND d.device_id=? ORDER BY d.added_at DESC,sp.id DESC LIMIT 1",[$sid,$deviceId]):smp_db_fetch_one($dbx,"SELECT id,pallet_id FROM shipment_pallets WHERE shipment_id=? ORDER BY id DESC LIMIT 1",[$sid]);
        if (!$row) ps_out(['ok'=>0, 'err'=>'No pallets added by this Zebra to remove']);
        $lastPal=smp_db_fetch_one($dbx,"SELECT pallet_id FROM shipment_pallets WHERE id=?",[(int)$row['id']]);
        if($lastPal)smp_db_exec($dbx,"DELETE FROM tc26_shipment_case_allocations WHERE shipment_id=? AND pallet_id=?",[$sid,$lastPal['pallet_id']]);
        $res = smp_tc26_remove_pallet_from_shipment($dbx, (int)$row['id'], $sid);
        if (empty($res['ok'])) ps_out($res);
        if($lastPal)smp_db_exec($dbx,"DELETE FROM tc26_shipment_pallet_devices WHERE shipment_id=? AND pallet_id=?",[$sid,$lastPal['pallet_id']]);
        $detail=ps_shipment_detail($dbx,$sid,$meta,!empty($input['private_device_view']));$detail['multi']=ps_allocate_shipment_cases($dbx,$sid);ps_out($detail);
    }
    if ($action === 'shipment_take_over') {
        $sid=trim((string)($input['shipment_id']??''));$meta=ps_shipment_meta($input,$uid);
        if($sid==='')ps_out(['ok'=>0,'err'=>'Missing shipment_id'],400);
        if(!ps_shipment_takeover_password_valid($cfg,(string)($input['password']??'')))ps_out(['ok'=>0,'err'=>'Incorrect take-over password'],403);
        $deviceId=trim((string)($meta['device_id']??''));if($deviceId==='')ps_out(['ok'=>0,'err'=>'Device ID is required for shipment take-over'],400);
        ps_shipment_touch($dbx,$sid,$meta);
        smp_db_exec($dbx,"INSERT INTO tc26_shipment_takeovers(shipment_id,device_id,device_model,operator_id,operator_name)
            VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE device_id=VALUES(device_id),device_model=VALUES(device_model),operator_id=VALUES(operator_id),operator_name=VALUES(operator_name),taken_over_at=CURRENT_TIMESTAMP",[$sid,$deviceId,(string)($meta['device_model']??''),(int)($meta['operator_id']??0),(string)($meta['operator_name']??'')]);
        $detail=ps_shipment_detail($dbx,$sid,$meta,!empty($input['private_device_view']));$detail['takeover_completed']=1;ps_out($detail);
    }
    if ($action === 'shipment_release_take_over') {
        $sid=trim((string)($input['shipment_id']??''));$meta=ps_shipment_meta($input,$uid);$takeover=ps_shipment_takeover_row($dbx,$sid);
        if(!$takeover)ps_out(['ok'=>1,'released'=>0]);
        $deviceId=trim((string)($meta['device_id']??''));if($deviceId===''||!hash_equals((string)$takeover['device_id'],$deviceId))ps_out(['ok'=>0,'err'=>'Only the Zebra that took over this shipment can release it'],403);
        smp_db_exec($dbx,"DELETE FROM tc26_shipment_takeovers WHERE shipment_id=?",[$sid]);
        $detail=ps_shipment_detail($dbx,$sid,$meta,!empty($input['private_device_view']));$detail['released']=1;ps_out($detail);
    }
    if ($action === 'shipment_close') {
        $sid = trim((string)($input['shipment_id'] ?? ''));
        $meta=ps_shipment_meta($input,$uid);ps_shipment_touch($dbx,$sid,$meta);
        if($blocked=ps_shipment_takeover_guard($dbx,$sid,$meta))ps_out($blocked,423);
        $other=ps_shipment_other_devices($dbx,$sid,(string)($meta['device_id']??''));$takeover=ps_shipment_takeover_row($dbx,$sid);
        $ownsTakeover=$takeover&&(string)($meta['device_id']??'')!==''&&hash_equals((string)$takeover['device_id'],(string)$meta['device_id']);
        if($other&&!$ownsTakeover)ps_out(['ok'=>0,'err'=>'Other Zebra devices are still active on this shipment. Use Take Over with the password before closing it.','other_devices_active'=>count($other),'requires_take_over'=>1],409);
        // The shared close commits every saved PO link together with the
        // shipment before label printing or notification email can delay it.
        $res=smp_tc26_close_shipment($dbx,$sid,$uid,0);
        if(!empty($res['ok'])){smp_db_exec($dbx,"DELETE FROM tc26_shipment_takeovers WHERE shipment_id=?",[$sid]);smp_db_exec($dbx,"DELETE FROM tc26_shipment_device_sessions WHERE shipment_id=?",[$sid]);}
        ps_out($res);
    }

    ps_out(['ok'=>0, 'err'=>'Unknown action'], 400);
} catch (Throwable $e) {
    ps_out(['ok'=>0, 'err'=>$e->getMessage()], 500);
}
