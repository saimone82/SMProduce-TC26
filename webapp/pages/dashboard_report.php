<?php
/**
 * dashboard_report.php  — SM Produce LTD
 * Redesigned: clean sections, coherent filters, no chart+table duplication.
 */
require_once __DIR__ . '/../config/user_functions.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireLogin();
require_once __DIR__ . '/../includes/db.php';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* ════════════════════════════════════════════════════════════
   HELPERS
   ════════════════════════════════════════════════════════════ */
function rp_fetch_all(mysqli $conn, string $sql): array {
    $res = $conn->query($sql);
    if (!$res) return [];
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $res->free();
    return $rows;
}
function rp_fetch_value(mysqli $conn, string $sql, $default = 0) {
    $rows = rp_fetch_all($conn, $sql);
    return $rows ? ($rows[0][array_key_first($rows[0])] ?? $default) : $default;
}

/* ════════════════════════════════════════════════════════════
   PARAMETERS & SANITISATION
   ════════════════════════════════════════════════════════════ */
$allowedRanges = ['today', '7d', '30d', 'all', 'custom'];
$range   = in_array($_GET['range'] ?? '', $allowedRanges, true) ? $_GET['range'] : 'today';
$from    = $_GET['from'] ?? date('Y-m-01');
$to      = $_GET['to']   ?? date('Y-m-d');
$grower  = trim($_GET['grower']  ?? '');
$variety = trim($_GET['variety'] ?? '');

$fromSafe = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-01');
$toSafe   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : date('Y-m-d');

$growerSafe  = $conn->real_escape_string($grower);
$varietySafe = $conn->real_escape_string($variety);

/* ────────────────────────────────────────────────────────────
   Build a SINGLE coherent WHERE block for cases
   (scanner_scans s  LEFT JOIN casecodes cc ON cc.serial = s.serial)
   All charts, KPIs and tables that involve cases use $casesWhere.
   ──────────────────────────────────────────────────────────── */
$casesWhere = "WHERE s.serial LIKE 'U%'";

switch ($range) {
    case 'today':  $casesWhere .= " AND DATE(s.scanned_at) = CURDATE()"; break;
    case '7d':     $casesWhere .= " AND s.scanned_at >= NOW() - INTERVAL 7 DAY"; break;
    case '30d':    $casesWhere .= " AND s.scanned_at >= NOW() - INTERVAL 30 DAY"; break;
    case 'custom': $casesWhere .= " AND DATE(s.scanned_at) BETWEEN '$fromSafe' AND '$toSafe'"; break;
    // 'all' → no date clause
}
if ($grower  !== '') $casesWhere .= " AND TRIM(cc.grower)  = '$growerSafe'";
if ($variety !== '') $casesWhere .= " AND TRIM(cc.variety) = '$varietySafe'";

/* WHERE for bins (uses bi.updated_at for dumped, no date for full stock) */
$binsRangeWhere = '';
switch ($range) {
    case 'today':  $binsRangeWhere = " AND DATE(bi.updated_at) = CURDATE()"; break;
    case '7d':     $binsRangeWhere = " AND bi.updated_at >= NOW() - INTERVAL 7 DAY"; break;
    case '30d':    $binsRangeWhere = " AND bi.updated_at >= NOW() - INTERVAL 30 DAY"; break;
    case 'custom': $binsRangeWhere = " AND DATE(bi.updated_at) BETWEEN '$fromSafe' AND '$toSafe'"; break;
}

/* ────────────────────────────────────────────────────────────
   RANGE LABEL
   ──────────────────────────────────────────────────────────── */
$rangeLabels = [
    'today'  => 'Today (' . date('d M Y') . ')',
    '7d'     => 'Last 7 days',
    '30d'    => 'Last 30 days',
    'all'    => 'All time',
    'custom' => "$fromSafe → $toSafe",
];
$rangeLabel  = $rangeLabels[$range];
$generatedAt = date('d M Y, H:i:s');

/* ════════════════════════════════════════════════════════════
   KPIs
   ════════════════════════════════════════════════════════════ */
// Full bins: always current stock (no range makes sense for a stock snapshot)
$kpiFull    = (int) rp_fetch_value($conn, "SELECT COUNT(*) FROM bins_ingresso WHERE status='AVAILABLE'");

// Empty bins: current total quantity
$kpiEmpty   = (int) rp_fetch_value($conn, "SELECT COALESCE(SUM(quantity),0) FROM empty_bins");

// Cases produced: respects full filter (range + grower + variety)
$kpiCases   = (int) rp_fetch_value($conn,
    "SELECT COUNT(*) FROM scanner_scans s
     LEFT JOIN casecodes cc ON cc.serial = s.serial
     $casesWhere"
);

// Dumped bins: respects range filter
$kpiDumped  = (int) rp_fetch_value($conn,
    "SELECT COUNT(*) FROM bins_ingresso bi WHERE bi.status='DUMPED' $binsRangeWhere"
);

/* ════════════════════════════════════════════════════════════
   CASES — breakdown data  (all use $casesWhere consistently)
   ════════════════════════════════════════════════════════════ */

// By Grower (for chart) — subquery avoids ONLY_FULL_GROUP_BY issues
$casesByGrower = rp_fetch_all($conn,
    "SELECT label, COUNT(*) AS qty
     FROM (
         SELECT COALESCE(NULLIF(TRIM(cc.grower),''),'Unknown') AS label
         FROM scanner_scans s
         LEFT JOIN casecodes cc ON cc.serial = s.serial
         $casesWhere
     ) _g
     GROUP BY label
     ORDER BY qty DESC
     LIMIT 12"
);

// By Variety (for chart) — subquery avoids ONLY_FULL_GROUP_BY issues
$casesByVariety = rp_fetch_all($conn,
    "SELECT label, COUNT(*) AS qty
     FROM (
         SELECT COALESCE(NULLIF(TRIM(cc.variety),''),'Unknown') AS label
         FROM scanner_scans s
         LEFT JOIN casecodes cc ON cc.serial = s.serial
         $casesWhere
     ) _v
     GROUP BY label
     ORDER BY qty DESC
     LIMIT 12"
);

