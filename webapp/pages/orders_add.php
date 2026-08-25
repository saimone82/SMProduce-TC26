<?php
ob_start();
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
    echo "<div class='container-fluid py-4'><div class='alert alert-danger'>PDO database connection not available. Order creation requires SQL connection.</div></div>";
    include '../includes/footer.php';
    exit;
}

orders_sql_init();
// ── AJAX handlers (early exit, JSON only) ───────────────────────────────────
if (isset($_GET['action']) && in_array($_GET['action'],
        ['get_pick_presets','add_pick_preset','get_city_presets','add_city_preset','get_pack_presets','add_pack_preset'], true)) {
    header('Content-Type: application/json');
    orders_sql_init();
    $db = orders_db();
    $act = $_GET['action'];

    if ($act === 'get_pick_presets') {
        $rows = $db->query("SELECT id, label FROM order_pick_presets ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows); exit;
    }
    if ($act === 'add_pick_preset') {
        $label = trim((string)($_GET['label'] ?? ''));
        if ($label === '') { echo json_encode(['ok'=>false,'error'=>'Empty']); exit; }
        $st = $db->prepare("INSERT INTO order_pick_presets (label) VALUES (?)");
        $st->execute([$label]);
        echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId(),'label'=>$label]); exit;
    }
    if ($act === 'get_city_presets') {
        $rows = $db->query("SELECT id, city_label, ship_to_address FROM order_city_presets ORDER BY sort_order, city_label")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows); exit;
    }
    if ($act === 'add_city_preset') {
        $city  = trim((string)($_GET['city']    ?? ''));
        $shipto = trim((string)($_GET['ship_to'] ?? ''));
        if ($city === '') { echo json_encode(['ok'=>false,'error'=>'Empty city']); exit; }
        $st = $db->prepare("INSERT INTO order_city_presets (city_label, ship_to_address) VALUES (?,?)");
        $st->execute([$city, $shipto]);
        echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId(),'city_label'=>$city,'ship_to_address'=>$shipto]); exit;
    }
    if ($act === 'get_pack_presets') {
        try {
            $rows = $db->query("SELECT label FROM order_pack_presets ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(array_column($rows, 'label'));
        } catch (Throwable $e) {
            echo json_encode([]);
        }
        exit;
    }
    if ($act === 'add_pack_preset') {
        $label     = trim((string)($_GET['label']  ?? ''));
        $weightLbs = (float)($_GET['weight_lbs'] ?? 0);
        if ($label === '') { echo json_encode(['ok'=>false,'error'=>'Empty label']); exit; }
        try {
            // crea tabella se non esiste (per sicurezza, include weight_lbs)
            $db->exec("CREATE TABLE IF NOT EXISTS order_pack_presets (
                id INT NOT NULL AUTO_INCREMENT,
                label VARCHAR(200) NOT NULL,
                weight_lbs DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // aggiungi colonna weight_lbs se non esiste
            try { $db->exec("ALTER TABLE order_pack_presets ADD COLUMN weight_lbs DECIMAL(8,2) NOT NULL DEFAULT 0.00"); } catch (Throwable $_e) {}
            $exists = $db->prepare("SELECT id FROM order_pack_presets WHERE label=? LIMIT 1");
            $exists->execute([$label]);
            $row = $exists->fetch();
            if (!$row) {
                $st = $db->prepare("INSERT INTO order_pack_presets (label, weight_lbs) VALUES (?, ?)");
                $st->execute([$label, $weightLbs]);
            } elseif ($weightLbs > 0) {
                // aggiorna peso se fornito
                $db->prepare("UPDATE order_pack_presets SET weight_lbs=? WHERE label=?")->execute([$weightLbs, $label]);
            }
            // ritorna anche weight_lbs per usarlo nel JS
            $preset = $db->prepare("SELECT weight_lbs FROM order_pack_presets WHERE label=? LIMIT 1");
            $preset->execute([$label]);
            $savedWeight = (float)(($preset->fetch(PDO::FETCH_ASSOC))['weight_lbs'] ?? $weightLbs);
            echo json_encode(['ok'=>true,'label'=>$label,'weight_lbs'=>$savedWeight]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }
}
// ─────────────────────────────────────────────────────────────────────────────

orders_sql_init();
$pickPresets = orders_db()->query("SELECT id, label FROM order_pick_presets ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC);
$cityPresets = orders_db()->query("SELECT id, city_label, ship_to_address FROM order_city_presets ORDER BY sort_order, city_label")->fetchAll(PDO::FETCH_ASSOC);

// BUG FIX: $packPresets was never fetched — dropdown was always empty
try {
    $packPresets = orders_db()->query("SELECT label, weight_lbs FROM order_pack_presets ORDER BY sort_order, label")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $packPresets = [];
}

$clients = orders_fetch_clients_sql();
$skus = orders_fetch_skus_sql();

$form = [
    'po'             => '',
    'client_id'      => (string)((int)($_GET['client_id'] ?? 0)),
    'ship_date'      => date('Y-m-d'),
    'notes'          => '',
    'pick_location'  => '',
    'ship_to_address'=> '',
    'dest_city'      => '',
];
$rows = [['sku_id' => '', 'quantity' => '']];
$err = '';
$showClientModal = false;
$clientModalName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'save_order'));
    $form['po'] = trim((string)($_POST['po'] ?? ''));
    $form['client_id'] = trim((string)($_POST['client_id'] ?? ''));
    $form['ship_date'] = trim((string)($_POST['ship_date'] ?? ''));
    $form['notes']            = trim((string)($_POST['notes']             ?? ''));
    $form['pick_location']    = trim((string)($_POST['pick_location']    ?? ''));
    $form['ship_to_address']  = trim((string)($_POST['ship_to_address']  ?? ''));
    $form['dest_city']        = trim((string)($_POST['dest_city']        ?? ''));

    $postedSkuIds    = $_POST['sku_id']             ?? [];
    $postedQtys      = $_POST['quantity']           ?? [];
    $postedPackPresets = $_POST['packaging_preset'] ?? [];
    $rows = [];
    foreach ((array)$postedSkuIds as $idx => $skuId) {
        $rows[] = [
            'sku_id' => trim((string)$skuId),
            'quantity' => trim((string)($postedQtys[$idx] ?? '')),
        ];
    }
    if (!$rows) {
        $rows[] = ['sku_id' => '', 'quantity' => ''];
    }

    if ($action === 'add_client') {
        $clientModalName = trim((string)($_POST['new_client_name'] ?? ''));
        if ($clientModalName === '') {
            $err = 'Client name is required.';
            $showClientModal = true;
        } else {
            $newId = orders_create_client_sql($clientModalName);
            if ($newId > 0) {
                header('Location: orders_add.php?client_id=' . $newId . '&client_added=1');
                exit;
            }
            $err = 'Unable to create the new client.';
            $showClientModal = true;
        }
    }

    if ($action === 'save_order') {
        $normalizedLines = [];
        // BUG FIX: was 'foreach ($rows as $row)' — $idx was undefined, packaging_preset never saved
        foreach ($rows as $idx => $row) {
            $skuId = (int)($row['sku_id'] ?? 0);
            $qty = (int)($row['quantity'] ?? 0);
            if ($skuId <= 0 && $qty <= 0) {
                continue;
            }
            if ($skuId <= 0 || $qty <= 0) {
                $err = 'Complete both SKU and Quantity on every order line.';
                break;
            }
            $packPresetPosted = trim((string)($postedPackPresets[$idx] ?? ''));
            $normalizedLines[] = ['sku_id' => $skuId, 'quantity' => $qty, 'packaging_preset' => $packPresetPosted];
        }

        if ($err === '') {
            $saveError = null;
            $orderId = orders_create_sql([
                'po'             => $form['po'],
                'client_id'      => (int)$form['client_id'],
                'ship_date'      => $form['ship_date'],
                'notes'          => $form['notes'],
                'pick_location'  => $form['pick_location'],
                'ship_to_address'=> $form['ship_to_address'],
                'dest_city'      => $form['dest_city'],
                'status'         => 'Open',
                'lines'          => $normalizedLines,
            ], $saveError);

            if ($orderId > 0) {
                header('Location: orders.php?created=1');
                exit;
            }
            $err = 'Unable to save the order.' . ($saveError ? ' ' . $saveError : '');
        }
    }

    $clients = orders_fetch_clients_sql();
}

include '../includes/header.php';
?>
<style>
.order-shell{width:100%;max-width:none;margin:0}.order-card{border:1px solid #dfe5ec;border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.05);overflow:hidden}
.order-head{padding:22px 24px;border-bottom:1px solid #e7edf2;background:#fff}.order-body{padding:24px}.panel{border:1px solid #e5eaf0;border-radius:16px;background:linear-gradient(180deg,#fff 0%,#f8fbfd 100%);padding:18px;height:100%}
.panel-title{font-size:.82rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:800;margin-bottom:14px}.readonly-pill{display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#f1f5f9;color:#334155;font-size:.85rem;font-weight:700}
.line-table th{font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:#475569}.sku-search{font-size:.9rem}
.sku-search-wrap{position:relative}.sku-suggestions{max-height:320px;overflow-y:auto;background:#fff;border:1px solid #cbd5e1;border-radius:.5rem;box-shadow:0 8px 18px rgba(15,23,42,.10);display:none}.sku-suggestions.show{display:block}.sku-results-count{position:sticky;top:0;z-index:1;padding:7px 12px;background:#f8fafc;border-bottom:1px solid #dbe3ec;color:#475569;font-size:.78rem;font-weight:700}.sku-suggestion{display:block;width:100%;border:0;border-bottom:1px solid #eef2f7;background:#fff;padding:10px 12px;text-align:left;font-size:.88rem;color:#17212b}.sku-suggestion:last-child{border-bottom:0}.sku-suggestion:hover,.sku-suggestion.active{background:#eff6ff;color:#1d4ed8}.sku-suggestion-empty{padding:10px 12px;color:#64748b;font-size:.86rem}.sku-selected{font-size:.82rem;color:#166534;font-weight:700;display:none}.sku-selected.show{display:block}.sku-select{display:none!important}
</style>
<div class="container-fluid py-4 px-3 px-xl-4">
    <div class="order-shell">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
            <div>
                <h2 class="mb-1">New Order</h2>
                <div class="text-muted">Create a multi-line order with SQL client selection, shipment date, notes and printable report.</div>
            </div>
            <a href="orders.php" class="btn btn-outline-secondary">Back to Orders</a>
        </div>

        <?php if (isset($_GET['client_added'])): ?><div class="alert alert-success">Client created successfully.</div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-danger"><?= orders_h($err) ?></div><?php endif; ?>

        <form method="post" class="order-card">
            <div class="order-head d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h4 class="mb-1">Order Setup</h4>
                    <div class="text-muted">PO is free text, Created At is automatic and order lines support multiple SKUs.</div>
                </div>
                <div class="readonly-pill">Created At: automatic on save</div>
            </div>
            <div class="order-body">
                <div class="row g-4 mb-4">
                    <div class="col-xl-8">
                        <div class="panel">
                            <div class="panel-title">Order Information</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">PO</label>
                                    <input type="text" name="po" class="form-control form-control-lg" value="<?= orders_h($form['po']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Ship Date</label>
                                    <input type="date" name="ship_date" class="form-control form-control-lg" value="<?= orders_h($form['ship_date']) ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Client</label>
                                    <div class="input-group input-group-lg">
                                        <select name="client_id" class="form-select" required>
                                            <option value="">Select client...</option>
                                            <?php foreach ($clients as $client): ?>
                                                <option value="<?= (int)$client['id'] ?>" <?= (string)$form['client_id'] === (string)$client['id'] ? 'selected' : '' ?>><?= orders_h($client['client_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#newClientModal">+ New</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="panel">
                            <div class="panel-title">Notes</div>
                            <textarea name="notes" class="form-control" rows="8" placeholder="Shipping instructions, customer requests, pallet notes...\nThis text will also appear in the report."><?= orders_h($form['notes']) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="panel mb-4">
                    <div class="panel-title">Shipping Details</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Pick Up Location</label>
                            <div class="input-group">
                                <select name="pick_location" id="pickLocationSelect" class="form-select">
                                    <option value="">-- select or type below --</option>
                                    <?php foreach ($pickPresets as $pp): ?>
                                    <option value="<?= orders_h($pp['label']) ?>" <?= $form['pick_location']===$pp['label']?'selected':'' ?>><?= orders_h($pp['label']) ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($form['pick_location']!=='' && !in_array($form['pick_location'], array_column($pickPresets,'label'))): ?>
                                    <option value="<?= orders_h($form['pick_location']) ?>" selected><?= orders_h($form['pick_location']) ?></option>
                                    <?php endif; ?>
                                </select>
                                <button type="button" class="btn btn-outline-primary" id="btnNewPick">+ New</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Dest. City</label>
                            <div class="input-group">
                                <select name="dest_city" id="destCitySelect" class="form-select">
                                    <option value="">-- select or type below --</option>
                                    <?php foreach ($cityPresets as $cp): ?>
                                    <option value="<?= orders_h($cp['city_label']) ?>"
                                            data-shipto="<?= orders_h($cp['ship_to_address']) ?>"
                                            <?= $form['dest_city']===$cp['city_label']?'selected':'' ?>
                                    ><?= orders_h($cp['city_label']) ?></option>
                                    <?php endforeach; ?>
                                    <?php if ($form['dest_city']!=='' && !in_array($form['dest_city'], array_column($cityPresets,'city_label'))): ?>
                                    <option value="<?= orders_h($form['dest_city']) ?>" selected><?= orders_h($form['dest_city']) ?></option>
                                    <?php endif; ?>
                                </select>
                                <button type="button" class="btn btn-outline-primary" id="btnNewCity">+ New</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Ship To <span class="text-muted fw-normal">(multiline)</span></label>
                            <textarea name="ship_to_address" class="form-control" rows="3" placeholder="Consignee name&#10;Address line 1&#10;City, Province, ZIP"><?= orders_h($form['ship_to_address']) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <div class="panel-title mb-1">Order Lines</div>
                            <div class="text-muted small">Each row contains a searchable SKU select and a quantity.</div>
                        </div>
                        <button type="button" class="btn btn-outline-primary" id="addLineBtn">+ Add Line</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle line-table mb-0" id="orderLinesTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:60px;">#</th>
                                    <th>Product (Variety / Pack / Size)</th>
                                    <th style="width:200px;">Packaging</th>
                                    <th style="width:140px;">Quantity</th>
                                    <th style="width:90px;" class="text-end">Remove</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $idx => $row): ?>
                                    <tr>
                                        <td class="row-num"><?= $idx + 1 ?></td>
                                        <td>
                                            <div class="vstack gap-2 sku-search-wrap">
                                                <input type="text" class="form-control sku-search" placeholder="Type words in any order: Gala bag 100..." autocomplete="off">
                                                <div class="sku-suggestions" role="listbox"></div>
                                                <div class="sku-selected"></div>
                                                <select name="sku_id[]" class="form-select sku-select" aria-hidden="true" tabindex="-1">
                                                    <option value="">Select SKU...</option>
                                                    <?php foreach ($skus as $sku): ?>
                                                        <?php
                                          $skuDesc = trim(implode(' - ', array_values(array_filter([(string)($sku['variety'] ?? ''), (string)($sku['packaging'] ?? ''), (string)($sku['size'] ?? '')], fn($v) => $v !== ''))));
                                          if ($skuDesc === '') $skuDesc = 'SKU ' . $sku['sku_id'];
                                        ?>
                                        <option value="<?= (int)$sku['sku_id'] ?>"
                                                data-search="<?= orders_h(implode(' ', [(string)$sku['sku_id'], (string)($sku['variety'] ?? ''), (string)($sku['packaging'] ?? ''), (string)($sku['size'] ?? '')])) ?>"
                                                data-sku="<?= orders_h((string)$sku['sku_id']) ?>"
                                                data-variety="<?= orders_h((string)($sku['variety'] ?? '')) ?>"
                                                data-packaging="<?= orders_h((string)($sku['packaging'] ?? '')) ?>"
                                                data-size="<?= orders_h((string)($sku['size'] ?? '')) ?>"
                                                <?= (string)$row['sku_id'] === (string)$sku['sku_id'] ? 'selected' : '' ?>><?= orders_h('SKU ' . $sku['sku_id'] . ' — ' . $skuDesc) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="input-group">
                                                <select name="packaging_preset[]" class="form-select pack-select">
                                                    <option value="">-- select --</option>
                                                    <?php foreach ($packPresets as $pp): ?>
                                                    <option value="<?= orders_h($pp['label']) ?>"><?= orders_h($pp['label']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="btn btn-outline-primary btn-new-pack">+ New</button>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="number" name="quantity[]" class="form-control" min="1" step="1" value="<?= orders_h($row['quantity']) ?>" required>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-outline-danger btn-sm remove-line">Remove</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="d-flex gap-2 flex-wrap mt-4">
                    <button type="submit" name="action" value="save_order" class="btn btn-primary btn-lg px-4" data-disable-on-submit="1">Save Order</button>
                    <a href="orders.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Add Pick Location Preset Modal ─────────────────────── -->
<!-- ═══ Pack Preset Modal ═══ -->
<div class="modal fade" id="newPackModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">New Packaging Preset</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <label class="form-label fw-semibold">Packaging Label</label>
      <input type="text" id="newPackLabel" class="form-control mb-2" placeholder="e.g. 10kg Bags">
      <label class="form-label fw-semibold">Weight per case (lbs)</label>
      <input type="number" id="newPackWeight" class="form-control" min="0" step="0.01" placeholder="e.g. 22.05">
      <div id="newPackErr" class="text-danger small mt-1" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="button" class="btn btn-primary" id="savePackPreset">Save</button>
    </div>
  </div></div>
</div>

<div class="modal fade" id="newPickModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header"><h6 class="modal-title fw-bold">New Pick Location</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <label class="form-label fw-semibold">Location name</label>
        <input type="text" id="newPickLabel" class="form-control" placeholder="e.g. Warehouse A">
        <div id="newPickErr" class="text-danger small mt-1" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="savePickPreset">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Add Dest City Preset Modal ──────────────────────────── -->
<div class="modal fade" id="newCityModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h6 class="modal-title fw-bold">New Destination City</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold">City / Destination label</label>
          <input type="text" id="newCityLabel" class="form-control" placeholder="e.g. Vancouver, BC">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Ship To address <span class="text-muted fw-normal">(auto-fills when selected)</span></label>
          <textarea id="newCityShipTo" class="form-control" rows="4"
            placeholder="Consignee name&#10;Address line 1&#10;City, Province, ZIP"></textarea>
        </div>
        <div id="newCityErr" class="col-12 text-danger small" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="saveCityPreset">Save</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="newClientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">New Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="po" value="<?= orders_h($form['po']) ?>">
                    <input type="hidden" name="client_id" value="<?= orders_h($form['client_id']) ?>">
                    <input type="hidden" name="ship_date" value="<?= orders_h($form['ship_date']) ?>">
                    <input type="hidden" name="notes" value="<?= orders_h($form['notes']) ?>">
                    <input type="hidden" name="pick_location" value="<?= orders_h($form['pick_location']) ?>">
                    <input type="hidden" name="ship_to_address" value="<?= orders_h($form['ship_to_address']) ?>">
                    <input type="hidden" name="dest_city" value="<?= orders_h($form['dest_city']) ?>">
                    <?php foreach ($rows as $row): ?>
                        <input type="hidden" name="sku_id[]" value="<?= orders_h($row['sku_id']) ?>">
                        <input type="hidden" name="quantity[]" value="<?= orders_h($row['quantity']) ?>">
                    <?php endforeach; ?>
                    <p class="text-muted mb-3">Create a new SQL client and keep the order form values already entered.</p>
                    <label class="form-label fw-semibold">Client Name</label>
                    <input type="text" name="new_client_name" class="form-control" value="<?= orders_h($clientModalName) ?>" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="add_client" class="btn btn-primary">Save Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  /* ── helpers ── */
  function addOption(sel, val, text, shipto){
    const o = document.createElement('option');
    o.value = val; o.textContent = text;
    if (shipto !== undefined) o.dataset.shipto = shipto;
    sel.appendChild(o);
    sel.value = val;
  }

  /* ── Dest City → auto-fill Ship To ── */
  const citySelect   = document.getElementById('destCitySelect');
  const shipToArea   = document.querySelector('textarea[name="ship_to_address"]');
  if (citySelect && shipToArea) {
    citySelect.addEventListener('change', function(){
      const opt = this.options[this.selectedIndex];
      const shipto = opt && opt.dataset.shipto !== undefined ? opt.dataset.shipto : null;
      if (shipto !== null && shipto !== '') shipToArea.value = shipto;
    });
  }

  /* ── Pick Location modal ── */
  const pickModal  = new bootstrap.Modal(document.getElementById('newPickModal'));
  const pickLabel  = document.getElementById('newPickLabel');
  const pickErr    = document.getElementById('newPickErr');
  const pickSelect = document.getElementById('pickLocationSelect');

  document.getElementById('btnNewPick')?.addEventListener('click', function(){
    pickLabel.value = ''; pickErr.style.display = 'none'; pickModal.show();
    setTimeout(() => pickLabel.focus(), 400);
  });
  document.getElementById('savePickPreset')?.addEventListener('click', function(){
    const label = pickLabel.value.trim();
    if (!label){ pickErr.textContent = 'Enter a name.'; pickErr.style.display = ''; return; }
    fetch('orders_add.php?action=add_pick_preset&label=' + encodeURIComponent(label))
      .then(r => r.json()).then(d => {
        if (!d.ok){ pickErr.textContent = d.error || 'Error'; pickErr.style.display = ''; return; }
        addOption(pickSelect, d.label, d.label);
        pickModal.hide();
      }).catch(() => { pickErr.textContent = 'Network error'; pickErr.style.display = ''; });
  });

  /* ── Dest City modal ── */
  const cityModal  = new bootstrap.Modal(document.getElementById('newCityModal'));
  const cityLabel  = document.getElementById('newCityLabel');
  const cityShipTo = document.getElementById('newCityShipTo');
  const cityErr    = document.getElementById('newCityErr');

  document.getElementById('btnNewCity')?.addEventListener('click', function(){
    cityLabel.value = ''; cityShipTo.value = ''; cityErr.style.display = 'none'; cityModal.show();
    setTimeout(() => cityLabel.focus(), 400);
  });
  document.getElementById('saveCityPreset')?.addEventListener('click', function(){
    const city   = cityLabel.value.trim();
    const shipto = cityShipTo.value.trim();
    if (!city){ cityErr.textContent = 'Enter a city name.'; cityErr.style.display = ''; return; }
    fetch('orders_add.php?action=add_city_preset&city=' + encodeURIComponent(city) + '&ship_to=' + encodeURIComponent(shipto))
      .then(r => r.json()).then(d => {
        if (!d.ok){ cityErr.textContent = d.error || 'Error'; cityErr.style.display = ''; return; }
        addOption(citySelect, d.city_label, d.city_label, d.ship_to_address);
        if (shipToArea && d.ship_to_address) shipToArea.value = d.ship_to_address;
        cityModal.hide();
      }).catch(() => { cityErr.textContent = 'Network error'; cityErr.style.display = ''; });
  });

  /* ── Pack Preset modal ── */
  const packModal  = new bootstrap.Modal(document.getElementById('newPackModal'));
  const packLabel  = document.getElementById('newPackLabel');
  const packErr    = document.getElementById('newPackErr');
  let   activePackSelect = null;

  document.getElementById('orderLinesTable')?.addEventListener('click', function(e){
    if (!e.target.classList.contains('btn-new-pack')) return;
    activePackSelect = e.target.closest('td')?.querySelector('.pack-select') || null;
    packLabel.value = ''; if(document.getElementById('newPackWeight')) document.getElementById('newPackWeight').value=''; packErr.style.display = 'none'; packModal.show();
    setTimeout(() => packLabel.focus(), 400);
  });

  document.getElementById('savePackPreset')?.addEventListener('click', function(){
    const label = packLabel.value.trim();
    if (!label){ packErr.textContent = 'Enter a packaging label.'; packErr.style.display = ''; return; }
    // BUG FIX: weight_lbs was never sent to the server
    const weightVal = parseFloat(document.getElementById('newPackWeight')?.value || 0) || 0;
    fetch('orders_add.php?action=add_pack_preset&label=' + encodeURIComponent(label) + '&weight_lbs=' + weightVal)
      .then(r => r.text().then(txt => {
        try { return JSON.parse(txt); }
        catch(e) { throw new Error('Server response (HTTP ' + r.status + '): ' + txt.substring(0,200)); }
      })).then(d => {
        if (!d.ok){ packErr.textContent = d.error || 'Error'; packErr.style.display = ''; return; }
        document.querySelectorAll('.pack-select').forEach(sel => {
          if (!Array.from(sel.options).some(o => o.value === d.label)) {
            const o = document.createElement('option');
            o.value = o.textContent = d.label;
            o.dataset.weight = d.weight_lbs || 0;
            sel.appendChild(o);
          }
        });
        if (activePackSelect) activePackSelect.value = d.label;
        packModal.hide();
      }).catch(err => { packErr.textContent = err.message || 'Network error'; packErr.style.display = ''; });
  });
});
</script>

<script>
(function(){
    const tableBody = document.querySelector('#orderLinesTable tbody');
    const addBtn = document.getElementById('addLineBtn');
    if (!tableBody || !addBtn) return;

    function bindRow(row){
        const removeBtn = row.querySelector('.remove-line');
        if(removeBtn){
            removeBtn.addEventListener('click', function(){
                if(tableBody.querySelectorAll('tr').length === 1){
                    row.querySelector('select[name="sku_id[]"]').value = '';
                    row.querySelector('input[name="quantity[]"]').value = '';
                    const search = row.querySelector('.sku-search');
                    if(search) search.value = '';
                    const selected = row.querySelector('.sku-selected');
                    if(selected){ selected.textContent = ''; selected.classList.remove('show'); }
                } else {
                    row.remove();
                    renumber();
                }
            });
        }

        const searchInput = row.querySelector('.sku-search');
        const select = row.querySelector('.sku-select');
        const suggestionBox = row.querySelector('.sku-suggestions');
        const selectedBox = row.querySelector('.sku-selected');
        if(searchInput && select && suggestionBox){
            const originalOptions = Array.from(select.options).map(opt => ({
                value: opt.value,
                text: opt.textContent.trim(),
                search: normalize(opt.dataset.search || opt.textContent),
                fields: [opt.dataset.sku, opt.dataset.variety, opt.dataset.packaging, opt.dataset.size].map(normalize)
            }));
            let matches = [];
            let activeIndex = -1;

            function normalize(value){
                return String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
            }

            function choose(opt){
                select.value = opt.value;
                searchInput.value = opt.text;
                suggestionBox.classList.remove('show');
                if(selectedBox){
                    selectedBox.textContent = 'Selected: ' + opt.text;
                    selectedBox.classList.add('show');
                }
                select.dispatchEvent(new Event('change', {bubbles:true}));
            }

            function renderSuggestions(){
                const terms = normalize(searchInput.value)
                    .split(/\s+/)
                    .filter(Boolean);
                if(!terms.length){
                    matches = [];
                    suggestionBox.innerHTML = '';
                    suggestionBox.classList.remove('show');
                    return;
                }
                if(selectedBox) selectedBox.classList.remove('show');
                select.value = '';
                matches = originalOptions
                    .filter(opt => opt.value !== '' && terms.every(term => opt.fields.some(field => field.includes(term))))
                    .map(function(opt){
                        let score = 0;
                        terms.forEach(function(term){
                            opt.fields.forEach(function(field, index){
                                if(field === term) score += index === 0 ? 300 : 100;
                                else if(field.startsWith(term)) score += index === 0 ? 150 : 50;
                                else if(field.includes(term)) score += 20;
                            });
                        });
                        return Object.assign({}, opt, {score:score});
                    })
                    .sort((a,b) => b.score - a.score || a.text.localeCompare(b.text, undefined, {numeric:true}))
                    .slice(0, 50);
                activeIndex = -1;
                suggestionBox.innerHTML = '';
                if(!matches.length){
                    const empty = document.createElement('div');
                    empty.className = 'sku-suggestion-empty';
                    empty.textContent = 'No matching product found';
                    suggestionBox.appendChild(empty);
                } else {
                    const count = document.createElement('div');
                    count.className = 'sku-results-count';
                    count.textContent = matches.length + (matches.length === 1 ? ' product found' : ' products found');
                    suggestionBox.appendChild(count);
                    matches.forEach(function(opt){
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'sku-suggestion';
                        button.textContent = opt.text;
                        button.addEventListener('mousedown', function(e){ e.preventDefault(); choose(opt); });
                        suggestionBox.appendChild(button);
                    });
                }
                suggestionBox.classList.add('show');
            }

            searchInput.addEventListener('focus', function(){ if(this.value.trim() !== '') renderSuggestions(); });
            searchInput.addEventListener('input', renderSuggestions);
            searchInput.addEventListener('keydown', function(e){
                if(!suggestionBox.classList.contains('show')) return;
                const buttons = suggestionBox.querySelectorAll('.sku-suggestion');
                if(e.key === 'ArrowDown' || e.key === 'ArrowUp'){
                    e.preventDefault();
                    if(!buttons.length) return;
                    activeIndex = e.key === 'ArrowDown'
                        ? (activeIndex + 1) % buttons.length
                        : (activeIndex - 1 + buttons.length) % buttons.length;
                    buttons.forEach((b, i) => b.classList.toggle('active', i === activeIndex));
                    buttons[activeIndex].scrollIntoView({block:'nearest'});
                } else if(e.key === 'Enter' && activeIndex >= 0 && matches[activeIndex]){
                    e.preventDefault(); choose(matches[activeIndex]);
                } else if(e.key === 'Escape'){
                    suggestionBox.classList.remove('show');
                }
            });
            searchInput.addEventListener('blur', function(){
                setTimeout(() => suggestionBox.classList.remove('show'), 120);
            });
            select.addEventListener('change', function(){
                const chosen = originalOptions.find(opt => opt.value === select.value);
                if(chosen){
                    searchInput.value = chosen.text;
                    if(selectedBox){ selectedBox.textContent = 'Selected: ' + chosen.text; selectedBox.classList.add('show'); }
                }
            });
            const initiallySelected = originalOptions.find(opt => opt.value === select.value);
            if(initiallySelected && initiallySelected.value !== ''){
                searchInput.value = initiallySelected.text;
                if(selectedBox){ selectedBox.textContent = 'Selected: ' + initiallySelected.text; selectedBox.classList.add('show'); }
            }
        }
    }

    function renumber(){
        tableBody.querySelectorAll('tr').forEach(function(row, idx){
            const cell = row.querySelector('.row-num');
            if(cell) cell.textContent = String(idx + 1);
        });
    }

    addBtn.addEventListener('click', function(){
        const first = tableBody.querySelector('tr');
        if(!first) return;
        const clone = first.cloneNode(true);
        clone.querySelectorAll('input').forEach(function(input){
            if(input.name === 'quantity[]' || input.classList.contains('sku-search')) input.value = '';
        });
        clone.querySelectorAll('select').forEach(function(select){ select.value = ''; });
        clone.querySelectorAll('.sku-selected').forEach(function(el){ el.textContent = ''; el.classList.remove('show'); });
        clone.querySelectorAll('.sku-suggestions').forEach(function(el){ el.innerHTML = ''; el.classList.remove('show'); });
        // Reset pack-select su nuova riga
        clone.querySelectorAll('.pack-select').forEach(function(sel){ sel.value = ''; });
        tableBody.appendChild(clone);
        bindRow(clone);
        renumber();
    });

    tableBody.querySelectorAll('tr').forEach(bindRow);
})();
</script>
<?php if ($showClientModal): ?><script>document.addEventListener('DOMContentLoaded',function(){const el=document.getElementById('newClientModal');if(el&&typeof bootstrap!=='undefined'){bootstrap.Modal.getOrCreateInstance(el).show();}});</script><?php endif; ?>
<?php include '../includes/footer.php'; ?>
