<?php
require_once __DIR__ . '/../config/user_functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user'])) { header('Location: /auth/login.php'); exit; }
$role = $_SESSION['user']['role'] ?? 'viewer';
if (!sp_allow('bins', ['warehouse','supervisor'])) { http_response_code(403); die('Forbidden — you do not have access to this section.'); }

require_once __DIR__ . '/../config/db_remote.php';
require_once __DIR__ . '/../includes/print_engine.php';
require_once __DIR__ . '/../includes/full_bin_report.php';

/* ── helper ── */
function bh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ── ensure tables ── */
$mysqli->query("CREATE TABLE IF NOT EXISTS bin_types_list (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) UNIQUE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$mysqli->query("CREATE TABLE IF NOT EXISTS growers_list   (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) UNIQUE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$mysqli->query("CREATE TABLE IF NOT EXISTS varieties_list (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) UNIQUE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$mysqli->query("CREATE TABLE IF NOT EXISTS empty_bins_log (id INT AUTO_INCREMENT PRIMARY KEY, grower VARCHAR(100) NOT NULL, type VARCHAR(100) NOT NULL, qty_change INT NOT NULL, reason VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Keep source receipt lineage in the movement log.
$ebLogCols = [];
if ($resCols = $mysqli->query("SHOW COLUMNS FROM empty_bins_log")) {
    while ($c = $resCols->fetch_assoc()) $ebLogCols[strtolower((string)$c['Field'])] = true;
}
if (!isset($ebLogCols['source_empty_bin_id'])) {
    $mysqli->query("ALTER TABLE empty_bins_log ADD COLUMN source_empty_bin_id INT NULL AFTER type");
}
if (!isset($ebLogCols['report_pdf'])) {
    $mysqli->query("ALTER TABLE empty_bins_log ADD COLUMN report_pdf VARCHAR(255) NULL AFTER reason");
}


$mysqli->query("
    CREATE TABLE IF NOT EXISTS full_bins_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        group_id BIGINT NULL,
        grower VARCHAR(100) NOT NULL,
        variety VARCHAR(100) NOT NULL,
        type VARCHAR(100) NOT NULL,
        lot VARCHAR(120) NULL,
        qty_change INT NOT NULL,
        reason VARCHAR(255) NULL,
        receipt_id BIGINT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fbl_group(group_id),
        INDEX idx_fbl_receipt(receipt_id),
        INDEX idx_fbl_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

fbr_ensure_settings($mysqli);
fbr_ensure_receipts($mysqli);

/* ADMIN ONLY — delete Full Bin Movement Log events.
   Inventory is untouched. Receipt-backed PDFs are physically deleted.
   Receipt is soft-deleted to prevent movement-log backfill from recreating it. */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['movement_log_delete'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($role !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Admin only.']); exit;
    }

    $raw=$_POST['ids']??[];
    if(!is_array($raw)) $raw=[$raw];
    $ids=array_values(array_unique(array_filter(array_map('intval',$raw),fn($v)=>$v>0)));
    if(!$ids){ echo json_encode(['ok'=>false,'error'=>'Select at least one event.']); exit; }
    if(count($ids)>5000){ echo json_encode(['ok'=>false,'error'=>'Too many events selected.']); exit; }

    $idList=implode(',',$ids);
    $q=$mysqli->query("SELECT id,receipt_id FROM full_bins_log WHERE id IN ($idList)");
    $rows=$q?$q->fetch_all(MYSQLI_ASSOC):[];
    if(!$rows){ echo json_encode(['ok'=>false,'error'=>'No matching movement events found.']); exit; }

    $receiptIds=array_values(array_unique(array_filter(
        array_map(fn($r)=>(int)($r['receipt_id']??0),$rows),fn($v)=>$v>0
    )));

    $pdfFiles=[];
    if($receiptIds){
        $ridList=implode(',',$receiptIds);
        $rq=$mysqli->query("SELECT id,report_pdf FROM full_bin_receipts WHERE id IN ($ridList)");
        if($rq){
            while($rr=$rq->fetch_assoc()){
                $pdf=trim((string)($rr['report_pdf']??''));
                if($pdf!==''){
                    $abs=__DIR__.'/../data/full_bin_reports/'.basename($pdf);
                    if(is_file($abs)) $pdfFiles[$abs]=true;
                }
            }
        }
    }

    foreach(array_keys($pdfFiles) as $abs){
        if(!@unlink($abs)){
            echo json_encode(['ok'=>false,'error'=>'Unable to delete linked PDF: '.basename($abs)]); exit;
        }
    }

    try{
        $mysqli->begin_transaction();
        if($receiptIds){
            $ridList=implode(',',$receiptIds);
            $mysqli->query("UPDATE full_bin_receipts SET report_pdf=NULL,is_deleted=1 WHERE id IN ($ridList)");
        }
        $mysqli->query("DELETE FROM full_bins_log WHERE id IN ($idList)");
        $deleted=$mysqli->affected_rows;
        $mysqli->commit();
        echo json_encode(['ok'=>true,'deleted'=>$deleted,'pdf_deleted'=>count($pdfFiles)]);
    }catch(Throwable $e){
        try{$mysqli->rollback();}catch(Throwable $ignore){}
        echo json_encode(['ok'=>false,'error'=>'Delete failed: '.$e->getMessage()]);
    }
    exit;
}


/* ── Backfill Full Bin Movement Log from existing receipts ──
   Older Full Bin records existed before full_bins_log was introduced.
   Import each receipt once so Movement Log immediately reflects history. */
$hasReceiptUnique = false;
if ($idxRes = $mysqli->query("SHOW INDEX FROM full_bins_log WHERE Key_name='uq_full_bins_log_receipt'")) {
    $hasReceiptUnique = $idxRes->num_rows > 0;
}
if (!$hasReceiptUnique) {
    // Multiple NULL receipt_id values are allowed by MySQL unique indexes.
    @$mysqli->query("ALTER TABLE full_bins_log ADD UNIQUE INDEX uq_full_bins_log_receipt (receipt_id)");
}

$backfillSql = "
    INSERT IGNORE INTO full_bins_log
        (group_id,grower,variety,type,lot,qty_change,reason,receipt_id,created_at)
    SELECT
        r.group_id,
        r.grower,
        r.variety,
        r.type,
        COALESCE(r.lot,''),
        r.quantity,
        'Full bins received',
        r.id,
        r.created_at
    FROM full_bin_receipts r
    LEFT JOIN full_bins_log l ON l.receipt_id=r.id
    WHERE l.id IS NULL
      AND COALESCE(r.is_deleted,0)=0
";
$mysqli->query($backfillSql);

/* Fallback for very old inventory rows that predate full_bin_receipts too:
   create one opening-balance movement per group only when that group has no
   receipt and no movement history at all. */
$legacyGroups = $mysqli->query("
    SELECT
        bi.group_id,
        COALESCE(gp.name,'') AS grower,
        COALESCE(vl.name,'') AS variety,
        COALESCE(tl.name,'') AS type,
        COALESCE(MIN(bi.lot),'') AS lot,
        COUNT(*) AS qty,
        MIN(CONCAT(bi.date,' 00:00:00')) AS created_at
    FROM bins_ingresso bi
    LEFT JOIN growers_list gp ON bi.grower_id=gp.id
    LEFT JOIN varieties_list vl ON bi.variety_id=vl.id
    LEFT JOIN bin_types_list tl ON bi.type_id=tl.id
    LEFT JOIN full_bin_receipts r ON r.group_id=bi.group_id
    LEFT JOIN full_bins_log l ON l.group_id=bi.group_id
    WHERE r.id IS NULL AND l.id IS NULL
    GROUP BY bi.group_id,gp.name,vl.name,tl.name
");
if ($legacyGroups) {
    while ($lg=$legacyGroups->fetch_assoc()) {
        $lgid=(int)$lg['group_id'];
        $lgrow=(string)$lg['grower'];
        $lvar=(string)$lg['variety'];
        $ltype=(string)$lg['type'];
        $llot=(string)$lg['lot'];
        $lqty=(int)$lg['qty'];
        $ldt=(string)$lg['created_at'];
        $reason='Opening balance imported from existing Full Bin inventory';

        $stmtLegacy=$mysqli->prepare("
            INSERT INTO full_bins_log
                (group_id,grower,variety,type,lot,qty_change,reason,receipt_id,created_at)
            VALUES(?,?,?,?,?,?,?,NULL,?)
        ");
        if ($stmtLegacy) {
            $stmtLegacy->bind_param('issssiss',$lgid,$lgrow,$lvar,$ltype,$llot,$lqty,$reason,$ldt);
            $stmtLegacy->execute();
            $stmtLegacy->close();
        }
    }
}


$fullBinPrintSettings=fbr_get_settings($mysqli);
$windowsReportPrinters=ebr_windows_printers();

$fullBinLabelTemplates = [];
try {
    $tplQ = $mysqli->query("
        SELECT id,name,dpi,template_mode,is_active
        FROM print_templates
        WHERE is_active=1
          AND label_type='full_bins'
        ORDER BY name ASC,id ASC
    ");
    if ($tplQ) $fullBinLabelTemplates = $tplQ->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $tplEx) {
    $fullBinLabelTemplates = [];
}


/* ── load presets ── */
$growers   = $mysqli->query("SELECT id, name FROM growers_list   ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$varieties = $mysqli->query("SELECT id, name FROM varieties_list ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$types     = $mysqli->query("SELECT id, name FROM bin_types_list ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

/* ── ensure barcode column exists + backfill ── */
/* ── barcode column: compatible with MySQL 5.7+ / MariaDB ── */
$_bc_check = $mysqli->query("SHOW COLUMNS FROM bins_ingresso LIKE 'barcode'");
if ($_bc_check && $_bc_check->num_rows === 0) {
    $mysqli->query("ALTER TABLE bins_ingresso ADD COLUMN barcode VARCHAR(20) NULL AFTER id");
    @$mysqli->query("ALTER TABLE bins_ingresso ADD UNIQUE INDEX idx_barcode (barcode)");
}
$mysqli->query("UPDATE bins_ingresso SET barcode = CONCAT('FBIN-', LPAD(id,5,'0')) WHERE barcode IS NULL OR barcode = ''");
/* ── Full Bin label batch traceability ──────────────────────────────────────
   Each receiving action is a distinct label batch. This preserves "1 of 20"
   even if more bins are later added to the same inventory group.
----------------------------------------------------------------------------- */
$_fb_cols = [];
if ($_fb_res = $mysqli->query("SHOW COLUMNS FROM bins_ingresso")) {
    while ($_fb_row = $_fb_res->fetch_assoc()) {
        $_fb_cols[strtolower((string)$_fb_row['Field'])] = true;
    }
}
if (!isset($_fb_cols['receipt_id'])) {
    @$mysqli->query("ALTER TABLE bins_ingresso ADD COLUMN receipt_id BIGINT NULL AFTER group_id");
    @$mysqli->query("ALTER TABLE bins_ingresso ADD INDEX idx_bins_receipt_id(receipt_id)");
}
if (!isset($_fb_cols['batch_position'])) {
    @$mysqli->query("ALTER TABLE bins_ingresso ADD COLUMN batch_position INT NULL AFTER receipt_id");
}
if (!isset($_fb_cols['batch_total'])) {
    @$mysqli->query("ALTER TABLE bins_ingresso ADD COLUMN batch_total INT NULL AFTER batch_position");
}



/* ── load printers ── */
$printers = [];
try {
    $t = $mysqli->query("SHOW TABLES LIKE 'printers_list'");
    if ($t && $t->num_rows > 0) {
        $q = $mysqli->query("SELECT id, COALESCE(printer_name,name) AS display_name, is_default, dpi, printer_ip, active FROM printers_list WHERE active=1 ORDER BY is_default DESC, display_name ASC");
        if ($q) $printers = $q->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) { $printers = []; }

$msg = null; $err = null;

/* ── consume empty bins FIFO ── */
function consume_empty_bins_out(mysqli $mysqli, string $growerName, string $typeName, int $qty, int $groupId): void {
    if ($qty <= 0) return;
    $ge = $mysqli->real_escape_string($growerName);
    $te = $mysqli->real_escape_string($typeName);
    $remaining = $qty;
    $res = $mysqli->query("SELECT id, quantity FROM empty_bins WHERE grower='$ge' AND type='$te' AND quantity>0 ORDER BY date ASC, id ASC");
    if (!$res) return;
    while ($row = $res->fetch_assoc()) {
        if ($remaining <= 0) break;
        $rid = (int)$row['id']; $rq = (int)$row['quantity'];
        if ($rq <= 0) continue;
        $take = min($remaining, $rq); $remaining -= $take; $nq = $rq - $take;
        if ($nq > 0) $mysqli->query("UPDATE empty_bins SET quantity=$nq WHERE id=$rid");
        else         $mysqli->query("DELETE FROM empty_bins WHERE id=$rid");
        $reason = $mysqli->real_escape_string("Full bins received group #$groupId");
        $srcPdf = null;
        if ($pdfRes = $mysqli->query("SELECT report_pdf FROM empty_bins_log WHERE source_empty_bin_id=$rid AND qty_change>0 AND report_pdf IS NOT NULL ORDER BY id ASC LIMIT 1")) {
            if ($pdfRow = $pdfRes->fetch_assoc()) $srcPdf = trim((string)($pdfRow['report_pdf'] ?? ''));
        }
        $pdfSql = $srcPdf !== null && $srcPdf !== '' ? "'" . $mysqli->real_escape_string($srcPdf) . "'" : "NULL";
        $mysqli->query("INSERT INTO empty_bins_log(grower,type,source_empty_bin_id,qty_change,reason,report_pdf) VALUES('$ge','$te',$rid," . (-$take) . ",'$reason',$pdfSql)");
    }
}

/* ── AJAX: Full Bin Current Balance ── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['full_bin_balance_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $rows=[];
    $q=$mysqli->query("
        SELECT gp.name AS grower, vl.name AS variety, tl.name AS type, bi.lot, COUNT(*) AS qty
        FROM bins_ingresso bi
        LEFT JOIN growers_list gp ON bi.grower_id=gp.id
        LEFT JOIN varieties_list vl ON bi.variety_id=vl.id
        LEFT JOIN bin_types_list tl ON bi.type_id=tl.id
        WHERE bi.status='AVAILABLE'
        GROUP BY gp.name,vl.name,tl.name,bi.lot
        ORDER BY gp.name,vl.name,tl.name,bi.lot
    ");
    if($q)while($r=$q->fetch_assoc())$rows[]=$r;
    echo json_encode(['ok'=>true,'balances'=>$rows]); exit;
}

/* ── AJAX: persistent printer settings ── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_full_bin_printer_setting'])) {
    header('Content-Type: application/json; charset=utf-8');
    $kind=trim((string)($_POST['kind']??''));
    if($kind==='label'){
        $pid=(int)($_POST['printer_id']??0);
        echo json_encode(['ok'=>fbr_set_label_printer($mysqli,$pid)]);
        exit;
    }
    if($kind==='template'){
        $tid=(int)($_POST['template_id']??0);
        if($tid>0){
            $stmtTpl=$mysqli->prepare("SELECT id FROM print_templates WHERE id=? AND label_type='full_bins' AND is_active=1 LIMIT 1");
            $stmtTpl->bind_param('i',$tid);
            $stmtTpl->execute();
            $validTpl=$stmtTpl->get_result()->fetch_assoc();
            $stmtTpl->close();
            if(!$validTpl){echo json_encode(['ok'=>false,'error'=>'Selected Full Bin template is not active or does not exist.']);exit;}
        }
        echo json_encode(['ok'=>fbr_set_label_template($mysqli,$tid)]);
        exit;
    }
    if($kind==='report'){
        $name=trim((string)($_POST['printer_name']??''));
        $installed=ebr_windows_printers();
        if($name!==''&&!in_array($name,$installed,true)){echo json_encode(['ok'=>false,'error'=>'Selected Windows printer is not available.']);exit;}
        echo json_encode(['ok'=>fbr_set_report_printer($mysqli,$name)]);
        exit;
    }
    echo json_encode(['ok'=>false,'error'=>'Unknown printer setting.']); exit;
}

/* ── AJAX: report preview / test print ── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['full_bin_report_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $rid=(int)($_POST['receipt_id']??0); $action=trim((string)($_POST['full_bin_report_action']??''));
    $stmt=$mysqli->prepare("SELECT * FROM full_bin_receipts WHERE id=? LIMIT 1");
    $stmt->bind_param('i',$rid); $stmt->execute(); $receipt=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if(!$receipt){echo json_encode(['ok'=>false,'error'=>'Receipt not found.']);exit;}
    $file=fbr_report_file((int)$receipt['id'],(string)$receipt['receiving_date']);
    if(!$file['exists']){
        $growerInventory=fbr_grower_inventory($mysqli,(string)($receipt['grower']??''));
        $gen=fbr_generate_receipt_pdf($receipt,[
            'inventory_rows'=>$growerInventory['rows']??[],
            'grower_total'=>(int)($growerInventory['total']??0),
        ]);
        if(empty($gen['ok'])){echo json_encode(['ok'=>false,'error'=>$gen['error']??'Unable to generate report.']);exit;}
        $file=$gen;
    }
    if($action==='preview'){echo json_encode(['ok'=>true,'url'=>$file['url']]);exit;}
    if($action==='test_print'){
        $s=fbr_get_settings($mysqli); $printer=trim((string)$s['report_printer']);
        if($printer===''){echo json_encode(['ok'=>false,'error'=>'No Full Bin report printer selected.']);exit;}
        $p=ebr_print_pdf_windows((string)$file['path'],$printer);
        echo json_encode(['ok'=>!empty($p['ok']),'printer'=>$printer,'jobId'=>$p['job_id']??null,'error'=>$p['error']??null]);exit;
    }
    echo json_encode(['ok'=>false,'error'=>'Unknown report action.']);exit;
}

/* ══════════════ POST HANDLERS ══════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── presets ── */
    if ($action === 'add_grower' && $role === 'admin') {
        $n = $mysqli->real_escape_string(trim($_POST['new_grower'] ?? ''));
        if ($n) { $mysqli->query("INSERT IGNORE INTO growers_list(name) VALUES('$n')"); $msg = "Grower '$n' added."; }
    }
    if ($action === 'add_variety' && $role === 'admin') {
        $n = $mysqli->real_escape_string(trim($_POST['new_variety'] ?? ''));
        if ($n) { $mysqli->query("INSERT IGNORE INTO varieties_list(name) VALUES('$n')"); $msg = "Variety '$n' added."; }
    }
    if ($action === 'add_type' && $role === 'admin') {
        $n = $mysqli->real_escape_string(trim($_POST['new_type'] ?? ''));
        if ($n) { $mysqli->query("INSERT IGNORE INTO bin_types_list(name) VALUES('$n')"); $msg = "Type '$n' added."; }
    }

    /* ── inline edit group (AJAX) ── */
    if ($action === 'edit_group_inline') {
        @ini_set('display_errors', 0);
        $gid     = intval($_POST['group_id'] ?? 0);
        $grower  = $mysqli->real_escape_string(trim($_POST['grower']  ?? ''));
        $variety = $mysqli->real_escape_string(trim($_POST['variety'] ?? ''));
        $type    = $mysqli->real_escape_string(trim($_POST['type']    ?? ''));
        $lot     = $mysqli->real_escape_string(trim($_POST['lot']     ?? ''));
        $date    = $mysqli->real_escape_string(trim($_POST['date']    ?? ''));
        $g = $mysqli->query("SELECT id FROM growers_list   WHERE name='$grower'")->fetch_assoc();
        $v = $mysqli->query("SELECT id FROM varieties_list WHERE name='$variety'")->fetch_assoc();
        $t = $mysqli->query("SELECT id FROM bin_types_list WHERE name='$type'")->fetch_assoc();
        $gid2 = (int)($g['id'] ?? 0); $vid = (int)($v['id'] ?? 0); $tid = (int)($t['id'] ?? 0);
        if ($gid > 0 && $gid2 > 0 && $vid > 0 && $tid > 0 && $date !== '') {
            $mysqli->query("UPDATE bins_ingresso SET grower_id=$gid2, variety_id=$vid, type_id=$tid, lot='$lot', date='$date' WHERE group_id=$gid");
            echo "OK";
        } else { echo "ERROR"; }
        exit;
    }

    /* ── inline edit single bin (AJAX) ── */
    if ($action === 'edit_single_inline') {
        @ini_set('display_errors', 0);
        $id      = intval($_POST['id'] ?? 0);
        $grower  = $mysqli->real_escape_string(trim($_POST['grower']  ?? ''));
        $variety = $mysqli->real_escape_string(trim($_POST['variety'] ?? ''));
        $type    = $mysqli->real_escape_string(trim($_POST['type']    ?? ''));
        $lot     = $mysqli->real_escape_string(trim($_POST['lot']     ?? ''));
        $date    = $mysqli->real_escape_string(trim($_POST['date']    ?? ''));
        $g = $mysqli->query("SELECT id FROM growers_list   WHERE name='$grower'")->fetch_assoc();
        $v = $mysqli->query("SELECT id FROM varieties_list WHERE name='$variety'")->fetch_assoc();
        $t = $mysqli->query("SELECT id FROM bin_types_list WHERE name='$type'")->fetch_assoc();
        $gid = (int)($g['id'] ?? 0); $vid = (int)($v['id'] ?? 0); $tid = (int)($t['id'] ?? 0);
        if ($id > 0 && $gid > 0 && $vid > 0 && $tid > 0 && $date !== '') {
            $mysqli->query("UPDATE bins_ingresso SET grower_id=$gid, variety_id=$vid, type_id=$tid, lot='$lot', date='$date' WHERE id=$id");
            echo "OK";
        } else { echo "ERROR"; }
        exit;
    }

    /* ── add group + consume empties + optional print ── */
    if ($action === 'add') {
        $grower     = trim($_POST['grower']   ?? '');
        $date       = $_POST['date']          ?? date('Y-m-d');
        $variety    = trim($_POST['variety']  ?? '');
        $type       = trim($_POST['type']     ?? '');
        $lot        = trim($_POST['lot']      ?? '');
        $notes      = trim($_POST['notes']    ?? '');
        $qty        = max(1, intval($_POST['quantity'] ?? 1));
        // API Save Only: all DB/receipt/PDF logic remains identical, only physical printing is skipped.
        // Normal webpage behavior is unchanged because skip_print is absent there.
        $skipPrint  = ((string)($_POST['skip_print'] ?? '') === '1');
        $settingsNow=fbr_get_settings($mysqli);
        $printer_id=(int)($settingsNow['label_printer_id'] ?? 0);
        $template_id=(int)($settingsNow['label_template_id'] ?? 0);

        if ($grower && $variety && $type) {
            $ge = $mysqli->real_escape_string($grower);
            $ve = $mysqli->real_escape_string($variety);
            $te = $mysqli->real_escape_string($type);
            $le = $mysqli->real_escape_string($lot);
            $de = $mysqli->real_escape_string($date);
            $g  = $mysqli->query("SELECT id FROM growers_list   WHERE name='$ge'")->fetch_assoc();
            $v  = $mysqli->query("SELECT id FROM varieties_list WHERE name='$ve'")->fetch_assoc();
            $t  = $mysqli->query("SELECT id FROM bin_types_list WHERE name='$te'")->fetch_assoc();
            $gid = (int)($g['id'] ?? 0); $vid = (int)($v['id'] ?? 0); $tid = (int)($t['id'] ?? 0);
            if ($gid <= 0 || $vid <= 0 || $tid <= 0) {
                $err = "Grower / Variety / Type must exist in presets.";
            } else {
                /* ── Check for existing group with same grower+variety+type on same date ── */
                $existQ = $mysqli->query("SELECT group_id FROM bins_ingresso
                    WHERE grower_id=$gid AND variety_id=$vid AND type_id=$tid
                      AND date='$de' AND status='AVAILABLE'
                    LIMIT 1");
                $existRow = $existQ ? $existQ->fetch_assoc() : null;
                $isNewGroup = ($existRow === null);
                $groupId    = $isNewGroup ? time() : (int)$existRow['group_id'];

                $printed = 0;
                $printFailed = 0;
                $insertFailed = 0;
                $insertedQty = 0;
                $newBinIds = [];

                for ($i = 0; $i < $qty; $i++) {
                    $ok = $mysqli->query("
                        INSERT INTO bins_ingresso
                            (grower_id,variety_id,type_id,lot,date,status,group_id)
                        VALUES
                            ($gid,$vid,$tid,'$le','$de','AVAILABLE',$groupId)
                    ");

                    if (!$ok) {
                        $insertFailed++;
                        continue;
                    }

                    $newId = (int)$mysqli->insert_id;
                    $insertedQty++;
                    $newBinIds[] = $newId;

                    // IMPORTANT: assign the definitive barcode BEFORE printing.
                    $mysqli->query("
                        UPDATE bins_ingresso
                        SET barcode = CONCAT('FBIN-', LPAD(id,5,'0'))
                        WHERE id=$newId
                          AND (barcode IS NULL OR barcode='')
                    ");


                }

                // The business quantity is the quantity actually inserted, not merely requested.
                $effectiveQty = $insertedQty;

                if ($effectiveQty > 0) {
                    consume_empty_bins_out($mysqli, $grower, $type, $effectiveQty, $groupId);
                }
                if ($effectiveQty <= 0) {
                    $err = "No Full Bin records were inserted. Nothing was printed or received.";
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'ok'=>false,
                            'err'=>$err,
                            'requestedQty'=>$qty,
                            'insertedQty'=>0,
                            'insertFailed'=>$insertFailed,
                            'printed'=>0
                        ]);
                        exit;
                    }
                }

                $enteredBy=trim((string)($_SESSION['user']['username']??$_SESSION['user']['name']??''));
                $stmtR=$mysqli->prepare("INSERT INTO full_bin_receipts(group_id,grower,variety,type,lot,notes,receiving_date,quantity,entered_by) VALUES(?,?,?,?,?,?,?,?,?)");
                $stmtR->bind_param('isssssiss',$groupId,$grower,$variety,$type,$lot,$notes,$date,$effectiveQty,$enteredBy);
                $stmtR->execute(); $receiptId=(int)$mysqli->insert_id; $stmtR->close();

                // Persist this single insertion/receipt as its own label batch.
                // Example: this insertion of 20 bins is permanently 1 of 20 ... 20 of 20.
                foreach ($newBinIds as $batchIdx => $batchBinId) {
                    $batchPos = $batchIdx + 1;
                    $stmtBatch = $mysqli->prepare(
                        "UPDATE bins_ingresso
                         SET receipt_id=?, batch_position=?, batch_total=?
                         WHERE id=?"
                    );
                    if ($stmtBatch) {
                        $stmtBatch->bind_param('iiii',$receiptId,$batchPos,$effectiveQty,$batchBinId);
                        $stmtBatch->execute();
                        $stmtBatch->close();
                    }
                }

                // Print only AFTER the full batch exists and has its definitive x-of-y values.
                if (!$skipPrint && $printer_id > 0) {
                    foreach ($newBinIds as $batchBinId) {
                        if (printBinLabel($mysqli,(int)$batchBinId,$printer_id,$template_id)) $printed++;
                        else $printFailed++;
                    }
                }

                $logStmt=$mysqli->prepare("INSERT INTO full_bins_log(group_id,grower,variety,type,lot,qty_change,reason,receipt_id) VALUES(?,?,?,?,?,?,?,?)");
                $logReason='Full bins received';
                $logStmt->bind_param('issssisi',$groupId,$grower,$variety,$type,$lot,$effectiveQty,$logReason,$receiptId);
                $logStmt->execute();
                $movementLogId=(int)$mysqli->insert_id;
                $logStmt->close();

                $receiptRow=['id'=>$receiptId,'group_id'=>$groupId,'grower'=>$grower,'variety'=>$variety,'type'=>$type,'lot'=>$lot,'notes'=>$notes,'receiving_date'=>$date,'quantity'=>$effectiveQty,'entered_by'=>$enteredBy,'created_at'=>date('Y-m-d H:i:s')];
                $growerInventory=fbr_grower_inventory($mysqli,$grower);
                $fullReport=fbr_generate_receipt_pdf($receiptRow,[
                    'inventory_rows'=>$growerInventory['rows']??[],
                    'grower_total'=>(int)($growerInventory['total']??0),
                ]);
                if(!empty($fullReport['ok'])&&!empty($fullReport['filename'])){
                    $pe=$mysqli->real_escape_string((string)$fullReport['filename']);
                    $mysqli->query("UPDATE full_bin_receipts SET report_pdf='$pe' WHERE id=$receiptId");
                }
                $reportPrinter=trim((string)$settingsNow['report_printer']);
                $reportPrint=['ok'=>false,'skipped'=>true,'error'=>'No Full Bin report printer selected.'];
                if($skipPrint){
                    $reportPrint=['ok'=>false,'skipped'=>true,'error'=>'Printing skipped: Save Only selected.'];
                } elseif(!empty($fullReport['ok'])&&$reportPrinter!==''){
                    try{$reportPrint=ebr_print_pdf_windows((string)$fullReport['path'],$reportPrinter);}
                    catch(Throwable $ex){$reportPrint=['ok'=>false,'error'=>$ex->getMessage()];}
                }

                $msg = $isNewGroup
                    ? "✅ New group created — $effectiveQty bins added."
                    : "✅ Added $effectiveQty bins to existing group (same grower · variety · type · date).";
                if ($insertFailed > 0) {
                    $msg .= " Insert failed: $insertFailed.";
                }
                if ($skipPrint) {
                    $msg .= " Saved without printing.";
                } elseif ($printer_id > 0) {
                    $msg .= " Printed: $printed";
                    if ($printFailed > 0) $msg .= " (print failed: $printFailed)";
                }
                /* ── AJAX early exit ── */
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    $ajaxSt = $mysqli->query("SELECT COUNT(DISTINCT group_id) AS g, COUNT(*) AS b, COUNT(DISTINCT grower_id) AS gr FROM bins_ingresso WHERE status='AVAILABLE'")->fetch_assoc();
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok'           => true,
                        'msg'          => $msg,
                        'group_id'     => $groupId,
                        'merged'       => !$isNewGroup,
                        'totalBins'    => (int)($ajaxSt['b']  ?? 0),
                        'totalGroups'  => (int)($ajaxSt['g']  ?? 0),
                        'totalGrowers' => (int)($ajaxSt['gr'] ?? 0),
                        'grower'  => $grower,
                        'variety' => $variety,
                        'type'    => $type,
                        'lot'     => $lot,
                        'date'    => $date,
                        'qty'     => $effectiveQty,
                        'requestedQty' => $qty,
                        'insertedQty'  => $effectiveQty,
                        'insertFailed' => $insertFailed,
                        'printed'      => $printed,
                        'printFailed'  => $printFailed,
                        'receipt' => [
                            'id'=>$receiptId,'created_at'=>date('Y-m-d H:i:s'),'grower'=>$grower,'variety'=>$variety,
                            'type'=>$type,'lot'=>$lot,'notes'=>$notes,'date'=>$date,'qty'=>$qty,'report_url'=>$fullReport['url']??null,
                            'report_generated'=>!empty($fullReport['ok']),'report_printer'=>$reportPrinter,
                            'report_job_id'=>$reportPrint['job_id']??null,'report_error'=>$reportPrint['error']??null
                        ],
                        'movementLog' => [
                            'id'=>$movementLogId,'created_at'=>date('Y-m-d H:i:s'),'group_id'=>$groupId,
                            'grower'=>$grower,'variety'=>$variety,'type'=>$type,'lot'=>$lot,
                            'qty_change'=>$effectiveQty,'reason'=>'Full bins received','receipt_id'=>$receiptId
                        ],
                    ]);
                    exit;
                }
            }
        } else {
            $err = "Grower, Variety and Type are required.";
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'err' => $err]);
                exit;
            }
        }
    }

    /* ── delete group ── */
    if ($action === 'delete_group' && $role === 'admin') {
        $gid = intval($_POST['group_id'] ?? 0);
        if ($gid > 0) { $mysqli->query("DELETE FROM bins_ingresso WHERE group_id=$gid"); $msg = "Group deleted."; }
    }

    /* ── delete single bin ── */
    if ($action === 'delete_single' && $role === 'admin') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) { $mysqli->query("DELETE FROM bins_ingresso WHERE id=$id"); $msg = "Bin deleted."; }
    }
}

/* ── add_to_group (AJAX only – standalone) ── */
if (($_POST['action'] ?? '') === 'add_to_group') {
        $gid2       = intval($_POST['group_id'] ?? 0);
        $qty2       = max(1, intval($_POST['quantity'] ?? 1));
        $settings2  = fbr_get_settings($mysqli);
        $pid2       = (int)($settings2['label_printer_id'] ?? 0);
        $tidTpl2    = (int)($settings2['label_template_id'] ?? 0);
        $resp       = ['ok' => false, 'msg' => ''];
        if ($gid2 > 0) {
            $qEx = $mysqli->query("SELECT MIN(grower_id) AS gid, MIN(variety_id) AS vid, MIN(type_id) AS tid, MIN(lot) AS lot, MIN(date) AS date FROM bins_ingresso WHERE group_id=$gid2 AND status='AVAILABLE' GROUP BY group_id");
            $ex  = $qEx ? $qEx->fetch_assoc() : null;
            if ($ex) {
                $egid=(int)$ex['gid']; $evid=(int)$ex['vid']; $etid=(int)$ex['tid'];
                $elot  = $mysqli->real_escape_string($ex['lot']);
                $edate = $mysqli->real_escape_string($ex['date']);
                $pr2=0; $prf2=0; $insFail2=0; $inserted2=0; $newBinIds2=[];
                for ($i=0; $i<$qty2; $i++) {
                    $ok2=$mysqli->query("
                        INSERT INTO bins_ingresso(grower_id,variety_id,type_id,lot,date,status,group_id)
                        VALUES($egid,$evid,$etid,'$elot','$edate','AVAILABLE',$gid2)
                    ");
                    if (!$ok2) {
                        $insFail2++;
                        continue;
                    }

                    $nid2=(int)$mysqli->insert_id;
                    $inserted2++;
                    $newBinIds2[]=$nid2;

                    // Definitive barcode first, then label print.
                    $mysqli->query("
                        UPDATE bins_ingresso
                        SET barcode=CONCAT('FBIN-',LPAD(id,6,'0'))
                        WHERE id=$nid2
                          AND (barcode IS NULL OR barcode='')
                    ");


                }
                $effectiveQty2=$inserted2;
                $namesQ=$mysqli->query("SELECT gp.name AS grower,vl.name AS variety,tl.name AS type FROM bins_ingresso bi LEFT JOIN growers_list gp ON bi.grower_id=gp.id LEFT JOIN varieties_list vl ON bi.variety_id=vl.id LEFT JOIN bin_types_list tl ON bi.type_id=tl.id WHERE bi.group_id=$gid2 LIMIT 1");
                $names=$namesQ?$namesQ->fetch_assoc():['grower'=>'','variety'=>'','type'=>''];
                if ($effectiveQty2 > 0) consume_empty_bins_out($mysqli,(string)$names['grower'],(string)$names['type'],$effectiveQty2,$gid2);

                if ($effectiveQty2 <= 0) {
                    $resp = [
                        'ok'=>false,
                        'msg'=>'No Full Bin records were inserted.',
                        'requested'=>$qty2,
                        'added'=>0,
                        'insertFailed'=>$insFail2,
                        'printed'=>0
                    ];
                    header('Content-Type: application/json');
                    echo json_encode($resp);
                    exit;
                }

                $entered2=trim((string)($_SESSION['user']['username']??$_SESSION['user']['name']??''));
                $lotPlain=(string)$ex['lot']; $datePlain=(string)$ex['date'];
                $stmtR2=$mysqli->prepare("INSERT INTO full_bin_receipts(group_id,grower,variety,type,lot,receiving_date,quantity,entered_by) VALUES(?,?,?,?,?,?,?,?)");
                $stmtR2->bind_param('isssssis',$gid2,$names['grower'],$names['variety'],$names['type'],$lotPlain,$datePlain,$effectiveQty2,$entered2);
                $stmtR2->execute(); $receiptId2=(int)$mysqli->insert_id; $stmtR2->close();

                // This "Add to Group" action is a NEW label batch of its own.
                foreach($newBinIds2 as $batchIdx2=>$batchBinId2){
                    $batchPos2=$batchIdx2+1;
                    $stmtBatch2=$mysqli->prepare(
                        "UPDATE bins_ingresso
                         SET receipt_id=?,batch_position=?,batch_total=?
                         WHERE id=?"
                    );
                    if($stmtBatch2){
                        $stmtBatch2->bind_param('iiii',$receiptId2,$batchPos2,$effectiveQty2,$batchBinId2);
                        $stmtBatch2->execute();
                        $stmtBatch2->close();
                    }
                }

                // Print after all x-of-y values are permanent.
                if($pid2>0){
                    foreach($newBinIds2 as $batchBinId2){
                        if(printBinLabel($mysqli,(int)$batchBinId2,$pid2,$tidTpl2)) $pr2++;
                        else $prf2++;
                    }
                }

                $logStmt2=$mysqli->prepare("INSERT INTO full_bins_log(group_id,grower,variety,type,lot,qty_change,reason,receipt_id) VALUES(?,?,?,?,?,?,?,?)");
                $logReason2='Full bins added to existing group';
                $logStmt2->bind_param('issssisi',$gid2,$names['grower'],$names['variety'],$names['type'],$lotPlain,$effectiveQty2,$logReason2,$receiptId2);
                $logStmt2->execute();
                $movementLogId2=(int)$mysqli->insert_id;
                $logStmt2->close();

                $st2 = $mysqli->query("SELECT COUNT(DISTINCT group_id) AS g, COUNT(*) AS b, COUNT(DISTINCT grower_id) AS gr FROM bins_ingresso WHERE status='AVAILABLE'")->fetch_assoc();
                $nt2 = (int)$mysqli->query("SELECT COUNT(*) AS c FROM bins_ingresso WHERE group_id=$gid2 AND status='AVAILABLE'")->fetch_assoc()['c'];

                $rr2=['id'=>$receiptId2,'group_id'=>$gid2,'grower'=>(string)$names['grower'],'variety'=>(string)$names['variety'],'type'=>(string)$names['type'],'lot'=>$lotPlain,'receiving_date'=>$datePlain,'quantity'=>$effectiveQty2,'entered_by'=>$entered2,'created_at'=>date('Y-m-d H:i:s')];
                $growerInventory2=fbr_grower_inventory($mysqli,(string)$names['grower']);
                $report2=fbr_generate_receipt_pdf($rr2,[
                    'inventory_rows'=>$growerInventory2['rows']??[],
                    'grower_total'=>(int)($growerInventory2['total']??0),
                ]);
                if(!empty($report2['ok'])&&!empty($report2['filename'])){$pdf2=$mysqli->real_escape_string((string)$report2['filename']);$mysqli->query("UPDATE full_bin_receipts SET report_pdf='$pdf2' WHERE id=$receiptId2");}
                $reportPrinter2=trim((string)$settings2['report_printer']);
                $reportPrint2=['ok'=>false,'skipped'=>true,'error'=>'No Full Bin report printer selected.'];
                if(!empty($report2['ok'])&&$reportPrinter2!==''){try{$reportPrint2=ebr_print_pdf_windows((string)$report2['path'],$reportPrinter2);}catch(Throwable $exP){$reportPrint2=['ok'=>false,'error'=>$exP->getMessage()];}}

                $resp = ['ok'=>true,'msg'=> "\u2705 $effectiveQty2 ".($effectiveQty2>1?'bins':'bin')." added to group.",'requested'=>$qty2,'added'=>$effectiveQty2,'insertFailed'=>$insFail2,'printed'=>$pr2,'printFailed'=>$prf2,'newGroupTotal'=>$nt2,'totalBins'=>(int)$st2['b'],'totalGroups'=>(int)$st2['g'],'totalGrowers'=>(int)$st2['gr'],
                    'receipt'=>['id'=>$receiptId2,'created_at'=>date('Y-m-d H:i:s'),'grower'=>(string)$names['grower'],'variety'=>(string)$names['variety'],'type'=>(string)$names['type'],'lot'=>$lotPlain,'date'=>$datePlain,'qty'=>$effectiveQty2,'report_url'=>$report2['url']??null,'report_generated'=>!empty($report2['ok']),'report_printer'=>$reportPrinter2,'report_job_id'=>$reportPrint2['job_id']??null,'report_error'=>$reportPrint2['error']??null],
                    'movementLog'=>['id'=>$movementLogId2,'created_at'=>date('Y-m-d H:i:s'),'group_id'=>$gid2,'grower'=>(string)$names['grower'],'variety'=>(string)$names['variety'],'type'=>(string)$names['type'],'lot'=>$lotPlain,'qty_change'=>$effectiveQty2,'reason'=>'Full bins added to existing group','receipt_id'=>$receiptId2]
                ];
            } else { $resp['msg'] = 'Group not found.'; }
        } else { $resp['msg'] = 'Invalid group ID.'; }
        header('Content-Type: application/json');
        echo json_encode($resp);
        exit;
    }

/* get_group_detail (AJAX - refresh bin rows after add-more) */
if (($_POST['action'] ?? '') === 'get_group_detail') {
    @ini_set('display_errors', 0);
    $gidD = intval($_POST['group_id'] ?? 0);
    if ($gidD <= 0) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'bins'=>[]]); exit; }
    $qD = $mysqli->query("
        SELECT bi.id, COALESCE(bi.barcode, CONCAT('FBIN-', LPAD(bi.id,6,'0'))) AS barcode,
               gp.name AS grower, vl.name AS variety, tl.name AS type,
               bi.lot, bi.date
        FROM bins_ingresso bi
        LEFT JOIN growers_list   gp ON bi.grower_id  = gp.id
        LEFT JOIN varieties_list vl ON bi.variety_id = vl.id
        LEFT JOIN bin_types_list tl ON bi.type_id    = tl.id
        WHERE bi.group_id=$gidD AND bi.status='AVAILABLE'
        ORDER BY bi.id ASC
    ");
    $bins = $qD ? $qD->fetch_all(MYSQLI_ASSOC) : [];
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'bins'=>$bins]);
    exit;
}

