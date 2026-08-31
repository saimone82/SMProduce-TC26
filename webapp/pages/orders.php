<?php
require_once __DIR__ . '/../config/user_functions.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../config/users_local.php';
require_once __DIR__ . '/../config/orders_sql_lib.php';

if (!user_has_permission('orders')) {
    http_response_code(403);
    include '../includes/header.php';
    echo "<div class='container-fluid py-4'><h3 class='text-danger'>Access denied</h3></div>";
    include '../includes/footer.php';
    exit;
}

if (!orders_sql_ready()) {
    include '../includes/header.php';
    echo "<div class='container-fluid py-4'><div class='alert alert-danger'>PDO database connection not available. Orders page requires SQL connection.</div></div>";
    include '../includes/footer.php';
    exit;
}

orders_sql_init();

// ── AJAX delete handler ──────────────────────────────────────────────────
if (!empty($_POST['ajax']) && ($_POST['action'] ?? '') === 'delete_order') {
    header('Content-Type: application/json');
    $del_id = (int)($_POST['id'] ?? 0);
    if ($del_id <= 0) { echo json_encode(['ok'=>0,'err'=>'Invalid ID']); exit; }
    $ok = orders_delete_sql($del_id);
    echo json_encode($ok ? ['ok'=>1] : ['ok'=>0,'err'=>'Delete failed']); exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$shipFrom = trim((string)($_GET['ship_from'] ?? ''));
$shipTo = trim((string)($_GET['ship_to'] ?? ''));
$packingClientId = (int)($_GET['packing_client_id'] ?? 0);
$packingSkuId = (int)($_GET['packing_sku_id'] ?? 0);
$packingVariety = trim((string)($_GET['packing_variety'] ?? ''));
$packingPackaging = trim((string)($_GET['packing_packaging'] ?? ''));
$packingSize = trim((string)($_GET['packing_size'] ?? ''));
$packingShipFrom = trim((string)($_GET['packing_ship_from'] ?? ''));
$packingShipTo = trim((string)($_GET['packing_ship_to'] ?? ''));
$packingGroupBy = trim((string)($_GET['packing_group_by'] ?? 'client_sku'));
$showPacking = isset($_GET['show_packing']) && $_GET['show_packing'] === '1';

$summary = orders_fetch_summary_sql();
$orders = orders_fetch_list_sql($q, $status, $shipFrom, $shipTo);
$packingFilterOptions = orders_fetch_open_packing_filter_options_sql();
$packingSummary = $showPacking ? orders_fetch_open_packing_summary_sql([
    'client_id' => $packingClientId,
    'sku_id' => $packingSkuId,
    'variety' => $packingVariety,
    'packaging' => $packingPackaging,
    'size' => $packingSize,
    'ship_from' => $packingShipFrom,
    'ship_to' => $packingShipTo,
    'group_by' => $packingGroupBy,
]) : [];
$packingGroupLabels = [
    'by_po'      => 'By PO',
    'client_sku' => 'Client + SKU',
    'sku'        => 'SKU Only',
    'variety'    => 'Variety',
    'packaging'  => 'Packaging',
    'size'       => 'Size',
];

include '../includes/header.php';
?>
<style>
.orders-page .page-card{border:1px solid #dfe5ec;border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.05)}
.orders-page .page-card-header{padding:20px 22px;border-bottom:1px solid #e8edf2;background:#fff;border-top-left-radius:18px;border-top-right-radius:18px}
.orders-page .kpi-card{border:1px solid #e3e8ef;border-radius:16px;background:linear-gradient(180deg,#fff 0%,#f8fbfd 100%);padding:18px 18px;height:100%}
.orders-page .kpi-label{font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:800;margin-bottom:8px}
.orders-page .kpi-value{font-size:1.9rem;font-weight:800;color:#0f172a;line-height:1}
.orders-page .kpi-sub{margin-top:8px;color:#64748b;font-size:.9rem}
.orders-page .table thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:#475569;border-bottom-width:1px}
.orders-page .badge-soft{display:inline-flex;align-items:center;padding:.45rem .7rem;border-radius:999px;font-weight:700;font-size:.78rem}
.orders-page .actions .btn{min-width:76px}
.orders-page .notes-cell{max-width:250px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.orders-page .kpi-toggle{text-decoration:none;color:inherit;display:block}
.orders-page .kpi-toggle:hover .kpi-card{border-color:#cbd5e1;box-shadow:0 10px 22px rgba(15,23,42,.08)}
.orders-page .packing-box{border-top:1px solid #e8edf2;background:#fbfdff}
</style>
<div class="container-fluid py-4 orders-page">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h2 class="mb-1">Orders</h2>
            <div class="text-muted">Order management, printable reports and open packing requirements overview.</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="orders_mix_add.php" class="btn btn-warning fw-bold">+ New MIX Order</a>
            <a href="orders_add.php" class="btn btn-dark">+ New Order</a>
        </div>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Order created successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Order updated successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Order deleted successfully.</div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Total Orders</div><div class="kpi-value" id="kpiTotal"><?= (int)$summary['total_orders'] ?></div><div class="kpi-sub">All orders in the SQL module.</div></div></div>
        <div class="col-md-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Open Orders</div><div class="kpi-value" id="kpiOpen"><?= (int)$summary['open_orders'] ?></div><div class="kpi-sub">Still active and not closed.</div></div></div>
        <div class="col-md-6 col-xl-3"><div class="kpi-card"><div class="kpi-label">Closed Orders</div><div class="kpi-value" id="kpiClosed"><?= (int)$summary['closed_orders'] ?></div><div class="kpi-sub">Closed or shipped orders.</div></div></div>
        <div class="col-md-6 col-xl-3">
            <a class="kpi-toggle" href="?<?= http_build_query(array_merge($_GET, ['show_packing' => '1', 'packing_group_by' => 'by_po'])) ?>#packingSummaryBox" id="kpiOpenQtyCard">
                <div class="kpi-card kpi-card-highlight" style="border-color:#f59e0b;background:linear-gradient(180deg,#fffbeb 0%,#fef3c7 100%)">
                    <div class="kpi-label" style="color:#b45309">📦 Cases to Produce</div>
                    <div class="kpi-value" id="kpiQty" style="color:#92400e"><?= (int)$summary['open_quantity'] ?></div>
                    <div class="kpi-sub" style="color:#92400e;font-size:.78rem;margin-top:6px">
                        From <strong><?= (int)$summary['open_orders'] ?></strong> open order<?= $summary['open_orders'] != 1 ? 's' : '' ?>
                        &nbsp;·&nbsp; <span style="text-decoration:underline">Click to drill down ↓</span>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <?php if ($showPacking): ?>
    <?php
        $packingTotal = 0;
        $packingOrdersTotal = 0;
        foreach ($packingSummary as $packingRowMeta) {
            $packingTotal += (int)($packingRowMeta['qty_to_pack'] ?? 0);
            $packingOrdersTotal += (int)($packingRowMeta['orders_count'] ?? 0);
        }
    ?>
    <div id="packingSummaryBox" class="page-card mb-4">
        <div class="page-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-1">📦 Cases to Produce — Open Orders</h5>
                <div class="text-muted small">Quantities from OPEN orders only. Use <strong>Group By → By PO</strong> for per-order drill-down.</div>
            </div>
            <a href="?<?= http_build_query(array_merge($_GET, ['show_packing' => '0'])) ?>" class="btn btn-sm btn-outline-secondary">Close</a>
        </div>
        <div class="packing-box p-4">
            <form method="get" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="q" value="<?= orders_h($q) ?>">
                <input type="hidden" name="status" value="<?= orders_h($status) ?>">
                <input type="hidden" name="ship_from" value="<?= orders_h($shipFrom) ?>">
                <input type="hidden" name="ship_to" value="<?= orders_h($shipTo) ?>">
                <input type="hidden" name="show_packing" value="1">

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Client</label>
                    <select name="packing_client_id" class="form-select">
                        <option value="0">All clients</option>
                        <?php foreach (($packingFilterOptions['clients'] ?? []) as $opt): ?>
                            <option value="<?= (int)$opt['id'] ?>" <?= $packingClientId === (int)$opt['id'] ? 'selected' : '' ?>><?= orders_h($opt['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">SKU</label>
                    <select name="packing_sku_id" class="form-select">
                        <option value="0">All SKU</option>
                        <?php foreach (($packingFilterOptions['skus'] ?? []) as $opt): ?>
                            <option value="<?= (int)$opt['id'] ?>" <?= $packingSkuId === (int)$opt['id'] ? 'selected' : '' ?>><?= orders_h($opt['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Variety</label>
                    <select name="packing_variety" class="form-select">
                        <option value="">All varieties</option>
                        <?php foreach (($packingFilterOptions['varieties'] ?? []) as $opt): ?>
                            <option value="<?= orders_h($opt['value']) ?>" <?= $packingVariety === (string)$opt['value'] ? 'selected' : '' ?>><?= orders_h($opt['value']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Packaging</label>
                    <select name="packing_packaging" class="form-select">
                        <option value="">All packaging</option>
                        <?php foreach (($packingFilterOptions['packaging'] ?? []) as $opt): ?>
                            <option value="<?= orders_h($opt['value']) ?>" <?= $packingPackaging === (string)$opt['value'] ? 'selected' : '' ?>><?= orders_h($opt['value']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Size</label>
                    <select name="packing_size" class="form-select">
                        <option value="">All sizes</option>
                        <?php foreach (($packingFilterOptions['sizes'] ?? []) as $opt): ?>
                            <option value="<?= orders_h($opt['value']) ?>" <?= $packingSize === (string)$opt['value'] ? 'selected' : '' ?>><?= orders_h($opt['value']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Group By</label>
                    <select name="packing_group_by" class="form-select">
                        <?php foreach ($packingGroupLabels as $groupKey => $groupLabel): ?>
                            <option value="<?= orders_h($groupKey) ?>" <?= $packingGroupBy === $groupKey ? 'selected' : '' ?>><?= orders_h($groupLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Ship Date From</label>
                    <input type="date" name="packing_ship_from" class="form-control" value="<?= orders_h($packingShipFrom) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Ship Date To</label>
                    <input type="date" name="packing_ship_to" class="form-control" value="<?= orders_h($packingShipTo) ?>">
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
                <div class="col-md-2 d-grid">
                    <a href="?<?= http_build_query(array_merge($_GET, ['show_packing' => '1', 'packing_client_id' => 0, 'packing_sku_id' => 0, 'packing_variety' => '', 'packing_packaging' => '', 'packing_size' => '', 'packing_ship_from' => '', 'packing_ship_to' => '', 'packing_group_by' => 'client_sku'])) ?>#packingSummaryBox" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>

            <div class="d-flex flex-wrap gap-3 mb-3 align-items-center">
                <span class="badge" style="background:#f59e0b;color:#fff;font-size:.9rem;padding:8px 16px">
                    📦 <?= number_format((int)$packingTotal) ?> cases to produce
                </span>
                <span class="badge bg-primary" style="font-size:.82rem;padding:6px 12px">
                    <?= (int)$packingOrdersTotal ?> open orders
                </span>
                <small class="text-muted ms-2">View: <strong><?= orders_h($packingGroupLabels[$packingGroupBy] ?? 'Client + SKU') ?></strong></small>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <?php if ($packingGroupBy === 'by_po'): ?>
                        <tr>
                            <th>PO</th>
                            <th>Client</th>
                            <th>Ship Date</th>
                            <th class="text-end">Qty to Pack</th>
                            <th></th>
                        </tr>
                    <?php elseif ($packingGroupBy === 'variety' || $packingGroupBy === 'packaging' || $packingGroupBy === 'size'): ?>
                        <tr>
                            <th><?= orders_h($packingGroupLabels[$packingGroupBy] ?? 'Group') ?></th>
                            <th class="text-end">Qty to Pack</th>
                            <th class="text-end">Open Orders</th>
                            <th>Ship Window</th>
                        </tr>
                    <?php elseif ($packingGroupBy === 'sku'): ?>
                        <tr>
                            <th>SKU</th>
                            <th>Variety</th>
                            <th>Packaging</th>
                            <th>Size</th>
                            <th class="text-end">Qty to Pack</th>
                            <th class="text-end">Open Orders</th>
                            <th>Ship Window</th>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th>Client</th>
                            <th>SKU</th>
                            <th>Variety</th>
                            <th>Packaging</th>
                            <th>Size</th>
                            <th class="text-end">Qty to Pack</th>
                            <th class="text-end">Open Orders</th>
                            <th>Ship Window</th>
                        </tr>
                    <?php endif; ?>
                    </thead>
                    <tbody>
                    <?php if (!$packingSummary): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No open packing requirements found for the selected filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($packingSummary as $row): ?>
                            <?php $shipWindow = trim((string)($row['first_ship_date'] ?? '')); $lastShip = trim((string)($row['last_ship_date'] ?? '')); if ($shipWindow !== '' && $lastShip !== '' && $lastShip !== $shipWindow) { $shipWindow .= ' → ' . $lastShip; } elseif ($shipWindow === '') { $shipWindow = '—'; } ?>
                            <?php if ($packingGroupBy === 'by_po'): ?>
                                <tr>
                                    <td class="fw-bold">
                                        <a href="?q=<?= urlencode($row['po'] ?? '') ?>&status=OPEN" class="text-decoration-none text-dark">
                                            <?= orders_h($row['po'] ?? '—') ?>
                                        </a>
                                    </td>
                                    <td><?= orders_h($row['client_name'] ?? '—') ?></td>
                                    <td><?= orders_h($row['ship_date'] ?? '—') ?></td>
                                    <td class="text-end fw-bold text-warning-emphasis"><?= (int)($row['qty_to_pack'] ?? 0) ?></td>
                                    <td>
                                        <a href="orders_edit.php?id=<?= (int)($row['order_id'] ?? 0) ?>" class="btn btn-outline-secondary btn-sm">Edit</a>
                                    </td>
                                </tr>
                            <?php elseif ($packingGroupBy === 'variety' || $packingGroupBy === 'packaging' || $packingGroupBy === 'size'): ?>
                                <tr data-oid="<?= (int)$order['id'] ?>">
                                    <td><strong><?= orders_h($row['group_label'] ?? '—') ?></strong></td>
                                    <td class="text-end fw-bold"><?= (int)($row['qty_to_pack'] ?? 0) ?></td>
                                    <td class="text-end"><?= (int)($row['orders_count'] ?? 0) ?></td>
                                    <td><?= orders_h($shipWindow) ?></td>
                                </tr>
                            <?php elseif ($packingGroupBy === 'sku'): ?>
                                <tr data-oid="<?= (int)$order['id'] ?>">
                                    <td><span class="badge text-bg-dark"><?= (int)($row['sku_id'] ?? 0) ?></span></td>
                                    <td><?= orders_h($row['variety'] ?? '') ?></td>
                                    <td><?= orders_h($row['packaging'] ?? '') ?></td>
                                    <td><?= orders_h($row['size'] ?? '') ?></td>
                                    <td class="text-end fw-bold"><?= (int)($row['qty_to_pack'] ?? 0) ?></td>
                                    <td class="text-end"><?= (int)($row['orders_count'] ?? 0) ?></td>
                                    <td><?= orders_h($shipWindow) ?></td>
                                </tr>
                            <?php else: ?>
                                <tr data-oid="<?= (int)$order['id'] ?>">
                                    <td><strong><?= orders_h($row['client_name'] ?: '—') ?></strong></td>
                                    <td><span class="badge text-bg-dark"><?= (int)($row['sku_id'] ?? 0) ?></span></td>
                                    <td><?= orders_h($row['variety'] ?? '') ?></td>
                                    <td><?= orders_h($row['packaging'] ?? '') ?></td>
                                    <td><?= orders_h($row['size'] ?? '') ?></td>
                                    <td class="text-end fw-bold"><?= (int)($row['qty_to_pack'] ?? 0) ?></td>
                                    <td class="text-end"><?= (int)($row['orders_count'] ?? 0) ?></td>
                                    <td><?= orders_h($shipWindow) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="page-card">
        <div class="page-card-header">
            <form class="row g-3 align-items-end" method="get">
                <div class="col-lg-4">
                    <label class="form-label fw-semibold">Search</label>
                    <input type="text" class="form-control" name="q" value="<?= orders_h($q) ?>" placeholder="Search PO, client, notes...">
                </div>
                <div class="col-lg-2">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <?php foreach (['OPEN','IN SHIPMENT','CLOSED'] as $st): ?>
                            <option value="<?= orders_h($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= orders_h($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label fw-semibold">Ship From</label>
                    <input type="date" class="form-control" name="ship_from" value="<?= orders_h($shipFrom) ?>">
                </div>
                <div class="col-lg-2">
                    <label class="form-label fw-semibold">Ship To</label>
                    <input type="date" class="form-control" name="ship_to" value="<?= orders_h($shipTo) ?>">
                </div>
                <div class="col-lg-2 d-grid gap-2">
                    <button type="submit" class="btn btn-dark">Apply</button>
                    <a href="orders.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">PO</th>
                            <th>Client</th>
                            <th>Ship Date</th>
                            <th>Pick Up Location</th>
                            <th>Dest City</th>
                            <th>Created</th>
                            <th class="text-end">Total Qty</th>
                            <th>Status</th>
                            <th>Notes</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="11" class="text-center text-muted py-5">No orders found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <tr data-oid="<?= (int)$order['id'] ?>" data-status="<?= orders_h((string)($order['status'] ?? 'Open')) ?>">
                                <td class="ps-4 fw-bold"><?= orders_h($order['po']) ?></td>
                                <td><?= orders_h($order['client_name'] ?? '') ?></td>
                                <td><?= orders_h($order['ship_date'] ?? '') ?></td>
                                <td class="text-muted"><?= orders_h($order['pick_location'] ?? '') ?></td>
                                <td class="text-muted"><?= orders_h($order['dest_city'] ?? '') ?></td>
                                <td><?= orders_h($order['created_at'] ?? '') ?></td>
                                <td class="text-end fw-bold"><?= (int)($order['total_quantity'] ?? 0) ?></td>
                                <td><span class="badge <?= orders_status_badge_class((string)($order['status'] ?? 'Open')) ?>"><?= orders_h(orders_status_for_ui((string)($order['status'] ?? 'Open'))) ?></span></td>
                                <td class="notes-cell" title="<?= orders_h($order['notes'] ?? '') ?>"><?= orders_h($order['notes'] ?? '') ?></td>
                                <td class="text-end pe-4 actions">
                                    <div class="btn-group btn-group-sm">
                                        <a href="order_report.php?id=<?= (int)$order['id'] ?>" class="btn btn-outline-secondary">Preview</a>
                                        <a href="order_report.php?id=<?= (int)$order['id'] ?>&autoprint=1" class="btn btn-outline-dark">Print</a>
                                        <a href="orders_edit.php?id=<?= (int)$order['id'] ?>" class="btn btn-primary">Edit</a>
                                        <button class="btn btn-danger" onclick="deleteOrder(<?= (int)$order['id'] ?>, '<?= addslashes(orders_h($order['po'])) ?>')"><i class="bi bi-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script>
async function deleteOrder(id, po) {
  if (!confirm('Delete order ' + po + '?\nThis removes the order and all its lines. This action cannot be undone.')) return;
  const fd = new FormData();
  fd.append('ajax', '1');
  fd.append('action', 'delete_order');
  fd.append('id', id);
  const r = await fetch(location.pathname, {method:'POST', body:fd});
  const d = await r.json().catch(() => ({ok:0, err:'Invalid response'}));
  if (d.ok) {
    const row = document.querySelector('tr[data-oid="' + id + '"]');
    const rowStatus = row ? (row.dataset.status || '').toLowerCase() : '';
    if (row) row.remove();
    // Update KPI counters
    const kpiTotal  = document.getElementById('kpiTotal');
    const kpiOpen   = document.getElementById('kpiOpen');
    const kpiClosed = document.getElementById('kpiClosed');
    if (kpiTotal)  { const n = parseInt(kpiTotal.textContent)||0;  kpiTotal.textContent  = Math.max(0, n-1); }
    if (kpiOpen   && (rowStatus === 'open'   || rowStatus === ''))   { const n = parseInt(kpiOpen.textContent)||0;   kpiOpen.textContent   = Math.max(0, n-1); }
    if (kpiClosed && (rowStatus === 'closed' || rowStatus === 'shipped' || rowStatus === 'fulfilled')) { const n = parseInt(kpiClosed.textContent)||0; kpiClosed.textContent = Math.max(0, n-1); }
  } else {
    alert('Error: ' + (d.err || 'Could not delete'));
  }
}
</script>
<?php include '../includes/footer.php'; ?>