// Full detail table: Grower × Variety × Packaging × Size — subquery pattern
$caseDetail = rp_fetch_all($conn,
    "SELECT grower, variety, packaging, size, COUNT(*) AS qty
     FROM (
         SELECT
             COALESCE(NULLIF(TRIM(cc.grower),''),'Unknown')    AS grower,
             COALESCE(NULLIF(TRIM(cc.variety),''),'Unknown')   AS variety,
             COALESCE(NULLIF(TRIM(cc.packaging),''),'—')       AS packaging,
             COALESCE(NULLIF(TRIM(cc.size),''),'—')            AS size
         FROM scanner_scans s
         LEFT JOIN casecodes cc ON cc.serial = s.serial
         $casesWhere
     ) _d
     GROUP BY grower, variety, packaging, size
     ORDER BY qty DESC
     LIMIT 100"
);
$caseDetailTotal = array_sum(array_column($caseDetail, 'qty'));

/* ════════════════════════════════════════════════════════════
   HOURLY production — today only (always today, not filtered by range)
   ════════════════════════════════════════════════════════════ */
$hourlyRows = rp_fetch_all($conn,
    "SELECT DATE_FORMAT(s.scanned_at,'%H') AS hr, COUNT(*) AS qty
     FROM scanner_scans s
     WHERE s.serial LIKE 'U%' AND DATE(s.scanned_at) = CURDATE()
     GROUP BY DATE_FORMAT(s.scanned_at,'%H')
     ORDER BY hr ASC"
);
$hrMap = [];
foreach ($hourlyRows as $r) $hrMap[(int)$r['hr']] = (int)$r['qty'];
$hLabels = $hValues = [];
for ($i = 0; $i < 24; $i++) {
    $hLabels[] = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
    $hValues[] = $hrMap[$i] ?? 0;
}
$hourlyTotal = array_sum($hValues);

/* ════════════════════════════════════════════════════════════
   BINS — full stock by Grower & Variety
   ════════════════════════════════════════════════════════════ */
$fullBinsByGrower = rp_fetch_all($conn,
    "SELECT COALESCE(g.name,'Unknown') AS label, COUNT(*) AS qty
     FROM bins_ingresso bi
     LEFT JOIN growers_list g ON g.id = bi.grower_id
     WHERE bi.status='AVAILABLE'
     GROUP BY g.id, g.name
     ORDER BY qty DESC
     LIMIT 15"
);
$fullBinsByVariety = rp_fetch_all($conn,
    "SELECT COALESCE(v.name,'Unknown') AS label, COUNT(*) AS qty
     FROM bins_ingresso bi
     LEFT JOIN varieties_list v ON v.id = bi.variety_id
     WHERE bi.status='AVAILABLE'
     GROUP BY v.id, v.name
     ORDER BY qty DESC
     LIMIT 15"
);

/* ════════════════════════════════════════════════════════════
   BINS — dumped (range-filtered) by Grower
   ════════════════════════════════════════════════════════════ */
$dumpedByGrower = rp_fetch_all($conn,
    "SELECT COALESCE(g.name,'Unknown') AS label, COUNT(*) AS qty
     FROM bins_ingresso bi
     LEFT JOIN growers_list g ON g.id = bi.grower_id
     WHERE bi.status='DUMPED' $binsRangeWhere
     GROUP BY g.id, g.name
     ORDER BY qty DESC
     LIMIT 15"
);

/* ════════════════════════════════════════════════════════════
   DROPDOWN OPTIONS
   ════════════════════════════════════════════════════════════ */
$growerOptions = rp_fetch_all($conn,
    "SELECT name FROM (
        SELECT name FROM growers_list WHERE name IS NOT NULL AND TRIM(name)<>''
        UNION
        SELECT DISTINCT TRIM(grower) FROM casecodes WHERE grower IS NOT NULL AND TRIM(grower)<>''
     ) t ORDER BY name ASC"
);
$varietyOptions = rp_fetch_all($conn,
    "SELECT name FROM (
        SELECT name FROM varieties_list WHERE name IS NOT NULL AND TRIM(name)<>''
        UNION
        SELECT DISTINCT TRIM(variety) FROM casecodes WHERE variety IS NOT NULL AND TRIM(variety)<>''
     ) t ORDER BY name ASC"
);

/* ════════════════════════════════════════════════════════════
   SAVED REPORT EMAIL — read from report_settings.json
   ════════════════════════════════════════════════════════════ */
$_rpSettingsFile = __DIR__ . '/../config/report_settings.json';
$_rpSettings     = [];
if (is_file($_rpSettingsFile)) {
    $raw = @file_get_contents($_rpSettingsFile);
    if ($raw) $_rpSettings = json_decode($raw, true) ?? [];
}
$savedReportEmail = trim($_rpSettings['report_email'] ?? '');

/* ════════════════════════════════════════════════════════════
   PRINT MODE
   ════════════════════════════════════════════════════════════ */
$printMode = isset($_GET['print']) && $_GET['print'] === '1';

if (!$printMode) {
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/sidebar.php';
}
?>
<?php if ($printMode): ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Production Report — SM Produce</title>
<?php endif; ?>

<style>
/* ═══════════════════════════════════════════════════════════
   DASHBOARD REPORT v2 — clean, coherent layout
   ═══════════════════════════════════════════════════════════ */
:root {
    --bg:      #f0f2f7;
    --card:    #ffffff;
    --border:  #e2e8f0;
    --radius:  10px;
    --shadow:  0 1px 4px rgba(0,0,0,.07), 0 4px 16px rgba(0,0,0,.04);
    --green:   #16a34a;
    --blue:    #2563eb;
    --purple:  #7c3aed;
    --amber:   #d97706;
    --red:     #dc2626;
    --slate:   #334155;
    --muted:   #94a3b8;
}

<?php if (!$printMode): ?>
body { background: var(--bg) !important; }
<?php endif; ?>

