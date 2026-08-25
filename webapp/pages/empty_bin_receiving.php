<?php
require_once __DIR__ . '/../config/user_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user'])) { header('Location: /auth/login.php'); exit; }
$role = $_SESSION['user']['role'] ?? 'viewer';
if (!sp_allow('bins', ['warehouse'])) { http_response_code(403); die('Forbidden — you do not have access to this section.'); }

require_once __DIR__ . '/../config/db_remote.php';
require_once __DIR__ . '/../includes/empty_bin_report.php';

function ebh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$messages = [];
$errors   = [];

ebr_ensure_print_settings($mysqli);
$reportPrinters = ebr_windows_printers();
$selectedReportPrinter = ebr_get_report_printer($mysqli);

/*-----------------------------------------
  ENSURE TABLES
-----------------------------------------*/
$mysqli->query("CREATE TABLE IF NOT EXISTS growers_list (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) UNIQUE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$mysqli->query("CREATE TABLE IF NOT EXISTS bin_types_list (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) UNIQUE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$mysqli->query("CREATE TABLE IF NOT EXISTS empty_bins_log (id INT AUTO_INCREMENT PRIMARY KEY, grower VARCHAR(100) NOT NULL, type VARCHAR(100) NOT NULL, qty_change INT NOT NULL, reason VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mysqli->query("CREATE TABLE IF NOT EXISTS carriers_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Optional receipt information stored on the Empty Bin record itself.
$ebCols = [];
if ($resEbCols = $mysqli->query("SHOW COLUMNS FROM empty_bins")) {
    while ($c = $resEbCols->fetch_assoc()) $ebCols[strtolower((string)$c['Field'])] = true;
}
if (!isset($ebCols['carrier'])) {
    $mysqli->query("ALTER TABLE empty_bins ADD COLUMN carrier VARCHAR(150) NULL AFTER type");
}
if (!isset($ebCols['notes'])) {
    $mysqli->query("ALTER TABLE empty_bins ADD COLUMN notes TEXT NULL AFTER carrier");
}

// Seed managed Carrier presets from names already used by Shipping / Empty Bins.
if ($mysqli->query("SHOW TABLES LIKE 'shipments'")?->num_rows) {
    @$mysqli->query("
        INSERT IGNORE INTO carriers_list(name)
        SELECT DISTINCT TRIM(carrier)
        FROM shipments
        WHERE carrier IS NOT NULL AND TRIM(carrier)<>''
    ");
}
@$mysqli->query("
    INSERT IGNORE INTO carriers_list(name)
    SELECT DISTINCT TRIM(carrier)
    FROM empty_bins
    WHERE carrier IS NOT NULL AND TRIM(carrier)<>''
");
// Permanent receipt/PDF linkage for Movement Log.
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

/* ADMIN ONLY — delete Empty Bin Movement Log events.
   Empty-bin balances are untouched. Linked PDFs are physically deleted. */
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
    $q=$mysqli->query("SELECT id,report_pdf FROM empty_bins_log WHERE id IN ($idList)");
    $rows=$q?$q->fetch_all(MYSQLI_ASSOC):[];
    if(!$rows){ echo json_encode(['ok'=>false,'error'=>'No matching movement events found.']); exit; }

    $pdfNames=[];
    foreach($rows as $row){
        $pdf=trim((string)($row['report_pdf']??''));
        if($pdf!=='') $pdfNames[basename($pdf)]=true;
    }

    foreach(array_keys($pdfNames) as $name){
        $abs=__DIR__.'/../data/empty_bin_reports/'.$name;
        if(is_file($abs)&&!@unlink($abs)){
            echo json_encode(['ok'=>false,'error'=>'Unable to delete linked PDF: '.$name]); exit;
        }
    }

    try{
        $mysqli->begin_transaction();
        foreach(array_keys($pdfNames) as $name){
            $esc=$mysqli->real_escape_string($name);
            $mysqli->query("UPDATE empty_bins_log SET report_pdf=NULL WHERE report_pdf='$esc'");
        }
        $mysqli->query("DELETE FROM empty_bins_log WHERE id IN ($idList)");
        $deleted=$mysqli->affected_rows;
        $mysqli->commit();
        echo json_encode(['ok'=>true,'deleted'=>$deleted,'pdf_deleted'=>count($pdfNames)]);
    }catch(Throwable $e){
        try{$mysqli->rollback();}catch(Throwable $ignore){}
        echo json_encode(['ok'=>false,'error'=>'Delete failed: '.$e->getMessage()]);
    }
    exit;
}



/*-----------------------------------------
  AJAX: EMPTY BIN PDF ACTIONS
-----------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $reportAction = trim((string)($_POST['report_action'] ?? ''));
    $recordId = (int)($_POST['record_id'] ?? 0);

    if ($recordId <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Invalid record ID.']);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT id, grower, type, carrier, notes, date, quantity, created_at
        FROM empty_bins
        WHERE id=?
        LIMIT 1
    ");
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record) {
        echo json_encode(['ok'=>false,'error'=>'Record not found.']);
        exit;
    }

    $reportFile = ebr_report_file_for_record((int)$record['id'], (string)$record['date']);

    if ($reportAction === 'preview_report') {
        if (!$reportFile['exists']) {
            echo json_encode(['ok'=>false,'missing'=>true,'error'=>'No PDF report exists for this record.']);
            exit;
        }
        echo json_encode(['ok'=>true,'url'=>$reportFile['url'],'record_id'=>$recordId]);
        exit;
    }

    if ($reportAction === 'regenerate_report' || $reportAction === 'test_print') {
        $g = $mysqli->real_escape_string((string)$record['grower']);
        $t = $mysqli->real_escape_string((string)$record['type']);
        $balanceRow = $mysqli->query("SELECT COALESCE(SUM(quantity),0) AS total FROM empty_bins WHERE grower='$g' AND type='$t'")->fetch_assoc();
        $overallRow = $mysqli->query("SELECT COALESCE(SUM(quantity),0) AS total FROM empty_bins")->fetch_assoc();

        $reportResult = ebr_generate_receipt_pdf([
            'id'=>(int)$record['id'],
            'grower'=>(string)$record['grower'],
            'type'=>(string)$record['type'],
            'carrier'=>(string)($record['carrier'] ?? ''),
            'notes'=>(string)($record['notes'] ?? ''),
            'date'=>(string)$record['date'],
            'quantity'=>(int)$record['quantity'],
            'created_at'=>(string)($record['created_at'] ?? ''),
            'entered_by'=>'',
        ], [
            'grower_type_balance'=>(int)($balanceRow['total'] ?? 0),
            'overall_empty_bins'=>(int)($overallRow['total'] ?? 0),
        ]);

        if (empty($reportResult['ok'])) {
            echo json_encode(['ok'=>false,'error'=>$reportResult['error'] ?? 'Unable to generate PDF.']);
            exit;
        }

        if ($reportAction === 'regenerate_report') {
            echo json_encode(['ok'=>true,'url'=>$reportResult['url'],'record_id'=>$recordId]);
            exit;
        }

        $printer = ebr_get_report_printer($mysqli);
        if ($printer === '') {
            echo json_encode(['ok'=>false,'requiresPrinter'=>true,'error'=>'Select a report printer before Test Print.']);
            exit;
        }

        try {
            $print = ebr_print_pdf_windows((string)$reportResult['path'], $printer);
        } catch (Throwable $printEx) {
            $print = [
                'ok'=>false,
                'error'=>'Test Print error: ' . $printEx->getMessage()
            ];
        }
        echo json_encode([
            'ok'=>!empty($print['ok']),
            'printer'=>$printer,
            'url'=>$reportResult['url'],
            'method'=>$print['method'] ?? null,
            'verified'=>$print['verified'] ?? null,
            'queued'=>$print['queued'] ?? null,
            'jobId'=>$print['job_id'] ?? null,
            'printerStatus'=>$print['printer_status'] ?? null,
            'detail'=>$print['detail'] ?? null,
            'exitCode'=>$print['exit_code'] ?? null,
            'error'=>$print['error'] ?? null
        ]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown report action.']);
    exit;
}

/*-----------------------------------------
  AJAX: REPORT PRINTER
-----------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_report_printer'])) {
    header('Content-Type: application/json; charset=utf-8');

    $printer = trim((string)($_POST['report_printer'] ?? ''));
    $installed = ebr_windows_printers();

    if ($printer !== '' && !in_array($printer, $installed, true)) {
        echo json_encode(['ok'=>false, 'error'=>'Selected Windows printer is not currently available.']);
        exit;
    }

    $ok = ebr_set_report_printer($mysqli, $printer);
    echo json_encode([
        'ok'=>$ok,
        'printer'=>$printer,
        'message'=>$ok
            ? ($printer !== '' ? 'Report printer saved: ' . $printer : 'Automatic report printing disabled.')
            : 'Unable to save report printer.'
    ]);
    exit;
}

/*-----------------------------------------
  AJAX: ADD PRESET (grower / type)
-----------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($role !== 'admin') { echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }
    $action  = trim($_POST['ajax_action'] ?? '');
    $name    = trim($_POST['name'] ?? '');
    if ($name === '') { echo json_encode(['ok'=>false,'error'=>'Missing name']); exit; }
    $nameEsc = $mysqli->real_escape_string($name);
    if ($action === 'add_grower') { $mysqli->query("INSERT IGNORE INTO growers_list(name) VALUES('$nameEsc')"); echo json_encode(['ok'=>true,'name'=>$name]); exit; }
    if ($action === 'add_type')   { $mysqli->query("INSERT IGNORE INTO bin_types_list(name) VALUES('$nameEsc')"); echo json_encode(['ok'=>true,'name'=>$name]); exit; }
    if ($action === 'add_carrier'){ $mysqli->query("INSERT IGNORE INTO carriers_list(name) VALUES('$nameEsc')"); echo json_encode(['ok'=>true,'name'=>$name]); exit; }
    echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
}

/*-----------------------------------------
  LOAD PRESETS
-----------------------------------------*/
$growers = [];
$types   = [];
$res = $mysqli->query("SELECT id, name FROM growers_list ORDER BY name ASC");
if ($res) $growers = $res->fetch_all(MYSQLI_ASSOC);
$res = $mysqli->query("SELECT id, name FROM bin_types_list ORDER BY name ASC");
if ($res) $types = $res->fetch_all(MYSQLI_ASSOC);

/*-----------------------------------------
  POST ACTIONS
-----------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* INLINE EDIT (AJAX) */
    if (isset($_POST['inline_edit'])) {
        $id     = (int)($_POST['id'] ?? 0);
        $grower = $mysqli->real_escape_string(trim($_POST['grower'] ?? ''));
        $type   = $mysqli->real_escape_string(trim($_POST['type'] ?? ''));
        $date   = $mysqli->real_escape_string(trim($_POST['date'] ?? ''));
        $qty    = (int)($_POST['quantity'] ?? 0);
        if ($id <= 0 || $grower === '' || $type === '' || $date === '' || $qty <= 0) { echo "ERR"; exit; }
        $resOld = $mysqli->query("SELECT grower, type, quantity FROM empty_bins WHERE id = $id");
        if (!$resOld || !$resOldRow = $resOld->fetch_assoc()) { echo "ERR"; exit; }
        $oldQty = (int)$resOldRow['quantity'];
        $mysqli->query("UPDATE empty_bins SET grower='$grower', type='$type', date='$date', quantity=$qty WHERE id=$id");
        $diff = $qty - $oldQty;
        if ($diff !== 0) {
            $reason = $mysqli->real_escape_string('Inline edit via empty_bin_receiving');
            $mysqli->query("INSERT INTO empty_bins_log(grower,type,qty_change,reason) VALUES('$grower','$type',$diff,'$reason')");
        }
        echo "OK"; exit;
    }

    /* ADD EMPTY BINS */
    if (isset($_POST['save_empty'])) {
        $grower  = trim($_POST['grower'] ?? '');
        $type    = trim($_POST['type']   ?? '');
        $carrier = trim($_POST['carrier'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');
        $date    = trim($_POST['date']   ?? '');
        $qty     = (int)($_POST['quantity'] ?? 0);
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

        // HARD GATE: no record may be created without a selected report printer
        // unless the user explicitly uses the password-protected admin override.
        $reportPrinterAtSave = ebr_get_report_printer($mysqli);
        // API Save Only: save the record + PDF, but do not require or use a printer.
        // Normal webpage behavior is unchanged because skip_print is absent there.
        $skipPrint = ((string)($_POST['skip_print'] ?? '') === '1');
        $allowWithoutPrinter = ((string)($_POST['allow_without_printer'] ?? '') === '1');
        $overridePassword = (string)($_POST['admin_override_password'] ?? '');
        $overrideAuthorized = $allowWithoutPrinter && ebr_verify_no_printer_admin_password($overridePassword);

        if ($reportPrinterAtSave === '' && !$overrideAuthorized && !$skipPrint) {
            $err = $allowWithoutPrinter
                ? 'Invalid admin password. Record was not saved.'
                : 'No report printer selected. Select a printer or use the admin override to continue without printing.';

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => false,
                    'requiresPrinter' => true,
                    'passwordInvalid' => $allowWithoutPrinter,
                    'err' => $err,
                ]);
                exit;
            }

            $errors[] = $err;
        } elseif ($grower === '' || $type === '' || $date === '' || $qty <= 0) {
            $err = "Grower, type, date and quantity are required.";
            $errors[] = $err;
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>$err]); exit; }
        } else {
            $g = $mysqli->real_escape_string($grower);
            $t = $mysqli->real_escape_string($type);
            $carr = $mysqli->real_escape_string($carrier);
            $nts = $mysqli->real_escape_string($notes);
            $d = $mysqli->real_escape_string($date);

            $mysqli->query("INSERT INTO empty_bins(grower,type,carrier,notes,date,quantity,created_at)
                            VALUES('$g','$t',".($carrier!==''?"'$carr'":"NULL").",".($notes!==''?"'$nts'":"NULL").",'$d',$qty,NOW())");
            $newId = (int)$mysqli->insert_id;
            $reason = $mysqli->real_escape_string('Manual add via empty_bin_receiving');
            $mysqli->query("INSERT INTO empty_bins_log(grower,type,source_empty_bin_id,qty_change,reason) VALUES('$g','$t',$newId,$qty,'$reason')");
            $newLogId = (int)$mysqli->insert_id;
            $messages[] = "✅ Empty bins added.";

            /* Automatic PDF receiving report */
            $balanceRow = $mysqli->query("SELECT COALESCE(SUM(quantity),0) AS total FROM empty_bins WHERE grower='$g' AND type='$t'")->fetch_assoc();
            $overallRow = $mysqli->query("SELECT COALESCE(SUM(quantity),0) AS total FROM empty_bins")->fetch_assoc();

            $receiptRow = [
                'id'         => $newId,
                'grower'     => $grower,
                'type'       => $type,
                'carrier'    => $carrier,
                'notes'      => $notes,
                'date'       => $date,
                'quantity'   => $qty,
                'created_at' => date('Y-m-d H:i:s'),
                'entered_by' => (string)($_SESSION['user']['username'] ?? $_SESSION['user']['name'] ?? ''),
            ];
            $reportResult = ebr_generate_receipt_pdf($receiptRow, [
                'grower_type_balance' => (int)($balanceRow['total'] ?? 0),
                'overall_empty_bins'  => (int)($overallRow['total'] ?? 0),
            ]);

            // Keep the generated receipt permanently linked to the positive Movement Log row.
            if (!empty($reportResult['ok']) && !empty($reportResult['filename']) && !empty($newLogId)) {
                $pdfNameEsc = $mysqli->real_escape_string((string)$reportResult['filename']);
                $mysqli->query("UPDATE empty_bins_log SET report_pdf='$pdfNameEsc' WHERE id=" . (int)$newLogId);
            }

            $printResult = ['ok'=>false, 'skipped'=>true, 'error'=>'No report printer selected.'];
            $reportPrinter = $reportPrinterAtSave;
            if ($skipPrint) {
                $printResult = ['ok'=>false, 'skipped'=>true, 'error'=>'Printing skipped: Save Only selected.'];
            } elseif (!empty($reportResult['ok']) && $reportPrinter !== '') {
                try {
                    $printResult = ebr_print_pdf_windows((string)$reportResult['path'], $reportPrinter);
                } catch (Throwable $printEx) {
                    $printResult = [
                        'ok'=>false,
                        'error'=>'Automatic print error: ' . $printEx->getMessage()
                    ];
                }
            } elseif ($overrideAuthorized) {
                $printResult = ['ok'=>false, 'skipped'=>true, 'error'=>'Printing skipped by password-protected admin override.'];
            }

            if ($isAjax) {
                /* re-query totals */
                $ajaxTot = $mysqli->query("SELECT SUM(quantity) AS total FROM empty_bins")->fetch_assoc();
                $ajaxGr  = $mysqli->query("SELECT COUNT(DISTINCT grower) AS c FROM empty_bins")->fetch_assoc();
                $ajaxRw  = $mysqli->query("SELECT COUNT(*) AS c FROM empty_bins")->fetch_assoc();

                $ajaxBalances = [];
                $ajaxBalRes = $mysqli->query("
                    SELECT grower, type, SUM(quantity) AS total_qty
                    FROM empty_bins
                    GROUP BY grower, type
                    HAVING SUM(quantity) <> 0
                    ORDER BY grower ASC, type ASC
                ");
                if ($ajaxBalRes) {
                    while ($abr = $ajaxBalRes->fetch_assoc()) {
                        $ajaxBalances[] = [
                            'grower' => (string)$abr['grower'],
                            'type' => (string)$abr['type'],
                            'total_qty' => (int)$abr['total_qty'],
                        ];
                    }
                }

                header('Content-Type: application/json');
                echo json_encode([
                    'ok'    => true,
                    'msg'   => "✅ Empty bins added.",
                    'row'   => ['id'=>$newId,'grower'=>$grower,'type'=>$type,'carrier'=>$carrier,'notes'=>$notes,'date'=>$date,'quantity'=>$qty],
                    'totalQty'    => (int)($ajaxTot['total'] ?? 0),
                    'totalGrowers'=> (int)($ajaxGr['c'] ?? 0),
                    'totalRows'   => (int)($ajaxRw['c'] ?? 0),
                    'balances'    => $ajaxBalances,
                    'movementLog' => [
                        'id' => (int)($newLogId ?? 0),
                        'created_at' => date('Y-m-d H:i:s'),
                        'grower' => $grower,
                        'type' => $type,
                        'qty_change' => $qty,
                        'reason' => 'Manual add via empty_bin_receiving',
                        'source_empty_bin_id' => $newId,
                        'report_pdf' => $reportResult['filename'] ?? null,
                        'report_url' => $reportResult['url'] ?? null,
                    ],
                    'report' => [
                        'generated' => !empty($reportResult['ok']),
                        'url'       => $reportResult['url'] ?? null,
                        'printer'   => $reportPrinter,
                        'printed'   => !empty($printResult['ok']),
                        'printError'=> $printResult['error'] ?? null,
                    ],
                ]);
                exit;
            }
        }
    }

    /* DELETE ROW */
    if (isset($_POST['delete_row']) && $role === 'admin') {
        $id = (int)($_POST['delete_id'] ?? 0);
        if ($id > 0) {
            $resDel = $mysqli->query("SELECT grower,type,quantity FROM empty_bins WHERE id=$id");
            if ($resDel && $rowDel = $resDel->fetch_assoc()) {
                $g = $mysqli->real_escape_string($rowDel['grower']);
                $t = $mysqli->real_escape_string($rowDel['type']);
                $q = (int)$rowDel['quantity'];
                if ($q > 0) {
                    $reason = $mysqli->real_escape_string('Row deleted via empty_bin_receiving');
                    $mysqli->query("INSERT INTO empty_bins_log(grower,type,qty_change,reason) VALUES('$g','$t',".(-$q).",'$reason')");
                }
            }
            $mysqli->query("DELETE FROM empty_bins WHERE id=$id");
            $messages[] = "Record deleted.";
        }
    }
}

