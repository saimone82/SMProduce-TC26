<?php
// SM Produce Bins Receiving API v1.1.2
header('Content-Type: application/json; charset=utf-8');

$TOKEN = 'SMTC26_SECURE_2026';
$incoming = (string)($_SERVER['HTTP_X_APP_TOKEN'] ?? $_GET['token'] ?? '');
if (!hash_equals($TOKEN, $incoming)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user'] = [
    'username' => 'Bins Receiving Tablet',
    'name' => 'Bins Receiving Tablet',
    'role' => 'warehouse',
];

require_once __DIR__ . '/../../config/db_remote.php';
$action = trim((string)($_REQUEST['api_action'] ?? 'presets'));

/* Password-protected record editor used by Bins Receiving 1.1.3+. */
$EDIT_PASSWORD = 'Apples2424';
$requireEditPassword = static function() use ($EDIT_PASSWORD): void {
    $password = (string)($_POST['edit_password'] ?? '');
    if (!hash_equals($EDIT_PASSWORD, $password)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Invalid password']);
        exit;
    }
};

if ($action === 'list_records') {
    $requireEditPassword();
    $kind = (string)($_POST['kind'] ?? '');
    $rows = [];
    if ($kind === 'full') {
        $sql = "SELECT bi.group_id AS id, COALESCE(g.name,'') grower,
                       COALESCE(t.name,'') type, COALESCE(v.name,'') variety,
                       COALESCE(MIN(bi.lot),'') lot, DATE_FORMAT(MIN(bi.date),'%Y-%m-%d') date,
                       COUNT(DISTINCT CASE WHEN NULLIF(TRIM(bi.barcode),'') IS NOT NULL
                           THEN CONCAT('B:',TRIM(bi.barcode)) ELSE CONCAT('I:',bi.id) END) quantity
                FROM bins_ingresso bi
                LEFT JOIN growers_list g ON g.id=bi.grower_id
                LEFT JOIN bin_types_list t ON t.id=bi.type_id
                LEFT JOIN varieties_list v ON v.id=bi.variety_id
                WHERE bi.status='AVAILABLE'
                GROUP BY bi.group_id,g.name,t.name,v.name
                ORDER BY MAX(bi.id) DESC LIMIT 100";
    } elseif ($kind === 'empty') {
        $sql = "SELECT id,grower,type,'' variety,'' lot,DATE_FORMAT(date,'%Y-%m-%d') date,quantity
                FROM empty_bins ORDER BY id DESC LIMIT 100";
    } else {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid record type']); exit;
    }
    if ($q=$mysqli->query($sql)) while($r=$q->fetch_assoc()) $rows[]=$r;
    echo json_encode(['ok'=>true,'records'=>$rows]); exit;
}