/* ── Topbar ────────────────────────────────────────────── */
.rp-topbar {
    position: sticky; top: 0; z-index: 300;
    background: #0f172a; color: #fff;
    height: 54px; padding: 0 24px;
    display: flex; align-items: center; gap: 14px;
    box-shadow: 0 2px 12px rgba(0,0,0,.35);
}
.rp-topbar h1 { font-size: 14px; font-weight: 700; margin: 0; letter-spacing: .2px; }
.rp-topbar-actions { margin-left: auto; display: flex; gap: 8px; }
.rp-btn {
    font-size: 12px; padding: 6px 14px; border-radius: 7px;
    border: 1px solid rgba(255,255,255,.2);
    background: rgba(255,255,255,.08); color: #cbd5e1;
    cursor: pointer; text-decoration: none; white-space: nowrap;
    transition: all .15s; display: inline-flex; align-items: center; gap: 5px;
}
.rp-btn:hover { background: rgba(255,255,255,.18); color: #fff; text-decoration: none; }
.rp-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
.rp-btn.primary:hover { background: #1d4ed8; }

/* ── Filter bar ────────────────────────────────────────── */
.rp-filters {
    background: var(--card);
    border-bottom: 1px solid var(--border);
    padding: 10px 24px;
    display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
}
.rp-filter-group { display: flex; flex-direction: column; }
.rp-filter-group label {
    font-size: 10px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .5px; margin-bottom: 3px;
}
.rp-filters select,
.rp-filters input[type=date] {
    font-size: 12px; border: 1px solid var(--border);
    border-radius: 7px; padding: 6px 10px; color: var(--slate);
    background: #fff; outline: none; transition: border-color .15s;
    height: 32px;
}
.rp-filters select:focus,
.rp-filters input[type=date]:focus { border-color: #93c5fd; }
.rp-filter-sep { width: 1px; background: var(--border); align-self: stretch; margin: 0 4px; }
.rp-filter-apply {
    height: 32px; padding: 0 18px; background: #2563eb; color: #fff;
    border: none; border-radius: 7px; font-size: 12px; font-weight: 600;
    cursor: pointer; white-space: nowrap; transition: background .15s;
    align-self: flex-end;
}
.rp-filter-apply:hover { background: #1d4ed8; }
/* Active filter badge */
.rp-active-filters {
    display: flex; gap: 6px; align-items: center; flex-wrap: wrap;
    padding: 6px 24px; background: #eff6ff; border-bottom: 1px solid #bfdbfe;
    font-size: 11px; color: #1d4ed8;
}
.rp-active-filters span { background: #dbeafe; border-radius: 20px; padding: 2px 10px; }
.rp-active-filters strong { color: #1e40af; }

/* ── Main wrapper ──────────────────────────────────────── */
.rp-wrap { padding: 20px 24px; max-width: 1440px; margin: 0 auto; }

/* ── Cover ─────────────────────────────────────────────── */
.rp-cover {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
    color: #fff; border-radius: var(--radius);
    padding: 24px 28px; margin-bottom: 20px;
    display: flex; justify-content: space-between; align-items: center;
    gap: 16px; flex-wrap: wrap;
}
.rp-cover-title { font-size: 20px; font-weight: 800; margin: 0 0 4px; }
.rp-cover-sub   { font-size: 12px; color: #94a3b8; margin: 0; }
.rp-cover-meta  { font-size: 11px; color: #64748b; text-align: right; line-height: 1.8; }
.rp-cover-meta strong { color: #cbd5e1; }

/* ── KPI row ───────────────────────────────────────────── */
.rp-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px; margin-bottom: 20px;
}
@media (max-width: 860px) { .rp-kpis { grid-template-columns: repeat(2, 1fr); } }
.rp-kpi {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 16px 20px;
    box-shadow: var(--shadow); position: relative; overflow: hidden;
}
.rp-kpi::after {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; border-radius: 2px 0 0 2px;
}
.rp-kpi.green::after  { background: var(--green); }
.rp-kpi.blue::after   { background: var(--blue); }
.rp-kpi.purple::after { background: var(--purple); }
.rp-kpi.amber::after  { background: var(--amber); }
.rp-kpi-lbl  { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 6px; }
.rp-kpi-val  { font-size: 32px; font-weight: 800; line-height: 1; color: var(--slate); }
.rp-kpi.green  .rp-kpi-val { color: var(--green); }
.rp-kpi.blue   .rp-kpi-val { color: var(--blue); }
.rp-kpi.purple .rp-kpi-val { color: var(--purple); }
.rp-kpi.amber  .rp-kpi-val { color: var(--amber); }
.rp-kpi-sub  { font-size: 11px; color: var(--muted); margin-top: 5px; }

/* ── Section header ────────────────────────────────────── */
.rp-section {
    display: flex; align-items: center; gap: 10px;
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .7px; color: var(--muted);
    border-bottom: 1px solid var(--border);
    padding-bottom: 8px; margin: 24px 0 14px;
}
.rp-section-bar {
    width: 3px; height: 15px; border-radius: 2px; flex-shrink: 0;
}
.rp-section-bar.blue   { background: var(--blue); }
.rp-section-bar.green  { background: var(--green); }
.rp-section-bar.amber  { background: var(--amber); }
.rp-section-badge {
    margin-left: auto; font-size: 10px; font-weight: 600;
    background: #f1f5f9; color: #64748b;
    padding: 2px 8px; border-radius: 20px;
}

/* ── Card ──────────────────────────────────────────────── */
.rp-card {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden;
}
.rp-card-head {
    background: #f8fafc; border-bottom: 1px solid var(--border);
    padding: 10px 16px;
    display: flex; align-items: center; justify-content: space-between;
}
.rp-card-head-title {
    font-size: 12px; font-weight: 700; color: var(--slate);
    display: flex; align-items: center; gap: 7px;
}
.rp-card-head-meta { font-size: 11px; color: var(--muted); }

/* ── Grid helpers ──────────────────────────────────────── */
.rp-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.rp-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.rp-grid-1-2 { display: grid; grid-template-columns: 1fr 2fr; gap: 14px; margin-bottom: 14px; }
@media (max-width: 900px) { .rp-grid-2, .rp-grid-3, .rp-grid-1-2 { grid-template-columns: 1fr; } }

/* ── Chart ─────────────────────────────────────────────── */
.rp-chart-wrap { padding: 14px 16px; position: relative; }
.rp-chart-wrap.h200 { height: 200px; }
.rp-chart-wrap.h240 { height: 240px; }
.rp-chart-wrap.h280 { height: 280px; }

/* ── Table ─────────────────────────────────────────────── */
.rp-table-wrap { overflow-x: auto; }
.rp-table-scroll { max-height: 320px; overflow-y: auto; }
table.rp-table { width: 100%; border-collapse: collapse; font-size: 12px; }
table.rp-table thead th {
    background: #f8fafc; color: #64748b;
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .4px; padding: 7px 12px;
    border-bottom: 2px solid var(--border);
    text-align: left; position: sticky; top: 0; z-index: 2;
    white-space: nowrap;
}
table.rp-table tbody tr { border-bottom: 1px solid #f1f5f9; }
table.rp-table tbody tr:hover { background: #f8fafc; }
table.rp-table tbody tr:last-child { border-bottom: none; }
table.rp-table td { padding: 7px 12px; vertical-align: middle; color: var(--slate); }
.td-num  { text-align: right; font-weight: 700; color: #1e293b; white-space: nowrap; }
.td-pct  { text-align: right; color: var(--muted); font-size: 11px; white-space: nowrap; }
.td-rank { width: 28px; text-align: center; color: var(--muted); font-size: 11px; font-weight: 700; }
.td-bar  { width: 80px; padding-right: 8px; }
.rp-bar  { height: 4px; background: #f1f5f9; border-radius: 999px; overflow: hidden; margin-top: 2px; }
.rp-bar-fill { height: 100%; border-radius: 999px; }

/* Tag pills for packaging / size */
.tag {
    display: inline-block; font-size: 10px; font-weight: 600;
    padding: 2px 7px; border-radius: 5px;
    background: #f1f5f9; color: #475569;
}

/* ── Bins table (compact) ──────────────────────────────── */
.bins-table-wrap { padding: 8px 0; }

/* ── Hourly info bar ───────────────────────────────────── */
.hourly-meta {
    display: flex; gap: 20px; padding: 10px 18px;
    border-bottom: 1px solid var(--border); font-size: 11px; flex-wrap: wrap;
}
.hourly-meta-item strong { font-size: 14px; font-weight: 800; color: var(--purple); }
.hourly-meta-item span   { color: var(--muted); display: block; font-size: 10px; margin-top: 1px; }

/* ── Footer ────────────────────────────────────────────── */
.rp-footer {
    border-top: 1px solid var(--border); padding: 12px 24px;
    font-size: 11px; color: var(--muted);
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 6px; margin-top: 24px;
}


/* ── Send Email Modal ──────────────────────────────────── */
.rp-email-status { font-size: 12px; min-height: 18px; }
.rp-email-status.success { color: #16a34a; font-weight: 600; }
.rp-email-status.error   { color: #dc2626; font-weight: 600; }

/* ══════════════════════════════════════════════════════════
   PRINT
   ══════════════════════════════════════════════════════════ */
@media print {
    @page { size: A4 portrait; margin: 12mm 14mm; }
    body  { background: #fff !important; font-size: 10pt; }

    #sidebarMenu, #layoutSidenav_nav { display: none !important; }
    #layoutSidenav         { display: block !important; }
    #layoutSidenav_content { display: block !important; width: 100% !important; padding: 0 !important; }
    main.container-fluid   { padding: 0 !important; margin: 0 !important; max-width: 100% !important; }

    .rp-topbar, .rp-filters, .rp-active-filters,
    .rp-footer, .no-print { display: none !important; }

    .rp-wrap { padding: 0; max-width: 100%; }
    .rp-cover { background: #0f172a !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; margin-bottom: 10px; }
    .rp-kpis  { gap: 6px; }
    .rp-kpi   { break-inside: avoid; }
    .rp-kpi-val { font-size: 22px; }
    .rp-card  { break-inside: avoid; box-shadow: none !important; border: 1px solid #ccc; }
    .rp-card-head { background: #f0f0f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .rp-grid-2, .rp-grid-3 { gap: 8px; }
    .rp-grid-1-2 { grid-template-columns: 1fr 2fr; gap: 8px; }
    .rp-chart-wrap.h200 { height: 150px !important; }
    .rp-chart-wrap.h240 { height: 180px !important; }
    .rp-chart-wrap.h280 { height: 200px !important; }
    .rp-table-scroll { max-height: none !important; overflow: visible !important; }
    table.rp-table { font-size: 8.5pt; }
    table.rp-table thead th { font-size: 7.5pt; }

    .rp-kpi.green::after  { background: var(--green) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .rp-kpi.blue::after   { background: var(--blue)  !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .rp-kpi.purple::after { background: var(--purple)!important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .rp-kpi.amber::after  { background: var(--amber) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .rp-bar-fill { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<?php if (!$printMode): ?>
<!-- ═══ TOPBAR ═══════════════════════════════════════════ -->
<div class="rp-topbar no-print">
    <h1>📋 Production Report</h1>
    <div class="rp-topbar-actions">
        <a href="/chooser.php" class="rp-btn">← Main Menu</a>
        <button class="rp-btn primary" onclick="openPrintView()">🖨️ Print / PDF</button>
        <button class="rp-btn" onclick="openSendModal()"
                style="background:rgba(22,163,74,.18);border-color:rgba(22,163,74,.4);color:#86efac;">
            ✉️ Send Report
        </button>
    </div>
</div>

<!-- ═══ FILTERS ══════════════════════════════════════════ -->
<form method="get" class="rp-filters no-print" id="rpForm">
    <div class="rp-filter-group">
        <label>Period</label>
        <select name="range" onchange="toggleCustomDates(this.value)">
            <?php foreach (['today'=>'Today','7d'=>'Last 7 days','30d'=>'Last 30 days','all'=>'All time','custom'=>'Custom range'] as $k=>$l): ?>
                <option value="<?= h($k) ?>" <?= $range===$k ? 'selected':'' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="rp-filter-group" id="rpFromWrap" style="<?= $range==='custom' ? '' : 'display:none' ?>">
        <label>From</label>
        <input type="date" name="from" value="<?= h($fromSafe) ?>">
    </div>
    <div class="rp-filter-group" id="rpToWrap" style="<?= $range==='custom' ? '' : 'display:none' ?>">
        <label>To</label>
        <input type="date" name="to" value="<?= h($toSafe) ?>">
    </div>
    <?php if ($range !== 'custom'): ?>
        <input type="hidden" name="from" value="<?= h($fromSafe) ?>">
        <input type="hidden" name="to"   value="<?= h($toSafe) ?>">
    <?php endif; ?>

    <div class="rp-filter-sep"></div>

    <div class="rp-filter-group">
        <label>Grower</label>
        <select name="grower">
            <option value="">All growers</option>
            <?php foreach ($growerOptions as $g): ?>
                <option value="<?= h($g['name']) ?>" <?= $grower===$g['name'] ? 'selected':'' ?>><?= h($g['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="rp-filter-group">
        <label>Variety</label>
        <select name="variety">
            <option value="">All varieties</option>
            <?php foreach ($varietyOptions as $v): ?>
                <option value="<?= h($v['name']) ?>" <?= $variety===$v['name'] ? 'selected':'' ?>><?= h($v['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit" class="rp-filter-apply">Apply</button>
</form>

<?php if ($grower !== '' || $variety !== ''): ?>
<div class="rp-active-filters no-print">
    <strong>Active filters:</strong>
    <?php if ($grower  !== ''): ?><span>👨‍🌾 Grower: <?= h($grower) ?></span><?php endif; ?>
    <?php if ($variety !== ''): ?><span>🌱 Variety: <?= h($variety) ?></span><?php endif; ?>
    <a href="?range=<?= h($range) ?>&from=<?= h($fromSafe) ?>&to=<?= h($toSafe) ?>" style="margin-left:auto;color:#64748b;text-decoration:none;font-size:11px;">✕ Clear filters</a>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ═══ MAIN ══════════════════════════════════════════════ -->
<div class="rp-wrap" id="rpContent">

    <!-- ── Cover ── -->
    <div class="rp-cover">
        <div>
            <div class="rp-cover-title">📋 Production Report</div>
            <div class="rp-cover-sub">
                <?= h($rangeLabel) ?>
                <?= $grower  ? ' &nbsp;·&nbsp; 👨‍🌾 '.h($grower)  : '' ?>
                <?= $variety ? ' &nbsp;·&nbsp; 🌱 '.h($variety) : '' ?>
            </div>
        </div>
        <div class="rp-cover-meta">
            <strong>SM Produce LTD</strong><br>
            Generated: <?= h($generatedAt) ?>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         KPIs
         ════════════════════════════════════════════════════ -->
    <div class="rp-kpis">
        <div class="rp-kpi green">
            <div class="rp-kpi-lbl">🗂️ Full Bins</div>
            <div class="rp-kpi-val"><?= number_format($kpiFull) ?></div>
            <div class="rp-kpi-sub">Current stock (all time)</div>
        </div>
        <div class="rp-kpi blue">
            <div class="rp-kpi-lbl">📦 Empty Bins Out</div>
            <div class="rp-kpi-val"><?= number_format($kpiEmpty) ?></div>
            <div class="rp-kpi-sub">Current quantity</div>
        </div>
        <div class="rp-kpi purple">
            <div class="rp-kpi-lbl">🏷️ Cases Produced</div>
            <div class="rp-kpi-val"><?= number_format($kpiCases) ?></div>
            <div class="rp-kpi-sub"><?= h($rangeLabel) ?></div>
        </div>
        <div class="rp-kpi amber">
            <div class="rp-kpi-lbl">🪣 Bins Dumped</div>
            <div class="rp-kpi-val"><?= number_format($kpiDumped) ?></div>
            <div class="rp-kpi-sub"><?= h($rangeLabel) ?></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         SECTION 1 — CASES PRODUCED
         ════════════════════════════════════════════════════ -->
    <div class="rp-section">
        <div class="rp-section-bar blue"></div>
        🏷️ Cases Produced
        <div class="rp-section-badge"><?= h($rangeLabel) ?><?= $grower||$variety ? ' · filtered' : '' ?></div>
    </div>

    <!-- Charts: By Grower + By Variety -->
    <div class="rp-grid-2" style="margin-bottom:14px;">

        <!-- By Grower chart -->
        <div class="rp-card">
            <div class="rp-card-head">
                <div class="rp-card-head-title">👨‍🌾 By Grower</div>
                <div class="rp-card-head-meta"><?= count($casesByGrower) ?> growers &nbsp;·&nbsp; <?= number_format(array_sum(array_column($casesByGrower,'qty'))) ?> cases</div>
            </div>
            <div class="rp-chart-wrap h240">
                <canvas id="chartGrower"></canvas>
            </div>
        </div>

        <!-- By Variety chart -->
        <div class="rp-card">
            <div class="rp-card-head">
                <div class="rp-card-head-title">🌱 By Variety</div>
                <div class="rp-card-head-meta"><?= count($casesByVariety) ?> varieties &nbsp;·&nbsp; <?= number_format(array_sum(array_column($casesByVariety,'qty'))) ?> cases</div>
            </div>
            <div class="rp-chart-wrap h240">
                <canvas id="chartVariety"></canvas>
            </div>
        </div>

    </div>

    <!-- Full detail table: Grower × Variety × Packaging × Size -->
    <div class="rp-card" style="margin-bottom:0;">
        <div class="rp-card-head">
            <div class="rp-card-head-title">📋 Breakdown — Grower × Variety × Packaging × Size</div>
            <div class="rp-card-head-meta"><?= count($caseDetail) ?> combinations &nbsp;·&nbsp; <?= number_format($caseDetailTotal) ?> total cases</div>
        </div>
        <div class="rp-table-scroll rp-table-wrap">
            <table class="rp-table">
                <thead>
                    <tr>
                        <th class="td-rank">#</th>
                        <th>Grower</th>
                        <th>Variety</th>
                        <th>Packaging</th>
                        <th>Size</th>
                        <th class="td-num">Cases</th>
                        <th class="td-pct">Share</th>
                        <th class="td-bar" style="min-width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($caseDetail)): ?>
                    <tr><td colspan="8" style="padding:20px;text-align:center;color:var(--muted);">No production data for the selected period / filters.</td></tr>
                <?php else:
                    $maxQty = max(array_column($caseDetail, 'qty'));
                    $COLORS = ['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed','#0891b2','#ea580c','#be185d','#15803d','#1d4ed8'];
                    foreach ($caseDetail as $i => $r):
                        $pct    = $caseDetailTotal > 0 ? round($r['qty'] / $caseDetailTotal * 100, 1) : 0;
                        $barPct = $maxQty > 0 ? round($r['qty'] / $maxQty * 100) : 0;
                        $clr    = $COLORS[$i % count($COLORS)];
                ?>
                    <tr>
                        <td class="td-rank"><?= $i+1 ?></td>
                        <td><?= h($r['grower']) ?></td>
                        <td><?= h($r['variety']) ?></td>
                        <td><span class="tag"><?= h($r['packaging']) ?></span></td>
                        <td><span class="tag"><?= h($r['size']) ?></span></td>
                        <td class="td-num"><?= number_format($r['qty']) ?></td>
                        <td class="td-pct"><?= $pct ?>%</td>
                        <td class="td-bar"><div class="rp-bar"><div class="rp-bar-fill" style="width:<?= $barPct ?>%;background:<?= $clr ?>;"></div></div></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         SECTION 2 — HOURLY PRODUCTION (today)
         ════════════════════════════════════════════════════ -->
    <div class="rp-section">
        <div class="rp-section-bar blue"></div>
        📈 Hourly Production
        <div class="rp-section-badge">Today — <?= number_format($hourlyTotal) ?> cases</div>
    </div>

    <div class="rp-card">
        <?php
        // find peak hour
        $peakIdx = !empty($hValues) ? array_search(max($hValues), $hValues) : null;
        $peakHr  = $peakIdx !== null ? $hLabels[$peakIdx] : '—';
        $peakQty = $peakIdx !== null ? $hValues[$peakIdx] : 0;
        // active hours
        $activeHours = count(array_filter($hValues, fn($v) => $v > 0));
        ?>
        <div class="hourly-meta">
            <div class="hourly-meta-item">
                <strong><?= number_format($hourlyTotal) ?></strong>
                <span>Cases today</span>
            </div>
            <div class="hourly-meta-item">
                <strong><?= $peakHr ?></strong>
                <span>Peak hour (<?= number_format($peakQty) ?> cases)</span>
            </div>
            <div class="hourly-meta-item">
                <strong><?= $activeHours ?>h</strong>
                <span>Active hours</span>
            </div>
            <?php if ($activeHours > 0): ?>
            <div class="hourly-meta-item">
                <strong><?= number_format(round($hourlyTotal / $activeHours)) ?></strong>
                <span>Avg cases/active hour</span>
            </div>
            <?php endif; ?>
        </div>
        <div class="rp-chart-wrap h280">
            <canvas id="chartHourly"></canvas>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         SECTION 3 — BINS STATUS
         ════════════════════════════════════════════════════ -->
    <div class="rp-section">
        <div class="rp-section-bar green"></div>
        🗂️ Bins Status
        <div class="rp-section-badge">Stock snapshot + <?= h($rangeLabel) ?></div>
    </div>

    <div class="rp-grid-3">

        <!-- Full Bins by Grower -->
        <div class="rp-card">
            <div class="rp-card-head">
                <div class="rp-card-head-title">🗂️ Full Bins by Grower</div>
                <div class="rp-card-head-meta"><?= number_format($kpiFull) ?> total</div>
            </div>
            <div class="rp-chart-wrap h200">
                <canvas id="chartFullGrower"></canvas>
            </div>
            <div class="rp-table-scroll">
                <?php rp_bins_table($fullBinsByGrower, 'label', '#16a34a'); ?>
            </div>
        </div>

        <!-- Full Bins by Variety -->
        <div class="rp-card">
            <div class="rp-card-head">
                <div class="rp-card-head-title">🌿 Full Bins by Variety</div>
                <div class="rp-card-head-meta"><?= count($fullBinsByVariety) ?> varieties</div>
            </div>
            <div class="rp-chart-wrap h200">
                <canvas id="chartFullVariety"></canvas>
            </div>
            <div class="rp-table-scroll">
                <?php rp_bins_table($fullBinsByVariety, 'label', '#0891b2'); ?>
            </div>
        </div>

        <!-- Dumped Bins by Grower -->
        <div class="rp-card">
            <div class="rp-card-head">
                <div class="rp-card-head-title">🪣 Dumped Bins by Grower</div>
                <div class="rp-card-head-meta"><?= number_format($kpiDumped) ?> total · <?= h($rangeLabel) ?></div>
            </div>
            <div class="rp-chart-wrap h200">
                <canvas id="chartDumpedGrower"></canvas>
            </div>
            <div class="rp-table-scroll">
                <?php rp_bins_table($dumpedByGrower, 'label', '#d97706'); ?>
            </div>
        </div>

    </div>

</div><!-- /rp-wrap -->

<!-- ── Footer ── -->
<div class="rp-footer">
    <span>SM Produce LTD — Production Report</span>
    <span>Generated <?= h($generatedAt) ?> &nbsp;·&nbsp; Period: <?= h($rangeLabel) ?></span>
</div>

<?php
/* ── helper: compact bins table ───────────────────────── */
function rp_bins_table(array $rows, string $col, string $accentColor): void {
    $total = array_sum(array_column($rows, 'qty'));
    $max   = !empty($rows) ? max(array_column($rows, 'qty')) : 1;
    echo '<table class="rp-table">';
    echo '<thead><tr><th class="td-rank">#</th><th>'.$col.'</th><th class="td-num">Bins</th><th class="td-pct">%</th></tr></thead>';
    echo '<tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="4" style="padding:14px;text-align:center;color:#94a3b8;">No data</td></tr>';
    } else {
        foreach ($rows as $i => $r) {
            $pct    = $total > 0 ? round($r['qty']/$total*100,1) : 0;
            $barPct = $max   > 0 ? round($r['qty']/$max*100)     : 0;
            echo '<tr>';
            echo '<td class="td-rank">'.($i+1).'</td>';
            echo '<td>';
            echo '<div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:140px;">'.h($r[$col]).'</div>';
            echo '<div class="rp-bar"><div class="rp-bar-fill" style="width:'.$barPct.'%;background:'.h($accentColor).';"></div></div>';
            echo '</td>';
            echo '<td class="td-num">'.number_format($r['qty']).'</td>';
            echo '<td class="td-pct">'.$pct.'%</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
/* ── Colour palette ─────────────────────────────────── */
const PAL = ['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed',
             '#0891b2','#ea580c','#be185d','#15803d','#1d4ed8',
             '#7e22ce','#0f766e'];

function alphaCol(hex, a) {
    const r = parseInt(hex.slice(1,3),16);
    const g = parseInt(hex.slice(3,5),16);
    const b = parseInt(hex.slice(5,7),16);
    return `rgba(${r},${g},${b},${a})`;
}

/* ── Horizontal bar (for Grower / Variety) ─────────── */
function makeHBar(id, labels, values, accentIdx) {
    const c = document.getElementById(id);
    if (!c) return;
    const colors = labels.map((_,i) => PAL[i % PAL.length]);
    new Chart(c, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: colors.map(c => alphaCol(c, .18)),
                borderColor: colors,
                borderWidth: 1.5,
                borderRadius: 4,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => '  ' + ctx.parsed.x.toLocaleString() + ' cases' } }
            },
            scales: {
                x: { beginAtZero: true, ticks: { precision:0, font:{size:10} }, grid: { color:'#f1f5f9' } },
                y: { grid: { display:false }, ticks: { font:{size:11} } }
            }
        }
    });
}

/* ── Vertical bar (for bins charts) ────────────────── */
function makeVBar(id, labels, values, baseColor) {
    const c = document.getElementById(id);
    if (!c) return;
    new Chart(c, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: alphaCol(baseColor, .18),
                borderColor: baseColor,
                borderWidth: 1.5,
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false },
                       tooltip: { callbacks: { label: ctx => '  ' + ctx.parsed.y.toLocaleString() + ' bins' } } },
            scales: {
                x: { grid:{display:false}, ticks:{font:{size:10}, maxRotation:35, maxTicksLimit:10} },
                y: { beginAtZero:true, ticks:{precision:0,font:{size:10}}, grid:{color:'#f1f5f9'} }
            }
        }
    });
}

/* ── Hourly line chart ──────────────────────────────── */
function makeHourly(id, labels, values) {
    const c = document.getElementById(id);
    if (!c) return;
    new Chart(c, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                data: values,
                fill: true,
                tension: 0.4,
                borderColor: '#7c3aed',
                backgroundColor: alphaCol('#7c3aed', .08),
                borderWidth: 2.5,
                pointRadius: values.map(v => v > 0 ? 4 : 0),
                pointBackgroundColor: '#7c3aed',
                pointBorderColor: '#fff',
                pointBorderWidth: 1.5,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => '  ' + ctx.parsed.y + ' cases' } }
            },
            scales: {
                x: { grid:{display:false}, ticks:{font:{size:10}, maxTicksLimit:12} },
                y: { beginAtZero:true, ticks:{precision:0,font:{size:10}}, grid:{color:'#f1f5f9'} }
            }
        }
    });
}

/* ── Boot ───────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {

    // Cases by Grower (horizontal)
    makeHBar('chartGrower',
        <?= json_encode(array_column($casesByGrower,  'label')) ?>,
        <?= json_encode(array_map(fn($r)=>(int)$r['qty'], $casesByGrower)) ?>
    );

    // Cases by Variety (horizontal)
    makeHBar('chartVariety',
        <?= json_encode(array_column($casesByVariety, 'label')) ?>,
        <?= json_encode(array_map(fn($r)=>(int)$r['qty'], $casesByVariety)) ?>
    );

    // Hourly
    makeHourly('chartHourly',
        <?= json_encode($hLabels) ?>,
        <?= json_encode($hValues) ?>
    );

    // Bins charts
    makeVBar('chartFullGrower',
        <?= json_encode(array_column($fullBinsByGrower,  'label')) ?>,
        <?= json_encode(array_map(fn($r)=>(int)$r['qty'], $fullBinsByGrower)) ?>,
        '#16a34a'
    );
    makeVBar('chartFullVariety',
        <?= json_encode(array_column($fullBinsByVariety, 'label')) ?>,
        <?= json_encode(array_map(fn($r)=>(int)$r['qty'], $fullBinsByVariety)) ?>,
        '#0891b2'
    );
    makeVBar('chartDumpedGrower',
        <?= json_encode(array_column($dumpedByGrower, 'label')) ?>,
        <?= json_encode(array_map(fn($r)=>(int)$r['qty'], $dumpedByGrower)) ?>,
        '#d97706'
    );
});

/* ── Toggle custom date fields ──────────────────────── */
function toggleCustomDates(v) {
    const show = v === 'custom';
    document.getElementById('rpFromWrap').style.display = show ? '' : 'none';
    document.getElementById('rpToWrap').style.display   = show ? '' : 'none';
    if (!show) document.getElementById('rpForm').submit();
}

/* ── Open print/PDF view ────────────────────────────── */
function openPrintView() {
    const p = new URLSearchParams(window.location.search);
    p.set('print','1');
    window.open('dashboard_report.php?' + p.toString(), '_blank');
}

/* ══════════════════════════════════════════════════════════
   SEND EMAIL MODAL
   ══════════════════════════════════════════════════════════ */
function openSendModal() {
    document.getElementById('rpEmailModal').style.display = 'flex';
    setTimeout(() => document.getElementById('rpEmailInput').focus(), 80);
}
function closeSendModal() {
    document.getElementById('rpEmailModal').style.display = 'none';
    const st = document.getElementById('rpEmailStatus');
    st.textContent = '';
    st.className   = 'rp-email-status';
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('rpEmailModal').addEventListener('click', function(e) {
        if (e.target === this) closeSendModal();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSendModal(); });
});

function sendReportEmail() {
    const emailEl = document.getElementById('rpEmailInput');
    const email   = emailEl.value.trim();
    const save    = document.getElementById('rpEmailSave').checked ? '1' : '';
    const status  = document.getElementById('rpEmailStatus');
    const btn     = document.getElementById('rpEmailSendBtn');

    // Validate email
    if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
        status.textContent = '⚠️ Enter a valid email address.';
        status.className   = 'rp-email-status error';
        emailEl.focus();
        return;
    }

    const params = new URLSearchParams(window.location.search);

    btn.disabled       = true;
    btn.textContent    = 'Sending…';
    status.textContent = '';
    status.className   = 'rp-email-status';

    const body = new FormData();
    body.append('email',   email);
    body.append('save',    save);
    body.append('range',   params.get('range')   || 'today');
    body.append('from',    params.get('from')    || '');
    body.append('to',      params.get('to')      || '');
    body.append('grower',  params.get('grower')  || '');
    body.append('variety', params.get('variety') || '');

    fetch('api/send_report_email.php', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                status.textContent = '✅ Report sent successfully!';
                status.className   = 'rp-email-status success';
                btn.textContent    = 'Send';
                btn.disabled       = false;
                setTimeout(closeSendModal, 2400);
            } else {
                status.textContent = '❌ ' + (data.error || 'Unknown error.');
                status.className   = 'rp-email-status error';
                btn.textContent    = 'Send';
                btn.disabled       = false;
            }
        })
        .catch(() => {
            status.textContent = '❌ Network error. Try again.';
            status.className   = 'rp-email-status error';
            btn.textContent    = 'Send';
            btn.disabled       = false;
        });
}
</script>

<!-- ═══ SEND EMAIL MODAL ════════════════════════════════════════ -->
<?php if (!$printMode): ?>
<div id="rpEmailModal" style="
    display:none; position:fixed; inset:0; z-index:9999;
    background:rgba(0,0,0,.55); align-items:center; justify-content:center;
    backdrop-filter:blur(2px);
" class="no-print">
  <div style="
      background:#fff; border-radius:14px; padding:28px 30px;
      width:100%; max-width:460px; box-shadow:0 20px 60px rgba(0,0,0,.3);
      position:relative; margin:16px;
  ">
    <!-- Close X -->
    <button onclick="closeSendModal()" style="
        position:absolute; top:14px; right:16px;
        background:none; border:none; font-size:20px; color:#94a3b8;
        cursor:pointer; line-height:1; padding:0;
    ">&#10005;</button>

    <!-- Header -->
    <div style="margin-bottom:20px;">
        <div style="font-size:16px;font-weight:800;color:#0f172a;">&#9993;&#65039; Send Report by Email</div>
        <div style="font-size:12px;color:#64748b;margin-top:5px;">
            The report will be generated as a PDF and sent as an attachment.
        </div>
    </div>

    <!-- Email input -->
    <div style="margin-bottom:14px;">
        <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px;">
            Recipient Email
        </label>
        <input
            id="rpEmailInput"
            type="email"
            value="<?= h($savedReportEmail) ?>"
            placeholder="e.g. manager@smproduce.com"
            style="
                width:100%; box-sizing:border-box;
                border:1.5px solid #e2e8f0; border-radius:8px;
                padding:9px 12px; font-size:13px; color:#1e293b;
                outline:none; transition:border-color .15s; background:#fff;
            "
            onfocus="this.style.borderColor='#93c5fd'"
            onblur="this.style.borderColor='#e2e8f0'"
            onkeydown="if(event.key==='Enter') sendReportEmail()"
        >
    </div>

    <!-- Save checkbox -->
    <label style="display:flex;align-items:center;gap:9px;font-size:12px;color:#475569;margin-bottom:18px;cursor:pointer;user-select:none;">
        <input id="rpEmailSave" type="checkbox" <?= $savedReportEmail !== '' ? 'checked' : '' ?>
               style="width:15px;height:15px;cursor:pointer;accent-color:#2563eb;flex-shrink:0;">
        Save this address for future reports
    </label>

    <!-- Active filters info -->
    <?php if ($grower !== '' || $variety !== '' || $range !== 'today'): ?>
    <div style="
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;
        padding:10px 13px; margin-bottom:16px; font-size:11px; color:#64748b;
        line-height:1.7;
    ">
        <strong style="color:#334155;">Report will include:</strong>
        &nbsp;Period: <span style="color:#2563eb;font-weight:600;"><?= h($rangeLabel) ?></span>
        <?php if ($grower  !== ''): ?>
            &nbsp;&middot;&nbsp; Grower: <span style="color:#2563eb;font-weight:600;"><?= h($grower) ?></span>
        <?php endif; ?>
        <?php if ($variety !== ''): ?>
            &nbsp;&middot;&nbsp; Variety: <span style="color:#2563eb;font-weight:600;"><?= h($variety) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Status -->
    <div id="rpEmailStatus" class="rp-email-status" style="margin-bottom:14px;min-height:20px;"></div>

    <!-- Action buttons -->
    <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button onclick="closeSendModal()" style="
            padding:9px 20px; border-radius:8px;
            border:1px solid #e2e8f0; background:#f8fafc;
            color:#64748b; font-size:13px; font-weight:600; cursor:pointer;
        ">Cancel</button>
        <button id="rpEmailSendBtn" onclick="sendReportEmail()" style="
            padding:9px 24px; border-radius:8px; border:none;
            background:#2563eb; color:#fff; font-size:13px; font-weight:700;
            cursor:pointer; transition:background .15s;
        "
        onmouseover="this.style.background='#1d4ed8'"
        onmouseout="this.style.background='#2563eb'">Send</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
if ($printMode) {
    echo '<script>window.addEventListener("load",()=>setTimeout(()=>window.print(),800));</script>';
    echo '</body></html>';
} else {
    include __DIR__ . '/../includes/footer.php';
}
?>