/*-----------------------------------------
  DASHBOARD TOTALS
-----------------------------------------*/
$totals = [];
$resTotals = $mysqli->query("SELECT grower, type, SUM(quantity) AS total_qty FROM empty_bins GROUP BY grower, type ORDER BY grower ASC, type ASC");
if ($resTotals) $totals = $resTotals->fetch_all(MYSQLI_ASSOC);

$totalQty     = array_sum(array_column($totals, 'total_qty'));
$totalGrowers = count(array_unique(array_column($totals, 'grower')));
$totalRows    = 0; // filled below


/*-----------------------------------------
  CARRIER PRESETS
-----------------------------------------*/
$carriers = [];
$resCarriers = $mysqli->query("SELECT id,name FROM carriers_list WHERE TRIM(name)<>'' ORDER BY name ASC");
if ($resCarriers) $carriers = $resCarriers->fetch_all(MYSQLI_ASSOC);

/*-----------------------------------------
  LOG
-----------------------------------------*/
$log_rows = [];
$resLog = $mysqli->query("SELECT id,grower,type,source_empty_bin_id,qty_change,reason,report_pdf,created_at FROM empty_bins_log ORDER BY created_at DESC");
if ($resLog) $log_rows = $resLog->fetch_all(MYSQLI_ASSOC);