if ($action === 'update_record') {
    $requireEditPassword();
    $kind=(string)($_POST['kind']??''); $id=max(0,(int)($_POST['id']??0));
    $grower=trim((string)($_POST['grower']??'')); $type=trim((string)($_POST['type']??''));
    $variety=trim((string)($_POST['variety']??'')); $lot=trim((string)($_POST['lot']??''));
    $date=trim((string)($_POST['date']??'')); $quantity=max(0,(int)($_POST['quantity']??0));
    if($id<=0||$grower===''||$type===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||$quantity<=0){
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Complete all required fields']); exit;
    }
    try {
        $mysqli->begin_transaction();
        if($kind==='empty'){
            $s=$mysqli->prepare("SELECT grower,type,quantity FROM empty_bins WHERE id=? FOR UPDATE");
            $s->bind_param('i',$id); $s->execute(); $old=$s->get_result()->fetch_assoc(); $s->close();
            if(!$old) throw new Exception('Record not found');
            $s=$mysqli->prepare("UPDATE empty_bins SET grower=?,type=?,date=?,quantity=? WHERE id=?");
            $s->bind_param('sssii',$grower,$type,$date,$quantity,$id); $s->execute(); $s->close();
            $delta=$quantity-(int)$old['quantity'];
            if($delta!==0){$reason='Edited from Bins Receiving app';$s=$mysqli->prepare("INSERT INTO empty_bins_log(grower,type,qty_change,reason,source_empty_bin_id) VALUES(?,?,?,?,?)");$s->bind_param('ssisi',$grower,$type,$delta,$reason,$id);$s->execute();$s->close();}
        } elseif($kind==='full'){
            if($variety==='') throw new Exception('Variety is required');
            $findId=static function(mysqli $db,string $table,string $name): int {$s=$db->prepare("SELECT id FROM `$table` WHERE name=? LIMIT 1");$s->bind_param('s',$name);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return (int)($r['id']??0);};
            $gid=$findId($mysqli,'growers_list',$grower); $tid=$findId($mysqli,'bin_types_list',$type); $vid=$findId($mysqli,'varieties_list',$variety);
            if(!$gid||!$tid||!$vid) throw new Exception('Grower, type or variety not found');
            $q=$mysqli->query("SELECT id,barcode FROM bins_ingresso WHERE group_id=$id AND status='AVAILABLE' ORDER BY id ASC FOR UPDATE");
            $existing=$q?$q->fetch_all(MYSQLI_ASSOC):[]; if(!$existing) throw new Exception('Record not found');
            $oldQty=count($existing); $s=$mysqli->prepare("UPDATE bins_ingresso SET grower_id=?,type_id=?,variety_id=?,lot=?,date=? WHERE group_id=? AND status='AVAILABLE'");
            $s->bind_param('iiissi',$gid,$tid,$vid,$lot,$date,$id);$s->execute();$s->close();
            if($quantity<$oldQty){
                $remove=array_slice(array_reverse($existing),0,$oldQty-$quantity);
                foreach($remove as $r){$rid=(int)$r['id'];$bc=trim((string)$r['barcode']);if($bc!==''){$s=$mysqli->prepare("DELETE FROM casecodes WHERE serial=?");if($s){$s->bind_param('s',$bc);@$s->execute();$s->close();}}$mysqli->query("DELETE FROM bins_ingresso WHERE id=$rid");}
            } elseif($quantity>$oldQty){
                $s=$mysqli->prepare("INSERT INTO bins_ingresso(grower_id,variety_id,type_id,lot,date,status,group_id) VALUES(?,?,?,?,?,'AVAILABLE',?)");
                for($i=$oldQty;$i<$quantity;$i++){$s->bind_param('iiissi',$gid,$vid,$tid,$lot,$date,$id);$s->execute();$nid=(int)$mysqli->insert_id;$bc='FBIN-'.str_pad((string)$nid,5,'0',STR_PAD_LEFT);$u=$mysqli->prepare("UPDATE bins_ingresso SET barcode=? WHERE id=?");$u->bind_param('si',$bc,$nid);$u->execute();$u->close();}$s->close();
            }
            $delta=$quantity-$oldQty;if($delta!==0){$reason="Edited from Bins Receiving app ($oldQty to $quantity)";$s=$mysqli->prepare("INSERT INTO full_bins_log(group_id,grower,variety,type,lot,qty_change,reason,receipt_id) VALUES(?,?,?,?,?,?,?,NULL)");$s->bind_param('issssis',$id,$grower,$variety,$type,$lot,$delta,$reason);$s->execute();$s->close();}
        } else throw new Exception('Invalid record type');
        $mysqli->commit(); echo json_encode(['ok'=>true]);
    } catch(Throwable $e) {
        try{$mysqli->rollback();}catch(Throwable $ignore){}
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

if ($action === 'add_grower') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Grower name is required']);
        exit;
    }
    if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);
    $stmt = $mysqli->prepare("INSERT IGNORE INTO growers_list(name) VALUES(?)");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Cannot prepare grower insert']);
        exit;
    }
    $stmt->bind_param('s', $name);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Cannot save grower']);
        exit;
    }
    echo json_encode(['ok'=>true,'name'=>$name,'msg'=>'Grower saved']);
    exit;
}

if ($action === 'presets') {
    $readNames = function(string $table) use ($mysqli): array {
        $out=[];
        $q=$mysqli->query("SELECT name FROM `$table` ORDER BY name ASC");
        if ($q) while($r=$q->fetch_assoc()) $out[]=(string)$r['name'];
        return $out;
    };
    echo json_encode([
        'ok'=>true,
        'growers'=>$readNames('growers_list'),
        'binTypes'=>$readNames('bin_types_list'),
        'varieties'=>$readNames('varieties_list'),
    ]);
    exit;
}

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_SERVER['REQUEST_METHOD'] = 'POST';
$wantPrint = ((string)($_POST['print'] ?? '1') !== '0');
$_POST['skip_print'] = $wantPrint ? '0' : '1';

if ($action === 'save_empty') {
    $_POST['save_empty'] = '1';
    $_POST['grower'] = trim((string)($_POST['grower'] ?? ''));
    $_POST['type'] = trim((string)($_POST['type'] ?? ''));
    $_POST['quantity'] = max(0, (int)($_POST['quantity'] ?? 0));
    $_POST['date'] = trim((string)($_POST['date'] ?? date('Y-m-d')));
    $_POST['carrier'] = trim((string)($_POST['carrier'] ?? ''));
    $_POST['notes'] = trim((string)($_POST['notes'] ?? ''));
    require __DIR__ . '/../empty_bin_receiving.php';
    exit;
}

if ($action === 'save_full') {
    $_POST['action'] = 'add';
    $_POST['grower'] = trim((string)($_POST['grower'] ?? ''));
    $_POST['type'] = trim((string)($_POST['type'] ?? ''));
    $_POST['variety'] = trim((string)($_POST['variety'] ?? ''));
    // Lot is intentionally optional. Empty string is valid and is stored as empty.
    $_POST['lot'] = trim((string)($_POST['lot'] ?? ''));
    $_POST['quantity'] = max(1, (int)($_POST['quantity'] ?? 1));
    $_POST['date'] = trim((string)($_POST['date'] ?? date('Y-m-d')));
    $_POST['notes'] = trim((string)($_POST['notes'] ?? ''));
    require __DIR__ . '/../bins_ingresso.php';
    exit;
}

http_response_code(400);
echo json_encode(['ok'=>false,'error'=>'Unknown action']);