/* ── fetch groups ── */
$groups = $mysqli->query("
    SELECT MIN(gp.name) AS grower, MIN(vl.name) AS variety, MIN(tl.name) AS type,
           MIN(bi.date) AS date, MIN(bi.lot) AS lot, COUNT(*) AS total_bins, bi.group_id
    FROM bins_ingresso bi
    LEFT JOIN growers_list   gp ON bi.grower_id  = gp.id
    LEFT JOIN varieties_list vl ON bi.variety_id = vl.id
    LEFT JOIN bin_types_list tl ON bi.type_id    = tl.id
    WHERE bi.status = 'AVAILABLE'
    GROUP BY bi.group_id
    ORDER BY date DESC, grower ASC
")->fetch_all(MYSQLI_ASSOC);

/* ── stats ── */
$totalBins  = array_sum(array_column($groups, 'total_bins'));
$totalGroups = count($groups);
$totalGrowers = count(array_unique(array_column($groups, 'grower')));

$fullBinBalance=[];
$balQ=$mysqli->query("
    SELECT gp.name AS grower, vl.name AS variety, tl.name AS type, bi.lot, COUNT(*) AS qty
    FROM bins_ingresso bi
    LEFT JOIN growers_list gp ON bi.grower_id=gp.id
    LEFT JOIN varieties_list vl ON bi.variety_id=vl.id
    LEFT JOIN bin_types_list tl ON bi.type_id=tl.id
    WHERE bi.status='AVAILABLE'
    GROUP BY gp.name,vl.name,tl.name,bi.lot
    ORDER BY gp.name,vl.name,tl.name,bi.lot
");
if($balQ)$fullBinBalance=$balQ->fetch_all(MYSQLI_ASSOC);

$fullBinMovement=[];
$logQ=$mysqli->query("
    SELECT id,group_id,grower,variety,type,lot,qty_change,reason,receipt_id,created_at
    FROM full_bins_log
    ORDER BY id DESC
    LIMIT 500
");
if($logQ)$fullBinMovement=$logQ->fetch_all(MYSQLI_ASSOC);

$fullBinReceipts=[];
$resReceipts=$mysqli->query("SELECT * FROM full_bin_receipts ORDER BY id DESC LIMIT 250");
if($resReceipts)$fullBinReceipts=$resReceipts->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<style>
/* ══ Design tokens ══ */
:root {
    --bi-bg:        #f0f2f7;
    --bi-card:      #ffffff;
    --bi-border:    #e2e8f0;
    --bi-radius:    12px;
    --bi-shadow:    0 1px 4px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.04);
    --bi-shadow-md: 0 4px 16px rgba(15,23,42,.10);
    --c-green:      #16a34a; --c-green-bg:  #f0fdf4; --c-green-bdr: #bbf7d0;
    --c-blue:       #2563eb; --c-blue-bg:   #eff6ff; --c-blue-bdr:  #bfdbfe;
    --c-amber:      #d97706; --c-amber-bg:  #fffbeb; --c-amber-bdr: #fde68a;
    --c-red:        #dc2626; --c-red-bg:    #fef2f2; --c-red-bdr:   #fecaca;
    --c-purple:     #7c3aed; --c-purple-bg: #f5f3ff; --c-purple-bdr:#ddd6fe;
    --c-muted:      #94a3b8;
}

/* ── Top bar ── */
.bi-topbar {
    position: sticky; top: 0; z-index: 100;
    background: #0f172a;
    display: flex; align-items: center; gap: 14px;
    padding: 0 20px; height: 52px;
    box-shadow: 0 2px 8px rgba(0,0,0,.25);
}
.bi-topbar h1 { font-size: 15px; font-weight: 700; color: #f1f5f9; margin: 0; white-space: nowrap; }
.bi-topbar-sub { font-size: 11px; color: #64748b; white-space: nowrap; }
.bi-topbar-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
.bi-tbtn {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600; padding: 5px 12px;
    border-radius: 6px; border: 1px solid rgba(255,255,255,.15);
    background: rgba(255,255,255,.08); color: #e2e8f0;
    cursor: pointer; text-decoration: none; transition: background .15s; white-space: nowrap;
}
.bi-tbtn:hover { background: rgba(255,255,255,.18); color: #fff; }
.bi-tbtn-primary { background: #2563eb; border-color: #1d4ed8; color: #fff; }
.bi-tbtn-primary:hover { background: #1d4ed8; color: #fff; }

/* ── Main ── */
.bi-main { max-width: 100%; margin: 0; padding: 16px 20px 48px; }

/* ── Flash ── */
.bi-flash {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 16px; border-radius: 8px; border: 1px solid;
    margin-bottom: 14px; font-size: 13px; font-weight: 500;
}
.bi-flash-success { background: var(--c-green-bg); border-color: var(--c-green-bdr); color: var(--c-green); }
.bi-flash-danger  { background: var(--c-red-bg);   border-color: var(--c-red-bdr);   color: var(--c-red); }

/* ── Stat chips inline nel panel header ── */
.bi-stat-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 5px; }
.bi-stat-chip {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 700; padding: 2px 9px;
    border-radius: 20px; border: 1px solid;
}
.bi-chip-green  { background: var(--c-green-bg);  border-color: var(--c-green-bdr);  color: var(--c-green); }
.bi-chip-blue   { background: var(--c-blue-bg);   border-color: var(--c-blue-bdr);   color: var(--c-blue); }
.bi-chip-purple { background: var(--c-purple-bg); border-color: var(--c-purple-bdr); color: var(--c-purple); }

/* ── Panel card ── */
.bi-panel {
    background: var(--bi-card); border: 1px solid var(--bi-border);
    border-radius: var(--bi-radius); box-shadow: var(--bi-shadow);
    margin-bottom: 16px; overflow: visible;
}
.bi-panel-hdr {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 10px; padding: 13px 18px;
    background: #f8fafc; border-bottom: 1px solid var(--bi-border);
}
.bi-panel-title    { font-size: 13px; font-weight: 700; color: #1e293b; }
.bi-panel-subtitle { font-size: 11px; color: var(--c-muted); margin-top: 1px; }
.bi-panel-body     { padding: 18px; }

/* ── Form layout ── */
.bi-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 110px 140px 80px auto;
    gap: 10px; align-items: end;
}
@media (max-width: 1200px) { .bi-form-grid { grid-template-columns: 1fr 1fr 1fr 110px 130px 70px auto; } }
@media (max-width: 900px)  { .bi-form-grid { grid-template-columns: 1fr 1fr 1fr; } }
@media (max-width: 640px)  { .bi-form-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 420px)  { .bi-form-grid { grid-template-columns: 1fr; } }

.bi-field-label {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .4px; color: #475569; display: block; margin-bottom: 4px;
}
.bi-input-group { display: flex; gap: 0; }
.bi-input-group .form-select { border-radius: 7px 0 0 7px; flex: 1; }
.bi-input-group .bi-new-btn  {
    border-radius: 0 7px 7px 0; border: 1px solid var(--bi-border); border-left: none;
    background: #f8fafc; color: var(--c-blue); font-size: 11px; font-weight: 700;
    padding: 0 10px; cursor: pointer; white-space: nowrap;
    transition: background .12s;
}
.bi-input-group .bi-new-btn:hover { background: var(--c-blue-bg); }

/* ── Printer row ── */
.bi-printer-row {
    grid-column: 1 / -1;
    display: flex; gap: 12px; align-items: end; flex-wrap: wrap;
    padding-top: 10px; border-top: 1px dashed var(--bi-border); margin-top: 4px;
}
.bi-printer-row .bi-field-wrap { flex: 1; min-width: 200px; }
.bi-printer-note{font-size:10px;color:var(--c-muted);margin-top:4px}
.bi-pdf-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #fecaca;background:#fef2f2;color:#dc2626;border-radius:7px;padding:4px 8px;font-size:10px;font-weight:800;cursor:pointer}
.bi-pdf-btn:hover{background:#fee2e2;color:#b91c1c}
.bi-report-table-wrap{overflow:auto}
.bi-report-table{width:100%;border-collapse:collapse;font-size:11px}
.bi-report-table th,.bi-report-table td{padding:7px 9px;border-bottom:1px solid var(--bi-border);white-space:nowrap}
.bi-report-table th{background:#f8fafc;color:#475569;font-size:10px;text-transform:uppercase;letter-spacing:.35px}
.bi-balance-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.bi-balance-card{border:1px solid var(--bi-border);border-radius:10px;padding:12px;background:#fff}
.bi-balance-grower{font-weight:800;color:#0f172a}
.bi-balance-sub{font-size:11px;color:#475569;margin-top:2px}
.bi-balance-lot{font-size:10px;color:var(--c-muted);margin:3px 0 8px}


/* ── Submit btn ── */
.bi-submit-btn {
    display: inline-flex; align-items: center; gap: 7px;
    background: #16a34a; border: none; color: #fff;
    font-size: 13px; font-weight: 700; padding: 9px 20px;
    border-radius: 8px; cursor: pointer; transition: background .15s, transform .1s;
    align-self: end;
}
.bi-submit-btn:hover  { background: #15803d; }
.bi-submit-btn:active { transform: translateY(1px); }

/* ── Filter bar ── */
.bi-filter-bar {
    display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
    padding: 12px 18px; background: #f8fafc;
    border-bottom: 1px solid var(--bi-border);
}
.bi-filter-input {
    flex: 1; min-width: 180px; max-width: 280px;
    font-size: 12px; padding: 6px 11px;
    border: 1px solid var(--bi-border); border-radius: 7px;
    outline: none; transition: border-color .15s;
}
.bi-filter-input:focus { border-color: var(--c-blue); box-shadow: 0 0 0 3px rgba(37,99,235,.10); }
.bi-filter-select {
    font-size: 12px; padding: 6px 11px; min-width: 130px;
    border: 1px solid var(--bi-border); border-radius: 7px;
    outline: none; background: #fff;
}
.bi-filter-select:focus { border-color: var(--c-blue); }
.bi-filter-printer-wrap { display: flex; align-items: center; gap: 6px; margin-left: auto; flex-wrap: wrap; }
.bi-filter-printer-wrap label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #64748b; white-space: nowrap; }
.bi-expand-btns { display: flex; gap: 4px; }
.bi-sm-btn {
    font-size: 11px; font-weight: 600; padding: 4px 10px;
    border: 1px solid var(--bi-border); border-radius: 6px;
    background: #fff; color: #475569; cursor: pointer; transition: background .12s;
}
.bi-sm-btn:hover { background: #f1f5f9; }

/* ── Groups table ── */
.bi-table-wrap { overflow-x: auto; }
.bi-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.bi-table thead th {
    background: #f8fafc; border-bottom: 2px solid var(--bi-border);
    padding: 9px 12px; text-align: left;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #64748b;
    white-space: nowrap;
}
.bi-table tbody tr.group-row {
    background: #fff; border-bottom: 1px solid #f1f5f9;
    cursor: pointer; transition: background .1s;
}
.bi-table tbody tr.group-row:hover { background: #f8fafc; }
.bi-table td { padding: 9px 12px; vertical-align: middle; }

/* ── Qty badge ── */
.bi-qty-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 28px; height: 24px; border-radius: 12px;
    font-size: 12px; font-weight: 800; padding: 0 8px;
}
.bi-qty-low    { background: var(--c-red-bg);   color: var(--c-red);   border: 1px solid var(--c-red-bdr); }
.bi-qty-medium { background: var(--c-amber-bg); color: var(--c-amber); border: 1px solid var(--c-amber-bdr); }
.bi-qty-high   { background: var(--c-green-bg); color: var(--c-green); border: 1px solid var(--c-green-bdr); }

/* ── Arrow toggle ── */
.bi-arrow {
    display: inline-block; font-size: 10px; color: var(--c-muted);
    transition: transform .2s; user-select: none;
}
.bi-arrow.open { transform: rotate(90deg); color: var(--c-blue); }

/* ── Expand row ── */
.bi-detail-row td { padding: 0 !important; }
.bi-detail-inner {
    background: #f8fafc; border-top: 1px solid var(--bi-border);
    padding: 12px 16px 12px 40px;
}

/* ── Detail table ── */
.bi-detail-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.bi-detail-table th {
    background: #f1f5f9; padding: 7px 10px;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #64748b;
    border-bottom: 1px solid var(--bi-border); white-space: nowrap;
}
.bi-detail-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.bi-detail-table tbody tr:hover { background: #fff; }
.bi-barcode-pill {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 700; font-family: 'Courier New', monospace;
    background: #1e293b; color: #f1f5f9; padding: 2px 8px;
    border-radius: 4px; letter-spacing: .5px;
}

/* ── Action buttons in table rows ── */
.bi-act { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
.bi-abtn {
    font-size: 11px; font-weight: 600; padding: 3px 9px;
    border-radius: 5px; border: 1px solid; cursor: pointer;
    display: inline-flex; align-items: center; gap: 3px;
    transition: background .12s; text-decoration: none; background: #fff;
    white-space: nowrap;
}
.bi-abtn-print   { border-color: var(--c-blue-bdr);  color: var(--c-blue); }
.bi-abtn-print:hover   { background: var(--c-blue-bg); }
.bi-abtn-edit    { border-color: var(--c-amber-bdr); color: var(--c-amber); }
.bi-abtn-edit:hover    { background: var(--c-amber-bg); }
.bi-abtn-delete  { border-color: var(--c-red-bdr);   color: var(--c-red); }
.bi-abtn-delete:hover  { background: var(--c-red-bg); }
.bi-abtn-save    { border-color: var(--c-green-bdr); color: var(--c-green); background: var(--c-green-bg); }
.bi-abtn-save:hover    { background: #dcfce7; }
.bi-abtn-cancel  { border-color: var(--bi-border); color: #64748b; }
.bi-abtn-cancel:hover  { background: #f1f5f9; }

/* ── Inline edit inputs ── */
.bi-inline-input {
    font-size: 12px; padding: 3px 8px; border: 1px solid var(--c-blue-bdr);
    border-radius: 5px; background: #fff; outline: none; width: 100%; min-width: 80px;
}
.bi-inline-select {
    font-size: 12px; padding: 3px 8px; border: 1px solid var(--c-blue-bdr);
    border-radius: 5px; background: #fff; outline: none; min-width: 100px;
}
.bi-inline-input:focus, .bi-inline-select:focus { border-color: var(--c-blue); box-shadow: 0 0 0 2px rgba(37,99,235,.12); }

/* ── Age badge ── */
.bi-age-badge {
    display: inline-flex; align-items: center;
    font-size: 10px; font-weight: 700; padding: 1px 7px;
    border-radius: 20px; border: 1px solid; margin-left: 5px; white-space: nowrap;
}
.bi-age-today  { background: var(--c-green-bg);  border-color: var(--c-green-bdr);  color: var(--c-green); }
.bi-age-recent { background: var(--c-amber-bg);  border-color: var(--c-amber-bdr);  color: var(--c-amber); }
.bi-age-old    { background: var(--c-red-bg);    border-color: var(--c-red-bdr);    color: var(--c-red); }

/* ── Clone / Add-more btns ── */
.bi-abtn-clone     { border-color: #a78bfa; color: #7c3aed; }
.bi-abtn-clone:hover { background: var(--c-purple-bg); }
.bi-abtn-addmore   { border-color: var(--c-green-bdr); color: var(--c-green); }
.bi-abtn-addmore:hover { background: var(--c-green-bg); }

/* ── Empty state ── */
.bi-empty { text-align: center; padding: 48px 20px; color: var(--c-muted); font-size: 13px; }
.bi-empty-icon { font-size: 36px; display: block; margin-bottom: 10px; }

/* ── Modal overrides ── */
.modal-header  { background: #f8fafc; border-bottom: 1px solid var(--bi-border); border-radius: 12px 12px 0 0; }
.modal-footer  { background: #f8fafc; border-top:    1px solid var(--bi-border); border-radius: 0 0 12px 12px; }
.modal-content { border-radius: 12px; border: 1px solid var(--bi-border); }


/* EXACT Empty Bin component CSS */

/* ══ Design tokens (reuse bi- palette) ══ */
:root {
    --eb-bg:        #f0f2f7;
    --eb-card:      #ffffff;
    --eb-border:    #e2e8f0;
    --eb-radius:    12px;
    --eb-shadow:    0 1px 4px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.04);
    --c-green:      #16a34a; --c-green-bg:  #f0fdf4; --c-green-bdr: #bbf7d0;
    --c-blue:       #2563eb; --c-blue-bg:   #eff6ff; --c-blue-bdr:  #bfdbfe;
    --c-amber:      #d97706; --c-amber-bg:  #fffbeb; --c-amber-bdr: #fde68a;
    --c-red:        #dc2626; --c-red-bg:    #fef2f2; --c-red-bdr:   #fecaca;
    --c-purple:     #7c3aed; --c-purple-bg: #f5f3ff; --c-purple-bdr:#ddd6fe;
    --c-muted:      #94a3b8;
}

/* ── Top bar ── */
.eb-topbar {
    position: sticky; top: 0; z-index: 100;
    background: #0f172a;
    display: flex; align-items: center; gap: 14px;
    padding: 0 20px; height: 52px;
    box-shadow: 0 2px 8px rgba(0,0,0,.25);
}
.eb-topbar h1 { font-size: 15px; font-weight: 700; color: #f1f5f9; margin: 0; white-space: nowrap; }
.eb-topbar-sub { font-size: 11px; color: #64748b; white-space: nowrap; }
.eb-topbar-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
.eb-tbtn {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600; padding: 5px 12px;
    border-radius: 6px; border: 1px solid rgba(255,255,255,.15);
    background: rgba(255,255,255,.08); color: #e2e8f0;
    cursor: pointer; text-decoration: none; transition: background .15s; white-space: nowrap;
}
.eb-tbtn:hover { background: rgba(255,255,255,.18); color: #fff; }
.eb-tbtn-primary { background: #2563eb; border-color: #1d4ed8; color: #fff; }
.eb-tbtn-primary:hover { background: #1d4ed8; color: #fff; }
.eb-report-printer-wrap {
    display:flex; align-items:center; gap:6px;
    padding:4px 7px; border:1px solid rgba(255,255,255,.14);
    border-radius:7px; background:rgba(255,255,255,.06);
}
.eb-report-printer-wrap label {
    margin:0; font-size:10px; font-weight:700; color:#cbd5e1; white-space:nowrap;
}
.eb-report-printer {
    width:220px; max-width:28vw; height:30px;
    border:1px solid #475569; border-radius:5px;
    background:#fff; color:#0f172a; font-size:11px; padding:3px 7px;
}
.eb-printer-save-status { font-size:10px; color:#86efac; min-width:12px; }
.eb-report-printer-missing { border:2px solid #f59e0b; background:#fff7ed; }
@media (max-width:1100px) {
    .eb-report-printer-wrap label { display:none; }
    .eb-report-printer { width:170px; }
}

/* ── Main ── */
.eb-main { max-width: 100%; margin: 0; padding: 16px 20px 48px; }

/* ── Flash ── */
.eb-flash {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 16px; border-radius: 8px; border: 1px solid;
    margin-bottom: 14px; font-size: 13px; font-weight: 500;
}
.eb-flash-success { background: var(--c-green-bg); border-color: var(--c-green-bdr); color: var(--c-green); }
.eb-flash-danger  { background: var(--c-red-bg);   border-color: var(--c-red-bdr);   color: var(--c-red); }

/* ── Panel ── */
.eb-panel {
    background: var(--eb-card); border: 1px solid var(--eb-border);
    border-radius: var(--eb-radius); box-shadow: var(--eb-shadow);
    margin-bottom: 16px; overflow: visible;
}
.eb-panel-hdr {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 10px; padding: 13px 18px;
    background: #f8fafc; border-bottom: 1px solid var(--eb-border);
    border-radius: var(--eb-radius) var(--eb-radius) 0 0;
}
.eb-panel-title    { font-size: 13px; font-weight: 700; color: #1e293b; }
.eb-panel-subtitle { font-size: 11px; color: var(--c-muted); margin-top: 1px; }
.eb-panel-body     { padding: 18px; }

/* ── Stat chips ── */
.eb-stat-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 5px; }
.eb-stat-chip {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 700; padding: 2px 9px;
    border-radius: 20px; border: 1px solid;
}
.eb-chip-green  { background: var(--c-green-bg);  border-color: var(--c-green-bdr);  color: var(--c-green); }
.eb-chip-blue   { background: var(--c-blue-bg);   border-color: var(--c-blue-bdr);   color: var(--c-blue); }
.eb-chip-purple { background: var(--c-purple-bg); border-color: var(--c-purple-bdr); color: var(--c-purple); }
.eb-chip-amber  { background: var(--c-amber-bg);  border-color: var(--c-amber-bdr);  color: var(--c-amber); }

/* ── Field label ── */
.eb-field-label {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .4px; color: #475569; display: block; margin-bottom: 4px;
}

/* ── Add form grid ── */
.eb-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 140px 80px auto;
    gap: 10px; align-items: end;
}
@media (max-width: 1000px) { .eb-form-grid { grid-template-columns: 1fr 1fr 130px 70px auto; } }
@media (max-width: 750px)  { .eb-form-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 480px)  { .eb-form-grid { grid-template-columns: 1fr; } }

/* ── Input group ── */
.eb-input-group { display: flex; gap: 0; }
.eb-input-group .form-select { border-radius: 7px 0 0 7px; flex: 1; }
.eb-input-group .eb-new-btn {
    border-radius: 0 7px 7px 0; border: 1px solid var(--eb-border); border-left: none;
    background: #f8fafc; color: var(--c-blue); font-size: 11px; font-weight: 700;
    padding: 0 10px; cursor: pointer; white-space: nowrap; transition: background .12s;
}
.eb-input-group .eb-new-btn:hover { background: var(--c-blue-bg); }

/* ── Submit button ── */
.eb-submit-btn {
    display: inline-flex; align-items: center; gap: 7px;
    background: #16a34a; border: none; color: #fff;
    font-size: 13px; font-weight: 700; padding: 9px 20px;
    border-radius: 8px; cursor: pointer; transition: background .15s, transform .1s;
    align-self: end; width: 100%;
}
.eb-submit-btn:hover  { background: #15803d; }
.eb-submit-btn:active { transform: translateY(1px); }

/* ── Balance grid ── */
.eb-balance-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
}
.eb-balance-card {
    background: var(--eb-card); border: 1px solid var(--eb-border);
    border-radius: 10px; padding: 12px 14px;
    display: flex; flex-direction: column; gap: 4px;
}
.eb-balance-grower { font-size: 12px; font-weight: 700; color: #1e293b; }
.eb-balance-type   { font-size: 11px; color: var(--c-muted); }
.eb-balance-qty {
    font-size: 22px; font-weight: 800; color: var(--c-blue);
    line-height: 1;
}
.eb-balance-qty-small { font-size: 11px; color: var(--c-muted); font-weight: 400; }

/* ── Filter bar ── */
.eb-filter-bar {
    display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
    padding: 12px 18px; background: #f8fafc;
    border-bottom: 1px solid var(--eb-border);
}
.eb-filter-input {
    flex: 1; min-width: 160px; max-width: 260px;
    font-size: 12px; padding: 6px 11px;
    border: 1px solid var(--eb-border); border-radius: 7px;
    outline: none; transition: border-color .15s;
}
.eb-filter-input:focus { border-color: var(--c-blue); box-shadow: 0 0 0 3px rgba(37,99,235,.10); }
.eb-filter-select {
    font-size: 12px; padding: 6px 11px; min-width: 120px;
    border: 1px solid var(--eb-border); border-radius: 7px;
    outline: none; background: #fff;
}
.eb-filter-select:focus { border-color: var(--c-blue); }

/* ── Table ── */
.eb-table-wrap { overflow-x: auto; }
.eb-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.eb-table thead th {
    background: #f8fafc; border-bottom: 2px solid var(--eb-border);
    padding: 9px 12px; text-align: left;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #64748b;
    white-space: nowrap;
}
.eb-table tbody tr { background: #fff; border-bottom: 1px solid #f1f5f9; transition: background .1s; }
.eb-table tbody tr:hover { background: #f8fafc; }
.eb-table td { padding: 9px 12px; vertical-align: middle; }


.eb-pdf-modal-content { height:min(92vh,980px); }
.eb-pdf-modal-body { padding:0; background:#e5e7eb; min-height:70vh; overflow:hidden; }
#ebPdfFrame { width:100%; height:100%; min-height:72vh; border:0; background:#fff; display:block; }


/* ── PDF preview popup: independent from Bootstrap ── */
.eb-pdf-popup {
    display:none;
    position:fixed;
    inset:0;
    z-index:2147483000;
    background:rgba(15,23,42,.72);
    padding:22px;
    align-items:center;
    justify-content:center;
}
.eb-pdf-popup.is-open { display:flex; }
.eb-pdf-popup-panel {
    width:min(1180px,96vw);
    height:min(920px,94vh);
    background:#fff;
    border-radius:14px;
    box-shadow:0 28px 80px rgba(0,0,0,.38);
    overflow:hidden;
    display:flex;
    flex-direction:column;
}
.eb-pdf-popup-header {
    flex:0 0 auto;
    padding:12px 16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    border-bottom:1px solid #e2e8f0;
    background:#fff;
}
.eb-pdf-popup-title { font-size:15px; font-weight:800; color:#0f172a; }
.eb-pdf-popup-sub { margin-top:2px; font-size:11px; color:#64748b; }
.eb-pdf-popup-x {
    width:36px; height:36px; border:0; border-radius:8px;
    background:#f1f5f9; color:#334155; font-size:26px; line-height:1;
    cursor:pointer;
}
.eb-pdf-popup-x:hover { background:#e2e8f0; }
.eb-pdf-popup-body {
    flex:1 1 auto;
    min-height:0;
    background:#dbe1e8;
}
#ebPdfPopupFrame {
    width:100%;
    height:100%;
    border:0;
    display:block;
    background:#fff;
}
.eb-pdf-popup-footer {
    flex:0 0 auto;
    padding:10px 16px;
    border-top:1px solid #e2e8f0;
    background:#fff;
    display:flex;
    justify-content:flex-end;
}
body.eb-pdf-popup-open { overflow:hidden !important; }

/* ── PDF report icon ── */
.eb-pdf-icon {
    min-width:38px; height:34px; display:inline-flex; align-items:center; justify-content:center;
    gap:2px; padding:0 5px; border-radius:8px; font-size:9px; line-height:1;
    font-weight:900; letter-spacing:.2px; text-decoration:none; cursor:pointer;
    transition:transform .12s, box-shadow .12s, opacity .12s; border:1px solid;
}
.eb-pdf-icon::before { content:"📄"; font-size:15px; }
.eb-pdf-ready { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
.eb-pdf-ready:hover { color:#b91c1c; background:#fee2e2; transform:translateY(-1px); box-shadow:0 3px 8px rgba(220,38,38,.15); }
.eb-pdf-missing { background:#f8fafc; color:#94a3b8; border-color:#e2e8f0; }
.eb-pdf-missing:hover { background:#f1f5f9; color:#64748b; }

/* ── Qty badge ── */
.eb-qty-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 30px; height: 24px; border-radius: 12px;
    font-size: 12px; font-weight: 800; padding: 0 8px;
}
.eb-qty-low    { background: var(--c-red-bg);   color: var(--c-red);   border: 1px solid var(--c-red-bdr); }
.eb-qty-medium { background: var(--c-amber-bg); color: var(--c-amber); border: 1px solid var(--c-amber-bdr); }
.eb-qty-high   { background: var(--c-green-bg); color: var(--c-green); border: 1px solid var(--c-green-bdr); }

/* ── Age badge ── */
.eb-age-badge {
    display: inline-flex; align-items: center;
    font-size: 10px; font-weight: 700; padding: 1px 7px;
    border-radius: 20px; border: 1px solid; margin-left: 5px; white-space: nowrap;
}
.eb-age-today  { background: var(--c-green-bg); border-color: var(--c-green-bdr); color: var(--c-green); }
.eb-age-recent { background: var(--c-amber-bg); border-color: var(--c-amber-bdr); color: var(--c-amber); }
.eb-age-old    { background: var(--c-red-bg);   border-color: var(--c-red-bdr);   color: var(--c-red); }

/* ── Action buttons ── */
.eb-act { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
.eb-abtn {
    font-size: 11px; font-weight: 600; padding: 3px 9px;
    border-radius: 5px; border: 1px solid; cursor: pointer;
    display: inline-flex; align-items: center; gap: 3px;
    transition: background .12s; text-decoration: none; background: #fff; white-space: nowrap;
}
.eb-abtn-edit    { border-color: var(--c-amber-bdr); color: var(--c-amber); }
.eb-abtn-edit:hover    { background: var(--c-amber-bg); }
.eb-abtn-delete  { border-color: var(--c-red-bdr);   color: var(--c-red); }
.eb-abtn-delete:hover  { background: var(--c-red-bg); }
.eb-abtn-save    { border-color: var(--c-green-bdr); color: var(--c-green); background: var(--c-green-bg); }
.eb-abtn-save:hover    { background: #dcfce7; }
.eb-abtn-cancel  { border-color: var(--eb-border); color: #64748b; }
.eb-abtn-cancel:hover  { background: #f1f5f9; }

/* ── Inline edit inputs ── */
.eb-inline-input {
    font-size: 12px; padding: 3px 8px; border: 1px solid var(--c-blue-bdr);
    border-radius: 5px; background: #fff; outline: none; width: 100%; min-width: 80px;
}
.eb-inline-select {
    font-size: 12px; padding: 3px 8px; border: 1px solid var(--c-blue-bdr);
    border-radius: 5px; background: #fff; outline: none; min-width: 110px;
}
.eb-inline-input:focus, .eb-inline-select:focus {
    border-color: var(--c-blue); box-shadow: 0 0 0 2px rgba(37,99,235,.12);
}

/* ── Log delta ── */
.eb-delta-pos { color: var(--c-green); font-weight: 700; }
.eb-delta-neg { color: var(--c-red);   font-weight: 700; }

/* ── Log collapsible ── */
.eb-log-body { max-height: 380px; overflow-y: auto; }
.eb-log-toggle {
    font-size: 11px; font-weight: 600; padding: 4px 10px;
    border: 1px solid var(--eb-border); border-radius: 6px;
    background: #fff; color: #475569; cursor: pointer; transition: background .12s;
}
.eb-log-toggle:hover { background: #f1f5f9; }

/* ── Small btn ── */
.eb-sm-btn {
    font-size: 11px; font-weight: 600; padding: 4px 10px;
    border: 1px solid var(--eb-border); border-radius: 6px;
    background: #fff; color: #475569; cursor: pointer; transition: background .12s;
}
.eb-sm-btn:hover { background: #f1f5f9; }

/* ── Empty state ── */
.eb-empty { text-align: center; padding: 40px 20px; color: var(--c-muted); font-size: 13px; }
.eb-empty-icon { font-size: 32px; display: block; margin-bottom: 8px; }

/* ── Modal override ── */
.modal-header  { background: #f8fafc; border-bottom: 1px solid var(--eb-border); border-radius: 12px 12px 0 0; }
.modal-footer  { background: #f8fafc; border-top:    1px solid var(--eb-border); border-radius: 0 0 12px 12px; }
.modal-content { border-radius: 12px; border: 1px solid var(--eb-border); }

</style>

<!-- ══════════════════════════════
     BINS INGRESSO
     ══════════════════════════════ -->

<!-- TOP BAR -->
<div class="bi-topbar">
    <h1>🗃️ Full Bin Receiving</h1>
    <span class="bi-topbar-sub">Receiving · Inventory · Print</span>
    <div class="bi-topbar-right">
        <a href="bins_produzione.php"     class="bi-tbtn">🏭 Production</a>
        <a href="empty_bin_receiving.php" class="bi-tbtn">📭 Empty Bins</a>
        <a href="/chooser.php"            class="bi-tbtn bi-tbtn-primary">🏠 Main Menu</a>
    </div>
</div>

<div class="bi-main">

    <!-- Flash messages -->
    <?php if ($msg): ?>
        <div class="bi-flash bi-flash-success" id="bi-flash">
            <span><?= bh($msg); ?></span>
            <button onclick="this.closest('.bi-flash').remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:14px;color:inherit;opacity:.6;">✕</button>
        </div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="bi-flash bi-flash-danger" id="bi-flash-err">
            <span>⚠️ <?= bh($err); ?></span>
            <button onclick="this.closest('.bi-flash').remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:14px;color:inherit;opacity:.6;">✕</button>
        </div>
    <?php endif; ?>

    <!-- ═══ ADD FULL BINS FORM (always visible) ═══ -->
    <div class="bi-panel">
        <div class="bi-panel-hdr">
            <div>
                <div class="bi-panel-title">➕ Add Full Bins</div>
                <div class="bi-panel-subtitle">Register a new batch. Empty bins of matching type will be consumed automatically.</div>
            </div>
        </div>
        <div class="bi-panel-body">
            <form method="post" id="addForm">
                <input type="hidden" name="action" value="add">

                <div class="bi-form-grid">

                    <!-- Grower -->
                    <div>
                        <label class="bi-field-label">Grower <span style="color:var(--c-red)">*</span></label>
                        <div class="bi-input-group">
                            <select name="grower" class="form-select" required>
                                <option value="">Select grower…</option>
                                <?php foreach ($growers as $g): ?>
                                    <option value="<?= bh($g['name']); ?>"><?= bh($g['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($role === 'admin'): ?>
                                <button type="button" class="bi-new-btn" data-bs-toggle="modal" data-bs-target="#addGrowerModal" title="Add new grower">+ New</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Variety -->
                    <div>
                        <label class="bi-field-label">Variety <span style="color:var(--c-red)">*</span></label>
                        <div class="bi-input-group">
                            <select name="variety" class="form-select" required>
                                <option value="">Select variety…</option>
                                <?php foreach ($varieties as $v): ?>
                                    <option value="<?= bh($v['name']); ?>"><?= bh($v['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($role === 'admin'): ?>
                                <button type="button" class="bi-new-btn" data-bs-toggle="modal" data-bs-target="#addVarietyModal" title="Add new variety">+ New</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Type -->
                    <div>
                        <label class="bi-field-label">Bin Type <span style="color:var(--c-red)">*</span></label>
                        <div class="bi-input-group">
                            <select name="type" class="form-select" required>
                                <option value="">Select type…</option>
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= bh($t['name']); ?>"><?= bh($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($role === 'admin'): ?>
                                <button type="button" class="bi-new-btn" data-bs-toggle="modal" data-bs-target="#addTypeModal" title="Add new bin type">+ New</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Lot -->
                    <div>
                        <label class="bi-field-label">Lot</label>
                        <input type="text" name="lot" class="form-control" placeholder="e.g. L-241">
                    </div>

                    <!-- Notes -->
                    <div style="grid-column:span 2;">
                        <label class="bi-field-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Optional notes for this receiving report"></textarea>
                    </div>

                    <!-- Date -->
                    <div>
                        <label class="bi-field-label">Date <span style="color:var(--c-red)">*</span></label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
                    </div>

                    <!-- Qty -->
                    <div>
                        <label class="bi-field-label">Qty</label>
                        <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                    </div>

                    <!-- Submit -->
                    <div>
                        <label class="bi-field-label">&nbsp;</label>
                        <button type="submit" class="bi-submit-btn" id="addBtn" style="width:100%;">
                            <span id="addBtnSpinner" style="display:none;">⏳</span>
                            ➕ Add
                        </button>
                    </div>

                    <!-- Persistent Full Bin label configuration -->
                    <div class="bi-printer-row">
                        <div class="bi-field-wrap">
                            <label class="bi-field-label">🏷️ Label Printer</label>
                            <select id="biLabelPrinter" class="form-select">
                                <option value="0">Do not auto-print labels</option>
                                <?php foreach ($printers as $p): ?>
                                    <option value="<?= (int)$p['id']; ?>" <?= (int)($fullBinPrintSettings['label_printer_id'] ?? 0)===(int)$p['id']?'selected':''; ?>>
                                        <?= bh($p['display_name'] ?? ('Printer #'.(int)$p['id'])); ?>
                                        <?= !empty($p['dpi'])?' · '.(int)$p['dpi'].'dpi':''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="bi-printer-note">Saved automatically.</div>
                        </div>

                        <div class="bi-field-wrap">
                            <label class="bi-field-label">🧾 Label Template</label>
                            <select id="biLabelTemplate" class="form-select">
                                <option value="0">Select Full Bin template…</option>
                                <?php foreach ($fullBinLabelTemplates as $tpl): ?>
                                    <option value="<?= (int)$tpl['id']; ?>" <?= (int)($fullBinPrintSettings['label_template_id'] ?? 0)===(int)$tpl['id']?'selected':''; ?>>
                                        <?= bh($tpl['name']); ?>
                                        <?= !empty($tpl['dpi'])?' · '.(int)$tpl['dpi'].'dpi':''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="bi-printer-note">This is the only Full Bin template selector used by the webapp.</div>
                        </div>

                        <div class="bi-field-wrap">
                            <label class="bi-field-label">📄 Receiving Report Printer</label>
                            <select id="biReportPrinter" class="form-select">
                                <option value="">Do not auto-print report</option>
                                <?php foreach ($windowsReportPrinters as $wp): ?>
                                    <option value="<?= bh($wp); ?>" <?= $wp===($fullBinPrintSettings['report_printer'] ?? '')?'selected':''; ?>><?= bh($wp); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="bi-printer-note">Saved automatically. The PDF is always generated.</div>
                        </div>
                    </div>

                </div>
            </form>
        </div>
    </div>


    <!-- ═══ CURRENT BALANCE ═══ -->
    <div class="eb-panel">
        <div class="eb-panel-hdr">
            <div>
                <div class="eb-panel-title">📊 Current Balance</div>
                <div class="eb-stat-chips">
                    <span class="eb-stat-chip eb-chip-green"  id="ebChipQty">📦 <?= (int)$totalBins; ?> bins</span>
                    <span class="eb-stat-chip eb-chip-blue"   id="ebChipGrowers">🌱 <?= (int)$totalGrowers; ?> growers</span>
                    <span class="eb-stat-chip eb-chip-purple" id="ebChipRows">📋 <?= (int)$totalGroups; ?> groups</span>
                </div>
            </div>
        </div>
        <div class="eb-panel-body" id="ebCurrentBalanceBody">
            <?php if (!empty($fullBinBalance)): ?>
                <div class="eb-balance-grid">
                    <?php foreach ($fullBinBalance as $t):
                        $q = (int)$t['qty'];
                        $qc = $q <= 5 ? 'eb-qty-low' : ($q <= 20 ? 'eb-qty-medium' : 'eb-qty-high');
                    ?>
                    <div class="eb-balance-card">
                        <div class="eb-balance-grower"><?= bh($t['grower']); ?></div>
                        <div class="eb-balance-type"><?= bh($t['variety']); ?> · <?= bh($t['type']); ?></div>
                        <?php if(trim((string)$t['lot'])!==''): ?>
                        <div class="eb-balance-type" style="margin-top:2px;">Lot: <?= bh($t['lot']); ?></div>
                        <?php endif; ?>
                        <div><span class="eb-qty-badge <?= $qc; ?>" style="font-size:16px;height:28px;min-width:36px;"><?= $q; ?></span> <span class="eb-balance-qty-small">bins</span></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="eb-empty">
                    <span class="eb-empty-icon">📭</span>
                    No Full Bins currently available.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="bi-panel">
        <div class="bi-panel-hdr">
            <div>
                <div class="bi-panel-title">📦 Full Bins Inventory</div>
                <div class="bi-stat-chips">
                    <span class="bi-stat-chip bi-chip-green">📦 <?= $totalBins; ?> bins</span>
                    <span class="bi-stat-chip bi-chip-blue">🗂️ <?= $totalGroups; ?> groups</span>
                    <span class="bi-stat-chip bi-chip-purple">🌱 <?= $totalGrowers; ?> growers</span>
                </div>
            </div>
            <div class="bi-expand-btns">
                <button type="button" class="bi-sm-btn" onclick="expandAll()">⬇ Expand All</button>
                <button type="button" class="bi-sm-btn" onclick="collapseAll()">⬆ Collapse All</button>
            </div>
        </div>

        <!-- Filters -->
        <div class="bi-filter-bar">
            <input id="searchInput" class="bi-filter-input" placeholder="🔍 Search grower, variety, lot…">
            <select id="filterGrower" class="bi-filter-select">
                <option value="">All Growers</option>
                <?php foreach ($growers as $g): ?>
                    <option value="<?= bh($g['name']); ?>"><?= bh($g['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterVariety" class="bi-filter-select">
                <option value="">All Varieties</option>
                <?php foreach ($varieties as $v): ?>
                    <option value="<?= bh($v['name']); ?>"><?= bh($v['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterType" class="bi-filter-select">
                <option value="">All Types</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= bh($t['name']); ?>"><?= bh($t['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="bi-filter-printer-wrap">
                <label>🖨️ Print buttons use:</label>
                <select id="actionPrinter" class="bi-filter-select">
                    <?php if (empty($printers)): ?>
                        <option value="0">No printers</option>
                    <?php else: ?>
                        <?php foreach ($printers as $p): ?>
                            <option value="<?= (int)$p['id']; ?>" <?= (int)$fullBinPrintSettings['label_printer_id']===(int)$p['id'] ? 'selected' : ''; ?>>
                                <?= bh($p['display_name'] ?? ('Printer #' . (int)$p['id'])); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <!-- Table -->
        <div class="bi-table-wrap">
            <table class="bi-table">
                <thead>
                    <tr>
                        <th style="width:32px;"></th>
                        <th>Grower</th>
                        <th>Variety</th>
                        <th>Type</th>
                        <th>Lot</th>
                        <th>Date</th>
                        <th>Qty</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($groups)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="bi-empty">
                                <span class="bi-empty-icon">📦</span>
                                No full bins in inventory. Use the form above to add a new group.
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($groups as $g):
                    $gid = (int)$g['group_id'];
                    $latestReceiptId = 0;
                    $lrq = $mysqli->query("SELECT id FROM full_bin_receipts WHERE group_id=$gid ORDER BY id DESC LIMIT 1");
                    if ($lrq && $lrr = $lrq->fetch_assoc()) $latestReceiptId = (int)$lrr['id'];
                    $details = $mysqli->query("
                        SELECT bi.*, gp.name AS grower, vl.name AS variety, tl.name AS type
                        FROM bins_ingresso bi
                        LEFT JOIN growers_list   gp ON bi.grower_id  = gp.id
                        LEFT JOIN varieties_list vl ON bi.variety_id = vl.id
                        LEFT JOIN bin_types_list tl ON bi.type_id    = tl.id
                        WHERE bi.group_id=$gid ORDER BY bi.id ASC
                    ")->fetch_all(MYSQLI_ASSOC);
                    $q = (int)$g['total_bins'];
                    $qClass = $q <= 2 ? 'bi-qty-low' : ($q <= 5 ? 'bi-qty-medium' : 'bi-qty-high');
                    /* age badge */
                    $daysAgo = 0;
                    try { $d1=new DateTime($g['date']); $d2=new DateTime('now'); $daysAgo=max(0,(int)$d2->diff($d1)->days); } catch(Exception $ex2){ $daysAgo=0; }
                    $ageClass = $daysAgo===0 ? 'bi-age-today' : ($daysAgo<=2 ? 'bi-age-recent' : 'bi-age-old');
                    $ageLabel = $daysAgo===0 ? 'Today' : ($daysAgo===1 ? '1d ago' : $daysAgo.'d ago');
                ?>
                <!-- GROUP ROW -->
                <tr class="group-row"
                    data-id="<?= $gid; ?>"
                    data-grower="<?= bh($g['grower']); ?>"
                    data-variety="<?= bh($g['variety']); ?>"
                    data-type="<?= bh($g['type']); ?>"
                    data-lot="<?= bh($g['lot']); ?>"
                    data-date="<?= bh($g['date']); ?>"
                    onclick="toggleGroup(<?= $gid; ?>)">
                    <td><span class="bi-arrow" id="arrow_<?= $gid; ?>">▶</span></td>
                    <td id="g_grower_<?= $gid; ?>" style="font-weight:600;"><?= bh($g['grower']); ?></td>
                    <td id="g_variety_<?= $gid; ?>"><?= bh($g['variety']); ?></td>
                    <td id="g_type_<?= $gid; ?>"><?= bh($g['type']); ?></td>
                    <td id="g_lot_<?= $gid; ?>" style="font-size:11px;color:var(--c-muted);"><?= bh($g['lot']); ?></td>
                    <td id="g_date_<?= $gid; ?>" style="font-size:11px;">
                        <span class="bi-date-val"><?= bh($g['date']); ?></span>
                        <span class="bi-age-badge <?= $ageClass; ?>"><?= $ageLabel; ?></span>
                    </td>
                    <td><span class="bi-qty-badge <?= $qClass; ?>" id="g_qty_<?= $gid; ?>"><?= $q; ?></span></td>
                    <td id="g_actions_<?= $gid; ?>">
                        <div class="bi-act" onclick="event.stopPropagation();">
                            <?php if ($latestReceiptId > 0): ?>
                                <button type="button" class="bi-pdf-btn"
                                    onclick="biPreviewFullReport(<?= $latestReceiptId; ?>)"
                                    title="View latest receiving PDF">📄 PDF</button>
                            <?php else: ?>
                                <span class="bi-pdf-btn" style="opacity:.45;cursor:default;" title="No receiving PDF linked">📄 PDF</span>
                            <?php endif; ?>
                            <button type="button" class="bi-abtn bi-abtn-print"
                                onclick="openPrintGroup(<?= $gid; ?>)">🖨️ Print All</button>
                            <button type="button" class="bi-abtn bi-abtn-clone"
                                onclick="cloneGroupToForm(<?= $gid; ?>)" title="Clone to Add form">📋 Clone</button>
                            <button type="button" class="bi-abtn bi-abtn-addmore"
                                onclick="openAddMoreModal(this)"
                                data-gid="<?= $gid; ?>"
                                data-grower="<?= bh($g['grower']); ?>"
                                data-variety="<?= bh($g['variety']); ?>"
                                data-type="<?= bh($g['type']); ?>"
                                data-lot="<?= bh($g['lot']); ?>"
                                title="Add more bins to this group">➕ More</button>
                            <button type="button" class="bi-abtn bi-abtn-edit"
                                onclick="editGroupInline(<?= $gid; ?>)">✏️ Edit</button>
                            <?php if ($role === 'admin'): ?>
                                <form method="post" style="display:contents;"
                                      onsubmit="return confirm('Delete group #<?= $gid; ?> and ALL its bins? This cannot be undone.');">
                                    <input type="hidden" name="action"   value="delete_group">
                                    <input type="hidden" name="group_id" value="<?= $gid; ?>">
                                    <button type="submit" class="bi-abtn bi-abtn-delete">🗑 Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <!-- DETAIL ROW -->
                <tr class="bi-detail-row" id="group_<?= $gid; ?>" style="display:none;">
                    <td colspan="8">
                        <div class="bi-detail-inner">
                            <table class="bi-detail-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Barcode</th>
                                        <th>Grower</th>
                                        <th>Variety</th>
                                        <th>Type</th>
                                        <th>Lot</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($details as $b):
                                    $barcode = $b['barcode'] ?? '';
                                    if ($barcode === '' || $barcode === null)
                                        $barcode = 'FBIN-' . str_pad((string)$b['id'], 6, '0', STR_PAD_LEFT);
                                ?>
                                <tr id="bin_row_<?= (int)$b['id']; ?>">
                                    <td style="font-size:11px;color:var(--c-muted);"><?= (int)$b['id']; ?></td>
                                    <td><span class="bi-barcode-pill">📦 <?= bh($barcode); ?></span></td>
                                    <td id="b_grower_<?=  (int)$b['id']; ?>"><?= bh($b['grower']); ?></td>
                                    <td id="b_variety_<?= (int)$b['id']; ?>"><?= bh($b['variety']); ?></td>
                                    <td id="b_type_<?=    (int)$b['id']; ?>"><?= bh($b['type']); ?></td>
                                    <td id="b_lot_<?=     (int)$b['id']; ?>"><?= bh($b['lot']); ?></td>
                                    <td id="b_date_<?=    (int)$b['id']; ?>"><?= bh($b['date']); ?></td>
                                    <td id="b_actions_<?= (int)$b['id']; ?>">
                                        <div class="bi-act">
                                            <button type="button" class="bi-abtn bi-abtn-print"
                                                onclick="openPrintSingle(<?= (int)$b['id']; ?>)">🖨️ Print</button>
                                            <button type="button" class="bi-abtn bi-abtn-edit"
                                                onclick="editBinInline(<?= (int)$b['id']; ?>)">✏️ Edit</button>
                                            <?php if ($role === 'admin'): ?>
                                                <form method="post" style="display:contents;"
                                                      onsubmit="return confirm('Delete bin #<?= (int)$b['id']; ?>?');">
                                                    <input type="hidden" name="action" value="delete_single">
                                                    <input type="hidden" name="id"     value="<?= (int)$b['id']; ?>">
                                                    <button type="submit" class="bi-abtn bi-abtn-delete">🗑</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /.bi-main -->

<!-- ══ MODALS ══ -->
<!-- Add Grower -->
<div class="modal fade" id="addGrowerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="add_grower">
            <div class="modal-header">
                <h6 class="modal-title">🌱 Add Grower</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="bi-field-label">Grower name</label>
                <input type="text" name="new_grower" class="form-control" required autofocus placeholder="e.g. Smith Farms">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">✅ Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Variety -->
<div class="modal fade" id="addVarietyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="add_variety">
            <div class="modal-header">
                <h6 class="modal-title">🫐 Add Variety</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="bi-field-label">Variety name</label>
                <input type="text" name="new_variety" class="form-control" required autofocus placeholder="e.g. Duke Blueberry">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">✅ Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Type -->
<div class="modal fade" id="addTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="add_type">
            <div class="modal-header">
                <h6 class="modal-title">📦 Add Bin Type</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="bi-field-label">Type name</label>
                <input type="text" name="new_type" class="form-control" required autofocus placeholder="e.g. Wooden 450L">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">✅ Save</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Add More Bins to Group Modal ══ -->
<div class="modal fade" id="addMoreModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">➕ Add More Bins to Group</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="addMoreGroupId">
                <div id="addMoreGroupInfo" style="font-size:12px;color:#475569;margin-bottom:14px;padding:9px 12px;background:#f8fafc;border-radius:7px;border:1px solid var(--bi-border);line-height:1.6;"></div>
                <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px;align-items:end;">
                    <div>
                        <label class="bi-field-label">Qty to Add</label>
                        <input type="number" id="addMoreQty" class="form-control" min="1" value="1">
                    </div>
                    <div>
                        <label class="bi-field-label">🖨️ Printer (optional)</label>
                        <select id="addMorePrinter" class="form-select">
                            <option value="0">No Print</option>
                            <?php foreach ($printers as $p): ?>
                                <option value="<?= (int)$p['id']; ?>" <?= !empty($p['is_default']) ? 'selected' : ''; ?>>
                                    <?= bh($p['display_name'] ?? ('Printer #'.(int)$p['id'])); ?>
                                    <?= !empty($p['is_default']) ? ' ★' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" id="addMoreConfirmBtn" onclick="submitAddMore()">✅ Add Bins</button>
            </div>
        </div>
    </div>
</div>



<!-- TEMPLATE SELECTS for inline edit -->
<script type="text/template" id="tmplGrowerSelect">
<select class="bi-inline-select">
    <option value="">Select…</option>
    <?php foreach ($growers as $g): ?>
        <option value="<?= bh($g['name']); ?>"><?= bh($g['name']); ?></option>
    <?php endforeach; ?>
</select>
</script>
<script type="text/template" id="tmplVarietySelect">
<select class="bi-inline-select">
    <option value="">Select…</option>
    <?php foreach ($varieties as $v): ?>
        <option value="<?= bh($v['name']); ?>"><?= bh($v['name']); ?></option>
    <?php endforeach; ?>
</select>
</script>
<script type="text/template" id="tmplTypeSelect">
<select class="bi-inline-select">
    <option value="">Select…</option>
    <?php foreach ($types as $t): ?>
        <option value="<?= bh($t['name']); ?>"><?= bh($t['name']); ?></option>
    <?php endforeach; ?>
</select>
</script>



    <!-- ═══ MOVEMENT LOG (collapsible) ═══ -->
    <div class="eb-panel">
        <div class="eb-panel-hdr" style="cursor:pointer;" onclick="biToggleMovementLog()">
            <div>
                <div class="eb-panel-title">📜 Movement Log <span id="biMovementCount" style="font-size:11px;color:var(--c-muted);font-weight:400;">(<?= count($fullBinMovement); ?> entries)</span></div>
                <div class="eb-panel-subtitle">All stock changes — receipts, additions and inventory movements.</div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;" onclick="event.stopPropagation();">
                <!-- Log filters -->
                <select id="biLogGrower" class="eb-filter-select" style="min-width:110px;">
                    <option value="">All Growers</option>
                    <?php foreach ($growers as $g): ?>
                        <option value="<?= bh($g['name']); ?>"><?= bh($g['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="biLogVariety" class="eb-filter-select" style="min-width:100px;">
                    <option value="">All Varieties</option>
                    <?php foreach ($varieties as $v): ?>
                        <option value="<?= bh($v['name']); ?>"><?= bh($v['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="biLogType" class="eb-filter-select" style="min-width:100px;">
                    <option value="">All Types</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= bh($t['name']); ?>"><?= bh($t['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="biLogDatePreset" class="eb-filter-select">
                    <option value="all">All dates</option>
                    <option value="today">Today</option>
                    <option value="7">Last 7 days</option>
                    <option value="30">Last 30 days</option>
                    <option value="custom">Custom range</option>
                </select>
                <input type="date" id="biLogDateFrom" class="eb-filter-select d-none" style="min-width:130px;">
                <input type="date" id="biLogDateTo"   class="eb-filter-select d-none" style="min-width:130px;">
                <select id="biLogLimit" class="eb-filter-select" style="min-width:100px;">
                    <option value="20">Last 20</option>
                    <option value="50">Last 50</option>
                    <option value="100">Last 100</option>
                    <option value="all">All</option>
                </select>
                <button id="biExportMovementCsv" class="eb-sm-btn" type="button">⬇ CSV</button>
                <?php if ($role === 'admin'): ?>
                    <button class="eb-sm-btn" type="button" onclick="biSelectVisibleMovements()">☑ Select visible</button>
                    <button class="eb-sm-btn" type="button" onclick="biDeleteSelectedMovements()" style="color:#b91c1c;border-color:#fecaca;">🗑 Delete selected</button>
                <?php endif; ?>
                <button class="eb-log-toggle" id="biMovementToggleBtn" type="button">▼ Show</button>
            </div>
        </div>

        <div id="biMovementBody" style="display:none;">
            <div class="eb-table-wrap eb-log-body">
                <table class="eb-table">
                    <thead>
                        <tr>
                            <?php if ($role === 'admin'): ?><th style="width:34px;text-align:center;"><input type="checkbox" id="biSelectAllVisible" onchange="biToggleVisibleMovementChecks(this.checked)" title="Select visible"></th><?php endif; ?>
                            <th>Date / Time</th>
                            <th>Grower</th>
                            <th>Variety</th>
                            <th>Type</th>
                            <th>Lot</th>
                            <th style="text-align:right;">Δ Qty</th>
                            <th style="width:64px;text-align:center;">PDF</th>
                            <th>Reason</th>
                            <?php if ($role === 'admin'): ?><th style="width:54px;text-align:center;">Delete</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="biMovementTbody">
                        <?php foreach ($fullBinMovement as $i => $lr): ?>
                            <tr data-log-index="<?= $i; ?>"
                                data-log-id="<?= (int)$lr['id']; ?>"
                                data-grower="<?= bh($lr['grower']); ?>"
                                data-variety="<?= bh($lr['variety']); ?>"
                                data-type="<?= bh($lr['type']); ?>"
                                data-date="<?= bh(substr($lr['created_at'], 0, 10)); ?>">
                                <?php if ($role === 'admin'): ?><td style="text-align:center;"><input type="checkbox" class="biMovementCheck" value="<?= (int)$lr['id']; ?>" onclick="event.stopPropagation()"></td><?php endif; ?>
                                <td style="font-size:11px;white-space:nowrap;"><?= bh($lr['created_at']); ?></td>
                                <td><?= bh($lr['grower']); ?></td>
                                <td><?= bh($lr['variety']); ?></td>
                                <td><?= bh($lr['type']); ?></td>
                                <td><?= bh($lr['lot']); ?></td>
                                <td style="text-align:right;">
                                    <?php $delta = (int)$lr['qty_change']; ?>
                                    <span class="<?= $delta >= 0 ? 'eb-delta-pos' : 'eb-delta-neg'; ?>">
                                        <?= $delta >= 0 ? '+' : ''; ?><?= $delta; ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($delta > 0 && (int)($lr['receipt_id'] ?? 0) > 0): ?>
                                        <button type="button"
                                                class="eb-pdf-icon eb-pdf-ready"
                                                title="Preview receiving PDF"
                                                aria-label="Preview receiving PDF"
                                                onclick="biPreviewFullReport(<?= (int)$lr['receipt_id']; ?>)">PDF</button>
                                    <?php elseif ($delta > 0): ?>
                                        <span class="eb-pdf-icon eb-pdf-missing"
                                              title="No PDF linked to this historical movement">PDF</span>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:11px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:11px;color:var(--c-muted);"><?= bh($lr['reason'] ?? ''); ?></td>
                                <?php if ($role === 'admin'): ?>
                                    <td style="text-align:center;"><button type="button" class="eb-sm-btn" style="padding:3px 7px;color:#b91c1c;" title="Delete event" onclick="biDeleteOneMovement(<?= (int)$lr['id']; ?>)">🗑</button></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($log_rows)): ?>
                            <tr><td colspan="<?= $role === 'admin' ? 10 : 8 ?>" style="text-align:center;color:var(--c-muted);padding:24px;">No log entries.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<div id="biPdfPopup" style="display:none;position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.72);padding:22px;align-items:center;justify-content:center;">
  <div style="width:min(1180px,96vw);height:min(920px,94vh);background:#fff;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;">
    <div style="padding:12px 16px;display:flex;justify-content:space-between;border-bottom:1px solid #e2e8f0;"><div><strong>📄 Full Bin Receiving Report</strong><div id="biPdfPopupSub" style="font-size:11px;color:#64748b"></div></div><button type="button" onclick="biClosePdfPopup()" style="width:36px;height:36px;border:0;border-radius:8px;background:#f1f5f9;font-size:25px">×</button></div>
    <div style="flex:1;min-height:0;background:#dbe1e8"><iframe id="biPdfFrame" style="width:100%;height:100%;border:0;background:#fff"></iframe></div>
    <div style="padding:10px 16px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end"><button type="button" class="btn btn-secondary" onclick="biClosePdfPopup()">Close</button></div>
  </div>
</div>
<script>


function biVisibleMovementRows(){
    return [...document.querySelectorAll('#biMovementTbody tr[data-log-id]')].filter(tr=>tr.style.display!=='none');
}
function biToggleVisibleMovementChecks(checked){
    biVisibleMovementRows().forEach(tr=>{const cb=tr.querySelector('.biMovementCheck');if(cb)cb.checked=checked;});
}
function biSelectVisibleMovements(){
    const rows=biVisibleMovementRows();
    const allSelected=rows.length>0&&rows.every(tr=>tr.querySelector('.biMovementCheck')?.checked);
    biToggleVisibleMovementChecks(!allSelected);
    const all=document.getElementById('biSelectAllVisible');if(all)all.checked=!allSelected;
}
async function biDeleteMovementIds(ids){
    ids=[...new Set(ids.map(Number).filter(v=>v>0))];
    if(!ids.length){alert('Select at least one movement event.');return;}
    if(!confirm(`Delete ${ids.length} movement event${ids.length===1?'':'s'}?\n\nThis deletes Movement Log history only. Linked receiving PDFs will also be permanently deleted.`))return;
    const fd=new FormData();fd.append('movement_log_delete','1');ids.forEach(id=>fd.append('ids[]',String(id)));
    try{
        const r=await fetch('bins_ingresso.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
        const data=await r.json();if(!data.ok)throw new Error(data.error||'Delete failed.');
        alert(`Deleted ${data.deleted||0} event(s).${data.pdf_deleted?` ${data.pdf_deleted} PDF(s) deleted.`:''}`);
        location.reload();
    }catch(e){alert(e.message||String(e));}
}
function biDeleteSelectedMovements(){biDeleteMovementIds([...document.querySelectorAll('.biMovementCheck:checked')].map(x=>x.value));}
function biDeleteOneMovement(id){biDeleteMovementIds([id]);}

async function biRefreshCurrentBalance(){
    const fd=new FormData(); fd.append('full_bin_balance_action','1');
    const r=await fetch('bins_ingresso.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
    const data=await r.json();
    if(!data.ok)return;
    const body=document.getElementById('biCurrentBalanceBody'); if(!body)return;
    if(!Array.isArray(data.balances)||!data.balances.length){
        body.innerHTML=`<div class="eb-empty"><span class="eb-empty-icon">📭</span>No Full Bins currently available.</div>`;
        return;
    }
    body.innerHTML='<div class="eb-balance-grid">'+data.balances.map(b=>{
        const q=Number(b.qty||0),qc=q<=5?'eb-qty-low':(q<=20?'eb-qty-medium':'eb-qty-high');
        const lot=b.lot?`<div class="eb-balance-type" style="margin-top:2px;">Lot: ${biEsc(b.lot)}</div>`:'';
        return `<div class="eb-balance-card">
            <div class="eb-balance-grower">${biEsc(b.grower||'')}</div>
            <div class="eb-balance-type">${biEsc(b.variety||'')} · ${biEsc(b.type||'')}</div>
            ${lot}
            <div><span class="eb-qty-badge ${qc}" style="font-size:16px;height:28px;min-width:36px;">${q}</span> <span class="eb-balance-qty-small">bins</span></div>
        </div>`;
    }).join('')+'</div>';
}
function biPrependMovement(m){
    if(!m)return;
    const tbody=document.getElementById('biMovementTbody'); if(!tbody)return;
    document.getElementById('biNoMovement')?.remove();
    const delta=Number(m.qty_change||0);
    const pdf=(delta>0 && Number(m.receipt_id||0)>0)
        ? `<button type="button" class="eb-pdf-icon eb-pdf-ready" onclick="biPreviewFullReport(${Number(m.receipt_id)})">PDF</button>`
        : (delta>0 ? `<span class="eb-pdf-icon eb-pdf-missing">PDF</span>` : `<span class="text-muted" style="font-size:11px;">—</span>`);
    const tr=document.createElement('tr');
    tr.dataset.grower=m.grower||''; tr.dataset.variety=m.variety||''; tr.dataset.type=m.type||''; tr.dataset.date=String(m.created_at||'').slice(0,10);
    tr.innerHTML=`<td style="font-size:11px;white-space:nowrap;">${biEsc(m.created_at||'')}</td>
        <td>${biEsc(m.grower||'')}</td><td>${biEsc(m.variety||'')}</td><td>${biEsc(m.type||'')}</td><td>${biEsc(m.lot||'')}</td>
        <td style="text-align:right;"><span class="${delta>=0?'eb-delta-pos':'eb-delta-neg'}">${delta>=0?'+':''}${delta}</span></td>
        <td style="text-align:center;">${pdf}</td>
        <td style="font-size:11px;color:var(--c-muted);">${biEsc(m.reason||'')}</td>`;
    tbody.prepend(tr);
    const c=document.getElementById('biMovementCount'); if(c)c.textContent=`(${tbody.querySelectorAll('tr').length} entries)`;
    biApplyMovementFilters();
}


function biEsc(v){const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML;}





document.addEventListener('DOMContentLoaded',()=>{
    ['biLogGrower','biLogVariety','biLogType','biLogDatePreset','biLogDateFrom','biLogDateTo','biLogLimit'].forEach(id=>document.getElementById(id)?.addEventListener('change',biApplyMovementFilters));
    document.getElementById('biExportMovementCsv')?.addEventListener('click',biExportMovementCsv);
    biApplyMovementFilters();
});


let biMovementOpen = false;
function biToggleMovementLog() {
    const body = document.getElementById('biMovementBody');
    const btn  = document.getElementById('biMovementToggleBtn');
    biMovementOpen = !biMovementOpen;
    if (body) body.style.display = biMovementOpen ? '' : 'none';
    if (btn) btn.textContent = biMovementOpen ? '▲ Hide' : '▼ Show';
    if (biMovementOpen) biApplyMovementFilters();
}

function biToggleCustomDates() {
    const val = document.getElementById('biLogDatePreset')?.value;
    const from = document.getElementById('biLogDateFrom');
    const to   = document.getElementById('biLogDateTo');
    const show = val === 'custom';
    from?.classList.toggle('d-none', !show);
    to?.classList.toggle('d-none', !show);
}

function biMovementInDateRange(rowDate) {
    const mode = document.getElementById('biLogDatePreset')?.value || 'all';
    if (!rowDate || mode === 'all') return true;
    const today = new Date();
    const rd = new Date(rowDate + 'T00:00:00');
    if (mode === 'today') return rd.toDateString() === today.toDateString();
    if (mode === '7' || mode === '30') return (today - rd) / 86400000 <= parseInt(mode, 10);
    if (mode === 'custom') {
        const from = document.getElementById('biLogDateFrom')?.value;
        const to   = document.getElementById('biLogDateTo')?.value;
        if (from && rowDate < from) return false;
        if (to && rowDate > to) return false;
    }
    return true;
}

function biApplyMovementFilters() {
    const limit = document.getElementById('biLogLimit')?.value || '20';
    const gv = (document.getElementById('biLogGrower')?.value || '').toLowerCase();
    const vv = (document.getElementById('biLogVariety')?.value || '').toLowerCase();
    const tv = (document.getElementById('biLogType')?.value || '').toLowerCase();
    biToggleCustomDates();

    let shown = 0;
    Array.from(document.querySelectorAll('#biMovementTbody tr')).forEach(row => {
        if (row.id === 'biNoMovement') return;
        const rg = (row.dataset.grower || '').toLowerCase();
        const rv = (row.dataset.variety || '').toLowerCase();
        const rt = (row.dataset.type || '').toLowerCase();
        const rd = row.dataset.date || '';
        let match = true;
        if (gv && rg !== gv) match = false;
        if (vv && rv !== vv) match = false;
        if (tv && rt !== tv) match = false;
        if (!biMovementInDateRange(rd)) match = false;
        if (!match) { row.style.display = 'none'; return; }
        if (limit !== 'all' && shown >= parseInt(limit,10)) { row.style.display='none'; return; }
        row.style.display=''; shown++;
    });
}

function biExportMovementCsv() {
    const tbody = document.getElementById('biMovementTbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.style.display !== 'none' && tr.id !== 'biNoMovement');
    if (!rows.length) { alert('No rows to export'); return; }
    const headers = ['Date/Time','Grower','Variety','Type','Lot','Delta Qty','PDF','Reason'];
    const lines = [headers.join(',')];
    rows.forEach(tr => {
        const cells = Array.from(tr.children).map(td => '"' + (td.innerText||'').replace(/"/g,'""') + '"');
        lines.push(cells.join(','));
    });
    const blob = new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'full_bins_log_' + new Date().toISOString().slice(0,10) + '.csv'; a.click();
    URL.revokeObjectURL(url);
}

document.addEventListener('DOMContentLoaded', function () {
    ['biLogGrower','biLogVariety','biLogType','biLogDatePreset','biLogDateFrom','biLogDateTo','biLogLimit'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', biApplyMovementFilters);
    });
    document.getElementById('biExportMovementCsv')?.addEventListener('click', biExportMovementCsv);
    const ll = document.getElementById('biLogLimit');
    if (ll) ll.value = localStorage.getItem('bi_log_limit') || '20';
});

async function biSavePrinterSetting(kind,value){
 const fd=new FormData();fd.append('save_full_bin_printer_setting','1');fd.append('kind',kind);
 if(kind==='label')fd.append('printer_id',value);
 else if(kind==='template')fd.append('template_id',value);
 else fd.append('printer_name',value);
 const r=await fetch('bins_ingresso.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
 const raw=await r.text();let data;try{data=JSON.parse(raw)}catch(e){throw new Error(raw.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim())}
 if(!data.ok)throw new Error(data.error||'Unable to save printer'); return data;
}
document.getElementById('biLabelPrinter')?.addEventListener('change',async function(){try{await biSavePrinterSetting('label',this.value);const a=document.getElementById('actionPrinter');if(a&&this.value!=='0')a.value=this.value}catch(e){alert(e.message)}});
document.getElementById('biLabelTemplate')?.addEventListener('change',async function(){
    try{await biSavePrinterSetting('template',this.value)}
    catch(e){alert(e.message)}
});
document.getElementById('biReportPrinter')?.addEventListener('change',async function(){try{await biSavePrinterSetting('report',this.value)}catch(e){alert(e.message)}});

async function biReportAction(id,action){
 const fd=new FormData();fd.append('full_bin_report_action',action);fd.append('receipt_id',String(id));
 const r=await fetch('bins_ingresso.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
 const raw=await r.text();try{return JSON.parse(raw)}catch(e){return{ok:false,error:raw.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim()}}
}
function biOpenPdfPopup(url,id){const p=document.getElementById('biPdfPopup'),f=document.getElementById('biPdfFrame');if(!p||!f)return;f.src=url;document.getElementById('biPdfPopupSub').textContent='Receipt #'+id;p.style.display='flex';document.body.style.overflow='hidden'}
function biClosePdfPopup(){const p=document.getElementById('biPdfPopup'),f=document.getElementById('biPdfFrame');if(f)f.src='about:blank';if(p)p.style.display='none';document.body.style.overflow=''}
async function biPreviewFullReport(id){const d=await biReportAction(id,'preview');if(!d.ok){alert(d.error||'Unable to open report');return}biOpenPdfPopup(d.url,id)}
async function biTestFullReport(id){const d=await biReportAction(id,'test_print');if(!d.ok){alert(d.error||'Test Print failed');return}alert('Report queued for '+d.printer+(d.jobId?' · Job #'+d.jobId:''))}
document.getElementById('biTestLatestReport')?.addEventListener('click',function(){const id=Number(this.dataset.receiptId||0);if(id)biTestFullReport(id)});
document.getElementById('biPdfPopup')?.addEventListener('click',function(e){if(e.target===this)biClosePdfPopup()});
document.addEventListener('keydown',function(e){if(e.key==='Escape')biClosePdfPopup()});
function biPrependReceipt(r){
 if(!r)return;const tb=document.getElementById('biReceiptTbody');if(!tb)return;document.getElementById('biNoReceipts')?.remove();
 const tr=document.createElement('tr');tr.innerHTML=`<td>#${Number(r.id||0)}</td><td>${biEsc(r.created_at||'')}</td><td>${biEsc(r.grower||'')}</td><td>${biEsc(r.variety||'')}</td><td>${biEsc(r.type||'')}</td><td>${biEsc(r.lot||'')}</td><td><strong>${Number(r.qty||0)}</strong></td><td><button type="button" class="bi-pdf-btn" onclick="biPreviewFullReport(${Number(r.id||0)})">📄 PDF</button></td>`;tb.prepend(tr);
 const t=document.getElementById('biTestLatestReport');if(t){t.dataset.receiptId=String(r.id||0);t.disabled=false;}
}

/* ── helpers ── */
function getSelectedPrinterId() {
    return parseInt(document.getElementById('actionPrinter')?.value || '0', 10) || 0;
}
function openPrintSingle(id) {
    const pid = getSelectedPrinterId();
    window.open('print_bin_label.php?id=' + id + (pid > 0 ? '&printer_id=' + pid : ''), '_blank');
}
function openPrintGroup(groupId) {
    const pid = getSelectedPrinterId();
    window.open('print_bin_label_group.php?group_id=' + groupId + (pid > 0 ? '&printer_id=' + pid : ''), '_blank');
}

/* ── toggle group detail ── */
function toggleGroup(id) {
    const row   = document.getElementById('group_' + id);
    const arrow = document.getElementById('arrow_' + id);
    if (!row || !arrow) return;
    const isOpen = row.style.display !== 'none';
    /* collapse others */
    document.querySelectorAll('.bi-detail-row').forEach(r => { r.style.display = 'none'; });
    document.querySelectorAll('.bi-arrow').forEach(a => { a.classList.remove('open'); });
    if (!isOpen) { row.style.display = ''; arrow.classList.add('open'); }
}
function expandAll() {
    document.querySelectorAll('.bi-detail-row').forEach(r => r.style.display = '');
    document.querySelectorAll('.bi-arrow').forEach(a => a.classList.add('open'));
}
function collapseAll() {
    document.querySelectorAll('.bi-detail-row').forEach(r => r.style.display = 'none');
    document.querySelectorAll('.bi-arrow').forEach(a => a.classList.remove('open'));
}

/* ── inline edit: group ── */
function editGroupInline(gid) {
    const gc = document.getElementById('g_grower_'  + gid);
    const vc = document.getElementById('g_variety_' + gid);
    const tc = document.getElementById('g_type_'    + gid);
    const lc = document.getElementById('g_lot_'     + gid);
    const dc = document.getElementById('g_date_'    + gid);
    const ac = document.getElementById('g_actions_' + gid);
    if (!gc || !vc || !tc || !lc || !dc || !ac) return;

    const gs = createSelectFromTemplate('tmplGrowerSelect',  gc.innerText.trim(), 'eg_grower_'  + gid);
    const vs = createSelectFromTemplate('tmplVarietySelect', vc.innerText.trim(), 'eg_variety_' + gid);
    const ts = createSelectFromTemplate('tmplTypeSelect',    tc.innerText.trim(), 'eg_type_'    + gid);
    if (!gs || !vs || !ts) { alert('Error preparing inline edit.'); return; }

    gc.innerHTML = ''; gc.appendChild(gs);
    vc.innerHTML = ''; vc.appendChild(vs);
    tc.innerHTML = ''; tc.appendChild(ts);
    const l = lc.innerText.trim();
    const d = (dc.querySelector('.bi-date-val')?.textContent || dc.firstChild?.nodeValue || '').trim();
    lc.innerHTML = `<input type="text" class="bi-inline-input" id="eg_lot_${gid}"  value="${l}">`;
    dc.innerHTML = `<input type="date" class="bi-inline-input" id="eg_date_${gid}" value="${d}">`;

    ac.innerHTML = `
        <div class="bi-act">
            <button class="bi-abtn bi-abtn-save"   onclick="saveGroupInline(${gid}); event.stopPropagation();">💾 Save</button>
            <button class="bi-abtn bi-abtn-cancel" onclick="location.reload(); event.stopPropagation();">✕ Cancel</button>
        </div>`;
}
function saveGroupInline(gid) {
    const fd = new FormData();
    fd.append('action',   'edit_group_inline');
    fd.append('group_id', gid);
    fd.append('grower',   document.getElementById('eg_grower_'  + gid)?.value || '');
    fd.append('variety',  document.getElementById('eg_variety_' + gid)?.value || '');
    fd.append('type',     document.getElementById('eg_type_'    + gid)?.value || '');
    fd.append('lot',      document.getElementById('eg_lot_'     + gid)?.value || '');
    fd.append('date',     document.getElementById('eg_date_'    + gid)?.value || '');
    fetch('bins_ingresso.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(txt => {
            if (txt.trim() === 'OK') {
                location.reload();
            } else {
                alert('Errore salvataggio gruppo (risposta: ' + txt.substring(0,80) + ')');
                console.error('[saveGroupInline] server response:', txt);
            }
        })
        .catch(err => { alert('Errore di rete: ' + err.message); console.error('[saveGroupInline] fetch error:', err); });
}

/* ── inline edit: single bin ── */
function createSelectFromTemplate(tmplId, selectedValue, newId) {
    const tmpl = document.getElementById(tmplId);
    if (!tmpl) return null;
    const div = document.createElement('div');
    div.innerHTML = tmpl.innerHTML.trim();
    const sel = div.firstElementChild;
    if (!sel) return null;
    if (newId) sel.id = newId;
    sel.value = selectedValue || '';
    return sel;
}
function editBinInline(id) {
    const gc = document.getElementById('b_grower_'  + id);
    const vc = document.getElementById('b_variety_' + id);
    const tc = document.getElementById('b_type_'    + id);
    const lc = document.getElementById('b_lot_'     + id);
    const dc = document.getElementById('b_date_'    + id);
    const ac = document.getElementById('b_actions_' + id);
    if (!gc || !vc || !tc || !lc || !dc || !ac) return;

    const gs = createSelectFromTemplate('tmplGrowerSelect',  gc.innerText.trim(), 'eb_grower_'  + id);
    const vs = createSelectFromTemplate('tmplVarietySelect', vc.innerText.trim(), 'eb_variety_' + id);
    const ts = createSelectFromTemplate('tmplTypeSelect',    tc.innerText.trim(), 'eb_type_'    + id);
    if (!gs || !vs || !ts) { alert('Error preparing inline edit.'); return; }

    gc.innerHTML = ''; gc.appendChild(gs);
    vc.innerHTML = ''; vc.appendChild(vs);
    tc.innerHTML = ''; tc.appendChild(ts);
    lc.innerHTML = `<input type="text" class="bi-inline-input" id="eb_lot_${id}"  value="${lc.innerText.trim()}">`;
    dc.innerHTML = `<input type="date" class="bi-inline-input" id="eb_date_${id}" value="${dc.innerText.trim()}">`;

    ac.innerHTML = `
        <div class="bi-act">
            <button class="bi-abtn bi-abtn-save"   onclick="event.stopPropagation(); saveBinInline(${id});">💾 Save</button>
            <button class="bi-abtn bi-abtn-cancel" onclick="event.stopPropagation(); location.reload();">✕ Cancel</button>
        </div>`;
}
function saveBinInline(id) {
    const fd = new FormData();
    fd.append('action',  'edit_single_inline');
    fd.append('id',      id);
    fd.append('grower',  document.getElementById('eb_grower_'  + id).value);
    fd.append('variety', document.getElementById('eb_variety_' + id).value);
    fd.append('type',    document.getElementById('eb_type_'    + id).value);
    fd.append('lot',     document.getElementById('eb_lot_'     + id).value);
    fd.append('date',    document.getElementById('eb_date_'    + id).value);
    fetch('bins_ingresso.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(txt => {
            if (txt.trim() === 'OK') {
                location.reload();
            } else {
                alert('Errore salvataggio bin (risposta: ' + txt.substring(0,80) + ')');
                console.error('[saveBinInline] server response:', txt);
            }
        })
        .catch(err => { alert('Errore di rete: ' + err.message); console.error('[saveBinInline] fetch error:', err); });
}

/* ── live filters ── */
function applyFilters() {
    const search  = (document.getElementById('searchInput')?.value  || '').toLowerCase();
    const grower  = (document.getElementById('filterGrower')?.value || '').toLowerCase();
    const variety = (document.getElementById('filterVariety')?.value|| '').toLowerCase();
    const type    = (document.getElementById('filterType')?.value   || '').toLowerCase();

    document.querySelectorAll('.group-row').forEach(row => {
        const g = (row.dataset.grower  || '').toLowerCase();
        const v = (row.dataset.variety || '').toLowerCase();
        const t = (row.dataset.type    || '').toLowerCase();
        const l = (row.dataset.lot     || '').toLowerCase();
        const id = row.dataset.id;

        let ok = true;
        if (search  && !(g.includes(search) || v.includes(search) || l.includes(search))) ok = false;
        if (grower  && g !== grower)  ok = false;
        if (variety && v !== variety) ok = false;
        if (type    && t !== type)    ok = false;

        row.style.display = ok ? '' : 'none';
        const sub = document.getElementById('group_' + id);
        if (sub && !ok) { sub.style.display = 'none'; document.getElementById('arrow_' + id)?.classList.remove('open'); }
    });
}

/* ── AJAX helpers ── */
function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}
function showFlash(msg, type) {
    const cls = type === 'success' ? 'bi-flash-success' : 'bi-flash-danger';
    const ex = document.getElementById('bi-flash-ajax');
    if (ex) ex.remove();
    const div = document.createElement('div');
    div.id = 'bi-flash-ajax';
    div.className = 'bi-flash ' + cls;
    div.innerHTML = '<span>' + msg + '</span><button onclick="this.closest(\'.bi-flash\').remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:14px;color:inherit;opacity:.6;">✕</button>';
    const main = document.querySelector('.bi-main');
    if (main) main.insertBefore(div, main.firstChild);
    else document.body.prepend(div);
    setTimeout(() => { div.style.transition = 'opacity .5s'; div.style.opacity = '0'; setTimeout(() => div.remove(), 500); }, 5000);
}
function updateStatChips(bins, groups, growers) {
    document.querySelectorAll('.bi-chip-green').forEach(el  => { el.innerHTML = '📦 ' + bins    + ' bins'; });
    document.querySelectorAll('.bi-chip-blue').forEach(el   => { el.innerHTML = '🗂️ ' + groups  + ' groups'; });
    document.querySelectorAll('.bi-chip-purple').forEach(el => { el.innerHTML = '🌱 ' + growers + ' growers'; });
}
function prependGroupRow(data) {
    const tbody = document.querySelector('.bi-table tbody');
    if (!tbody) return;
    const gid = data.group_id;
    const q   = data.qty;
    const qc  = q <= 2 ? 'bi-qty-low' : (q <= 5 ? 'bi-qty-medium' : 'bi-qty-high');
    const html = `
    <tr class="group-row"
        data-id="${gid}"
        data-grower="${escHtml(data.grower)}"
        data-variety="${escHtml(data.variety)}"
        data-type="${escHtml(data.type)}"
        data-lot="${escHtml(data.lot)}"
        data-date="${escHtml(data.date)}"
        onclick="toggleGroup(${gid})">
        <td><span class="bi-arrow" id="arrow_${gid}">▶</span></td>
        <td id="g_grower_${gid}"  style="font-weight:600;">${escHtml(data.grower)}</td>
        <td id="g_variety_${gid}">${escHtml(data.variety)}</td>
        <td id="g_type_${gid}">${escHtml(data.type)}</td>
        <td id="g_lot_${gid}"    style="font-size:11px;color:var(--c-muted);">${escHtml(data.lot)}</td>
        <td id="g_date_${gid}"   style="font-size:11px;">${escHtml(data.date)}<span class="bi-age-badge bi-age-today">Today</span></td>
        <td><span class="bi-qty-badge ${qc}" id="g_qty_${gid}">${q}</span></td>
        <td id="g_actions_${gid}">
            <div class="bi-act" onclick="event.stopPropagation();">
                <button type="button" class="bi-abtn bi-abtn-print"   onclick="openPrintGroup(${gid})">🖨️ Print All</button>
                <button type="button" class="bi-abtn bi-abtn-clone"   onclick="cloneGroupToForm(${gid})" title="Clone to Add form">📋 Clone</button>
                <button type="button" class="bi-abtn bi-abtn-addmore" onclick="openAddMoreModal(this)"
                    data-gid="${gid}"
                    data-grower="${escHtml(data.grower)}"
                    data-variety="${escHtml(data.variety)}"
                    data-type="${escHtml(data.type)}"
                    data-lot="${escHtml(data.lot)}"
                    title="Add more bins">➕ More</button>
                <button type="button" class="bi-abtn bi-abtn-edit"    onclick="editGroupInline(${gid})">✏️ Edit</button>
            </div>
        </td>
    </tr>
    <tr class="bi-detail-row" id="group_${gid}" style="display:none;">
        <td colspan="8">
            <div class="bi-detail-inner">
                <table class="bi-detail-table">
                    <thead><tr>
                        <th>ID</th><th>Barcode</th><th>Grower</th><th>Variety</th>
                        <th>Type</th><th>Lot</th><th>Date</th><th>Actions</th>
                    </tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </td>
    </tr>`;
    const emptyRow = tbody.querySelector('.bi-empty')?.closest('tr');
    if (emptyRow) emptyRow.remove();
    tbody.insertAdjacentHTML('afterbegin', html);
    /* immediately populate the detail tbody with real bin rows */
    refreshGroupDetail(gid);
}

/* ── Clone group to Add form ── */
function cloneGroupToForm(gid) {
    const row  = document.querySelector(`.group-row[data-id="${gid}"]`);
    const form = document.getElementById('addForm');
    if (!row || !form) return;
    const set = (name, val) => { const el = form.querySelector(`[name="${name}"]`); if (el) el.value = val; };
    set('grower',   row.dataset.grower);
    set('variety',  row.dataset.variety);
    set('type',     row.dataset.type);
    set('lot',      row.dataset.lot);
    set('quantity', 1);
    form.closest('.bi-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    form.querySelector('[name="quantity"]')?.focus();
    const panel = form.closest('.bi-panel');
    if (panel) {
        panel.style.transition = 'box-shadow .3s';
        panel.style.boxShadow  = '0 0 0 3px rgba(37,99,235,.30)';
        setTimeout(() => { panel.style.boxShadow = ''; }, 1500);
    }
}

/* ── Add More Modal ── */
function openAddMoreModal(btn) {
    /* Read group data directly from the button's data-* attributes.
       This avoids a fragile DOM querySelector that could silently return
       null and cause 'nothing happens' on click. */
    if (!btn) { console.error('[openAddMoreModal] btn is null'); return; }
    const gid     = btn.dataset.gid     || '';
    const grower  = btn.dataset.grower  || '';
    const variety = btn.dataset.variety || '';
    const type    = btn.dataset.type    || '';
    const lot     = btn.dataset.lot     || '';
    if (!gid) { console.error('[openAddMoreModal] gid is empty'); return; }
    const modalEl = document.getElementById('addMoreModal');
    if (!modalEl) { console.error('[openAddMoreModal] modal element not found'); return; }
    document.getElementById('addMoreGroupId').value = gid;
    document.getElementById('addMoreGroupInfo').innerHTML =
        `<strong>Group #${escHtml(gid)}</strong> &nbsp;·&nbsp; ${escHtml(grower)} &nbsp;·&nbsp; `
        + `${escHtml(variety)} &nbsp;·&nbsp; ${escHtml(type)}`
        + (lot ? ` &nbsp;·&nbsp; Lot: <code>${escHtml(lot)}</code>` : '');
    document.getElementById('addMoreQty').value = 1;
    /* Sync printer with the global actionPrinter selector so labels are
       printed by default using the same printer already selected on the page */
    const globalPid = document.getElementById('actionPrinter')?.value || '0';
    const mPrinter  = document.getElementById('addMorePrinter');
    if (mPrinter && globalPid !== '0') mPrinter.value = globalPid;
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}
function submitAddMore() {
    const gid = document.getElementById('addMoreGroupId').value;
    const qty = parseInt(document.getElementById('addMoreQty').value) || 1;
    const pid = parseInt(document.getElementById('addMorePrinter')?.value || '0');
    const btn = document.getElementById('addMoreConfirmBtn');
    if (btn) btn.disabled = true;
    const fd = new FormData();
    fd.append('action',     'add_to_group');
    fd.append('group_id',   gid);
    fd.append('quantity',   qty);
    fd.append('printer_id', pid);
    fetch('bins_ingresso.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            bootstrap.Modal.getInstance(document.getElementById('addMoreModal'))?.hide();
            if (data.ok) {
                const extra = data.printed > 0 ? ' · Printed: ' + data.printed : '';
                showFlash(data.msg + extra, 'success');
                updateStatChips(data.totalBins, data.totalGroups, data.totalGrowers);
                biPrependReceipt(data.receipt);
                biPrependMovement(data.movementLog);
                biRefreshCurrentBalance();
                biPrependMovement(data.movementLog);
                biRefreshCurrentBalance();
                /* update qty badge */
                const badge = document.getElementById('g_qty_' + gid);
                if (badge) {
                    badge.textContent = data.newGroupTotal;
                    const nc = data.newGroupTotal <= 2 ? 'bi-qty-low' : (data.newGroupTotal <= 5 ? 'bi-qty-medium' : 'bi-qty-high');
                    badge.className = 'bi-qty-badge ' + nc;
                }
                /* reload detail rows so all new bins appear in the expanded view */
                refreshGroupDetail(gid);
            } else { showFlash(data.msg || 'Error.', 'danger'); }
        })
        .catch(() => { if (btn) btn.disabled = false; showFlash('Network error.', 'danger'); });
}

/* ── refreshGroupDetail: reload bin rows for a group after add-more ── */
function refreshGroupDetail(gid) {
    const detailRow = document.getElementById('group_' + gid);
    if (!detailRow) { return; }   /* not rendered, nothing to update */
    const tbody = detailRow.querySelector('tbody');
    if (!tbody) { return; }
    const fd = new FormData();
    fd.append('action',   'get_group_detail');
    fd.append('group_id', gid);
    fetch('bins_ingresso.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok || !data.bins) return;
            /* rebuild tbody rows */
            tbody.innerHTML = data.bins.map(b => {
                const isAdmin = document.querySelector('.bi-abtn-delete') !== null; /* rough check */
                const adminBtn = isAdmin
                    ? `<form method="post" style="display:contents;"
                           onsubmit="return confirm('Delete bin #${b.id}?');">
                           <input type="hidden" name="action" value="delete_single">
                           <input type="hidden" name="id" value="${b.id}">
                           <button type="submit" class="bi-abtn bi-abtn-delete">🗑</button>
                       </form>` : '';
                return `<tr id="bin_row_${b.id}">
                    <td style="font-size:11px;color:var(--c-muted);">${b.id}</td>
                    <td><span class="bi-barcode-pill">📦 ${escHtml(b.barcode)}</span></td>
                    <td id="b_grower_${b.id}">${escHtml(b.grower||'')}</td>
                    <td id="b_variety_${b.id}">${escHtml(b.variety||'')}</td>
                    <td id="b_type_${b.id}">${escHtml(b.type||'')}</td>
                    <td id="b_lot_${b.id}">${escHtml(b.lot||'')}</td>
                    <td id="b_date_${b.id}">${escHtml(b.date||'')}</td>
                    <td id="b_actions_${b.id}">
                        <div class="bi-act">
                            <button type="button" class="bi-abtn bi-abtn-print"
                                onclick="openPrintSingle(${b.id})">🖨️ Print</button>
                            <button type="button" class="bi-abtn bi-abtn-edit"
                                onclick="editBinInline(${b.id})">✏️ Edit</button>
                            ${adminBtn}
                        </div>
                    </td>
                </tr>`;
            }).join('');
            /* ensure the detail is visible */
            detailRow.style.display = '';
            const arrow = document.getElementById('arrow_' + gid);
            if (arrow) arrow.classList.add('open');
        })
        .catch(() => { /* silently ignore – user can reload manually */ });
}

/* ── AJAX Add form submit (no page reload) ── */
(function () {
    const form = document.getElementById('addForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const btn     = document.getElementById('addBtn');
        const spinner = document.getElementById('addBtnSpinner');
        if (btn) btn.disabled = true;
        if (spinner) spinner.style.display = '';
        fetch('bins_ingresso.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
        .then(r => r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (spinner) spinner.style.display = 'none';
            if (data.ok) {
                let reportExtra = '';
                if (data.receipt?.report_generated) {
                    reportExtra = data.receipt.report_printer
                        ? (data.receipt.report_error ? ' · ⚠ Report: ' + data.receipt.report_error : ' · Report queued')
                        : ' · PDF report generated';
                }
                showFlash(data.msg + reportExtra, 'success');
                updateStatChips(data.totalBins, data.totalGroups, data.totalGrowers);
                biPrependReceipt(data.receipt);
                if (data.merged) {
                    /* bins were added to an existing group — refresh its detail rows
                       and update its qty badge instead of prepending a new row */
                    const badge = document.getElementById('g_qty_' + data.group_id);
                    if (badge) {
                        const newTotal = parseInt(badge.textContent || '0') + Number(data.insertedQty ?? data.qty ?? 0);
                        badge.textContent = newTotal;
                        const nc = newTotal <= 2 ? 'bi-qty-low' : (newTotal <= 5 ? 'bi-qty-medium' : 'bi-qty-high');
                        badge.className = 'bi-qty-badge ' + nc;
                    }
                    refreshGroupDetail(data.group_id);
                } else {
                    prependGroupRow(data);
                }
                form.reset();
                const di = form.querySelector('[name="date"]');
                if (di) di.value = new Date().toISOString().split('T')[0];
            } else {
                showFlash(data.err || 'Error adding bins.', 'danger');
            }
        })
        .catch(() => {
            if (btn) btn.disabled = false;
            if (spinner) spinner.style.display = 'none';
            showFlash('Network error. Please try again.', 'danger');
        });
    });
})();

/* ── auto-dismiss flash ── */
['bi-flash','bi-flash-err'].forEach(id => {
    const el = document.getElementById(id);
    if (el) setTimeout(() => { el.style.transition = 'opacity .5s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }, 5000);
});

/* ── collapse toggle icon ── */
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('searchInput') ?.addEventListener('input',  applyFilters);
    document.getElementById('filterGrower') ?.addEventListener('change', applyFilters);
    document.getElementById('filterVariety')?.addEventListener('change', applyFilters);
    document.getElementById('filterType')   ?.addEventListener('change', applyFilters);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