/*-----------------------------------------
  LIST
-----------------------------------------*/
$rows = [];
$resRows = $mysqli->query("SELECT id,grower,type,carrier,notes,date,quantity,created_at FROM empty_bins ORDER BY date DESC, id DESC");
if ($resRows) $rows = $resRows->fetch_all(MYSQLI_ASSOC);
$totalRows = count($rows);

include('../includes/header.php');
include('../includes/sidebar.php');
?>
<style>
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

<!-- ═══════════════════════
     EMPTY BIN RECEIVING
     ═══════════════════════ -->

<!-- TOP BAR -->
<div class="eb-topbar">
    <h1>📭 Empty Bin Receiving</h1>
    <span class="eb-topbar-sub">Receiving · Balance · Log</span>
    <div class="eb-topbar-right">
        <?php $latestRow = !empty($rows) ? $rows[0] : null; ?>
<button type="button" class="eb-tbtn" id="ebTestPrintLatestReport"
                <?= $latestRow ? '' : 'disabled'; ?>
                data-record-id="<?= $latestRow ? (int)$latestRow['id'] : 0; ?>">🖨 Test Print</button>
        <div class="eb-report-printer-wrap">
            <label for="ebReportPrinter">Report printer</label>
            <select id="ebReportPrinter" class="eb-report-printer <?= $selectedReportPrinter === '' ? 'eb-report-printer-missing' : '' ?>">
                <option value="">Do not print automatically</option>
                <?php foreach ($reportPrinters as $printer): ?>
                    <option value="<?= ebh($printer); ?>" <?= $printer === $selectedReportPrinter ? 'selected' : ''; ?>>
                        <?= ebh($printer); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span id="ebPrinterSaveStatus" class="eb-printer-save-status"></span>
        </div>
        <a href="bins_ingresso.php"     class="eb-tbtn">🗃️ Full Bins</a>
        <a href="bins_produzione.php"   class="eb-tbtn">🏭 Production</a>
        <a href="/chooser.php"          class="eb-tbtn eb-tbtn-primary">🏠 Main Menu</a>
    </div>
</div>

<div class="eb-main">

