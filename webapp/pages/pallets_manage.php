<?php
// ── Auth ──────────────────────────────────────────────────────────────────
require_once __DIR__.'/../config/user_functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
// Accept both full user session and TC26 token session
if (!isset($_SESSION['user']) && empty($_SESSION['logged_in'])) {
    header('Location: /auth/login.php'); exit;
}

require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/print_engine.php';
require_once __DIR__.'/../includes/pallet_report.php';

$dbx = $conn; // Pallets Manage uses mysqli consistently (ppr_* helpers are mysqli-based)
smp_ensure_tc26_tables($dbx);
if ($dbx instanceof mysqli) {
    ppr_ensure_settings($dbx);
    $palletPrintSettings = ppr_get_settings($dbx);
} else {
    $palletPrintSettings = ['label_printer_id'=>0,'report_printer'=>''];
}
$palletLabelPrinters = smp_db_fetch_all($dbx,
    "SELECT id,name,printer_ip,printer_port,dpi,is_default FROM printers_list WHERE active=1 ORDER BY is_default DESC,name ASC,id ASC", []);
$palletReportPrinters = function_exists('ebr_windows_printers') ? ebr_windows_printers() : [];

// ── AJAX ─────────────────────────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['ajax'])) {
    // Disable ONLY_FULL_GROUP_BY so aggregate queries work without listing every column in GROUP BY
    try { $dbx->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))"); } catch(Throwable $e) {}
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    $editPassword = (string)($_POST['admin_password'] ?? '');
    $requireClosedPassword = function(string $pid) use ($dbx,$editPassword): bool {
        if (ppr_pallet_status($dbx,$pid) !== 'CLOSED') return true;
        return ppr_verify_closed_edit_password($editPassword);
    };

    if ($action === 'save_print_settings') {
        if (!($dbx instanceof mysqli)) { echo json_encode(['ok'=>0,'err'=>'MySQL connection required']); exit; }
        $labelPrinterId=(int)($_POST['label_printer_id']??0);
        $reportPrinter=trim((string)($_POST['report_printer']??''));
        if($labelPrinterId>0){
            // Validate against the exact active-printer list used to build the select.
            // This avoids false negatives caused by a second parameterized lookup.
            $activePrinterIds=array_map(static fn($row)=>(int)($row['id']??0),$palletLabelPrinters);
            if(!in_array($labelPrinterId,$activePrinterIds,true)){
                echo json_encode(['ok'=>0,'err'=>'The selected Zebra printer is no longer active. Refresh the page and select it again.']);exit;
            }
        }
        if($reportPrinter!==''&&!in_array($reportPrinter,ebr_windows_printers(),true)){
            // A stale Windows report-printer value must not block saving the Zebra label printer.
            $reportPrinter='';
        }
        $ok=ppr_save_settings($dbx,$labelPrinterId,$reportPrinter);
        echo json_encode(['ok'=>$ok?1:0,'msg'=>$ok?'Pallet print settings saved':'Save failed']);exit;
    }

    if ($action === 'report') {
        $pid=trim((string)($_POST['pallet_id']??''));
        if($pid===''){echo json_encode(['ok'=>0,'err'=>'Missing pallet_id']);exit;}
        if(!($dbx instanceof mysqli)){echo json_encode(['ok'=>0,'err'=>'MySQL connection required']);exit;}
        $r=ppr_generate_report($dbx,$pid);
        echo json_encode($r);exit;
    }

    if ($action === 'print_report') {
        $pid=trim((string)($_POST['pallet_id']??''));
        if($pid===''){echo json_encode(['ok'=>0,'err'=>'Missing pallet_id']);exit;}
        if(!($dbx instanceof mysqli)){echo json_encode(['ok'=>0,'err'=>'MySQL connection required']);exit;}
        echo json_encode(ppr_print_report($dbx,$pid));exit;
    }

    if ($action === 'list') {
        $status = $_POST['status']    ?? 'ALL';
        $search = trim($_POST['search'] ?? '');
        $from   = $_POST['date_from'] ?? '';
        $to     = $_POST['date_to']   ?? '';
        $where  = '1=1'; $params = [];
        if ($status !== 'ALL') { $where .= ' AND p.status=?';           $params[] = $status; }
        if ($search !== '')    { $where .= ' AND p.pallet_id LIKE ?';   $params[] = "%$search%"; }
        if ($from !== '')      { $where .= ' AND DATE(p.created_at)>=?';$params[] = $from; }
        if ($to   !== '')      { $where .= ' AND DATE(p.created_at)<=?';$params[] = $to; }
        // Use a safe query that works whether or not is_partial column exists yet
        try {
            $rows = smp_db_fetch_all($dbx,
                "SELECT p.pallet_id,
                        MAX(p.status)                                          AS status,
                        MAX(COALESCE(p.is_partial, 0))                         AS is_partial,
                        DATE_FORMAT(MAX(p.created_at),'%Y-%m-%d %H:%i')        AS created_at,
                        DATE_FORMAT(MAX(p.closed_at),'%Y-%m-%d %H:%i')         AS closed_at,
                        (SELECT COUNT(*) FROM pallet_cases WHERE pallet_id=p.pallet_id) AS actual_cases
                 FROM pallets p
                 WHERE $where GROUP BY p.pallet_id
                 ORDER BY p.created_at DESC LIMIT 200", $params);
        } catch (Throwable $e) {
            // Fallback: is_partial may not exist on very old schema
            $rows = smp_db_fetch_all($dbx,
                "SELECT p.pallet_id,
                        MAX(p.status)                                          AS status,
                        0                                                       AS is_partial,
                        DATE_FORMAT(MAX(p.created_at),'%Y-%m-%d %H:%i')        AS created_at,
                        DATE_FORMAT(MAX(p.closed_at),'%Y-%m-%d %H:%i')         AS closed_at,
                        (SELECT COUNT(*) FROM pallet_cases WHERE pallet_id=p.pallet_id) AS actual_cases
                 FROM pallets p
                 WHERE $where
                 GROUP BY p.pallet_id
                 ORDER BY MAX(p.created_at) DESC LIMIT 200", $params);
        }
        echo json_encode(['ok'=>1,'pallets'=>$rows]); exit;
    }

    if ($action === 'detail') {
        $pid = trim($_POST['pallet_id'] ?? '');
        if ($pid === '') { echo json_encode(['ok'=>0,'err'=>'Missing pallet_id']); exit; }
        if(function_exists('smp_repair_pallet_case_metadata'))
            smp_repair_pallet_case_metadata($dbx,$pid);
        $pallet = smp_db_fetch_one($dbx,
            "SELECT p.*, DATE_FORMAT(p.created_at,'%Y-%m-%d %H:%i') AS created_fmt,
                    DATE_FORMAT(p.closed_at,'%Y-%m-%d %H:%i') AS closed_fmt
             FROM pallets p WHERE p.pallet_id=?", [$pid]);
        if (!$pallet) { echo json_encode(['ok'=>0,'err'=>'Pallet not found']); exit; }
        try {
            $cases = smp_db_fetch_all($dbx,
                "SELECT pc.id, pc.case_serial,
                        COALESCE(NULLIF(pc.variety,''),cc.variety) AS variety,
                        COALESCE(NULLIF(pc.grower,''),cc.grower) AS grower,
                        COALESCE(NULLIF(pc.size,''),cc.size) AS size,
                        COALESCE(NULLIF(pc.packaging,''),cc.packaging) AS packaging,
                        COALESCE(pc.sku,cc.SKU) AS sku,
                        DATE_FORMAT(pc.scanned_at,'%Y-%m-%d %H:%i') AS scanned_at
                 FROM pallet_cases pc
                 LEFT JOIN casecodes cc ON cc.serial=pc.case_serial
                 WHERE pc.pallet_id=? ORDER BY pc.scanned_at ASC", [$pid]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>0,'err'=>'Unable to load pallet composition: '.$e->getMessage()]); exit;
        }
        try {
            $shipment = smp_db_fetch_one($dbx,
                "SELECT sp.shipment_id, s.status AS ship_status, s.po,
                        DATE_FORMAT(s.created_at,'%Y-%m-%d %H:%i') AS ship_created
                 FROM shipment_pallets sp
                 JOIN shipments s ON s.shipment_id=sp.shipment_id
                 WHERE sp.pallet_id=? LIMIT 1", [$pid]);
        } catch (Throwable $e) { $shipment = null; }
        echo json_encode(['ok'=>1,'pallet'=>$pallet,'cases'=>$cases,'shipment'=>$shipment]); exit;
    }

    if ($action === 'reopen') {
        $pid = trim($_POST['pallet_id'] ?? '');
        if ($pid===''){echo json_encode(['ok'=>0,'err'=>'Missing pallet_id']);exit;}
        $oldStatus=ppr_pallet_status($dbx,$pid);

        if($oldStatus==='CLOSED' && !$requireClosedPassword($pid)){
            echo json_encode([
                'ok'=>0,
                'password_required'=>true,
                'err'=>'Password required to reopen a CLOSED/complete pallet.'
            ]);exit;
        }

        smp_db_exec($dbx,
            "UPDATE pallets
             SET status='OPEN', is_partial=0, closed_at=NULL
             WHERE pallet_id=?", [$pid]);

        if($dbx instanceof mysqli){
            ppr_generate_report($dbx,$pid);
        }

        echo json_encode([
            'ok'=>1,
            'was_status'=>$oldStatus,
            'msg'=>"Pallet $pid reopened"
        ]); exit;
    }

    if ($action === 'delete') {
        $pid = trim($_POST['pallet_id'] ?? '');
        if ($pid === '') { echo json_encode(['ok'=>0,'err'=>'Missing pallet_id']); exit; }

        if(!$requireClosedPassword($pid)){
            echo json_encode([
                'ok'=>0,
                'password_required'=>true,
                'err'=>'Password required to delete a CLOSED/complete pallet.'
            ]);exit;
        }

        smp_db_exec($dbx, "DELETE FROM pallet_cases WHERE pallet_id=?", [$pid]);
        smp_db_exec($dbx, "DELETE FROM shipment_pallets WHERE pallet_id=?", [$pid]);
        smp_db_exec($dbx, "DELETE FROM pallets WHERE pallet_id=?", [$pid]);
        echo json_encode(['ok'=>1,'msg'=>"Pallet $pid deleted"]); exit;
    }

    if ($action === 'remove_case') {
        $cid = (int)($_POST['case_id'] ?? 0);
        $pid = trim($_POST['pallet_id'] ?? '');

        if(ppr_pallet_status($dbx,$pid)==='CLOSED'){
            echo json_encode([
                'ok'=>0,
                'password_required'=>true,
                'must_reopen'=>true,
                'err'=>'This pallet is CLOSED. Reopen it with the protected password before changing its cases.'
            ]);exit;
        }

        smp_db_exec($dbx, "DELETE FROM pallet_cases WHERE id=? AND pallet_id=?", [$cid,$pid]);
        smp_db_exec($dbx,
            "UPDATE pallets SET case_count=(SELECT COUNT(*) FROM pallet_cases WHERE pallet_id=?) WHERE pallet_id=?",
            [$pid,$pid]);

        if($dbx instanceof mysqli)ppr_generate_report($dbx,$pid);

        echo json_encode(['ok'=>1]); exit;
    }

    if ($action === 'reprint') {
        $pid = trim($_POST['pallet_id'] ?? '');
        $printerId = 0;
        if ($dbx instanceof mysqli) $printerId=(int)(ppr_get_settings($dbx)['label_printer_id']??0);
        if ($printerId === 0) { echo json_encode(['ok'=>0,'err'=>'Select Pallet Label Printer at the top of this page']); exit; }
        $ok = smp_tc26_print_pallet_label($dbx, $pid, $printerId);
        echo json_encode(['ok'=>$ok?1:0,'msg'=>$ok?'Label sent to saved Zebra printer':'Print failed — check printer']); exit;
    }

    echo json_encode(['ok'=>0,'err'=>'Unknown action']); exit;
}

include '../includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
.pm-page .page-card{border:1px solid #dfe5ec;border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.05)}
.pm-page .page-card-header{padding:18px 22px;border-bottom:1px solid #e8edf2;background:#fff;border-top-left-radius:18px;border-top-right-radius:18px}
.pm-page .kpi-card{border:1px solid #e3e8ef;border-radius:14px;background:linear-gradient(180deg,#fff 0%,#f8fbfd 100%);padding:16px 20px}
.pm-page .kpi-label{font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:800;margin-bottom:6px}
.pm-page .kpi-value{font-size:1.8rem;font-weight:800;color:#0f172a;line-height:1}
.pm-page table thead th{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#475569;border-bottom:2px solid #e8edf2;background:#f8fafc;font-weight:700}
.pm-page table tbody tr{cursor:pointer;transition:background .12s}
.pm-page table tbody tr:hover{background:#f0f6ff}
.pm-page table tbody tr.selected{background:#eff6ff!important;outline:2px solid #3b82f6;outline-offset:-2px}
.pm-page table td{vertical-align:middle;font-size:.875rem;border-color:#f0f4f8}
.pm-page .badge-open{background:#dbeafe;color:#1d4ed8;font-weight:700;font-size:.73rem;padding:.3rem .65rem;border-radius:999px}
.pm-page .badge-closed{background:#f1f5f9;color:#475569;font-weight:700;font-size:.73rem;padding:.3rem .65rem;border-radius:999px}
.pm-page .badge-partial{background:#fff7ed;color:#c2410c;font-weight:700;font-size:.73rem;padding:.3rem .65rem;border-radius:999px}
.pm-page .detail-panel{border:1px solid #dfe5ec;border-radius:18px;background:#fff;padding:22px;min-height:300px}
.pm-page .detail-panel .case-row:hover{background:#f8fafc}
.pm-page .filter-row input,.pm-page .filter-row select{background:#fff;border:1px solid #dfe5ec;border-radius:8px;font-size:.875rem;color:#0f172a}
.pm-page .filter-row input:focus,.pm-page .filter-row select:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.pm-page .detail-section-title{font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;font-weight:700;margin-bottom:8px}
.pm-page .meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;font-size:.85rem;margin-bottom:14px}
.pm-page .meta-grid .label{color:#94a3b8;font-size:.78rem}
.pm-page .meta-grid .value{color:#0f172a;font-weight:600}
.pm-page .ship-link{color:#3b82f6;text-decoration:none;font-weight:600}
.pm-page .ship-link:hover{text-decoration:underline}
.pm-page .case-table td{font-size:.8rem}
.pm-page .empty-state{text-align:center;padding:48px 0;color:#94a3b8}
.pm-page .empty-state i{font-size:2.5rem;display:block;margin-bottom:10px;opacity:.4}
</style>

<div class="container-fluid py-4 pm-page">

  <!-- Page header -->
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
      <h2 class="mb-1 fw-bold"><i class="bi bi-box-seam me-2 text-primary"></i>Pallets</h2>
      <div class="text-muted" style="font-size:.9rem">View, manage and reprint all pallets · CLOSED pallet edits are password protected</div>
    </div>
  </div>

  <!-- Pallet printing -->
  <div class="page-card mb-4">
    <div class="page-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <div class="fw-bold"><i class="bi bi-printer me-2 text-primary"></i>Pallet Printing</div>
        <div class="text-muted small">Saved automatically. Used by the Pallets/Shipping app and this page.</div>
      </div>
      <span id="palletPrintStatus" class="small text-muted">Saved</span>
    </div>
    <div class="p-3">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label small text-muted mb-1">Pallet Label Printer (Zebra)</label>
          <select class="form-select" id="palletLabelPrinter">
            <option value="">— Select Zebra label printer —</option>
            <?php foreach($palletLabelPrinters as $pr): ?>
              <option value="<?= (int)$pr['id'] ?>" <?= (int)$palletPrintSettings['label_printer_id']===(int)$pr['id']?'selected':'' ?>>
                <?= htmlspecialchars((string)$pr['name']) ?><?= !empty($pr['is_default'])?' ★':'' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label small text-muted mb-1">Pallet Report Printer</label>
          <select class="form-select" id="palletReportPrinter">
            <option value="">— Generate PDF only —</option>
            <?php foreach($palletReportPrinters as $wp): ?>
              <option value="<?= htmlspecialchars($wp,ENT_QUOTES) ?>" <?= $palletPrintSettings['report_printer']===$wp?'selected':'' ?>><?= htmlspecialchars($wp) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">The report is generated when a pallet is closed; if selected, it is sent through the PDF Print Agent.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Total</div><div class="kpi-value" id="kTotal">—</div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Open</div><div class="kpi-value text-primary" id="kOpen">—</div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Partial</div><div class="kpi-value text-warning" id="kPartial">—</div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Closed</div><div class="kpi-value text-success" id="kClosed">—</div></div></div>
  </div>

  <div class="row g-4">
    <!-- Left column: filters + table -->
    <div class="col-xl-7">
      <div class="page-card mb-3">
        <div class="page-card-header">
          <form class="row g-2 align-items-end filter-row" onsubmit="event.preventDefault();loadList()">
            <div class="col-sm-3">
              <label class="form-label small text-muted mb-1">Status</label>
              <select class="form-select form-select-sm" id="fStatus">
                <option value="ALL" selected>All</option>
                <option value="OPEN">Open</option>
                <option value="CLOSED">Closed</option>
                <option value="PARTIAL">Partial</option>
              </select>
            </div>
            <div class="col-sm-3">
              <label class="form-label small text-muted mb-1">From</label>
              <input type="date" class="form-control form-control-sm" id="fFrom">
            </div>
            <div class="col-sm-3">
              <label class="form-label small text-muted mb-1">To</label>
              <input type="date" class="form-control form-control-sm" id="fTo">
            </div>
            <div class="col-sm-3">
              <label class="form-label small text-muted mb-1">Search ID</label>
              <input type="text" class="form-control form-control-sm" id="fSearch" placeholder="PAL-…">
            </div>
            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-dark btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">Reset</button>
              <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" onclick="loadList()"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
          </form>
        </div>
      </div>

      <div class="page-card p-0">
        <table class="table table-hover mb-0" id="palletTable">
          <thead>
            <tr>
              <th style="padding:12px 16px">Pallet ID</th>
              <th>Status</th>
              <th class="text-center">Cases</th>
              <th>Created</th>
              <th>Closed</th>
              <th class="text-end" style="padding-right:16px">Actions</th>
            </tr>
          </thead>
          <tbody id="palletTbody">
            <tr><td colspan="6" class="text-center text-muted py-5">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Right column: detail panel -->
    <div class="col-xl-5">
      <div class="detail-panel" id="detailPanel">
        <div class="empty-state"><i class="bi bi-box-seam"></i>Select a pallet to see details</div>
      </div>
    </div>
  </div>
</div>

<!-- Reprint Modal -->
<div class="modal fade" id="reprintModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header border-bottom">
        <h5 class="modal-title fw-bold"><i class="bi bi-printer me-2 text-primary"></i>Reprint Label</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body"><p class="text-muted small mb-2">Uses the saved <strong>Pallet Label Printer (Zebra)</strong>.</p><div id="reprintPalletId" class="d-none"></div></div>
      <div class="modal-footer border-top">
        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-dark btn-sm" onclick="doReprint()"><i class="bi bi-printer me-1"></i>Print</button>
      </div>
    </div>
  </div>
</div>

<script>
let currentPid = null;

async function api(data) {
  const fd = new FormData();
  fd.append('ajax','1');
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  const r = await fetch(location.pathname, {method:'POST', body:fd});
  return r.json();
}
function esc(s) { const d=document.createElement('div'); d.textContent=s??''; return d.innerHTML; }

async function savePalletPrintSettings(){
  const st=document.getElementById('palletPrintStatus');
  if(st){st.textContent='Saving…';st.className='small text-warning';}
  const r=await api({action:'save_print_settings',label_printer_id:document.getElementById('palletLabelPrinter')?.value||'',report_printer:document.getElementById('palletReportPrinter')?.value||''});
  if(st){st.textContent=r.ok?'✓ Saved':'⚠ '+(r.err||'Save failed');st.className=r.ok?'small text-success fw-semibold':'small text-danger fw-semibold';}
}
document.getElementById('palletLabelPrinter')?.addEventListener('change',savePalletPrintSettings);
document.getElementById('palletReportPrinter')?.addEventListener('change',savePalletPrintSettings);

async function viewReport(pid){
  const r=await api({action:'report',pallet_id:pid});
  if(!r.ok){alert(r.error||r.err||'Report generation failed');return;}
  window.open(r.url,'_blank');
}
async function printReport(pid){
  const r=await api({action:'print_report',pallet_id:pid});
  if(!r.ok){alert(r.error||r.err||'Report print failed');return;}
  if(r.queued) alert('Report queued to '+r.printer+(r.job_id?' · Job #'+r.job_id:''));
  else if(r.skipped) alert('Report PDF generated. No report printer is selected.');
  else alert('Report generated.');
}


async function loadList() {
  const r = await api({
    action:'list',
    status: document.getElementById('fStatus').value,
    search: document.getElementById('fSearch').value,
    date_from: document.getElementById('fFrom').value,
    date_to:   document.getElementById('fTo').value,
  });
  if (!r.ok) { alert(r.err); return; }
  const rows = r.pallets || [];
  const open    = rows.filter(x=>x.status==='OPEN').length;
  const partial = rows.filter(x=>x.status==='PARTIAL').length;
  const closed  = rows.filter(x=>x.status==='CLOSED').length;
  document.getElementById('kTotal').textContent   = rows.length;
  document.getElementById('kOpen').textContent    = open;
  document.getElementById('kPartial').textContent = partial;
  document.getElementById('kClosed').textContent  = closed;

  const tb = document.getElementById('palletTbody');
  if (!rows.length) {
    tb.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">No pallets found</td></tr>';
    return;
  }
  tb.innerHTML = rows.map(p => `
    <tr onclick="showDetail('${esc(p.pallet_id)}')" data-pid="${esc(p.pallet_id)}">
      <td style="padding-left:16px"><span class="fw-semibold" style="font-family:monospace;font-size:.82rem;color:#0f172a">${esc(p.pallet_id)}</span></td>
      <td><span class="badge-${(p.status||'').toLowerCase()}">${esc(p.status)}</span></td>
      <td class="text-center fw-semibold">${esc(p.actual_cases)}</td>
      <td class="text-muted" style="font-size:.82rem">${esc(p.created_at)}</td>
      <td class="text-muted" style="font-size:.82rem">${p.closed_at||'—'}</td>
      <td class="text-end" style="padding-right:12px">
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-secondary" title="Reprint" onclick="event.stopPropagation();openReprint('${esc(p.pallet_id)}')"><i class="bi bi-printer"></i></button>
          <button class="btn btn-outline-primary" title="View PDF Report" onclick="event.stopPropagation();viewReport('${esc(p.pallet_id)}')"><i class="bi bi-file-earmark-pdf"></i></button>
          <button class="btn btn-outline-success" title="Print Report" onclick="event.stopPropagation();printReport('${esc(p.pallet_id)}')"><i class="bi bi-file-earmark-arrow-up"></i></button>
          ${(p.status==='CLOSED'||p.status==='PARTIAL')?`<button class="btn btn-outline-warning" title="Reopen" onclick="event.stopPropagation();doReopen('${esc(p.pallet_id)}','${esc(p.status)}')"><i class="bi bi-unlock"></i></button>`:''}
          <button class="btn btn-outline-danger" title="Delete" onclick="event.stopPropagation();doDelete('${esc(p.pallet_id)}','${esc(p.status)}')"><i class="bi bi-trash"></i></button>
        </div>
      </td>
    </tr>`).join('');
}

async function showDetail(pid) {
  currentPid = pid;
  document.querySelectorAll('#palletTbody tr').forEach(r => r.classList.toggle('selected', r.dataset.pid===pid));
  const panel = document.getElementById('detailPanel');
  panel.innerHTML = '<div class="empty-state"><div class="spinner-border spinner-border-sm text-primary"></div><div class="mt-2">Loading…</div></div>';

  const r = await api({action:'detail', pallet_id:pid});
  if (!r.ok) { panel.innerHTML=`<div class="alert alert-danger m-3">${esc(r.err)}</div>`; return; }
  const p     = r.pallet;
  const cases = r.cases || [];
  const ship  = r.shipment;

  panel.innerHTML = `
    <div class="d-flex align-items-center gap-2 mb-4 flex-wrap">
      <span class="fw-bold" style="font-family:monospace;font-size:.9rem;color:#0f172a">${esc(p.pallet_id)}</span>
      <span class="badge-${(p.status||'').toLowerCase()}">${esc(p.status)}</span>
      <div class="ms-auto d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" onclick="openReprint('${esc(p.pallet_id)}')"><i class="bi bi-printer"></i></button>
        <button class="btn btn-outline-primary btn-sm" title="PDF Report" onclick="viewReport('${esc(p.pallet_id)}')"><i class="bi bi-file-earmark-pdf"></i></button>
        <button class="btn btn-outline-success btn-sm" title="Print Report" onclick="printReport('${esc(p.pallet_id)}')"><i class="bi bi-file-earmark-arrow-up"></i></button>
        ${(p.status==='CLOSED'||p.status==='PARTIAL')?`<button class="btn btn-outline-warning btn-sm" onclick="doReopen('${esc(p.pallet_id)}','${esc(p.status)}')"><i class="bi bi-unlock me-1"></i>Reopen</button>`:''}
      </div>
    </div>

    <div class="detail-section-title">Pallet Info</div>
    <div class="meta-grid mb-3">
      <div><div class="label">Created</div><div class="value">${esc(p.created_fmt||'—')}</div></div>
      <div><div class="label">Closed</div><div class="value">${esc(p.closed_fmt||'—')}</div></div>
      <div><div class="label">Cases</div><div class="value">${cases.length}</div></div>
      <div><div class="label">Shipment</div><div class="value">${ship?`<a href="shipments_manage.php?highlight=${esc(ship.shipment_id)}" class="ship-link">${esc(ship.shipment_id)}</a>`:'Not shipped'}</div></div>
    </div>

    ${ship?`<div class="alert alert-info py-2 px-3 small mb-3 rounded-3">
      Linked to <strong>${esc(ship.shipment_id)}</strong> (${esc(ship.ship_status)})${ship.po?' &mdash; PO: <strong>'+esc(ship.po)+'</strong>':''}
    </div>`:''}

    <div class="detail-section-title">Cases (${cases.length})</div>
    <div style="max-height:360px;overflow-y:auto;border:1px solid #e8edf2;border-radius:10px">
      <table class="table table-sm mb-0 case-table">
        <thead><tr>
          <th style="padding:8px 12px">#</th>
          <th>Serial</th><th>Variety</th><th>Grower</th><th>Size</th><th></th>
        </tr></thead>
        <tbody>
          ${cases.length===0
            ? '<tr><td colspan="6" class="text-center text-muted py-3">No cases scanned</td></tr>'
            : cases.map((c,i)=>`<tr class="case-row">
                <td style="padding-left:12px;color:#94a3b8">${i+1}</td>
                <td style="font-family:monospace;font-size:.77rem;color:#334155">${esc(c.case_serial)}</td>
                <td>${esc(c.variety||'—')}</td>
                <td style="color:#64748b">${esc(c.grower||'—')}</td>
                <td style="color:#64748b">${esc(c.size||'—')}</td>
                <td><button class="btn btn-outline-danger btn-sm" style="padding:1px 7px;font-size:.75rem"
                  onclick="removeCase(${c.id},'${esc(p.pallet_id)}')"><i class="bi bi-x"></i></button></td>
              </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

function closedPalletPassword(action,pid){
  return prompt(`Password required to ${action} CLOSED pallet ${pid}:`) || '';
}

async function doReopen(pid, knownStatus='') {
  if (!confirm(`Reopen pallet ${pid}?`)) return;

  let admin_password='';
  if((knownStatus||'').toUpperCase()==='CLOSED'){
    admin_password=closedPalletPassword('reopen',pid);
    if(!admin_password)return;
  }

  let r=await api({action:'reopen',pallet_id:pid,admin_password});

  // If the UI did not know the status, server remains authoritative.
  if(!r.ok && r.password_required && !admin_password){
    admin_password=closedPalletPassword('reopen',pid);
    if(!admin_password)return;
    r=await api({action:'reopen',pallet_id:pid,admin_password});
  }

  if(r.ok){
    loadList();
    showDetail(pid);
    alert(r.was_status==='CLOSED'
      ? 'Protected pallet unlocked and reopened. Make the changes, then close it again to regenerate the final label/report.'
      : (r.msg||'Pallet reopened'));
  }else{
    alert(r.err||'Unable to reopen pallet');
  }
}

async function doDelete(pid, knownStatus='') {
  if (!confirm(`Delete pallet ${pid}? This cannot be undone.`)) return;

  let admin_password='';
  if((knownStatus||'').toUpperCase()==='CLOSED'){
    admin_password=closedPalletPassword('delete',pid);
    if(!admin_password)return;
  }

  let r=await api({action:'delete',pallet_id:pid,admin_password});
  if(!r.ok && r.password_required && !admin_password){
    admin_password=closedPalletPassword('delete',pid);
    if(!admin_password)return;
    r=await api({action:'delete',pallet_id:pid,admin_password});
  }

  if(r.ok){
    loadList();
    document.getElementById('detailPanel').innerHTML=
      '<div class="empty-state"><i class="bi bi-check-circle text-success"></i>Pallet deleted</div>';
  }else alert(r.err||'Delete failed');
}

async function removeCase(cid,pid) {
  if (!confirm('Remove this case from the pallet?')) return;
  const r=await api({action:'remove_case',case_id:cid,pallet_id:pid});

  if(r.must_reopen){
    alert('This pallet is complete and CLOSED. Use Reopen first; the protected password will be requested.');
    return;
  }

  if(r.ok){
    loadList();
    showDetail(pid);
  }else alert(r.err||'Unable to remove case');
}

async function openReprint(pid) {
  document.getElementById('reprintPalletId').textContent = pid;
  new bootstrap.Modal(document.getElementById('reprintModal')).show();
}
async function doReprint() {
  const pid = document.getElementById('reprintPalletId').textContent;
  const r   = await api({action:'reprint', pallet_id:pid});
  bootstrap.Modal.getInstance(document.getElementById('reprintModal'))?.hide();
  alert(r.msg || r.err || 'Done');
}
function clearFilters() {
  document.getElementById('fStatus').value = 'ALL';
  document.getElementById('fSearch').value = '';
  document.getElementById('fFrom').value   = '';
  document.getElementById('fTo').value     = '';
  loadList();
}
const urlP = new URLSearchParams(location.search);
if (urlP.get('highlight')) setTimeout(()=>showDetail(urlP.get('highlight')), 400);

loadList();
</script>

<?php include '../includes/footer.php'; ?>
