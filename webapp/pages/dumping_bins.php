<?php
require_once __DIR__ . '/../config/user_functions.php';

// DEBUG (puoi disattivarlo in produzione)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user'])) { header('Location: /auth/login.php'); exit; }

$role = $_SESSION['user']['role'] ?? 'viewer';
if (!sp_allow('bins', ['warehouse'])) { http_response_code(403); die('Forbidden — you do not have access to this section.'); }

require_once __DIR__ . '/../config/db_remote.php';


/* ----------------------------------------
   FULL BIN MOVEMENT LOG
   Shared history with bins_ingresso.php
---------------------------------------- */
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

/* ADMIN ONLY — delete Dumping Movement Log events.
   Inventory/status is untouched. Only dump/undo/barcode events can be deleted here. */
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
    $mysqli->query("
        DELETE FROM full_bins_log
        WHERE id IN ($idList)
          AND (
               LOWER(COALESCE(reason,'')) LIKE '%dump%'
            OR LOWER(COALESCE(reason,'')) LIKE '%undo%'
            OR LOWER(COALESCE(reason,'')) LIKE '%barcode%'
          )
    ");
    echo json_encode(['ok'=>true,'deleted'=>$mysqli->affected_rows,'pdf_deleted'=>0]);
    exit;
}

if (!function_exists('db_full_bin_meta_for_group')) {
    function db_full_bin_meta_for_group(mysqli $mysqli, int $groupId, string $status): array
    {
        if ($groupId <= 0) return [];

        $statusEsc = $mysqli->real_escape_string($status);
        $sql = "
            SELECT
                bi.group_id,
                COALESCE(gp.name,'') AS grower,
                COALESCE(vl.name,'') AS variety,
                COALESCE(tl.name,'') AS type,
                COALESCE(bi.lot,'') AS lot,
                COUNT(*) AS qty
            FROM bins_ingresso bi
            LEFT JOIN growers_list gp ON bi.grower_id=gp.id
            LEFT JOIN varieties_list vl ON bi.variety_id=vl.id
            LEFT JOIN bin_types_list tl ON bi.type_id=tl.id
            WHERE bi.group_id=$groupId
              AND bi.status='$statusEsc'
            GROUP BY bi.group_id,gp.name,vl.name,tl.name,bi.lot
        ";
        $res = $mysqli->query($sql);
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('db_full_bin_meta_for_single')) {
    function db_full_bin_meta_for_single(mysqli $mysqli, int $id, string $status): array
    {
        if ($id <= 0) return [];

        $statusEsc = $mysqli->real_escape_string($status);
        $sql = "
            SELECT
                bi.group_id,
                COALESCE(gp.name,'') AS grower,
                COALESCE(vl.name,'') AS variety,
                COALESCE(tl.name,'') AS type,
                COALESCE(bi.lot,'') AS lot,
                1 AS qty
            FROM bins_ingresso bi
            LEFT JOIN growers_list gp ON bi.grower_id=gp.id
            LEFT JOIN varieties_list vl ON bi.variety_id=vl.id
            LEFT JOIN bin_types_list tl ON bi.type_id=tl.id
            WHERE bi.id=$id
              AND bi.status='$statusEsc'
            LIMIT 1
        ";
        $res = $mysqli->query($sql);
        $row = $res ? $res->fetch_assoc() : null;
        return $row ? [$row] : [];
    }
}

if (!function_exists('db_write_full_bin_movements')) {
    function db_write_full_bin_movements(mysqli $mysqli, array $rows, int $sign, string $reason): int
    {
        if (!$rows || ($sign !== 1 && $sign !== -1)) return 0;

        $stmt = $mysqli->prepare("
            INSERT INTO full_bins_log
                (group_id,grower,variety,type,lot,qty_change,reason,receipt_id)
            VALUES(?,?,?,?,?,?,?,NULL)
        ");
        if (!$stmt) return 0;

        $written = 0;
        foreach ($rows as $r) {
            $gid = (int)($r['group_id'] ?? 0);
            $grower = (string)($r['grower'] ?? '');
            $variety = (string)($r['variety'] ?? '');
            $type = (string)($r['type'] ?? '');
            $lot = (string)($r['lot'] ?? '');
            $qty = max(0, (int)($r['qty'] ?? 0)) * $sign;
            if ($qty === 0) continue;

            $stmt->bind_param('issssis',
                $gid, $grower, $variety, $type, $lot, $qty, $reason
            );
            if ($stmt->execute()) $written++;
        }
        $stmt->close();
        return $written;
    }
}

$msg = null;
$err = null;

/* ----------------------------------------
   PRESET TABLES (ID-BASED)
---------------------------------------- */
$mysqli->query("
CREATE TABLE IF NOT EXISTS bin_types_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$mysqli->query("
CREATE TABLE IF NOT EXISTS growers_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL
)");

$mysqli->query("
CREATE TABLE IF NOT EXISTS varieties_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL
)");

$growers   = $mysqli->query("SELECT id, name FROM growers_list ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$varieties = $mysqli->query("SELECT id, name FROM varieties_list ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$types     = $mysqli->query("SELECT id, name FROM bin_types_list ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

/* ── barcode column: ensure exists + backfill existing rows ── */
/* ── barcode column: compatible with MySQL 5.7+ / MariaDB ── */
$_bc_check = $mysqli->query("SHOW COLUMNS FROM bins_ingresso LIKE 'barcode'");
if ($_bc_check && $_bc_check->num_rows === 0) {
    $mysqli->query("ALTER TABLE bins_ingresso ADD COLUMN barcode VARCHAR(20) NULL AFTER id");
    @$mysqli->query("ALTER TABLE bins_ingresso ADD UNIQUE INDEX idx_barcode (barcode)");
}
$mysqli->query("UPDATE bins_ingresso SET barcode = CONCAT('FBIN-', LPAD(id,6,'0')) WHERE barcode IS NULL OR barcode = ''");


/* ----------------------------------------
   ORDINAMENTO (AVAILABLE / DUMPED)
---------------------------------------- */
$allowedSort = ['grower','variety','lot','date','total_bins','group_id'];

// Available
$sort_av = $_GET['sort_av'] ?? 'date';
$dir_av  = strtolower($_GET['dir_av'] ?? 'desc');
if (!in_array($sort_av, $allowedSort)) $sort_av = 'date';
$dir_av = $dir_av === 'asc' ? 'asc' : 'desc';

// Dumped
$sort_dump = $_GET['sort_dump'] ?? 'date';
$dir_dump  = strtolower($_GET['dir_dump'] ?? 'desc');
if (!in_array($sort_dump, $allowedSort)) $sort_dump = 'date';
$dir_dump = $dir_dump === 'asc' ? 'asc' : 'desc';

$orderClauseAv   = "ORDER BY {$sort_av} {$dir_av}";
$orderClauseDump = "ORDER BY {$sort_dump} {$dir_dump}";

/* ----------------------------------------
   AZIONI POST (AJAX inline)
---------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ---------- INLINE EDIT GROUP (AJAX) ---------- */
    if ($action === 'edit_group_inline') {
        $gid     = intval($_POST['group_id'] ?? 0);
        $grower  = $mysqli->real_escape_string(trim($_POST['grower'] ?? ''));
        $variety = $mysqli->real_escape_string(trim($_POST['variety'] ?? ''));
        $type    = $mysqli->real_escape_string(trim($_POST['type'] ?? ''));
        $lot     = $mysqli->real_escape_string(trim($_POST['lot'] ?? ''));
        $date    = $mysqli->real_escape_string(trim($_POST['date'] ?? ''));

        $g = $mysqli->query("SELECT id FROM growers_list   WHERE name='$grower'")->fetch_assoc();
        $v = $mysqli->query("SELECT id FROM varieties_list WHERE name='$variety'")->fetch_assoc();
        $t = $mysqli->query("SELECT id FROM bin_types_list WHERE name='$type'")->fetch_assoc();

        $grower_id  = $g['id'] ?? 0;
        $variety_id = $v['id'] ?? 0;
        $type_id    = $t['id'] ?? 0;

        if ($gid > 0 && $grower_id > 0 && $variety_id > 0 && $date !== '') {
            $mysqli->query("
                UPDATE bins_ingresso
                SET 
                    grower_id  = $grower_id,
                    variety_id = $variety_id,
                    type_id    = $type_id,
                    lot        = '$lot',
                    date       = '$date'
                WHERE group_id = $gid
            ");
            echo "OK";
        } else {
            echo "ERROR";
        }
        exit;
    }

    /* ---------- INLINE EDIT BIN (AJAX) ---------- */
    if ($action === 'edit_bin_inline') {
        $id      = intval($_POST['id'] ?? 0);
        $grower  = $mysqli->real_escape_string(trim($_POST['grower'] ?? ''));
        $variety = $mysqli->real_escape_string(trim($_POST['variety'] ?? ''));
        $type    = $mysqli->real_escape_string(trim($_POST['type'] ?? ''));
        $lot     = $mysqli->real_escape_string(trim($_POST['lot'] ?? ''));
        $date    = $mysqli->real_escape_string(trim($_POST['date'] ?? ''));

        $g = $mysqli->query("SELECT id FROM growers_list   WHERE name='$grower'")->fetch_assoc();
        $v = $mysqli->query("SELECT id FROM varieties_list WHERE name='$variety'")->fetch_assoc();
        $t = $mysqli->query("SELECT id FROM bin_types_list WHERE name='$type'")->fetch_assoc();

        $grower_id  = $g['id'] ?? 0;
        $variety_id = $v['id'] ?? 0;
        $type_id    = $t['id'] ?? 0;

        if ($id > 0 && $grower_id > 0 && $variety_id > 0 && $date !== '') {
            $mysqli->query("
                UPDATE bins_ingresso
                SET
                    grower_id  = $grower_id,
                    variety_id = $variety_id,
                    type_id    = $type_id,
                    lot        = '$lot',
                    date       = '$date'
                WHERE id = $id
            ");
            echo "OK";
        } else {
            echo "ERROR";
        }
        exit;
    }

    /* ---------- ADD PRESETS ---------- */
    if ($action === 'add_grower' && $role === 'admin') {
        $n = trim($_POST['new_grower'] ?? '');
        if ($n !== '') {
            $n = $mysqli->real_escape_string($n);
            $mysqli->query("INSERT IGNORE INTO growers_list(name) VALUES('$n')");
            $msg = "Grower added.";
        }
    }

    if ($action === 'add_variety' && $role === 'admin') {
        $n = trim($_POST['new_variety'] ?? '');
        if ($n !== '') {
            $n = $mysqli->real_escape_string($n);
            $mysqli->query("INSERT IGNORE INTO varieties_list(name) VALUES('$n')");
            $msg = "Variety added.";
        }
    }

    if ($action === 'add_type' && $role === 'admin') {
        $n = trim($_POST['new_type'] ?? '');
        if ($n !== '') {
            $n = $mysqli->real_escape_string($n);
            $mysqli->query("INSERT IGNORE INTO bin_types_list(name) VALUES('$n')");
            $msg = "Type added.";
        }
    }

    /* ---------- DUMP / UNDO / DELETE ---------- */

    // DUMP GROUP: AVAILABLE -> DUMPED + negative movement
    if ($action === 'dump_group') {
        $gid = intval($_POST['group_id'] ?? 0);
        if ($gid > 0) {
            $movementRows = db_full_bin_meta_for_group($mysqli, $gid, 'AVAILABLE');

            $mysqli->query("
                UPDATE bins_ingresso
                SET status = 'DUMPED'
                WHERE group_id = {$gid}
                  AND status = 'AVAILABLE'
            ");
            $affected = (int)$mysqli->affected_rows;

            if ($affected > 0) {
                db_write_full_bin_movements(
                    $mysqli,
                    $movementRows,
                    -1,
                    'Full Bin group dumped'
                );
            }
        }
    }

    // DUMP SINGLE + negative movement
    if ($action === 'dump_single') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $movementRows = db_full_bin_meta_for_single($mysqli, $id, 'AVAILABLE');

            $mysqli->query("
                UPDATE bins_ingresso
                SET status = 'DUMPED'
                WHERE id = {$id}
                  AND status = 'AVAILABLE'
            ");
            $affected = (int)$mysqli->affected_rows;

            if ($affected > 0) {
                db_write_full_bin_movements(
                    $mysqli,
                    $movementRows,
                    -1,
                    'Single Full Bin dumped'
                );
            }
        }
    }

    // UNDO GROUP: DUMPED -> AVAILABLE + positive movement
    if ($action === 'undo_group') {
        $gid = intval($_POST['group_id'] ?? 0);
        if ($gid > 0) {
            $movementRows = db_full_bin_meta_for_group($mysqli, $gid, 'DUMPED');

            $mysqli->query("
                UPDATE bins_ingresso
                SET status = 'AVAILABLE'
                WHERE group_id = {$gid}
                  AND status = 'DUMPED'
            ");
            $affected = (int)$mysqli->affected_rows;

            if ($affected > 0) {
                db_write_full_bin_movements(
                    $mysqli,
                    $movementRows,
                    1,
                    'Undo dump — Full Bin group restored'
                );
            }
        }
    }

    // UNDO SINGLE + positive movement
    if ($action === 'undo_single') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $movementRows = db_full_bin_meta_for_single($mysqli, $id, 'DUMPED');

            $mysqli->query("
                UPDATE bins_ingresso
                SET status = 'AVAILABLE'
                WHERE id = {$id}
                  AND status = 'DUMPED'
            ");
            $affected = (int)$mysqli->affected_rows;

            if ($affected > 0) {
                db_write_full_bin_movements(
                    $mysqli,
                    $movementRows,
                    1,
                    'Undo dump — Single Full Bin restored'
                );
            }
        }
    }

    // Delete group
    if ($action === 'delete_group' && $role === 'admin') {
        $gid = intval($_POST['group_id'] ?? 0);
        if ($gid > 0) {
            $mysqli->query("DELETE FROM bins_ingresso WHERE group_id = {$gid}");
            $msg = "Group {$gid} deleted.";
        }
    }

    // Delete single bin
    if ($action === 'delete_single' && $role === 'admin') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $mysqli->query("DELETE FROM bins_ingresso WHERE id = {$id}");
            $msg = "Bin #{$id} deleted.";
        }
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && in_array($action, [
            'dump_group','dump_single','undo_group','undo_single',
            'delete_group','delete_single'
        ], true)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'action' => $action,
            'affected' => (int)($affected ?? 0),
            'message' => (string)($msg ?? '')
        ]);
        exit;
    }

}




/* ----------------------------------------
   DUMPING MOVEMENT LOG
   Reads the shared full_bins_log and shows only dumping/undo activity.
---------------------------------------- */
$dumpMovementRows = [];
$dumpLogQ = $mysqli->query("
    SELECT
        id, group_id, grower, variety, type, lot,
        qty_change, reason, receipt_id, created_at
    FROM full_bins_log
    WHERE reason IN (
        'Full Bin group dumped',
        'Single Full Bin dumped',
        'Dumped by Dumping APK',
        'Undo dump — Full Bin group restored',
        'Undo dump — Single Full Bin restored'
    )
    ORDER BY id DESC
    LIMIT 1000
");
if ($dumpLogQ) {
    $dumpMovementRows = $dumpLogQ->fetch_all(MYSQLI_ASSOC);
}

/* ----------------------------------------
   GRUPPI AVAILABLE (status = AVAILABLE)
---------------------------------------- */
$sql_av = "
    SELECT 
        MIN(gp.name) AS grower,
        MIN(vl.name) AS variety,
        MIN(tl.name) AS type,
        MIN(bi.lot)  AS lot,
        DATE_FORMAT(MIN(bi.date),'%Y-%m-%d') AS date,
        COUNT(*) AS total_bins,
        bi.group_id
    FROM bins_ingresso bi
    LEFT JOIN growers_list   gp ON bi.grower_id  = gp.id
    LEFT JOIN varieties_list vl ON bi.variety_id = vl.id
    LEFT JOIN bin_types_list tl ON bi.type_id    = tl.id
    WHERE bi.status = 'AVAILABLE'
    GROUP BY bi.group_id
    {$orderClauseAv}
";
$res_groups_av = $mysqli->query($sql_av);
$groups_available = $res_groups_av ? $res_groups_av->fetch_all(MYSQLI_ASSOC) : [];

/* ----------------------------------------
   GRUPPI DUMPED (status = DUMPED)
---------------------------------------- */
$sql_dump = "
    SELECT 
        MIN(gp.name) AS grower,
        MIN(vl.name) AS variety,
        MIN(tl.name) AS type,
        MIN(bi.lot)  AS lot,
        DATE_FORMAT(MIN(bi.date),'%Y-%m-%d') AS date,
        COUNT(*) AS total_bins,
        bi.group_id
    FROM bins_ingresso bi
    LEFT JOIN growers_list   gp ON bi.grower_id  = gp.id
    LEFT JOIN varieties_list vl ON bi.variety_id = vl.id
    LEFT JOIN bin_types_list tl ON bi.type_id    = tl.id
    WHERE bi.status = 'DUMPED'
    GROUP BY bi.group_id
    {$orderClauseDump}
";
$res_groups_dump = $mysqli->query($sql_dump);
$groups_dumped = $res_groups_dump ? $res_groups_dump->fetch_all(MYSQLI_ASSOC) : [];

/* ----------------------------------------
   LISTE PER FILTRI (Grower / Variety / Lot)
---------------------------------------- */
$dump_filter_growers = $mysqli->query("
    SELECT DISTINCT gp.name AS name
    FROM bins_ingresso bi
    LEFT JOIN growers_list gp ON bi.grower_id = gp.id
    WHERE bi.status IN ('AVAILABLE','DUMPED')
      AND gp.name IS NOT NULL AND gp.name <> ''
    ORDER BY gp.name ASC
")->fetch_all(MYSQLI_ASSOC);

$dump_filter_varieties = $mysqli->query("
    SELECT DISTINCT vl.name AS name
    FROM bins_ingresso bi
    LEFT JOIN varieties_list vl ON bi.variety_id = vl.id
    WHERE bi.status IN ('AVAILABLE','DUMPED')
      AND vl.name IS NOT NULL AND vl.name <> ''
    ORDER BY vl.name ASC
")->fetch_all(MYSQLI_ASSOC);

$dump_filter_lots = $mysqli->query("
    SELECT DISTINCT bi.lot AS name
    FROM bins_ingresso bi
    WHERE bi.status IN ('AVAILABLE','DUMPED')
      AND bi.lot IS NOT NULL AND bi.lot <> ''
    ORDER BY bi.lot ASC
")->fetch_all(MYSQLI_ASSOC);

/* ── chip stats ── */
$total_available = 0;
$res_av_c = $mysqli->query("SELECT COUNT(*) AS c FROM bins_ingresso WHERE status='AVAILABLE'");
if ($res_av_c) $total_available = (int)$res_av_c->fetch_assoc()['c'];
$total_dumped_c = 0;
$res_d_c = $mysqli->query("SELECT COUNT(*) AS c FROM bins_ingresso WHERE status='DUMPED'");
if ($res_d_c) $total_dumped_c = (int)$res_d_c->fetch_assoc()['c'];

include('../includes/header.php');
include('../includes/sidebar.php');

?>
<!-- ╔══════════════════════════════════════════════════════╗
     ║  dumping_bins.php  –  v5 UI  (db- design tokens)    ║
     ╚══════════════════════════════════════════════════════╝ -->

<style>
/* ═══ DESIGN TOKENS ═══════════════════════════════════════ */
:root{
  --db-bg:           #f0f2f5;
  --db-panel-bg:     #ffffff;
  --db-border:       #e2e5eb;
  --db-topbar-bg:    #1a2235;
  --db-topbar-text:  #e8ecf4;
  --db-accent:       #3b7de8;
  --db-accent-dark:  #2c62c4;
  --db-danger:       #e53e3e;
  --db-warning:      #d97706;
  --db-success:      #16a34a;
  --db-radius:       8px;
  --db-shadow:       0 2px 8px rgba(0,0,0,.07);
}

/* ═══ LAYOUT ══════════════════════════════════════════════ */
body { background: var(--db-bg); }
.db-wrap { max-width: 1280px; margin: 0 auto; padding: 0 16px 48px; }

/* ═══ TOPBAR ══════════════════════════════════════════════ */
.db-topbar {
  position: sticky; top: 0; z-index: 900;
  background: var(--db-topbar-bg);
  display: flex; align-items: center; gap: 16px;
  padding: 10px 20px; box-shadow: 0 2px 8px rgba(0,0,0,.25);
}
.db-topbar-title {
  font-size: 1.1rem; font-weight: 700;
  color: #fff; white-space: nowrap; letter-spacing: .3px;
}
.db-topbar-nav { display: flex; gap: 8px; flex-wrap: wrap; flex: 1; }
.db-topbar-nav a {
  color: var(--db-topbar-text); text-decoration: none;
  font-size: .8rem; padding: 4px 10px; border-radius: 4px;
  transition: background .15s;
}
.db-topbar-nav a:hover { background: rgba(255,255,255,.12); }
.db-chip-area  { display: flex; gap: 8px; flex-wrap: wrap; }
.db-chip {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: .75rem; font-weight: 600; padding: 4px 10px;
  border-radius: 20px; white-space: nowrap;
}
.db-chip-av   { background: #1e6e3a; color: #d1fae5; }
.db-chip-dump { background: #7b3a10; color: #fde68a; }

/* ═══ PANELS ══════════════════════════════════════════════ */
.db-panel {
  background: var(--db-panel-bg);
  border: 1px solid var(--db-border);
  border-radius: var(--db-radius);
  box-shadow: var(--db-shadow);
  margin-bottom: 20px;
  overflow: hidden;
}
.db-panel-hdr {
  background: #f7f8fb;
  border-bottom: 1px solid var(--db-border);
  padding: 10px 16px;
  display: flex; align-items: center; gap: 10px;
  font-weight: 700; font-size: .9rem;
}
.db-panel-hdr .db-panel-title { flex: 1; }
.db-panel-body { padding: 16px; }

/* ═══ FLASH MESSAGES ══════════════════════════════════════ */
.db-flash {
  padding: 10px 16px; border-radius: var(--db-radius);
  font-size: .88rem; font-weight: 500; margin-bottom: 14px;
  display: flex; align-items: center; gap: 8px;
}
.db-flash-ok  { background:#dcfce7; color:#15803d; border:1px solid #86efac; }
.db-flash-err { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; }

/* ═══ FILTER BAR ══════════════════════════════════════════ */
.db-filter-bar {
  display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end;
  margin-bottom: 14px;
}
.db-filter-bar input,
.db-filter-bar select {
  border: 1px solid var(--db-border);
  border-radius: 5px; padding: 6px 10px;
  font-size: .83rem; background: #fff;
  outline: none; transition: border-color .2s;
  min-width: 0;
}
.db-filter-bar input:focus,
.db-filter-bar select:focus { border-color: var(--db-accent); }
.db-filter-search { flex: 2; min-width: 140px; }
.db-filter-select { flex: 1; min-width: 110px; }

/* ═══ GROUP TABLE ═════════════════════════════════════════ */
.db-table-wrap { overflow-x: auto; }
.db-table {
  width: 100%; border-collapse: collapse;
  font-size: .83rem;
}
.db-table thead th {
  background: #f7f8fb; border-bottom: 2px solid var(--db-border);
  padding: 8px 10px; text-align: left;
  font-size: .72rem; font-weight: 700;
  color: #6b7280; text-transform: uppercase; letter-spacing: .4px;
  white-space: nowrap;
}
.db-table tbody td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.db-table tbody tr:last-child td { border-bottom: none; }
.db-table .db-group-row { cursor: pointer; transition: background .1s; }
.db-table .db-group-row:hover { background: #f7f9ff; }
.db-table .db-group-row-dumped:hover { background: #fffbf0; }
.db-empty-row td { text-align:center; color:#9ca3af; padding:24px; font-size:.85rem; }

/* Arrow toggle */
.db-arrow {
  display: inline-block; transition: transform .22s;
  color: #9ca3af; font-size: 13px; user-select: none;
}
.db-arrow.open { transform: rotate(90deg); color: var(--db-accent); }

/* ═══ AGE BADGE ═══════════════════════════════════════════ */
.db-age { display:inline-block; padding:2px 7px; border-radius:10px; font-size:.7rem; font-weight:700; }
.db-age-today  { background:#dcfce7; color:#15803d; }
.db-age-recent { background:#fef9c3; color:#854d0e; }
.db-age-old    { background:#fee2e2; color:#b91c1c; }

/* ═══ QTY BADGE ═══════════════════════════════════════════ */
.db-qty { display:inline-block; padding:3px 9px; border-radius:10px; font-size:.78rem; font-weight:700; min-width:28px; text-align:center; }
.db-qty-low    { background:#fee2e2; color:#b91c1c; }
.db-qty-mid    { background:#fef9c3; color:#854d0e; }
.db-qty-high   { background:#dcfce7; color:#15803d; }

/* ═══ ACTION BUTTONS ══════════════════════════════════════ */
.db-act { display:inline-flex; gap:4px; flex-wrap:wrap; align-items:center; }
.db-btn {
  border: none; border-radius: 4px; cursor: pointer;
  padding: 4px 9px; font-size: .75rem; font-weight: 600;
  line-height: 1.4; transition: filter .15s; white-space: nowrap;
}
.db-btn:hover { filter: brightness(.9); }
.db-btn-edit   { background:#fef3c7; color:#92400e; }
.db-btn-dump   { background:#dbeafe; color:#1d4ed8; }
.db-btn-undo   { background:#fef3c7; color:#92400e; }
.db-btn-del    { background:#fee2e2; color:#b91c1c; }
.db-btn-save   { background:#dcfce7; color:#15803d; }
.db-btn-cancel { background:#f3f4f6; color:#374151; }

/* ═══ DETAIL TABLE ════════════════════════════════════════ */
.db-detail-wrap { padding: 8px 8px 8px 36px; background: #fafbfc; }
.db-detail-table { width:100%; border-collapse:collapse; font-size:.78rem; }
.db-detail-table th {
  background:#f1f3f7; border-bottom:1px solid var(--db-border);
  padding:5px 8px; font-size:.68rem; font-weight:700;
  color:#6b7280; text-transform:uppercase; letter-spacing:.3px;
}
.db-detail-table td { padding:5px 8px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.db-detail-table tr:last-child td { border-bottom:none; }
.db-bc { font-family:monospace; color:#2563eb; font-size:.8rem; }

/* ═══ EXPAND/COLLAPSE BTNS ════════════════════════════════ */
.db-exp-btns { display:flex; gap:4px; }
.db-exp-btn {
  background:#f3f4f6; border:1px solid var(--db-border);
  border-radius:4px; padding:3px 9px; font-size:.72rem;
  cursor:pointer; font-weight:600; color:#374151;
  transition:background .15s;
}
.db-exp-btn:hover { background:#e5e7eb; }

/* ═══ PANEL HEADER BADGE ══════════════════════════════════ */
.db-panel-badge {
  font-size:.72rem; font-weight:700; padding:2px 8px;
  border-radius:10px; margin-left:4px;
}
.db-panel-badge-av   { background:#dcfce7; color:#15803d; }
.db-panel-badge-dump { background:#fef9c3; color:#854d0e; }

/* ═══ INLINE EDIT INPUTS ══════════════════════════════════ */
.db-ie-input {
  border: 1px solid var(--db-border); border-radius: 4px;
  padding: 3px 6px; font-size: .78rem; width: 100%;
  outline: none;
}
.db-ie-input:focus { border-color: var(--db-accent); }
.db-ie-select {
  border: 1px solid var(--db-border); border-radius: 4px;
  padding: 3px 6px; font-size: .78rem; width: 100%;
  outline: none; background:#fff;
}


/* Exact Empty/Full Movement Log styling */


/* ─────────────────────────────────────────────
   Movement Log — native Dumping Bins styling
───────────────────────────────────────────── */
.db-movement-panel{
  margin-top:16px;
}
.db-movement-hdr{
  cursor:pointer;
  gap:12px;
  flex-wrap:wrap;
}
.db-movement-sub{
  margin-top:4px;
  font-size:.78rem;
  color:#6b7280;
  font-weight:500;
}
.db-movement-count{
  margin-left:6px;
  vertical-align:middle;
}
.db-movement-tools{
  margin-left:auto;
  display:flex;
  align-items:center;
  justify-content:flex-end;
  gap:6px;
  flex-wrap:wrap;
}
.db-filter-select{
  height:32px;
  border:1px solid #d1d5db;
  border-radius:7px;
  background:#fff;
  color:#374151;
  padding:0 8px;
  font-size:.76rem;
  font-weight:600;
  outline:none;
}
.db-filter-select:focus{
  border-color:#9ca3af;
  box-shadow:0 0 0 2px rgba(107,114,128,.10);
}
.db-log-date-custom{
  min-width:128px;
}
.db-movement-toggle{
  min-width:72px;
}
.db-movement-table td,
.db-movement-table th{
  white-space:nowrap;
}
.db-movement-date{
  font-size:.76rem;
  color:#4b5563;
}
.db-movement-delta{
  display:inline-flex;
  min-width:38px;
  justify-content:center;
  align-items:center;
  height:24px;
  border-radius:999px;
  padding:0 8px;
  font-weight:800;
  font-size:.76rem;
}
.db-movement-plus{
  color:#166534;
  background:#dcfce7;
  border:1px solid #bbf7d0;
}
.db-movement-minus{
  color:#991b1b;
  background:#fee2e2;
  border:1px solid #fecaca;
}
.db-movement-action{
  display:inline-flex;
  align-items:center;
  border-radius:6px;
  padding:4px 7px;
  font-size:.73rem;
  font-weight:700;
}
.db-movement-action-dump{
  color:#991b1b;
  background:#fef2f2;
}
.db-movement-action-undo{
  color:#166534;
  background:#f0fdf4;
}
@media (max-width: 1100px){
  .db-movement-tools{
    width:100%;
    justify-content:flex-start;
    margin-left:0;
  }
}


/* Movement Log controls — exact same sizing behavior as filters above */
.db-movement-tools{
  width:100%;
  margin-left:0;
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:flex-end;
  justify-content:flex-start;
}
.db-movement-tools .db-filter-select{
  flex:1;
  min-width:110px;
  width:auto;
}
.db-movement-tools .db-exp-btn{
  flex:0 0 auto;
}
.db-movement-hdr{
  align-items:flex-start;
  flex-wrap:wrap;
}
.db-movement-hdr > div:first-child{
  width:100%;
}

/* Primary blocks: EXACT same outer width/alignment */
.db-main-section{
  display:block;
  width:100%;
  max-width:100%;
  box-sizing:border-box;
  margin-left:0;
  margin-right:0;
}

/* All four main boxes share the exact same parent and outer width */
.db-main-section{
  width:100%;
  max-width:100%;
  box-sizing:border-box;
  margin-left:0;
  margin-right:0;
}
</style>

<?php
/* ── sort_link helper (unused in current UI, kept for future) ── */
function sort_link($label, $col, $currentSort, $currentDir, $prefix) {
    $dir = 'asc'; $arrow = '';
    if ($currentSort === $col) {
        if ($currentDir === 'asc') { $dir = 'desc'; $arrow = ' ▲'; }
        else                       { $dir = 'asc';  $arrow = ' ▼'; }
    }
    $qs = $_GET;
    $qs["sort_{$prefix}"] = $col;
    $qs["dir_{$prefix}"]  = $dir;
    $href = htmlspecialchars($_SERVER['PHP_SELF'] . '?' . http_build_query($qs));
    return '<a href="'.$href.'">'.$label.$arrow.'</a>';
}
?>

<!-- ── TOPBAR ─────────────────────────────────────────── -->
<div class="db-topbar">
  <span class="db-topbar-title">⚙️ Dumping Station</span>
  <nav class="db-topbar-nav">
    <a href="bins_ingresso.php">📦 Full Bins</a>
    <a href="empty_bin_receiving.php">🗑️ Empty Bins</a>
    <a href="/chooser.php">🏠 Main Menu</a>
  </nav>
  <div class="db-chip-area">
    <span class="db-chip db-chip-av" id="topChipAv">
      ✓ Available: <?= $total_available ?>
    </span>
    <span class="db-chip db-chip-dump" id="topChipDump">
      ⬇ Dumped: <?= $total_dumped_c ?>
    </span>
  </div>
</div>

<div class="db-wrap">

<!-- ── FLASH MESSAGES ─────────────────────────────────── -->
<?php if ($msg): ?>
<div class="db-flash db-flash-ok" id="dbFlash">✅ <?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="db-flash db-flash-err" id="dbFlash">⚠️ <?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<form method="post" id="mainForm">

<!-- ══════════════════════════════════════════════════════
     FULL BINS AVAILABLE
══════════════════════════════════════════════════════ -->
<div class="db-panel db-main-section">
  <div class="db-panel-hdr">
    <span class="db-panel-title">
      Full Bins Available
      <span class="db-panel-badge db-panel-badge-av"><?= $total_available ?> bins</span>
    </span>
    <div class="db-exp-btns">
      <button type="button" class="db-exp-btn" onclick="expandAllAvailable()">Expand All</button>
      <button type="button" class="db-exp-btn" onclick="collapseAllAvailable()">Collapse All</button>
    </div>
  </div>
  <div class="db-panel-body">

    <!-- Filter bar -->
    <div class="db-filter-bar">
      <input id="dumpSearch"        class="db-filter-search" placeholder="🔍 Search grower, variety, lot…">
      <select id="dumpFilterGrower" class="db-filter-select">
        <option value="">All Growers</option>
        <?php foreach ($dump_filter_growers as $g): ?>
          <option value="<?= htmlspecialchars($g['name']) ?>"><?= htmlspecialchars($g['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="dumpFilterVariety" class="db-filter-select">
        <option value="">All Varieties</option>
        <?php foreach ($dump_filter_varieties as $v): ?>
          <option value="<?= htmlspecialchars($v['name']) ?>"><?= htmlspecialchars($v['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="dumpFilterLot" class="db-filter-select">
        <option value="">All Lots</option>
        <?php foreach ($dump_filter_lots as $l): ?>
          <option value="<?= htmlspecialchars($l['name']) ?>"><?= htmlspecialchars($l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="db-table-wrap">
      <table class="db-table">
        <thead>
          <tr>
            <th style="width:30px;"></th>
            <th>Grower</th><th>Variety</th><th>Type</th>
            <th>Lot</th><th>Date</th><th>Qty</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($groups_available)): ?>
          <?php foreach ($groups_available as $g):
            $gid    = (int)$g['group_id'];
            $grower = $g['grower']   ?? '';
            $var    = $g['variety']  ?? '';
            $type   = $g['type']     ?? '';
            $lot    = $g['lot']      ?? '';
            $date   = $g['date']     ?? '';
            $qty    = (int)$g['total_bins'];

            // Age badge
            try {
                $today   = new DateTime();
                $binDate = new DateTime($date ?: 'today');
                $diff    = (int)$today->diff($binDate)->days;
            } catch(\Exception $e) { $diff = 0; }
            if ($diff === 0)     { $ageClass = 'db-age-today';  $ageLabel = 'Today'; }
            elseif ($diff <= 2)  { $ageClass = 'db-age-recent'; $ageLabel = $diff.'d ago'; }
            else                 { $ageClass = 'db-age-old';    $ageLabel = $diff.'d ago'; }

            // Qty badge
            if ($qty <= 2)      $qClass = 'db-qty-low';
            elseif ($qty <= 5)  $qClass = 'db-qty-mid';
            else                $qClass = 'db-qty-high';

            // Detail bins
            $res_bins = $mysqli->query("
                SELECT bi.*, gp.name AS grower, vl.name AS variety, tl.name AS type
                FROM bins_ingresso bi
                LEFT JOIN growers_list   gp ON bi.grower_id  = gp.id
                LEFT JOIN varieties_list vl ON bi.variety_id = vl.id
                LEFT JOIN bin_types_list tl ON bi.type_id    = tl.id
                WHERE bi.group_id = {$gid} AND bi.status = 'AVAILABLE'
                ORDER BY bi.id ASC
            ");
            $bins = $res_bins ? $res_bins->fetch_all(MYSQLI_ASSOC) : [];
          ?>
          <!-- GROUP ROW AVAILABLE -->
          <tr class="db-group-row db-group-row-av"
              onclick="toggleAvailable(<?= $gid ?>)"
              data-grower="<?= htmlspecialchars($grower) ?>"
              data-variety="<?= htmlspecialchars($var) ?>"
              data-lot="<?= htmlspecialchars($lot) ?>">
            <td><span class="db-arrow" id="arrowA_<?= $gid ?>">▶</span></td>
            <td id="gA_grower_<?= $gid ?>"><?= htmlspecialchars($grower) ?></td>
            <td id="gA_variety_<?= $gid ?>"><?= htmlspecialchars($var) ?></td>
            <td id="gA_type_<?= $gid ?>"><?= htmlspecialchars($type) ?></td>
            <td id="gA_lot_<?= $gid ?>"><?= htmlspecialchars($lot) ?></td>
            <td id="gA_date_<?= $gid ?>">
              <?= htmlspecialchars($date) ?>
              <span class="db-age <?= $ageClass ?> ms-1"><?= $ageLabel ?></span>
            </td>
            <td><span class="db-qty <?= $qClass ?>" id="gA_qty_<?= $gid ?>"><?= $qty ?></span></td>
            <td id="gA_actions_<?= $gid ?>">
              <div class="db-act" onclick="event.stopPropagation();">
                <button type="button" class="db-btn db-btn-edit"
                        onclick="editGroupInline('A',<?= $gid ?>)">✏ Edit</button>
                <button type="button" class="db-btn db-btn-dump"
                        onclick="dbDumpGroup(<?= $gid ?>)">⬇ Dump Group</button>
                <?php if ($role === 'admin'): ?>
                <button type="button" class="db-btn db-btn-del"
                        onclick="dbDeleteGroup(<?= $gid ?>)">🗑 Delete</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>

          <!-- DETAIL ROW AVAILABLE -->
          <tr id="groupA_<?= $gid ?>" class="db-detail-row-av" style="display:none;">
            <td colspan="8" style="padding:0;">
              <?php if (!empty($bins)): ?>
              <div class="db-detail-wrap">
                <table class="db-detail-table">
                  <thead>
                    <tr><th>ID</th><th>Barcode</th><th>Grower</th><th>Variety</th>
                        <th>Type</th><th>Lot</th><th>Date</th><th>Actions</th>
                        <?php if ($role === 'admin'): ?><th>Del</th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($bins as $b):
                    $bid    = (int)$b['id'];
                    $barcode = 'FBIN-' . str_pad($bid, 6, '0', STR_PAD_LEFT);
                  ?>
                    <tr id="bin_row_<?= $bid ?>">
                      <td><?= $bid ?></td>
                      <td><span class="db-bc"><?= htmlspecialchars($barcode) ?></span></td>
                      <td id="b_grower_<?= $bid ?>"><?= htmlspecialchars($b['grower']  ?? '') ?></td>
                      <td id="b_variety_<?= $bid ?>"><?= htmlspecialchars($b['variety'] ?? '') ?></td>
                      <td id="b_type_<?= $bid ?>"><?= htmlspecialchars($b['type']    ?? '') ?></td>
                      <td id="b_lot_<?= $bid ?>"><?= htmlspecialchars($b['lot']     ?? '') ?></td>
                      <td id="b_date_<?= $bid ?>"><?= htmlspecialchars($b['date']    ?? '') ?></td>
                      <td id="b_actions_<?= $bid ?>">
                        <div class="db-act" onclick="event.stopPropagation();">
                          <button type="button" class="db-btn db-btn-edit"
                                  onclick="editBinInline(<?= $bid ?>)">✏ Edit</button>
                          <button type="button" class="db-btn db-btn-dump"
                                  onclick="dbDumpSingle(<?= $bid ?>)">⬇ Dump</button>
                        </div>
                      </td>
                      <?php if ($role === 'admin'): ?>
                      <td onclick="event.stopPropagation();">
                        <button type="button" class="db-btn db-btn-del"
                                onclick="dbDeleteSingle(<?= $bid ?>)">🗑</button>
                      </td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php else: ?>
                <div class="db-detail-wrap" style="color:#9ca3af;font-size:.82rem;">
                  No AVAILABLE bins in this group.
                </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>

        <?php else: ?>
          <tr class="db-empty-row"><td colspan="8">No AVAILABLE groups found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div><!-- /available panel -->


<!-- ══════════════════════════════════════════════════════
     DUMPED BINS
══════════════════════════════════════════════════════ -->
<div class="db-panel db-main-section">
  <div class="db-panel-hdr">
    <span class="db-panel-title">
      Dumped Bins
      <span class="db-panel-badge db-panel-badge-dump"><?= $total_dumped_c ?> bins</span>
    </span>
    <div class="db-exp-btns">
      <button type="button" class="db-exp-btn" onclick="expandAllDumped()">Expand All</button>
      <button type="button" class="db-exp-btn" onclick="collapseAllDumped()">Collapse All</button>
    </div>
  </div>
  <div class="db-panel-body">
    <div class="db-table-wrap">
      <table class="db-table">
        <thead>
          <tr>
            <th style="width:30px;"></th>
            <th>Grower</th><th>Variety</th><th>Type</th>
            <th>Lot</th><th>Date</th><th>Qty</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($groups_dumped)): ?>
          <?php foreach ($groups_dumped as $g):
            $gid   = (int)$g['group_id'];
            $grower = $g['grower']  ?? '';
            $var    = $g['variety'] ?? '';
            $type   = $g['type']    ?? '';
            $lot    = $g['lot']     ?? '';
            $date   = $g['date']    ?? '';
            $qty    = (int)$g['total_bins'];

            try {
                $today   = new DateTime();
                $binDate = new DateTime($date ?: 'today');
                $diff    = (int)$today->diff($binDate)->days;
            } catch(\Exception $e) { $diff = 0; }
            if ($diff === 0)    { $ageClass = 'db-age-today';  $ageLabel = 'Today'; }
            elseif ($diff <= 2) { $ageClass = 'db-age-recent'; $ageLabel = $diff.'d ago'; }
            else                { $ageClass = 'db-age-old';    $ageLabel = $diff.'d ago'; }

            if ($qty <= 2)     $qClass = 'db-qty-low';
            elseif ($qty <= 5) $qClass = 'db-qty-mid';
            else               $qClass = 'db-qty-high';

            $res_bins = $mysqli->query("
                SELECT bi.*, gp.name AS grower, vl.name AS variety, tl.name AS type
                FROM bins_ingresso bi
                LEFT JOIN growers_list   gp ON bi.grower_id  = gp.id
                LEFT JOIN varieties_list vl ON bi.variety_id = vl.id
                LEFT JOIN bin_types_list tl ON bi.type_id    = tl.id
                WHERE bi.group_id = {$gid} AND bi.status = 'DUMPED'
                ORDER BY bi.id ASC
            ");
            $bins = $res_bins ? $res_bins->fetch_all(MYSQLI_ASSOC) : [];
          ?>
          <!-- GROUP ROW DUMPED -->
          <tr class="db-group-row db-group-row-dumped"
              onclick="toggleDumped(<?= $gid ?>)"
              data-grower="<?= htmlspecialchars($grower) ?>"
              data-variety="<?= htmlspecialchars($var) ?>"
              data-lot="<?= htmlspecialchars($lot) ?>">
            <td><span class="db-arrow" id="arrowD_<?= $gid ?>">▶</span></td>
            <td id="gD_grower_<?= $gid ?>"><?= htmlspecialchars($grower) ?></td>
            <td id="gD_variety_<?= $gid ?>"><?= htmlspecialchars($var) ?></td>
            <td id="gD_type_<?= $gid ?>"><?= htmlspecialchars($type) ?></td>
            <td id="gD_lot_<?= $gid ?>"><?= htmlspecialchars($lot) ?></td>
            <td id="gD_date_<?= $gid ?>">
              <?= htmlspecialchars($date) ?>
              <span class="db-age <?= $ageClass ?> ms-1"><?= $ageLabel ?></span>
            </td>
            <td><span class="db-qty <?= $qClass ?>"><?= $qty ?></span></td>
            <td id="gD_actions_<?= $gid ?>">
              <div class="db-act" onclick="event.stopPropagation();">
                <button type="button" class="db-btn db-btn-edit"
                        onclick="editGroupInline('D',<?= $gid ?>)">✏ Edit</button>
                <button type="button" class="db-btn db-btn-undo"
                        onclick="dbUndoGroup(<?= $gid ?>)">↩ Undo Group</button>
                <?php if ($role === 'admin'): ?>
                <button type="button" class="db-btn db-btn-del"
                        onclick="dbDeleteGroup(<?= $gid ?>)">🗑 Delete</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>

          <!-- DETAIL ROW DUMPED -->
          <tr id="groupD_<?= $gid ?>" class="db-detail-row-dump" style="display:none;">
            <td colspan="8" style="padding:0;">
              <?php if (!empty($bins)): ?>
              <div class="db-detail-wrap">
                <table class="db-detail-table">
                  <thead>
                    <tr><th>ID</th><th>Barcode</th><th>Grower</th><th>Variety</th>
                        <th>Type</th><th>Lot</th><th>Date</th><th>Actions</th>
                        <?php if ($role === 'admin'): ?><th>Del</th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($bins as $b):
                    $bid    = (int)$b['id'];
                    $barcode = 'FBIN-' . str_pad($bid, 6, '0', STR_PAD_LEFT);
                  ?>
                    <tr id="bind_row_<?= $bid ?>">
                      <td><?= $bid ?></td>
                      <td><span class="db-bc"><?= htmlspecialchars($barcode) ?></span></td>
                      <td id="bd_grower_<?= $bid ?>"><?= htmlspecialchars($b['grower']  ?? '') ?></td>
                      <td id="bd_variety_<?= $bid ?>"><?= htmlspecialchars($b['variety'] ?? '') ?></td>
                      <td id="bd_type_<?= $bid ?>"><?= htmlspecialchars($b['type']    ?? '') ?></td>
                      <td id="bd_lot_<?= $bid ?>"><?= htmlspecialchars($b['lot']     ?? '') ?></td>
                      <td id="bd_date_<?= $bid ?>"><?= htmlspecialchars($b['date']    ?? '') ?></td>
                      <td id="bd_actions_<?= $bid ?>">
                        <div class="db-act" onclick="event.stopPropagation();">
                          <button type="button" class="db-btn db-btn-undo"
                                  onclick="dbUndoSingle(<?= $bid ?>)">↩ Undo</button>
                        </div>
                      </td>
                      <?php if ($role === 'admin'): ?>
                      <td onclick="event.stopPropagation();">
                        <button type="button" class="db-btn db-btn-del"
                                onclick="dbDeleteSingle(<?= $bid ?>)">🗑</button>
                      </td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php else: ?>
                <div class="db-detail-wrap" style="color:#9ca3af;font-size:.82rem;">
                  No DUMPED bins in this group.
                </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>

        <?php else: ?>
          <tr class="db-empty-row"><td colspan="8">No DUMPED groups found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div><!-- /dumped panel -->

</form><!-- /mainForm -->

<!-- ── SELECT TEMPLATES (for inline edit) ──────────────── -->
<template id="tpl-grower-select">
  <select class="db-ie-select" name="_grower_sel">
    <option value="">— Grower —</option>
    <?php foreach ($growers as $row): ?>
    <option value="<?= htmlspecialchars($row['name']) ?>"><?= htmlspecialchars($row['name']) ?></option>
    <?php endforeach; ?>
  </select>
</template>
<template id="tpl-variety-select">
  <select class="db-ie-select" name="_variety_sel">
    <option value="">— Variety —</option>
    <?php foreach ($varieties as $row): ?>
    <option value="<?= htmlspecialchars($row['name']) ?>"><?= htmlspecialchars($row['name']) ?></option>
    <?php endforeach; ?>
  </select>
</template>
<template id="tpl-type-select">
  <select class="db-ie-select" name="_type_sel">
    <option value="">— Type —</option>
    <?php foreach ($types as $row): ?>
    <option value="<?= htmlspecialchars($row['name']) ?>"><?= htmlspecialchars($row['name']) ?></option>
    <?php endforeach; ?>
  </select>
</template>



<!-- ══════════════════════════════════════════════════════
     MOVEMENT LOG
══════════════════════════════════════════════════════ -->
<div class="db-panel db-movement-panel db-main-section">
  <div class="db-panel db-main-section-hdr db-movement-hdr" onclick="dbToggleMovementLog()">
    <div>
      <span class="db-panel-title">
        📜 Movement Log
        <span id="dbMovementCount" class="db-panel-badge db-movement-count">
          <?= count($dumpMovementRows); ?> entries
        </span>
      </span>
      <div class="db-movement-sub">
        All dumping activity — group dumps, single dumps, Dumping APK operations and undo operations.
      </div>
    </div>

    <div class="db-movement-tools" onclick="event.stopPropagation();">
      <select id="dbLogGrower" class="db-filter-select">
        <option value="">All Growers</option>
        <?php foreach ($growers as $g): ?>
          <option value="<?= htmlspecialchars($g['name'] ?? '', ENT_QUOTES) ?>">
            <?= htmlspecialchars($g['name'] ?? '') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select id="dbLogVariety" class="db-filter-select">
        <option value="">All Varieties</option>
        <?php foreach ($varieties as $v): ?>
          <option value="<?= htmlspecialchars($v['name'] ?? '', ENT_QUOTES) ?>">
            <?= htmlspecialchars($v['name'] ?? '') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select id="dbLogType" class="db-filter-select">
        <option value="">All Types</option>
        <?php foreach ($types as $t): ?>
          <option value="<?= htmlspecialchars($t['name'] ?? '', ENT_QUOTES) ?>">
            <?= htmlspecialchars($t['name'] ?? '') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select id="dbLogAction" class="db-filter-select">
        <option value="">All Actions</option>
        <option value="dump">Dumps</option>
        <option value="undo">Undo</option>
      </select>

      <select id="dbLogDatePreset" class="db-filter-select">
        <option value="all">All dates</option>
        <option value="today">Today</option>
        <option value="7">Last 7 days</option>
        <option value="30">Last 30 days</option>
        <option value="custom">Custom range</option>
      </select>

      <input type="date" id="dbLogDateFrom" class="db-filter-select db-log-date-custom" style="display:none;">
      <input type="date" id="dbLogDateTo" class="db-filter-select db-log-date-custom" style="display:none;">

      <select id="dbLogLimit" class="db-filter-select">
        <option value="20">Last 20</option>
        <option value="50">Last 50</option>
        <option value="100">Last 100</option>
        <option value="all">All</option>
      </select>

      <button id="dbExportMovementCsv" type="button" class="db-exp-btn">⬇ CSV</button>
      <?php if ($role === 'admin'): ?>
        <button type="button" class="db-exp-btn" onclick="dbSelectVisibleMovements()">☑ Select visible</button>
        <button type="button" class="db-exp-btn" onclick="dbDeleteSelectedMovements()" style="color:#b91c1c;border-color:#fecaca;">🗑 Delete selected</button>
      <?php endif; ?>
      <button id="dbMovementToggleBtn" type="button" class="db-exp-btn db-movement-toggle">▼ Show</button>
    </div>
  </div>

  <div id="dbMovementBody" class="db-panel-body" style="display:none;">
    <div class="db-table-wrap">
      <table class="db-table db-movement-table">
        <thead>
          <tr>
            <?php if ($role === 'admin'): ?><th style="width:34px;text-align:center;"><input type="checkbox" id="dbSelectAllVisible" onchange="dbToggleVisibleMovementChecks(this.checked)" title="Select visible"></th><?php endif; ?>
            <th>Date / Time</th>
            <th>Grower</th>
            <th>Variety</th>
            <th>Type</th>
            <th>Lot</th>
            <th style="text-align:right;">Δ Qty</th>
            <th>Action</th>
            <?php if ($role === 'admin'): ?><th style="width:54px;text-align:center;">Delete</th><?php endif; ?>
          </tr>
        </thead>
        <tbody id="dbMovementTbody">
        <?php foreach ($dumpMovementRows as $i => $lr):
          $delta = (int)$lr['qty_change'];
          $reason = (string)($lr['reason'] ?? '');
          $actionKind = stripos($reason, 'undo') !== false ? 'undo' : 'dump';
        ?>
          <tr
            data-log-id="<?= (int)$lr['id'] ?>"
            data-grower="<?= htmlspecialchars($lr['grower'] ?? '', ENT_QUOTES) ?>"
            data-variety="<?= htmlspecialchars($lr['variety'] ?? '', ENT_QUOTES) ?>"
            data-type="<?= htmlspecialchars($lr['type'] ?? '', ENT_QUOTES) ?>"
            data-action="<?= $actionKind ?>"
            data-date="<?= htmlspecialchars(substr((string)$lr['created_at'],0,10), ENT_QUOTES) ?>">
            <?php if ($role === 'admin'): ?><td style="text-align:center;"><input type="checkbox" class="dbMovementCheck" value="<?= (int)$lr['id'] ?>" onclick="event.stopPropagation()"></td><?php endif; ?>
            <td class="db-movement-date"><?= htmlspecialchars($lr['created_at'] ?? '') ?></td>
            <td><?= htmlspecialchars($lr['grower'] ?? '') ?></td>
            <td><?= htmlspecialchars($lr['variety'] ?? '') ?></td>
            <td><?= htmlspecialchars($lr['type'] ?? '') ?></td>
            <td><?= htmlspecialchars($lr['lot'] ?? '') ?></td>
            <td style="text-align:right;">
              <span class="db-movement-delta <?= $delta >= 0 ? 'db-movement-plus' : 'db-movement-minus' ?>">
                <?= $delta >= 0 ? '+' : '' ?><?= $delta ?>
              </span>
            </td>
            <td>
              <span class="db-movement-action db-movement-action-<?= $actionKind ?>">
                <?= htmlspecialchars($reason) ?>
              </span>
            </td>
            <?php if ($role === 'admin'): ?>
              <td style="text-align:center;"><button type="button" class="db-exp-btn" style="padding:3px 7px;color:#b91c1c;" title="Delete event" onclick="dbDeleteOneMovement(<?= (int)$lr['id'] ?>)">🗑</button></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($dumpMovementRows)): ?>
          <tr id="dbNoMovement" class="db-empty-row">
            <td colspan="<?= $role === 'admin' ? 9 : 7 ?>">No dumping movements yet.</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div><!-- /db-wrap -->



<script>
/* ════════════════════════════════════════════════════════
   HELPERS
════════════════════════════════════════════════════════ */

function dbVisibleMovementRows(){
  return [...document.querySelectorAll('#dbMovementTbody tr[data-log-id]')].filter(tr=>tr.style.display!=='none');
}
function dbToggleVisibleMovementChecks(checked){
  dbVisibleMovementRows().forEach(tr=>{const cb=tr.querySelector('.dbMovementCheck');if(cb)cb.checked=checked;});
}
function dbSelectVisibleMovements(){
  const rows=dbVisibleMovementRows();
  const allSelected=rows.length>0&&rows.every(tr=>tr.querySelector('.dbMovementCheck')?.checked);
  dbToggleVisibleMovementChecks(!allSelected);
  const all=document.getElementById('dbSelectAllVisible');if(all)all.checked=!allSelected;
}
async function dbDeleteMovementIds(ids){
  ids=[...new Set(ids.map(Number).filter(v=>v>0))];
  if(!ids.length){alert('Select at least one movement event.');return;}
  if(!confirm(`Delete ${ids.length} dumping movement event${ids.length===1?'':'s'}?\n\nThis deletes Movement Log history only. Inventory is not changed.`))return;
  const fd=new FormData();fd.append('movement_log_delete','1');ids.forEach(id=>fd.append('ids[]',String(id)));
  try{
    const r=await fetch('dumping_bins.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
    const data=await r.json();if(!data.ok)throw new Error(data.error||'Delete failed.');
    alert(`Deleted ${data.deleted||0} event(s).`);location.reload();
  }catch(e){alert(e.message||String(e));}
}
function dbDeleteSelectedMovements(){dbDeleteMovementIds([...document.querySelectorAll('.dbMovementCheck:checked')].map(x=>x.value));}
function dbDeleteOneMovement(id){dbDeleteMovementIds([id]);}

function dbEsc(s){ const d=document.createElement('div');d.textContent=s;return d.innerHTML; }

function dbFlash(msg, ok=true){
  let el = document.getElementById('dbFlash');
  if (!el){
    el = document.createElement('div');
    el.id = 'dbFlash';
    document.querySelector('.db-wrap').prepend(el);
  }
  el.className = 'db-flash ' + (ok ? 'db-flash-ok' : 'db-flash-err');
  el.innerHTML  = (ok ? '✅ ' : '⚠️ ') + dbEsc(msg);
  el.style.display = '';
  clearTimeout(el._tid);
  el._tid = setTimeout(() => el.style.display = 'none', 5000);
}

function dbPost(data){
  const fd = new FormData();
  for(const [k,v] of Object.entries(data)) fd.append(k,v);

  return fetch('dumping_bins.php',{
    method:'POST',
    headers:{'X-Requested-With':'XMLHttpRequest'},
    body:fd
  }).then(async r=>{
    const raw=await r.text();
    let result;
    try{
      result=JSON.parse(raw);
    }catch(e){
      throw new Error(raw.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim() || 'Invalid server response');
    }
    if(!r.ok || !result.ok){
      throw new Error(result.error || result.message || 'Operation failed');
    }
    return result;
  });
}

/* ════════════════════════════════════════════════════════
   TOGGLE GROUP ROWS
════════════════════════════════════════════════════════ */
function toggleAvailable(id){
  const row  = document.getElementById('groupA_'+id);
  const icon = document.getElementById('arrowA_'+id);
  if (!row) return;
  const open = row.style.display==='table-row';
  row.style.display = open ? 'none' : 'table-row';
  if (icon) icon.classList.toggle('open', !open);
}
function toggleDumped(id){
  const row  = document.getElementById('groupD_'+id);
  const icon = document.getElementById('arrowD_'+id);
  if (!row) return;
  const open = row.style.display==='table-row';
  row.style.display = open ? 'none' : 'table-row';
  if (icon) icon.classList.toggle('open', !open);
}
function expandAllAvailable(){
  document.querySelectorAll('.db-detail-row-av').forEach(r=>{ r.style.display='table-row'; });
  document.querySelectorAll('[id^="arrowA_"]').forEach(a=>{ a.classList.add('open'); });
}
function collapseAllAvailable(){
  document.querySelectorAll('.db-detail-row-av').forEach(r=>{ r.style.display='none'; });
  document.querySelectorAll('[id^="arrowA_"]').forEach(a=>{ a.classList.remove('open'); });
}
function expandAllDumped(){
  document.querySelectorAll('.db-detail-row-dump').forEach(r=>{ r.style.display='table-row'; });
  document.querySelectorAll('[id^="arrowD_"]').forEach(a=>{ a.classList.add('open'); });
}
function collapseAllDumped(){
  document.querySelectorAll('.db-detail-row-dump').forEach(r=>{ r.style.display='none'; });
  document.querySelectorAll('[id^="arrowD_"]').forEach(a=>{ a.classList.remove('open'); });
}

/* ════════════════════════════════════════════════════════
   ACTION HELPERS (DUMP / UNDO / DELETE via AJAX POST)
════════════════════════════════════════════════════════ */
function dbDumpGroup(gid){
  if (!confirm('Dump ALL AVAILABLE bins in this group?')) return;
  dbPost({action:'dump_group',group_id:gid}).then(()=>location.reload()).catch(err=>alert(err.message || 'Operation failed'));
}
function dbDumpSingle(id){
  if (!confirm('Dump this bin?')) return;
  dbPost({action:'dump_single',id}).then(()=>location.reload()).catch(err=>alert(err.message || 'Operation failed'));
}
function dbUndoGroup(gid){
  if (!confirm('Undo dump for all DUMPED bins in this group?')) return;
  dbPost({action:'undo_group',group_id:gid}).then(()=>location.reload()).catch(err=>alert(err.message || 'Operation failed'));
}
function dbUndoSingle(id){
  if (!confirm('Undo dump for this bin?')) return;
  dbPost({action:'undo_single',id}).then(()=>location.reload()).catch(err=>alert(err.message || 'Operation failed'));
}
function dbDeleteGroup(gid){
  if (!confirm('Delete this group and ALL its bins? This cannot be undone.')) return;
  dbPost({action:'delete_group',group_id:gid}).then(()=>location.reload()).catch(err=>alert(err.message || 'Operation failed'));
}
function dbDeleteSingle(id){
  if (!confirm('Delete this bin? This cannot be undone.')) return;
  dbPost({action:'delete_single',id}).then(()=>location.reload()).catch(err=>alert(err.message || 'Operation failed'));
}

/* ════════════════════════════════════════════════════════
   INLINE EDIT (group / single bin)
   – grower / variety / type use <select> from templates
════════════════════════════════════════════════════════ */
function dbSelectFromTemplate(tplId, currentValue){
  const tpl = document.getElementById(tplId);
  if (!tpl) return null;
  const sel = tpl.content.firstElementChild.cloneNode(true);
  for(const opt of sel.options){
    if (opt.value === currentValue){ opt.selected=true; break; }
  }
  return sel;
}

function editGroupInline(prefix, gid){
  const pre = (prefix==='A') ? 'gA_' : 'gD_';

  const gEl = document.getElementById(pre+'grower_'+gid);
  const vEl = document.getElementById(pre+'variety_'+gid);
  const tEl = document.getElementById(pre+'type_'+gid);
  const lEl = document.getElementById(pre+'lot_'+gid);
  const dEl = document.getElementById(pre+'date_'+gid);

  // Strip age badge text from date cell
  const dateTxt = (dEl?.firstChild?.textContent || '').trim();

  const gSel = dbSelectFromTemplate('tpl-grower-select',  gEl?.innerText?.trim()||'');
  const vSel = dbSelectFromTemplate('tpl-variety-select', vEl?.innerText?.trim()||'');
  const tSel = dbSelectFromTemplate('tpl-type-select',    tEl?.innerText?.trim()||'');

  if (gEl && gSel){ gEl.innerHTML=''; gEl.appendChild(gSel); gSel.id='edit_grower_'+gid; }
  if (vEl && vSel){ vEl.innerHTML=''; vEl.appendChild(vSel); vSel.id='edit_variety_'+gid; }
  if (tEl && tSel){ tEl.innerHTML=''; tEl.appendChild(tSel); tSel.id='edit_type_'+gid; }
  if (lEl) lEl.innerHTML=`<input class="db-ie-input" id="edit_lot_${gid}" value="${dbEsc(lEl.innerText.trim())}">`;
  if (dEl) dEl.innerHTML=`<input type="date" class="db-ie-input" id="edit_date_${gid}" value="${dateTxt}">`;

  const actEl = document.getElementById(pre+'actions_'+gid);
  if (actEl) actEl.innerHTML=`
    <div class="db-act">
      <button type="button" class="db-btn db-btn-save"   onclick="saveGroupInline(${gid});event.stopPropagation();">💾 Save</button>
      <button type="button" class="db-btn db-btn-cancel" onclick="location.reload();event.stopPropagation();">✕</button>
    </div>`;
}

function saveGroupInline(gid){
  const fd = new FormData();
  fd.append('action','edit_group_inline');
  fd.append('group_id', gid);
  fd.append('grower',  document.getElementById('edit_grower_'+gid)?.value||'');
  fd.append('variety', document.getElementById('edit_variety_'+gid)?.value||'');
  fd.append('type',    document.getElementById('edit_type_'+gid)?.value||'');
  fd.append('lot',     document.getElementById('edit_lot_'+gid)?.value||'');
  fd.append('date',    document.getElementById('edit_date_'+gid)?.value||'');
  fetch('dumping_bins.php',{method:'POST',body:fd}).then(()=>location.reload());
}

function editBinInline(bid){
  const gEl = document.getElementById('b_grower_'+bid);
  const vEl = document.getElementById('b_variety_'+bid);
  const tEl = document.getElementById('b_type_'+bid);
  const lEl = document.getElementById('b_lot_'+bid);
  const dEl = document.getElementById('b_date_'+bid);

  const gSel = dbSelectFromTemplate('tpl-grower-select',  gEl?.innerText?.trim()||'');
  const vSel = dbSelectFromTemplate('tpl-variety-select', vEl?.innerText?.trim()||'');
  const tSel = dbSelectFromTemplate('tpl-type-select',    tEl?.innerText?.trim()||'');

  if (gEl && gSel){ gEl.innerHTML=''; gEl.appendChild(gSel); gSel.id='editb_grower_'+bid; }
  if (vEl && vSel){ vEl.innerHTML=''; vEl.appendChild(vSel); vSel.id='editb_variety_'+bid; }
  if (tEl && tSel){ tEl.innerHTML=''; tEl.appendChild(tSel); tSel.id='editb_type_'+bid; }
  if (lEl) lEl.innerHTML=`<input class="db-ie-input" id="editb_lot_${bid}" value="${dbEsc(lEl.innerText.trim())}">`;
  if (dEl) dEl.innerHTML=`<input type="date" class="db-ie-input" id="editb_date_${bid}" value="${dEl.innerText.trim()}">`;

  const actEl = document.getElementById('b_actions_'+bid);
  if (actEl) actEl.innerHTML=`
    <div class="db-act">
      <button type="button" class="db-btn db-btn-save"   onclick="saveBinInline(${bid});event.stopPropagation();">💾 Save</button>
      <button type="button" class="db-btn db-btn-cancel" onclick="location.reload();event.stopPropagation();">✕</button>
    </div>`;
}

function saveBinInline(bid){
  const fd = new FormData();
  fd.append('action','edit_bin_inline');
  fd.append('id', bid);
  fd.append('grower',  document.getElementById('editb_grower_'+bid)?.value||'');
  fd.append('variety', document.getElementById('editb_variety_'+bid)?.value||'');
  fd.append('type',    document.getElementById('editb_type_'+bid)?.value||'');
  fd.append('lot',     document.getElementById('editb_lot_'+bid)?.value||'');
  fd.append('date',    document.getElementById('editb_date_'+bid)?.value||'');
  fetch('dumping_bins.php',{method:'POST',body:fd}).then(()=>location.reload());
}

/* ════════════════════════════════════════════════════════
   FILTERS (search + dropdowns)
════════════════════════════════════════════════════════ */
function applyDumpingFilters(){
  const s  = (document.getElementById('dumpSearch')?.value||'').toLowerCase().trim();
  const gv = (document.getElementById('dumpFilterGrower')?.value||'').toLowerCase();
  const vv = (document.getElementById('dumpFilterVariety')?.value||'').toLowerCase();
  const lv = (document.getElementById('dumpFilterLot')?.value||'').toLowerCase();

  document.querySelectorAll('.db-group-row').forEach(row=>{
    const g = (row.dataset.grower||'').toLowerCase();
    const v = (row.dataset.variety||'').toLowerCase();
    const l = (row.dataset.lot||'').toLowerCase();
    let ok=true;
    if (s  && !(g+' '+v+' '+l).includes(s)) ok=false;
    if (gv && g!==gv) ok=false;
    if (vv && v!==vv) ok=false;
    if (lv && l!==lv) ok=false;
    row.style.display = ok?'':'none';
    // Also hide detail row
    const arrow = row.querySelector('.db-arrow');
    if (arrow){
      const m = arrow.id.match(/^arrow([AD])_(\d+)$/);
      if (m){
        const dr = document.getElementById('group'+m[1]+'_'+m[2]);
        if (dr && !ok) dr.style.display='none';
      }
    }
  });
}

function initDumpingFilters(){
  ['dumpSearch','dumpFilterGrower','dumpFilterVariety','dumpFilterLot'].forEach(id=>{
    const el = document.getElementById(id);
    if (el) el.addEventListener(el.tagName==='INPUT'?'input':'change', applyDumpingFilters);
  });
}

/* ════════════════════════════════════════════════════════
   AUTO-DISMISS FLASH (5 s)
════════════════════════════════════════════════════════ */
function autoDismissFlash(){
  const el = document.getElementById('dbFlash');
  if (el) setTimeout(()=>el.style.display='none', 5000);
}

/* ════════════════════════════════════════════════════════
   INIT
════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function(){
  initDumpingFilters();
  applyDumpingFilters();
  autoDismissFlash();

});

/* ════════════════════════════════════════════════════════
   DUMPING MOVEMENT LOG — same controls as Empty/Full Bins
════════════════════════════════════════════════════════ */
let dbMovementOpen = false;

function dbToggleMovementLog(){
  dbMovementOpen = !dbMovementOpen;
  const body = document.getElementById('dbMovementBody');
  const btn  = document.getElementById('dbMovementToggleBtn');
  if (body) body.style.display = dbMovementOpen ? '' : 'none';
  if (btn) btn.textContent = dbMovementOpen ? '▲ Hide' : '▼ Show';
  if (dbMovementOpen) dbApplyMovementFilters();
}

function dbToggleCustomDates(){
  const custom = (document.getElementById('dbLogDatePreset')?.value || '') === 'custom';
  const from = document.getElementById('dbLogDateFrom');
  const to = document.getElementById('dbLogDateTo');
  if (from) from.style.display = custom ? '' : 'none';
  if (to) to.style.display = custom ? '' : 'none';
}

function dbMovementDateOk(rowDate){
  const mode = document.getElementById('dbLogDatePreset')?.value || 'all';
  if (!rowDate || mode === 'all') return true;

  const today = new Date();
  const rd = new Date(rowDate + 'T00:00:00');

  if (mode === 'today') return rd.toDateString() === today.toDateString();
  if (mode === '7' || mode === '30') return (today - rd) / 86400000 <= Number(mode);

  if (mode === 'custom') {
    const from = document.getElementById('dbLogDateFrom')?.value;
    const to   = document.getElementById('dbLogDateTo')?.value;
    if (from && rowDate < from) return false;
    if (to && rowDate > to) return false;
  }
  return true;
}

function dbApplyMovementFilters(){
  dbToggleCustomDates();

  const grower  = (document.getElementById('dbLogGrower')?.value || '').toLowerCase();
  const variety = (document.getElementById('dbLogVariety')?.value || '').toLowerCase();
  const type    = (document.getElementById('dbLogType')?.value || '').toLowerCase();
  const action  = document.getElementById('dbLogAction')?.value || '';
  const limit   = document.getElementById('dbLogLimit')?.value || '20';

  let shown = 0;
  document.querySelectorAll('#dbMovementTbody tr').forEach(row => {
    if (row.id === 'dbNoMovement') return;

    let ok = true;
    if (grower && (row.dataset.grower || '').toLowerCase() !== grower) ok = false;
    if (variety && (row.dataset.variety || '').toLowerCase() !== variety) ok = false;
    if (type && (row.dataset.type || '').toLowerCase() !== type) ok = false;
    if (action && (row.dataset.action || '') !== action) ok = false;
    if (!dbMovementDateOk(row.dataset.date || '')) ok = false;

    if (ok && limit !== 'all' && shown >= Number(limit)) ok = false;

    row.style.display = ok ? '' : 'none';
    if (ok) shown++;
  });
}

function dbExportMovementCsv(){
  const rows = [...document.querySelectorAll('#dbMovementTbody tr')]
    .filter(r => r.id !== 'dbNoMovement' && r.style.display !== 'none');

  if (!rows.length) {
    alert('No rows to export');
    return;
  }

  const lines = [['Date/Time','Grower','Variety','Type','Lot','Delta Qty','Action'].join(',')];

  rows.forEach(r => {
    const cells = [...r.children].map(td =>
      '"' + (td.innerText || '').replace(/"/g,'""') + '"'
    );
    lines.push(cells.join(','));
  });

  const blob = new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8;'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'dumping_bins_log_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  URL.revokeObjectURL(url);
}

document.addEventListener('DOMContentLoaded', function(){
  ['dbLogGrower','dbLogVariety','dbLogType','dbLogAction',
   'dbLogDatePreset','dbLogDateFrom','dbLogDateTo','dbLogLimit']
  .forEach(id => document.getElementById(id)?.addEventListener('change', dbApplyMovementFilters));

  document.getElementById('dbExportMovementCsv')?.addEventListener('click', dbExportMovementCsv);

  const lim = document.getElementById('dbLogLimit');
  if (lim) lim.value = localStorage.getItem('db_dump_log_limit') || '20';

  lim?.addEventListener('change', function(){
    localStorage.setItem('db_dump_log_limit', this.value);
  });

  dbApplyMovementFilters();
});
</script>