</div>

    <!-- Flash messages -->
    <?php foreach ($messages as $m): ?>
        <div class="eb-flash eb-flash-success" data-eb-flash>
            <span><?= ebh($m); ?></span>
            <button onclick="this.closest('[data-eb-flash]').remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:14px;color:inherit;opacity:.6;">✕</button>
        </div>
    <?php endforeach; ?>
    <?php foreach ($errors as $m): ?>
        <div class="eb-flash eb-flash-danger" data-eb-flash>
            <span>⚠️ <?= ebh($m); ?></span>
            <button onclick="this.closest('[data-eb-flash]').remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:14px;color:inherit;opacity:.6;">✕</button>
        </div>
    <?php endforeach; ?>

    <!-- ═══ ADD FORM ═══ -->
    <div class="eb-panel">
        <div class="eb-panel-hdr">
            <div>
                <div class="eb-panel-title">📭 Add Empty Bins</div>
                <div class="eb-panel-subtitle">Register a batch of returned empty bins.</div>
            </div>
        </div>
        <div class="eb-panel-body">
            <form id="ebAddForm">
                <input type="hidden" name="save_empty" value="1">
                <div class="eb-form-grid">

                    <!-- Grower -->
                    <div>
                        <label class="eb-field-label">Grower <span style="color:var(--c-red)">*</span></label>
                        <div class="eb-input-group">
                            <select name="grower" id="ebGrowerSelect" class="form-select" required>
                                <option value="">Select grower…</option>
                                <?php foreach ($growers as $g): ?>
                                    <option value="<?= ebh($g['name']); ?>"><?= ebh($g['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($role === 'admin'): ?>
                                <button type="button" class="eb-new-btn" data-bs-toggle="modal" data-bs-target="#addGrowerModal" title="Add new grower">+ New</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Type -->
                    <div>
                        <label class="eb-field-label">Bin Type <span style="color:var(--c-red)">*</span></label>
                        <div class="eb-input-group">
                            <select name="type" id="ebTypeSelect" class="form-select" required>
                                <option value="">Select type…</option>
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= ebh($t['name']); ?>"><?= ebh($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($role === 'admin'): ?>
                                <button type="button" class="eb-new-btn" data-bs-toggle="modal" data-bs-target="#addTypeModal" title="Add new type">+ New</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Carrier -->
                    <div>
                        <label class="eb-field-label">Carrier</label>
                        <div class="eb-input-group">
                            <select name="carrier" id="ebCarrierSelect" class="form-select">
                                <option value="">Select carrier…</option>
                                <?php foreach ($carriers as $c): ?>
                                    <option value="<?= ebh($c['name']); ?>"><?= ebh($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($role === 'admin'): ?>
                                <button type="button" class="eb-new-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#addCarrierModal"
                                        title="Add new carrier">+ New</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div style="grid-column:span 2;">
                        <label class="eb-field-label">Notes</label>
                        <textarea name="notes"
                                  id="ebNotesInput"
                                  class="form-control"
                                  rows="2"
                                  placeholder="Optional notes"></textarea>
                    </div>

                    <!-- Date -->
                    <div>
                        <label class="eb-field-label">Date <span style="color:var(--c-red)">*</span></label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
                    </div>

                    <!-- Qty -->
                    <div>
                        <label class="eb-field-label">Qty</label>
                        <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                    </div>

                    <!-- Submit -->
                    <div>
                        <label class="eb-field-label">&nbsp;</label>
                        <button type="submit" class="eb-submit-btn" id="ebAddBtn">
                            <span id="ebAddSpinner" style="display:none;">⏳</span>
                            ➕ Add
                        </button>
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
                    <span class="eb-stat-chip eb-chip-green"  id="ebChipQty">📦 <?= (int)$totalQty; ?> bins</span>
                    <span class="eb-stat-chip eb-chip-blue"   id="ebChipGrowers">🌱 <?= $totalGrowers; ?> growers</span>
                    <span class="eb-stat-chip eb-chip-purple" id="ebChipRows">📋 <?= $totalRows; ?> entries</span>
                </div>
            </div>
        </div>
        <div class="eb-panel-body" id="ebCurrentBalanceBody">
            <?php if (!empty($totals)): ?>
                <div class="eb-balance-grid">
                    <?php foreach ($totals as $t):
                        $q = (int)$t['total_qty'];
                        $qc = $q <= 5 ? 'eb-qty-low' : ($q <= 20 ? 'eb-qty-medium' : 'eb-qty-high');
                    ?>
                    <div class="eb-balance-card">
                        <div class="eb-balance-grower"><?= ebh($t['grower']); ?></div>
                        <div class="eb-balance-type"><?= ebh($t['type']); ?></div>
                        <div><span class="eb-qty-badge <?= $qc; ?>" style="font-size:16px;height:28px;min-width:36px;"><?= $q; ?></span> <span class="eb-balance-qty-small">bins</span></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="eb-empty">
                    <span class="eb-empty-icon">📭</span>
                    No empty bins in stock.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ EMPTY BINS LIST ═══ -->
    <div class="eb-panel">
        <div class="eb-panel-hdr">
            <div>
                <div class="eb-panel-title">📋 Empty Bins Entries</div>
                <div class="eb-panel-subtitle">All individual receiving records — click Edit to modify inline.</div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="eb-filter-bar">
            <input id="ebFilterSearch" class="eb-filter-input" placeholder="🔍 Search grower / type…">
            <select id="ebFilterGrower" class="eb-filter-select">
                <option value="">All Growers</option>
                <?php foreach ($growers as $g): ?>
                    <option value="<?= ebh($g['name']); ?>"><?= ebh($g['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="ebFilterType" class="eb-filter-select">
                <option value="">All Types</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= ebh($t['name']); ?>"><?= ebh($t['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input id="ebFilterDate" type="date" class="eb-filter-select" title="Filter by date">
        </div>

        <!-- Table -->
        <div class="eb-table-wrap">
            <table class="eb-table">
                <thead>
                    <tr>
                        <th style="width:50px;">ID</th>
                        <th>Grower</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Qty</th>
                        <th style="width:64px;text-align:center;">PDF</th>
                        <th style="width:190px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="ebListTbody">
                    <?php foreach ($rows as $r):
                        $daysAgo = 0;
                        try { $d1=new DateTime($r['date']); $d2=new DateTime('now'); $daysAgo=max(0,(int)$d2->diff($d1)->days); } catch(Exception $ex){ $daysAgo=0; }
                        $ageClass = $daysAgo===0?'eb-age-today':($daysAgo<=2?'eb-age-recent':'eb-age-old');
                        $ageLabel = $daysAgo===0?'Today':($daysAgo===1?'1d ago':$daysAgo.'d ago');
                        $q = (int)$r['quantity'];
                        $qClass = $q<=5?'eb-qty-low':($q<=20?'eb-qty-medium':'eb-qty-high');
                        $reportFile = ebr_report_file_for_record((int)$r['id'], (string)$r['date']);
                    ?>
                    <tr id="eb_row_<?= (int)$r['id']; ?>"
                        data-id="<?= (int)$r['id']; ?>"
                        data-grower="<?= ebh($r['grower']); ?>"
                        data-type="<?= ebh($r['type']); ?>"
                        data-date="<?= ebh($r['date']); ?>">
                        <td style="font-size:11px;color:var(--c-muted);"><?= (int)$r['id']; ?></td>
                        <td id="ebc_grower_<?= (int)$r['id']; ?>" style="font-weight:600;"><?= ebh($r['grower']); ?></td>
                        <td id="ebc_type_<?= (int)$r['id']; ?>"><?= ebh($r['type']); ?></td>
                        <td id="ebc_date_<?= (int)$r['id']; ?>" style="font-size:11px;">
                            <?= ebh($r['date']); ?>
                            <span class="eb-age-badge <?= $ageClass; ?>"><?= $ageLabel; ?></span>
                        </td>
                        <td id="ebc_qty_<?= (int)$r['id']; ?>">
                            <span class="eb-qty-badge <?= $qClass; ?>"><?= $q; ?></span>
                        </td>
                        <td style="text-align:center;" id="ebc_pdf_<?= (int)$r['id']; ?>">
                            <?php if (!empty($reportFile['exists'])): ?>
                                <a href="<?= ebh($reportFile['url']); ?>"
                                   class="eb-pdf-icon eb-pdf-ready"
                                   title="View PDF Report"
                                   aria-label="View PDF Report"
                                   data-record-id="<?= (int)$r['id']; ?>"
                                   onclick="return ebOpenPdfFromElement(this,event)">PDF</a>
                            <?php else: ?>
                                <button type="button" class="eb-pdf-icon eb-pdf-missing"
                                        title="PDF not generated — click to create"
                                        aria-label="Generate PDF Report"
                                        onclick="ebRegenerateReport(<?= (int)$r['id']; ?>)">PDF</button>
                            <?php endif; ?>
                        </td>
                        <td id="ebc_actions_<?= (int)$r['id']; ?>">
                            <div class="eb-act">
                                <button type="button" class="eb-abtn eb-abtn-edit"
                                    onclick="ebEditRow(<?= (int)$r['id']; ?>)">✏️ Edit</button>
                                <?php if ($role === 'admin'): ?>
                                    <form method="post" style="display:contents;"
                                          onsubmit="return confirm('Delete entry #<?= (int)$r['id']; ?>?');">
                                        <input type="hidden" name="delete_row" value="1">
                                        <input type="hidden" name="delete_id" value="<?= (int)$r['id']; ?>">
                                        <button type="submit" class="eb-abtn eb-abtn-delete">🗑</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7"><div class="eb-empty"><span class="eb-empty-icon">📭</span>No entries yet.</div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══ MOVEMENT LOG (collapsible) ═══ -->
    <div class="eb-panel">
        <div class="eb-panel-hdr" style="cursor:pointer;" onclick="ebToggleLog()">
            <div>
                <div class="eb-panel-title">📜 Movement Log <span id="ebLogCount" style="font-size:11px;color:var(--c-muted);font-weight:400;">(<?= count($log_rows); ?> entries)</span></div>
                <div class="eb-panel-subtitle">All stock changes — adds, edits, consumptions, deletes.</div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;" onclick="event.stopPropagation();">
                <!-- Log filters -->
                <select id="logGrowerFilter" class="eb-filter-select" style="min-width:110px;">
                    <option value="">All Growers</option>
                    <?php foreach ($growers as $g): ?>
                        <option value="<?= ebh($g['name']); ?>"><?= ebh($g['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="logTypeFilter" class="eb-filter-select" style="min-width:100px;">
                    <option value="">All Types</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= ebh($t['name']); ?>"><?= ebh($t['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="logDatePreset" class="eb-filter-select">
                    <option value="all">All dates</option>
                    <option value="today">Today</option>
                    <option value="7">Last 7 days</option>
                    <option value="30">Last 30 days</option>
                    <option value="custom">Custom range</option>
                </select>
                <input type="date" id="logDateFrom" class="eb-filter-select d-none" style="min-width:130px;">
                <input type="date" id="logDateTo"   class="eb-filter-select d-none" style="min-width:130px;">
                <select id="logLimitSelect" class="eb-filter-select" style="min-width:100px;">
                    <option value="20">Last 20</option>
                    <option value="50">Last 50</option>
                    <option value="100">Last 100</option>
                    <option value="all">All</option>
                </select>
                <button id="exportLogCsv" class="eb-sm-btn" type="button">⬇ CSV</button>
                <?php if ($role === 'admin'): ?>
                    <button class="eb-sm-btn" type="button" onclick="ebSelectVisibleMovements()">☑ Select visible</button>
                    <button class="eb-sm-btn" type="button" onclick="ebDeleteSelectedMovements()" style="color:#b91c1c;border-color:#fecaca;">🗑 Delete selected</button>
                <?php endif; ?>
                <button class="eb-log-toggle" id="ebLogToggleBtn" type="button">▼ Show</button>
            </div>
        </div>

        <div id="ebLogBody" style="display:none;">
            <div class="eb-table-wrap eb-log-body">
                <table class="eb-table">
                    <thead>
                        <tr>
                            <?php if ($role === 'admin'): ?><th style="width:34px;text-align:center;"><input type="checkbox" id="ebSelectAllVisible" onchange="ebToggleVisibleMovementChecks(this.checked)" title="Select visible"></th><?php endif; ?>
                            <th>Date / Time</th>
                            <th>Grower</th>
                            <th>Type</th>
                            <th style="text-align:right;">Δ Qty</th>
                            <th style="width:64px;text-align:center;">PDF</th>
                            <th>Reason</th>
                            <?php if ($role === 'admin'): ?><th style="width:54px;text-align:center;">Delete</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="logTbody">
                        <?php foreach ($log_rows as $i => $lr): ?>
                            <tr data-log-index="<?= $i; ?>"
                                data-log-id="<?= (int)$lr['id']; ?>"
                                data-grower="<?= ebh($lr['grower']); ?>"
                                data-type="<?= ebh($lr['type']); ?>"
                                data-date="<?= ebh(substr($lr['created_at'], 0, 10)); ?>">
                                <?php if ($role === 'admin'): ?><td style="text-align:center;"><input type="checkbox" class="ebMovementCheck" value="<?= (int)$lr['id']; ?>" onclick="event.stopPropagation()"></td><?php endif; ?>
                                <td style="font-size:11px;white-space:nowrap;"><?= ebh($lr['created_at']); ?></td>
                                <td><?= ebh($lr['grower']); ?></td>
                                <td><?= ebh($lr['type']); ?></td>
                                <td style="text-align:right;">
                                    <?php $delta = (int)$lr['qty_change']; ?>
                                    <span class="<?= $delta >= 0 ? 'eb-delta-pos' : 'eb-delta-neg'; ?>">
                                        <?= $delta >= 0 ? '+' : ''; ?><?= $delta; ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <?php
                                    $logPdf = trim((string)($lr['report_pdf'] ?? ''));
                                    $logPdfAbs = $logPdf !== '' ? (__DIR__ . '/../data/empty_bin_reports/' . basename($logPdf)) : '';
                                    $logPdfExists = $delta > 0 && $logPdf !== '' && is_file($logPdfAbs) && filesize($logPdfAbs) > 100;
                                    ?>
                                    <?php if ($logPdfExists): ?>
                                        <a href="../data/empty_bin_reports/<?= ebh(rawurlencode(basename($logPdf))); ?>"
                                                class="eb-pdf-icon eb-pdf-ready"
                                                title="Preview receiving PDF"
                                                aria-label="Preview receiving PDF"
                                                data-record-id="<?= (int)($lr['source_empty_bin_id'] ?? 0); ?>"
                                                onclick="return ebOpenPdfFromElement(this,event)">PDF</a>
                                    <?php elseif ($delta > 0): ?>
                                        <span class="eb-pdf-icon eb-pdf-missing"
                                              title="No PDF linked to this historical movement">PDF</span>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:11px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:11px;color:var(--c-muted);"><?= ebh($lr['reason'] ?? ''); ?></td>
                                <?php if ($role === 'admin'): ?>
                                    <td style="text-align:center;"><button type="button" class="eb-sm-btn" style="padding:3px 7px;color:#b91c1c;" title="Delete event" onclick="ebDeleteOneMovement(<?= (int)$lr['id']; ?>)">🗑</button></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($log_rows)): ?>
                            <tr><td colspan="<?= $role === 'admin' ? 8 : 6 ?>" style="text-align:center;color:var(--c-muted);padding:24px;">No log entries.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /.eb-main -->

<!-- ══ MODALS ══ -->
<!-- Add Grower -->
<div class="modal fade" id="addGrowerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <div class="modal-content">
            <div class="modal-header"><h6 class="modal-title">🌱 Add Grower</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <label class="eb-field-label">Grower name</label>
                <input type="text" id="newGrowerInput" class="form-control" placeholder="e.g. Smith Farms" autofocus>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" onclick="ebAjaxAddPreset('add_grower')">✅ Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Type -->
<div class="modal fade" id="addTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <div class="modal-content">
            <div class="modal-header"><h6 class="modal-title">📦 Add Bin Type</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <label class="eb-field-label">Type name</label>
                <input type="text" id="newTypeInput" class="form-control" placeholder="e.g. Wooden 450L" autofocus>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" onclick="ebAjaxAddPreset('add_type')">✅ Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Carrier -->
<div class="modal fade" id="addCarrierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">🚚 Add Carrier</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="eb-field-label">Carrier name</label>
                <input type="text" id="newCarrierInput" class="form-control" placeholder="e.g. ABC Transport">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" onclick="ebAjaxAddPreset('add_carrier')">✅ Save</button>
            </div>
        </div>
    </div>
</div>

<?php include('../includes/footer.php'); ?>

<!-- Template selects for inline edit -->
<script type="text/template" id="tmplEbGrowerSelect">
<select class="eb-inline-select">
    <option value="">Select…</option>
    <?php foreach ($growers as $g): ?>
        <option value="<?= ebh($g['name']); ?>"><?= ebh($g['name']); ?></option>
    <?php endforeach; ?>
</select>
</script>
<script type="text/template" id="tmplEbTypeSelect">
<select class="eb-inline-select">
    <option value="">Select…</option>
    <?php foreach ($types as $t): ?>
        <option value="<?= ebh($t['name']); ?>"><?= ebh($t['name']); ?></option>
    <?php endforeach; ?>
</select>
</script>
<script type="text/template" id="tmplEbCarrierSelect">
<select class="eb-inline-select">
    <option value="">Select…</option>
    <?php foreach ($carriers as $c): ?>
        <option value="<?= ebh($c['name']); ?>"><?= ebh($c['name']); ?></option>
    <?php endforeach; ?>
</select>
</script>


<!-- PDF PREVIEW POPUP -->
<div id="ebPdfPopup" class="eb-pdf-popup" aria-hidden="true">
    <div class="eb-pdf-popup-panel" role="dialog" aria-modal="true" aria-labelledby="ebPdfPopupTitle">
        <div class="eb-pdf-popup-header">
            <div>
                <div id="ebPdfPopupTitle" class="eb-pdf-popup-title">📄 Empty Bin Report</div>
                <div id="ebPdfPopupSub" class="eb-pdf-popup-sub">Preview</div>
            </div>
            <button type="button" class="eb-pdf-popup-x" onclick="ebClosePdfPopup()" aria-label="Close">×</button>
        </div>
        <div class="eb-pdf-popup-body">
            <iframe id="ebPdfPopupFrame" title="PDF report preview"></iframe>
        </div>
        <div class="eb-pdf-popup-footer">
            <button type="button" class="btn btn-secondary" onclick="ebClosePdfPopup()">Close</button>
        </div>
    </div>
</div>

<!-- NO PRINTER WARNING / ADMIN OVERRIDE -->
<div class="modal fade" id="ebNoPrinterModal" tabindex="-1" aria-labelledby="ebNoPrinterModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:#fff7ed;border-bottom:1px solid #fed7aa;">
        <div>
          <h5 class="modal-title" id="ebNoPrinterModalLabel" style="color:#9a3412;">⚠️ No report printer selected</h5>
          <div class="small text-muted mt-1">This receipt normally requires an automatic PDF print.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning mb-3">
          Select a printer from <strong>Report printer</strong> before continuing.
          If printing is intentionally unavailable, an administrator can authorize this one receipt without printing.
        </div>

        <label class="form-label fw-semibold" for="ebAdminOverridePassword">Admin password</label>
        <input type="password"
               class="form-control"
               id="ebAdminOverridePassword"
               autocomplete="current-password"
               placeholder="Enter admin password">
        <div id="ebAdminOverrideError" class="text-danger small mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning" id="ebContinueWithoutPrinter">
          Continue without printer
        </button>
      </div>
    </div>
  </div>
</div>

<script>
/* ═══════════════════════════════
   HELPERS
═══════════════════════════════ */
function ebH(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

function ebFlash(msg, type) {
    const cls = type === 'success' ? 'eb-flash-success' : 'eb-flash-danger';
    const ex = document.getElementById('eb-flash-ajax');
    if (ex) ex.remove();
    const div = document.createElement('div');
    div.id = 'eb-flash-ajax';
    div.setAttribute('data-eb-flash', '');
    div.className = 'eb-flash ' + cls;
    div.innerHTML = '<span>' + msg + '</span><button onclick="this.closest(\'[data-eb-flash]\').remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:14px;color:inherit;opacity:.6;">✕</button>';
    const main = document.querySelector('.eb-main');
    if (main) main.insertBefore(div, main.firstChild);
    setTimeout(() => { div.style.transition = 'opacity .5s'; div.style.opacity = '0'; setTimeout(() => div.remove(), 500); }, 5000);
}



function ebVisibleMovementRows(){
    return [...document.querySelectorAll('#logTbody tr[data-log-id]')].filter(tr=>tr.style.display!=='none');
}
function ebToggleVisibleMovementChecks(checked){
    ebVisibleMovementRows().forEach(tr=>{const cb=tr.querySelector('.ebMovementCheck');if(cb)cb.checked=checked;});
}
function ebSelectVisibleMovements(){
    const rows=ebVisibleMovementRows();
    const allSelected=rows.length>0&&rows.every(tr=>tr.querySelector('.ebMovementCheck')?.checked);
    ebToggleVisibleMovementChecks(!allSelected);
    const all=document.getElementById('ebSelectAllVisible');if(all)all.checked=!allSelected;
}
async function ebDeleteMovementIds(ids){
    ids=[...new Set(ids.map(Number).filter(v=>v>0))];
    if(!ids.length){alert('Select at least one movement event.');return;}
    if(!confirm(`Delete ${ids.length} movement event${ids.length===1?'':'s'}?\n\nThis deletes Movement Log history only. Any linked PDF will also be permanently deleted.`))return;
    const fd=new FormData();fd.append('movement_log_delete','1');ids.forEach(id=>fd.append('ids[]',String(id)));
    try{
        const r=await fetch('empty_bin_receiving.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
        const data=await r.json();if(!data.ok)throw new Error(data.error||'Delete failed.');
        alert(`Deleted ${data.deleted||0} event(s).${data.pdf_deleted?` ${data.pdf_deleted} PDF(s) deleted.`:''}`);
        location.reload();
    }catch(e){alert(e.message||String(e));}
}
function ebDeleteSelectedMovements(){ebDeleteMovementIds([...document.querySelectorAll('.ebMovementCheck:checked')].map(x=>x.value));}
function ebDeleteOneMovement(id){ebDeleteMovementIds([id]);}

function ebPrependMovementLog(log) {
    if (!log) return;
    const tbody = document.getElementById('logTbody');
    if (!tbody) return;

    const empty = tbody.querySelector('td[colspan]')?.closest('tr');
    if (empty) empty.remove();

    const delta = Number(log.qty_change || 0);
    const pdf = (delta > 0 && log.report_url)
        ? `<a href="${ebH(log.report_url)}" class="eb-pdf-icon eb-pdf-ready"
             title="Preview receiving PDF"
             data-record-id="${Number(log.source_empty_bin_id || 0)}"
             onclick="return ebOpenPdfFromElement(this,event)">PDF</a>`
        : (delta > 0
            ? `<span class="eb-pdf-icon eb-pdf-missing" title="No PDF linked">PDF</span>`
            : `<span class="text-muted" style="font-size:11px;">—</span>`);

    const tr = document.createElement('tr');
    tr.dataset.grower = log.grower || '';
    tr.dataset.type = log.type || '';
    tr.dataset.date = String(log.created_at || '').slice(0,10);
    tr.innerHTML = `
        <td style="font-size:11px;white-space:nowrap;">${ebH(log.created_at || '')}</td>
        <td>${ebH(log.grower || '')}</td>
        <td>${ebH(log.type || '')}</td>
        <td style="text-align:right;"><span class="${delta >= 0 ? 'eb-delta-pos' : 'eb-delta-neg'}">${delta >= 0 ? '+' : ''}${delta}</span></td>
        <td style="text-align:center;">${pdf}</td>
        <td style="font-size:11px;color:var(--c-muted);">${ebH(log.reason || '')}</td>`;
    tbody.prepend(tr);

    const countEl = document.getElementById('ebLogCount');
    if (countEl) {
        const n = tbody.querySelectorAll('tr').length;
        countEl.textContent = `(${n} entries)`;
    }
}

function ebUpdateChips(qty, growers, rows) {
    const cq = document.getElementById('ebChipQty');
    const cg = document.getElementById('ebChipGrowers');
    const cr = document.getElementById('ebChipRows');
    if (cq) cq.innerHTML = '📦 ' + qty + ' bins';
    if (cg) cg.innerHTML = '🌱 ' + growers + ' growers';
    if (cr) cr.innerHTML = '📋 ' + rows + ' entries';
}

function ebUpdateCurrentBalance(balances) {
    const body = document.getElementById('ebCurrentBalanceBody');
    if (!body) return;

    if (!Array.isArray(balances) || balances.length === 0) {
        body.innerHTML = `
            <div class="eb-empty">
                <span class="eb-empty-icon">📭</span>
                No empty bins in stock.
            </div>`;
        return;
    }

    const cards = balances.map(b => {
        const q = Number(b.total_qty || 0);
        const qc = q <= 5 ? 'eb-qty-low' : (q <= 20 ? 'eb-qty-medium' : 'eb-qty-high');
        return `
            <div class="eb-balance-card">
                <div class="eb-balance-grower">${ebH(b.grower || '')}</div>
                <div class="eb-balance-type">${ebH(b.type || '')}</div>
                <div>
                    <span class="eb-qty-badge ${qc}" style="font-size:16px;height:28px;min-width:36px;">${q}</span>
                    <span class="eb-balance-qty-small">bins</span>
                </div>
            </div>`;
    }).join('');

    body.innerHTML = `<div class="eb-balance-grid">${cards}</div>`;
}

function ebSelectFromTemplate(tmplId, selectedValue, newId) {
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

/* ═══════════════════════════════
   AJAX ADD PRESET (grower/type)
═══════════════════════════════ */
function ebAjaxAddPreset(action) {
    const cfg = {
        add_grower:  {input:'newGrowerInput',  select:'ebGrowerSelect',  tmpl:'tmplEbGrowerSelect',  modal:'addGrowerModal',  label:'Grower'},
        add_type:    {input:'newTypeInput',    select:'ebTypeSelect',    tmpl:'tmplEbTypeSelect',    modal:'addTypeModal',    label:'Type'},
        add_carrier: {input:'newCarrierInput', select:'ebCarrierSelect', tmpl:'tmplEbCarrierSelect', modal:'addCarrierModal', label:'Carrier'}
    }[action];

    if (!cfg) return;
    const input = document.getElementById(cfg.input);
    const name  = input?.value.trim() || '';
    if (!name) { input?.focus(); return; }

    const fd = new FormData();
    fd.append('ajax_action', action);
    fd.append('name', name);

    fetch('empty_bin_receiving.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { alert(data.error || 'Error'); return; }

            const mainSel = document.getElementById(cfg.select);
            const tmplSel = document.getElementById(cfg.tmpl);

            if (mainSel && ![...mainSel.options].some(o => o.value === data.name)) {
                mainSel.appendChild(new Option(data.name, data.name));
            }
            if (mainSel) mainSel.value = data.name;

            if (tmplSel) {
                const div = document.createElement('div');
                div.innerHTML = tmplSel.innerHTML.trim();
                const sel = div.querySelector('select');
                if (sel && ![...sel.options].some(o => o.value === data.name)) {
                    sel.appendChild(new Option(data.name, data.name));
                }
                tmplSel.innerHTML = div.innerHTML;
            }

            bootstrap.Modal.getInstance(document.getElementById(cfg.modal))?.hide();
            if (input) input.value = '';
            ebFlash('✅ ' + cfg.label + ' "' + ebH(data.name) + '" added.', 'success');
        })
        .catch(() => alert('Network error'));
}

function ebOpenPdfModal(url, recordId) {
    return ebOpenPdfPopup(url, recordId);
}

function ebOpenPdfPopup(url, recordId) {
    const popup = document.getElementById('ebPdfPopup');
    const frame = document.getElementById('ebPdfPopupFrame');
    const sub = document.getElementById('ebPdfPopupSub');

    if (!popup || !frame || !url) {
        return false;
    }

    frame.src = url;
    if (sub) sub.textContent = recordId ? ('Entry #' + recordId) : 'Movement receipt';

    popup.classList.add('is-open');
    popup.setAttribute('aria-hidden','false');
    document.body.classList.add('eb-pdf-popup-open');
    return false;
}

function ebClosePdfPopup() {
    const popup = document.getElementById('ebPdfPopup');
    const frame = document.getElementById('ebPdfPopupFrame');
    if (frame) frame.src = 'about:blank';
    if (popup) {
        popup.classList.remove('is-open');
        popup.setAttribute('aria-hidden','true');
    }
    document.body.classList.remove('eb-pdf-popup-open');
}

function ebOpenPdfFromElement(el, ev) {
    if (ev) {
        ev.preventDefault();
        ev.stopPropagation();
    }
    const url = el?.getAttribute('href') || el?.dataset?.pdfUrl || '';
    const id = Number(el?.dataset?.recordId || 0);
    return ebOpenPdfPopup(url, id);
}

document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') ebClosePdfPopup();
});
document.getElementById('ebPdfPopup')?.addEventListener('click', function(e){
    if (e.target === this) ebClosePdfPopup();
});

/* ═══════════════════════════════
   EMPTY BIN PDF REPORT ACTIONS
═══════════════════════════════ */
async function ebReportAction(recordId, action) {
    const fd = new FormData();
    fd.append('report_action', action);
    fd.append('record_id', String(recordId));
    const r = await fetch('empty_bin_receiving.php', {
        method:'POST',
        headers:{'X-Requested-With':'XMLHttpRequest'},
        body:fd
    });
    const raw = await r.text();
    try {
        return JSON.parse(raw);
    } catch (e) {
        const cleaned = raw
            .replace(/<br\s*\/?>/gi, ' ')
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        return {ok:false, error:cleaned || 'Server returned invalid JSON.'};
    }
}

async function ebRegenerateReport(recordId) {
    try {
        const data = await ebReportAction(recordId, 'regenerate_report');
        if (!data.ok) throw new Error(data.error || 'Unable to generate PDF.');
        const cell = document.getElementById('ebc_pdf_' + recordId);
        if (cell) {
            cell.innerHTML = `<a href="${ebH(data.url)}"
                class="eb-pdf-icon eb-pdf-ready" title="View PDF Report"
                aria-label="View PDF Report" data-record-id="${recordId}"
                onclick="return ebOpenPdfFromElement(this,event)">PDF</a>`;
        }
        ebFlash('✅ PDF report generated for entry #' + recordId + '.', 'success');
        ebOpenPdfModal(data.url, recordId);
    } catch (e) {
        ebFlash('⚠️ ' + ebH(e.message || 'Unable to generate PDF.'), 'danger');
    }
}

async function ebPreviewReport(recordId) {
    try {
        let data = await ebReportAction(recordId, 'preview_report');
        if (!data.ok && data.missing) data = await ebReportAction(recordId, 'regenerate_report');
        if (!data.ok) throw new Error(data.error || 'Unable to open PDF.');
        ebOpenPdfModal(data.url, recordId);
    } catch(e) {
        ebFlash('⚠️ ' + ebH(e.message || 'Unable to preview report.'), 'danger');
    }
}

async function ebTestPrintReport(recordId) {
    try {
        const data = await ebReportAction(recordId, 'test_print');
        if (!data.ok) throw new Error(data.error || 'Test print failed.');
        const method = data.method ? (' using ' + data.method) : '';
        const state = data.queued ? (' Job #' + data.jobId + ' queued for local print agent.') :
                      (data.verified === true ? ' ✓ Print command completed.' : ' Print command submitted.');
        ebFlash('✅ Report sent to ' + ebH(data.printer) + method + '.' + state, 'success');
    } catch(e) {
        ebFlash('⚠️ ' + ebH(e.message || 'Test print failed.'), 'danger');
    }
}

document.getElementById('ebTestPrintLatestReport')?.addEventListener('click', function(){
    const id = Number(this.dataset.recordId || 0);
    if (id) ebTestPrintReport(id);
});

/* ═══════════════════════════════
   REPORT PRINTER
═══════════════════════════════ */
(function(){
    const sel = document.getElementById('ebReportPrinter');
    const status = document.getElementById('ebPrinterSaveStatus');
    if (!sel) return;

    sel.addEventListener('change', async () => {
        const fd = new FormData();
        fd.append('set_report_printer', '1');
        fd.append('report_printer', sel.value);

        if (status) status.textContent = 'Saving…';
        try {
            const r = await fetch('empty_bin_receiving.php', {
                method: 'POST',
                headers: {'X-Requested-With':'XMLHttpRequest'},
                body: fd
            });
            const data = await r.json();
            if (!data.ok) throw new Error(data.error || 'Unable to save printer');
            sel.classList.toggle('eb-report-printer-missing', !sel.value);
            if (status) {
                status.textContent = '✓';
                setTimeout(()=>status.textContent='', 1800);
            }
            ebFlash(ebH(data.message || 'Report printer saved.'), 'success');
        } catch (e) {
            if (status) status.textContent = '!';
            ebFlash('⚠️ ' + ebH(e.message || 'Unable to save report printer.'), 'danger');
        }
    });
})();

/* ═══════════════════════════════
   AJAX ADD FORM + PRINTER GATE
═══════════════════════════════ */
(function () {
    const form = document.getElementById('ebAddForm');
    if (!form) return;

    const printerSel = document.getElementById('ebReportPrinter');
    const modalEl = document.getElementById('ebNoPrinterModal');
    const passInput = document.getElementById('ebAdminOverridePassword');
    const passError = document.getElementById('ebAdminOverrideError');
    const continueBtn = document.getElementById('ebContinueWithoutPrinter');

    let pendingSubmit = false;

    function setBusy(busy) {
        const btn = document.getElementById('ebAddBtn');
        const spinner = document.getElementById('ebAddSpinner');
        if (btn) btn.disabled = !!busy;
        if (spinner) spinner.style.display = busy ? '' : 'none';
    }

    async function submitReceipt(overridePassword = '') {
        setBusy(true);

        const fd = new FormData(form);
        if (overridePassword !== '') {
            fd.append('allow_without_printer', '1');
            fd.append('admin_override_password', overridePassword);
        }

        try {
            const r = await fetch('empty_bin_receiving.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            const raw = await r.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (jsonErr) {
                const cleaned = raw
                    .replace(/<br\s*\/?>/gi, ' ')
                    .replace(/<[^>]+>/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim();
                throw new Error(cleaned || 'Server returned an invalid response.');
            }

            if (!data.ok) {
                if (data.requiresPrinter) {
                    if (passError) {
                        passError.style.display = data.passwordInvalid ? '' : 'none';
                        passError.textContent = data.passwordInvalid ? (data.err || 'Invalid admin password.') : '';
                    }
                    if (passInput && data.passwordInvalid) {
                        passInput.value = '';
                        passInput.focus();
                    }
                    if (modalEl && window.bootstrap) {
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                    return;
                }

                ebFlash(data.err || 'Error.', 'danger');
                return;
            }

            if (modalEl && window.bootstrap) {
                bootstrap.Modal.getInstance(modalEl)?.hide();
            }
            if (passInput) passInput.value = '';
            if (passError) passError.style.display = 'none';

            let reportMsg = data.msg;
            if (data.report?.generated) {
                reportMsg += ' PDF report generated.';
                if (data.report?.printed) {
                    reportMsg += ' Printed to ' + ebH(data.report.printer) + '.';
                } else if (data.report?.printer) {
                    reportMsg += ' ⚠️ Print failed: ' + ebH(data.report.printError || 'Unknown print error');
                } else {
                    reportMsg += ' ⚠️ Continued without printer by admin override.';
                }
            } else {
                reportMsg += ' ⚠️ PDF report could not be generated.';
            }

            ebFlash(reportMsg, data.report?.generated ? 'success' : 'danger');
            ebUpdateChips(data.totalQty, data.totalGrowers, data.totalRows);
            ebUpdateCurrentBalance(data.balances);
            ebPrependRow(data.row);
            ebPrependMovementLog(data.movementLog);
            if (data.report?.generated && data.report?.url) {
                const pdfCell = document.getElementById('ebc_pdf_' + data.row.id);
                if (pdfCell) {
                    pdfCell.innerHTML = `<a href="${ebH(data.report.url)}"
                        class="eb-pdf-icon eb-pdf-ready" title="View PDF Report"
                        aria-label="View PDF Report" data-record-id="${data.row.id}"
                        onclick="return ebOpenPdfFromElement(this,event)">PDF</a>`;
                }
            }

            form.querySelector('[name="grower"]').value = '';
            form.querySelector('[name="type"]').value = '';
            form.querySelector('[name="carrier"]').value = '';
            form.querySelector('[name="notes"]').value = '';
            form.querySelector('[name="quantity"]').value = '1';
        } catch (e) {
            ebFlash('Network error. Please try again.', 'danger');
        } finally {
            setBusy(false);
            pendingSubmit = false;
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (pendingSubmit) return;

        // Front-end warning first; server enforces the same rule again.
        if (!printerSel || printerSel.value.trim() === '') {
            if (passInput) passInput.value = '';
            if (passError) passError.style.display = 'none';
            if (modalEl && window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
                setTimeout(() => passInput?.focus(), 200);
            } else {
                ebFlash('⚠️ No report printer selected.', 'danger');
            }
            return;
        }

        pendingSubmit = true;
        submitReceipt('');
    });

    continueBtn?.addEventListener('click', function () {
        const password = passInput?.value || '';
        if (!password) {
            if (passError) {
                passError.textContent = 'Admin password is required.';
                passError.style.display = '';
            }
            passInput?.focus();
            return;
        }

        pendingSubmit = true;
        submitReceipt(password);
    });

    passInput?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            continueBtn?.click();
        }
    });
})();

function ebPrependRow(r) {
    const tbody = document.getElementById('ebListTbody');
    if (!tbody) return;
    const q  = parseInt(r.quantity, 10) || 0;
    const qc = q <= 5 ? 'eb-qty-low' : (q <= 20 ? 'eb-qty-medium' : 'eb-qty-high');
    const html = `
    <tr id="eb_row_${r.id}" data-id="${r.id}" data-grower="${ebH(r.grower)}" data-type="${ebH(r.type)}" data-date="${ebH(r.date)}">
        <td style="font-size:11px;color:var(--c-muted);">${r.id}</td>
        <td id="ebc_grower_${r.id}" style="font-weight:600;">${ebH(r.grower)}</td>
        <td id="ebc_type_${r.id}">${ebH(r.type)}</td>
        <td id="ebc_date_${r.id}" style="font-size:11px;">${ebH(r.date)}<span class="eb-age-badge eb-age-today">Today</span></td>
        <td id="ebc_qty_${r.id}"><span class="eb-qty-badge ${qc}">${q}</span></td>
        <td id="ebc_pdf_${r.id}" style="text-align:center;">
            <button type="button" class="eb-pdf-icon eb-pdf-missing"
                    title="PDF not generated — click to create"
                    onclick="ebRegenerateReport(${r.id})">PDF</button>
        </td>
        <td id="ebc_actions_${r.id}">
            <div class="eb-act">
                <button type="button" class="eb-abtn eb-abtn-edit" onclick="ebEditRow(${r.id})">✏️ Edit</button>
            </div>
        </td>
    </tr>`;
    const emptyRow = tbody.querySelector('.eb-empty')?.closest('tr');
    if (emptyRow) emptyRow.remove();
    tbody.insertAdjacentHTML('afterbegin', html);
    const testBtn = document.getElementById('ebTestPrintLatestReport');
    if (testBtn) { testBtn.dataset.recordId = String(r.id); testBtn.disabled = false; }
}

/* ═══════════════════════════════
   INLINE EDIT (with select dropdowns)
═══════════════════════════════ */
function ebEditRow(id) {
    const gc = document.getElementById('ebc_grower_' + id);
    const tc = document.getElementById('ebc_type_'   + id);
    const dc = document.getElementById('ebc_date_'   + id);
    const qc = document.getElementById('ebc_qty_'    + id);
    const ac = document.getElementById('ebc_actions_'+ id);
    if (!gc || !tc || !dc || !qc || !ac) return;

    /* grower select from template */
    const gs = ebSelectFromTemplate('tmplEbGrowerSelect', gc.innerText.trim(), 'eb_eg_grower_' + id);
    const ts = ebSelectFromTemplate('tmplEbTypeSelect',   tc.innerText.trim(), 'eb_eg_type_' + id);
    if (!gs || !ts) { alert('Error preparing edit.'); return; }

    const d = dc.innerText.split('\n')[0].trim(); /* strip age badge text */
    const q = qc.querySelector('.eb-qty-badge')?.innerText.trim() || qc.innerText.trim();

    gc.innerHTML = ''; gc.appendChild(gs);
    tc.innerHTML = ''; tc.appendChild(ts);
    dc.innerHTML = `<input type="date" class="eb-inline-input" id="eb_eg_date_${id}" value="${d}" style="min-width:130px;">`;
    qc.innerHTML = `<input type="number" class="eb-inline-input" id="eb_eg_qty_${id}" min="1" value="${q}" style="min-width:65px;">`;
    ac.innerHTML = `
        <div class="eb-act">
            <button class="eb-abtn eb-abtn-save"   onclick="ebSaveRow(${id})">💾 Save</button>
            <button class="eb-abtn eb-abtn-cancel" onclick="location.reload()">✕</button>
        </div>`;
}

function ebSaveRow(id) {
    const grower = document.getElementById('eb_eg_grower_' + id)?.value || '';
    const type   = document.getElementById('eb_eg_type_'   + id)?.value || '';
    const date   = document.getElementById('eb_eg_date_'   + id)?.value || '';
    const qty    = document.getElementById('eb_eg_qty_'    + id)?.value || '';

    if (!grower || !type || !date || !qty) { alert('All fields are required.'); return; }

    const fd = new FormData();
    fd.append('inline_edit', '1');
    fd.append('id',       id);
    fd.append('grower',   grower);
    fd.append('type',     type);
    fd.append('date',     date);
    fd.append('quantity', qty);

    /* ── BUG FIX: correct filename (no extra 's') ── */
    fetch('empty_bin_receiving.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(txt => {
            if (txt.trim() === 'OK') {
                /* update DOM without reload */
                const gc = document.getElementById('ebc_grower_' + id);
                const tc = document.getElementById('ebc_type_'   + id);
                const dc = document.getElementById('ebc_date_'   + id);
                const qc = document.getElementById('ebc_qty_'    + id);
                const ac = document.getElementById('ebc_actions_'+ id);
                const row = document.getElementById('eb_row_' + id);
                const q  = parseInt(qty, 10);
                const qcl = q<=5?'eb-qty-low':(q<=20?'eb-qty-medium':'eb-qty-high');
                if (gc) gc.innerHTML = ebH(grower);
                if (tc) tc.innerHTML = ebH(type);
                if (dc) dc.innerHTML = ebH(date) + '<span class="eb-age-badge eb-age-today">Today</span>';
                if (qc) qc.innerHTML = `<span class="eb-qty-badge ${qcl}">${q}</span>`;
                if (ac) ac.innerHTML = `
                    <div class="eb-act">
                        <button type="button" class="eb-abtn eb-abtn-edit" onclick="ebEditRow(${id})">✏️ Edit</button>
                    </div>`;
                if (row) { row.dataset.grower = grower; row.dataset.type = type; row.dataset.date = date; }
                ebFlash('✅ Entry #' + id + ' updated.', 'success');
            } else {
                alert('Error saving entry.');
            }
        })
        .catch(() => alert('Network error.'));
}

/* ═══════════════════════════════
   LIST FILTERS
═══════════════════════════════ */
function ebApplyFilters() {
    const search = (document.getElementById('ebFilterSearch')?.value || '').toLowerCase();
    const fg     = (document.getElementById('ebFilterGrower')?.value || '').toLowerCase();
    const ft     = (document.getElementById('ebFilterType')?.value   || '').toLowerCase();
    const fd     = (document.getElementById('ebFilterDate')?.value   || '');

    document.querySelectorAll('#ebListTbody tr').forEach(row => {
        const g = (row.dataset.grower || '').toLowerCase();
        const t = (row.dataset.type   || '').toLowerCase();
        const d = (row.dataset.date   || '');
        let ok = true;
        if (search && !(g.includes(search) || t.includes(search))) ok = false;
        if (fg && g !== fg) ok = false;
        if (ft && t !== ft) ok = false;
        if (fd && d !== fd) ok = false;
        row.style.display = ok ? '' : 'none';
    });
}

/* ═══════════════════════════════
   LOG PANEL
═══════════════════════════════ */
let ebLogOpen = false;
function ebToggleLog() {
    const body    = document.getElementById('ebLogBody');
    const btn     = document.getElementById('ebLogToggleBtn');
    ebLogOpen = !ebLogOpen;
    if (body) body.style.display = ebLogOpen ? '' : 'none';
    if (btn)  btn.textContent = ebLogOpen ? '▲ Hide' : '▼ Show';
    if (ebLogOpen) ebApplyLogFilters();
}

function ebToggleCustomDates() {
    const val = document.getElementById('logDatePreset')?.value;
    const from = document.getElementById('logDateFrom');
    const to   = document.getElementById('logDateTo');
    const show = val === 'custom';
    from?.classList.toggle('d-none', !show);
    to?.classList.toggle('d-none', !show);
}

function ebInDateRange(rowDate) {
    const mode = document.getElementById('logDatePreset')?.value || 'all';
    if (!rowDate || mode === 'all') return true;
    const today = new Date();
    const rd = new Date(rowDate + 'T00:00:00');
    if (mode === 'today') return rd.toDateString() === today.toDateString();
    if (mode === '7' || mode === '30') return (today - rd) / 86400000 <= parseInt(mode, 10);
    if (mode === 'custom') {
        const from = document.getElementById('logDateFrom')?.value;
        const to   = document.getElementById('logDateTo')?.value;
        if (from && rowDate < from) return false;
        if (to   && rowDate > to)   return false;
        return true;
    }
    return true;
}

function ebApplyLogFilters() {
    const limit    = document.getElementById('logLimitSelect')?.value || '20';
    const gv       = (document.getElementById('logGrowerFilter')?.value || '').toLowerCase();
    const tv       = (document.getElementById('logTypeFilter')?.value   || '').toLowerCase();
    ebToggleCustomDates();

    let shown = 0;
    Array.from(document.querySelectorAll('#logTbody tr')).forEach(row => {
        const rg = (row.dataset.grower || '').toLowerCase();
        const rt = (row.dataset.type   || '').toLowerCase();
        const rd =  row.dataset.date   || '';
        let match = true;
        if (gv && rg !== gv) match = false;
        if (tv && rt !== tv) match = false;
        if (!ebInDateRange(rd)) match = false;
        if (!match) { row.style.display = 'none'; return; }
        if (limit !== 'all' && shown >= parseInt(limit, 10)) { row.style.display = 'none'; return; }
        row.style.display = ''; shown++;
    });
}

function ebExportLogCsv() {
    const tbody = document.getElementById('logTbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.style.display !== 'none');
    if (!rows.length) { alert('No rows to export'); return; }
    const headers = ['Date/Time','Grower','Type','Delta Qty','Reason'];
    const lines = [headers.join(',')];
    rows.forEach(tr => {
        const cells = Array.from(tr.children).map(td => '"' + (td.innerText||'').replace(/"/g,'""') + '"');
        lines.push(cells.join(','));
    });
    const blob = new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8;'});
    const url  = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'empty_bins_log_' + new Date().toISOString().slice(0,10) + '.csv'; a.click();
    URL.revokeObjectURL(url);
}

/* ═══════════════════════════════
   AUTO-DISMISS FLASH
═══════════════════════════════ */
document.querySelectorAll('[data-eb-flash]').forEach(el => {
    setTimeout(() => { el.style.transition='opacity .5s'; el.style.opacity='0'; setTimeout(()=>el.remove(),500); }, 5000);
});

/* ═══════════════════════════════
   INIT
═══════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
    /* List filters */
    document.getElementById('ebFilterSearch') ?.addEventListener('input',  ebApplyFilters);
    document.getElementById('ebFilterGrower') ?.addEventListener('change', ebApplyFilters);
    document.getElementById('ebFilterType')   ?.addEventListener('change', ebApplyFilters);
    document.getElementById('ebFilterDate')   ?.addEventListener('change', ebApplyFilters);

    /* Log filters */
    ['logGrowerFilter','logTypeFilter','logDatePreset','logDateFrom','logDateTo','logLimitSelect'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', ebApplyLogFilters);
    });

    /* Log export */
    document.getElementById('exportLogCsv')?.addEventListener('click', ebExportLogCsv);

    /* Restore log filter state */
    const ll = document.getElementById('logLimitSelect');
    if (ll) ll.value = localStorage.getItem('eb_log_limit') || '20';
});
</script>
