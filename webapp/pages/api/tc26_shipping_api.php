<?php
/**
 * TC26 Shipping API  – updated with compare, delete_shipment, closed_pallets
 */
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../config/user_functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/print_engine.php';
require_once __DIR__ . '/../../includes/empty_bin_report.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

// ── Auth ──────────────────────────────────────────────────────────────────────
$appCfgFile = __DIR__ . '/../../config/pallets_shipping_app.php';
$appCfg = is_file($appCfgFile) ? require $appCfgFile : [];
$appToken = trim((string)($_SERVER['HTTP_X_APP_TOKEN'] ?? ''));
$isPalletsShippingApp = !empty($appCfg['enabled']) && $appToken !== ''
    && hash_equals((string)($appCfg['token'] ?? ''), $appToken);
if (function_exists('sp_can_access_page') && !sp_can_access_page('tc26_shipping.php')) {
    $isTC26 = isset($_SESSION['role']) && $_SESSION['role'] === 'admin'
           && !empty($_SESSION['logged_in']);
    if (!$isTC26 && !$isPalletsShippingApp) { http_response_code(403); echo json_encode(['ok'=>0,'err'=>'Access denied']); exit; }
}

// ── DB ────────────────────────────────────────────────────────────────────────
$dbx = null;
if (!empty($pdo))      $dbx = $pdo;
elseif (!empty($conn)) $dbx = $conn;
if (!$dbx) { echo json_encode(['ok'=>0,'err'=>'DB not available']); exit; }

smp_ensure_tc26_tables($dbx);

// ── bol_pdf_manual  (POST → genera PDF con valori manuali dal viewer) ────
{
    $earlyAct2 = $_POST['action'] ?? '';
    if ($earlyAct2 === 'bol_pdf_manual') {
        $sid      = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['shipment_id'] ?? ''));
        $safeSid  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $sid);
        if ($sid === '') {
            ob_clean(); http_response_code(400);
            header('Content-Type: text/plain');
            echo 'Missing shipment ID.'; exit;
        }

        // Leggi dati shipment dal DB (per header, customer, bo num, ecc.)
        $ship = smp_db_fetch_one($dbx, 'SELECT * FROM shipments WHERE shipment_id=?', [$sid]);
        if (!$ship) {
            ob_clean(); http_response_code(404);
            header('Content-Type: text/plain');
            echo 'Shipment not found.'; exit;
        }

        // Merge extra order fields
        try {
            require_once __DIR__ . '/../../config/orders_sql_lib.php';
            if (orders_sql_ready()) {
                orders_sql_init();
                $shipPo = trim((string)($ship['po'] ?? ''));
                if ($shipPo !== '') {
                    $orderExtra = orders_fetch_one_sql_by_po($shipPo) ?? [];
                    if ($orderExtra) {
                        foreach (['pick_location','ship_to_address','dest_city'] as $_ef) {
                            if (!empty($orderExtra[$_ef])) $ship[$_ef] = $orderExtra[$_ef];
                        }
                    }
                }
            }
        } catch (Throwable $_e) {}

        // Apply every field edited in the BOL review screen.
        $postMap = [
            'carrier'=>'carrier','ship_date'=>'ship_date','dest_city'=>'dest_city',
            'pick_location'=>'pick_location','ship_to_address'=>'ship_to_address',
            'customer_name'=>'customer_name','bol_label'=>'bol_label','bol_awb'=>'bol_awb',
            'bol_notify'=>'bol_notify','bol_consignee'=>'bol_consignee',
            'bol_keep_temp'=>'bol_keep_temp','bol_recorder'=>'bol_recorder','bol_phyto'=>'bol_phyto'
        ];
        foreach ($postMap as $_pk=>$_sk) {
            if (array_key_exists($_pk,$_POST)) $ship[$_sk]=trim((string)$_POST[$_pk]);
        }
        if (isset($ship['dest_city'])) $ship['destination']=$ship['dest_city'];

        // Leggi pallets dal DB
        $pallets = [];
        try {
            $pallets = smp_db_fetch_all($dbx,
                'SELECT sp.pallet_id, COUNT(pc.id) AS case_count
                 FROM shipment_pallets sp
                 LEFT JOIN pallet_cases pc ON pc.pallet_id = sp.pallet_id
                 WHERE sp.shipment_id=?
                 GROUP BY sp.pallet_id ORDER BY sp.id ASC', [$sid]) ?? [];
        } catch (Throwable $_e) {}

        // Usa prodRows manuali dal POST se presenti
        $prodRowsJson = trim((string)($_POST['prod_rows'] ?? '[]'));
        $manualRows   = @json_decode($prodRowsJson, true) ?: [];

        // Costruisci $varieties dai dati manuali (override completo)
        if (!empty($manualRows)) {
            $varieties = [];
            foreach ($manualRows as $mr) {
                $ctns   = (int)($mr['ctns'] ?? 0);
                $plts   = (int)($mr['plts'] ?? 0);
                $desc   = trim((string)($mr['desc'] ?? ''));
                $pack   = trim((string)($mr['pack'] ?? ''));
                $wRaw   = preg_replace('/[^0-9.]/', '', (string)($mr['weight'] ?? ''));
                $weight = $wRaw !== '' ? (float)$wRaw : 0.0;
                if ($ctns === 0 && $plts === 0 && $desc === '') continue;
                $varieties[] = [
                    'variety'      => $desc,
                    'grower'       => '',
                    'cases'        => $ctns,
                    'pallets'      => $plts,
                    'pack_preset'  => $pack,
                    'row_weight'   => $weight,
                ];
            }
            // Ricalcola totali dai valori manuali
            $totalCases   = array_sum(array_column($varieties, 'cases'));
            $totalPallets = array_sum(array_column($varieties, 'pallets'));
            $totalWeight  = round(array_sum(array_column($varieties, 'row_weight')), 2);
        } else {
            // Fallback: leggi dal DB
            $shipPoForV = trim((string)($ship['po'] ?? ''));
            $varieties  = fetchBolVarieties($dbx, $sid, $shipPoForV);
            $totalCases   = (int)array_sum(array_column($pallets, 'case_count'));
            $totalPallets = count($pallets);
            $totalWeight  = round(array_sum(array_column($varieties, 'row_weight')), 2);
        }

        // Logo
        $logoPath = __DIR__ . '/../../logo/logo.png';
        $logoB64  = '';
        if (file_exists($logoPath)) {
            $logoB64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }

        // BOL number
        $bolNum = trim((string)($ship['bol_number'] ?? ''));
        if ($bolNum === '') {
            $bolNum = 'BOL-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $sid), 0, 8))
                    . '-' . date('ymd');
            try {
                smp_db_exec($dbx,
                    "UPDATE shipments SET bol_number=?
                     WHERE shipment_id=? AND (bol_number IS NULL OR bol_number='')",
                    [$bolNum, $sid]);
            } catch (Throwable $_e) {}
        }

        // Genera PDF
        try {
            require_once __DIR__ . '/../../lib/dompdf/autoload.inc.php';
            $dompdfOpts = new \Dompdf\Options();
            $dompdfOpts->setIsRemoteEnabled(false);
            $dompdfOpts->setIsHtml5ParserEnabled(true);
            $dompdfOpts->setDefaultFont('DejaVu Sans');
            $dom = new \Dompdf\Dompdf($dompdfOpts);
            $dom->loadHtml(buildBolHtml($ship, $pallets, $varieties, $bolNum, $logoB64,
                                        $totalCases, $totalPallets, $totalWeight));
            $dom->setPaper('letter', 'portrait');
            $dom->render();
            $pdfOut = $dom->output();
            @file_put_contents(sys_get_temp_dir() . '/bol_' . $safeSid . '.pdf', $pdfOut);
        } catch (Throwable $pdfErr) {
            ob_clean(); http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'BOL PDF error: ' . $pdfErr->getMessage()
               . ' — Check PHP extensions: mbstring, dom, xml.';
            exit;
        }

        // Flush all output buffer levels before sending PDF
        while (ob_get_level() > 0) { ob_end_clean(); }
        header_remove('Content-Type');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="BOL_' . $safeSid . '.pdf"');
        header('Content-Length: ' . strlen($pdfOut));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $pdfOut;
        exit;
    }
}

// ── bol_download  (serves PDF, not JSON — must run before JSON header) ────
{
    $earlyAct = $_GET['action'] ?? '';
    if ($earlyAct === 'bol_download') {
        $sid      = preg_replace('/[^A-Za-z0-9_\\-]/', '', (string)($_GET['sid'] ?? ''));
        $safeSid  = preg_replace('/[^A-Za-z0-9_\\-]/', '_', $sid);
        $fPath    = sys_get_temp_dir() . '/bol_' . $safeSid . '.pdf';

        if ($sid === '') {
            ob_clean(); http_response_code(400);
            header('Content-Type: text/plain');
            echo 'Missing shipment ID.'; exit;
        }

        // ── Generate on-the-fly if PDF not cached ────────────────────────
        // Always regenerate to pick up latest order data
        @unlink($fPath);
        if (!file_exists($fPath)) {
            // Fetch shipment
            $ship = smp_db_fetch_one($dbx, 'SELECT * FROM shipments WHERE shipment_id=?', [$sid]);
            if (!$ship) {
                ob_clean(); http_response_code(404);
                header('Content-Type: text/plain');
                echo 'Shipment not found.'; exit;
            }
            // Merge order extra fields
            try {
                require_once __DIR__ . '/../../config/orders_sql_lib.php';
                if (orders_sql_ready()) {
                    orders_sql_init();
                    $shipPo = trim((string)($ship['po'] ?? ''));
                    if ($shipPo !== '') {
                        $orderExtra = orders_fetch_one_sql_by_po($shipPo) ?? [];
                        if ($orderExtra) {
                            $ship['pick_location']   = $orderExtra['pick_location']   ?? '';
                            $ship['ship_to_address'] = $orderExtra['ship_to_address'] ?? '';
                            $ship['dest_city']       = $orderExtra['dest_city']       ?? $ship['destination'] ?? '';
                        }
                    }
                }
            } catch (Throwable $_e) {}
            // Pallets
            $pallets = [];
            try {
                $pallets = smp_db_fetch_all($dbx,
                    'SELECT sp.pallet_id, COUNT(pc.id) AS case_count
                     FROM shipment_pallets sp
                     LEFT JOIN pallet_cases pc ON pc.pallet_id = sp.pallet_id
                     WHERE sp.shipment_id=?
                     GROUP BY sp.pallet_id ORDER BY sp.id ASC', [$sid]) ?? [];
            } catch (Throwable $_e) {}
            // Varieties (with pack preset + weight from order)
            $shipPoForVarieties = trim((string)($ship['po'] ?? ''));
            $varieties = fetchBolVarieties($dbx, $sid, $shipPoForVarieties);
            // Logo
            $logoPath = __DIR__ . '/../../logo/logo.png';
            $logoB64  = '';
            if (file_exists($logoPath)) {
                $logoB64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
            }
            // BOL number
            $bolNum = trim((string)($ship['bol_number'] ?? ''));
            if ($bolNum === '') {
                $bolNum = 'BOL-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $sid), 0, 8))
                        . '-' . date('ymd');
                try {
                    smp_db_exec($dbx,
                        "UPDATE shipments SET bol_number=?
                         WHERE shipment_id=? AND (bol_number IS NULL OR bol_number='')",
                        [$bolNum, $sid]);
                } catch (Throwable $_e) {}
            }
            $totalCases   = (int)array_sum(array_column($pallets, 'case_count'));
            $totalPallets = count($pallets);
            $totalWeight  = round(array_sum(array_column($varieties, 'row_weight')), 2);
            // Render PDF
            try {
                require_once __DIR__ . '/../../lib/dompdf/autoload.inc.php';
                $dompdfOpts = new \Dompdf\Options();
                $dompdfOpts->setIsRemoteEnabled(false);
                $dompdfOpts->setIsHtml5ParserEnabled(true);
                $dompdfOpts->setDefaultFont('DejaVu Sans');
                $dom = new \Dompdf\Dompdf($dompdfOpts);
                $dom->loadHtml(buildBolHtml($ship, $pallets, $varieties, $bolNum, $logoB64,
                                            $totalCases, $totalPallets, $totalWeight ?? 0));
                $dom->setPaper('letter', 'portrait');
                $dom->render();
                file_put_contents($fPath, $dom->output());
            } catch (Throwable $pdfErr) {
                ob_clean(); http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'BOL PDF error: ' . $pdfErr->getMessage()
                   . ' — Check PHP extensions: mbstring, dom, xml are required for dompdf.';
                exit;
            }
        }

        if (!file_exists($fPath)) {
            ob_clean(); http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Failed to generate BOL PDF.'; exit;
        }

        ob_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="BOL_' . $safeSid . '.pdf"');
        header('Content-Length: ' . filesize($fPath));
        readfile($fPath);
        exit;
    }

    if ($earlyAct === 'bol_view') {
        header_remove('Content-Type');
        header('Content-Type: text/html; charset=utf-8');

        $sid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['sid'] ?? ''));
        if ($sid === '') { echo '<p>Missing shipment ID.</p>'; exit; }

        $ship = smp_db_fetch_one($dbx, 'SELECT * FROM shipments WHERE shipment_id=?', [$sid]);
        if (!$ship) { echo '<p>Shipment not found.</p>'; exit; }

        // Fetch extra fields from orders table
        try {
            require_once __DIR__ . '/../../config/orders_sql_lib.php';
            if (orders_sql_ready()) {
                orders_sql_init();
                $viewShipPo = trim((string)($ship['po'] ?? ''));
                if ($viewShipPo !== '') {
                    $viewOrderExtra = orders_fetch_one_sql_by_po($viewShipPo) ?? [];
                    if ($viewOrderExtra) {
                        $ship['pick_location']   = $viewOrderExtra['pick_location']   ?? '';
                        $ship['ship_to_address'] = $viewOrderExtra['ship_to_address'] ?? '';
                        $ship['dest_city']       = $viewOrderExtra['dest_city']       ?? $ship['destination'] ?? '';
                    }
                }
            }
        } catch (Throwable $_e2) {}

        // Pallets & varieties for the view
        $vwPallets = [];
        try {
            $vwPallets = smp_db_fetch_all($dbx,
                'SELECT sp.pallet_id, COUNT(pc.id) AS case_count
                 FROM shipment_pallets sp
                 LEFT JOIN pallet_cases pc ON pc.pallet_id = sp.pallet_id
                 WHERE sp.shipment_id=?
                 GROUP BY sp.pallet_id ORDER BY sp.id ASC', [$sid]) ?? [];
        } catch (Throwable $e) {}

        // Varieties with pack preset + weight
        $vwShipPo = trim((string)($ship['po'] ?? ''));
        $vwVarieties = fetchBolVarieties($dbx, $sid, $vwShipPo);

        $logoPath = __DIR__ . '/../../logo/logo.png';
        $logoB64  = '';
        if (file_exists($logoPath)) {
            $logoB64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }
        $bolNum = trim((string)($ship['bol_number'] ?? ''));
        if ($bolNum === '') {
            $bolNum = 'BOL-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $sid), 0, 8))
                    . '-' . date('ymd');
        }
        $totalCases   = (int)array_sum(array_column($vwPallets, 'case_count'));
        $totalPallets = count($vwPallets);

        $he    = function($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); };
        $dlUrl = 'api/tc26_shipping_api.php?action=bol_download&sid=' . urlencode($sid);

        // ── Pre-build editable field defaults ─────────────────────────────────
        $vDate        = $he($ship['ship_date']      ?? date('Y-m-d'));
        $vPickup      = $he($ship['pick_location']  ?? '');
        $vShipTo      = $he($ship['ship_to_address'] ?? '');
        $vDestCity    = $he($ship['dest_city']       ?? $ship['destination'] ?? '');
        $vRefNum      = $he($sid);
        $vCustPO      = $he($ship['po']             ?? '');
        $vCustomer    = $he($ship['customer_name']  ?? '');
        $vCarrier     = $he($ship['carrier']        ?? '');
        $vBolNum      = $he($bolNum);
        $vTotalPallets= $he((string)$totalPallets);
        $vwTotalWeight = round(array_sum(array_column($vwVarieties, 'row_weight')), 2);
        $vTotalWeight = $vwTotalWeight > 0 ? number_format($vwTotalWeight, 2) : '';  // pre-filled from order (number only, unit shown in total display)
        $vAwb         = $he($ship['bol_awb'] ?? '');
        $vNotify      = $he($ship['bol_notify'] ?? '');
        $vConsignee   = $he(trim((string)($ship['bol_consignee'] ?? '')) !== ''
                            ? $ship['bol_consignee']
                            : ($ship['dest_city'] ?? $ship['destination'] ?? ''));
        $vKeepTemp    = $he($ship['bol_keep_temp'] ?? '');
        $vRecorderNum = $he($ship['bol_recorder'] ?? '');
        $vLabel       = $he($ship['bol_label'] ?? '');
        $vPhyto       = strtoupper(trim((string)($ship['bol_phyto'] ?? '')));
        $vPhytoY      = $vPhyto === 'Y' ? ' checked' : '';
        $vPhytoN      = $vPhyto === 'N' ? ' checked' : '';

        // Build variety rows JSON for JS dynamic table
        $jVarieties = json_encode($vwVarieties, JSON_UNESCAPED_UNICODE);

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>BOL {$vBolNum}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
html,body{
  font-family:Arial,Helvetica,sans-serif;font-size:11px;
  background:#1a1f2e;height:100%;overflow:hidden;
}

/* ══ FULLSCREEN SHELL ══ */
#app-shell{
  display:flex;flex-direction:column;height:100vh;width:100vw;overflow:hidden;
}

/* ── TOP TOOLBAR (row 1) ── */
#toolbar{
  flex:0 0 auto;
  height:46px;
  background:#1e293b;color:#f8fafc;
  display:flex;align-items:center;gap:8px;padding:0 12px;
  box-shadow:0 2px 8px rgba(0,0,0,.5);
  z-index:100;
}
#toolbar .t-title{font-size:12px;font-weight:700;color:#e2e8f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;}
#toolbar .t-badge{background:#0ea5e9;color:#fff;border-radius:6px;padding:3px 8px;font-size:10px;font-weight:700;white-space:nowrap;}
#toolbar .t-sep{width:1px;height:28px;background:#334155;margin:0 2px;}
#toolbar .t-spacer{flex:1;}
#toolbar > button{
  padding:5px 11px;border-radius:6px;border:none;cursor:pointer;
  font-size:11px;font-weight:700;transition:all .15s;white-space:nowrap;
}
#toolbar > button:hover{filter:brightness(1.15);}
#toolbar .btn-print{background:#16a34a;color:#fff;}
#toolbar .btn-pdf  {background:#6366f1;color:#fff;}
#toolbar .btn-save {background:#0f766e;color:#fff;}
#toolbar .btn-save:hover{background:#0d9488;}
#toolbar .btn-fs   {background:#334155;color:#f8fafc;}
#toolbar .btn-fs:hover{background:#475569;}

/* ── FORMAT TOOLBAR (row 2) ── */
#fmt-bar{
  flex:0 0 auto;
  background:#0f172a;
  border-bottom:1px solid #1e293b;
  display:flex;align-items:center;flex-wrap:wrap;gap:2px;
  padding:4px 10px;
  min-height:38px;
}
#fmt-bar .fb-sep{width:1px;height:22px;background:#334155;margin:0 3px;flex-shrink:0;}
#fmt-bar .fb-label{font-size:9px;color:#64748b;text-transform:uppercase;font-weight:700;white-space:nowrap;letter-spacing:.05em;}
/* generic fmt button */
#fmt-bar button{
  height:26px;min-width:26px;
  padding:0 6px;
  border:1px solid transparent;
  border-radius:4px;
  background:transparent;
  color:#cbd5e1;
  cursor:pointer;
  font-size:12px;
  font-weight:700;
  display:inline-flex;align-items:center;justify-content:center;
  transition:all .12s;
  white-space:nowrap;
  line-height:1;
}
#fmt-bar button:hover{background:#1e293b;border-color:#334155;color:#f8fafc;}
#fmt-bar button.active{background:#3b82f6;color:#fff;border-color:#2563eb;}
/* selects inside fmt-bar */
#fmt-bar select{
  height:26px;
  padding:0 4px;
  border:1px solid #334155;
  border-radius:4px;
  background:#1e293b;
  color:#cbd5e1;
  font-size:11px;
  cursor:pointer;
  outline:none;
}
#fmt-bar select:hover{border-color:#475569;}
/* color pickers */
#fmt-bar input[type=color]{
  width:26px;height:26px;
  border:1px solid #334155;
  border-radius:4px;
  background:#1e293b;
  cursor:pointer;
  padding:1px;
}
/* field-target info pill */
#fmt-bar .fb-target{
  font-size:9px;color:#64748b;
  background:#1e293b;
  border:1px solid #334155;
  border-radius:4px;padding:1px 6px;
  margin-left:2px;
  white-space:nowrap;
  max-width:160px;overflow:hidden;text-overflow:ellipsis;
}

/* ── ZOOM BAR ── */
#zoom-bar{
  flex:0 0 auto;
  height:40px;
  background:#0f172a;
  display:flex;align-items:center;justify-content:center;gap:8px;
  border-bottom:1px solid #1e293b;
}
#zoom-bar button{
  width:28px;height:28px;border-radius:6px;border:none;cursor:pointer;
  background:#1e293b;color:#f8fafc;font-size:16px;font-weight:900;
  display:flex;align-items:center;justify-content:center;
  transition:background .15s;line-height:1;
}
#zoom-bar button:hover{background:#334155;}
#zoom-slider{
  -webkit-appearance:none;appearance:none;
  width:180px;height:4px;border-radius:2px;
  background:linear-gradient(to right,#3b82f6 0%,#3b82f6 var(--pct,50%),#334155 var(--pct,50%),#334155 100%);
  cursor:pointer;outline:none;
}
#zoom-slider::-webkit-slider-thumb{
  -webkit-appearance:none;width:16px;height:16px;border-radius:50%;
  background:#3b82f6;cursor:pointer;box-shadow:0 0 4px rgba(59,130,246,.6);
}
#zoom-slider::-moz-range-thumb{
  width:16px;height:16px;border-radius:50%;border:none;
  background:#3b82f6;cursor:pointer;
}
#zoom-label{
  min-width:46px;text-align:center;font-size:12px;font-weight:700;
  color:#94a3b8;background:#1e293b;border-radius:5px;padding:3px 6px;
  cursor:pointer;user-select:none;
}
#zoom-label:hover{background:#334155;color:#f8fafc;}
#zoom-bar .zoom-hint{font-size:10px;color:#475569;margin-left:8px;}

/* copies row */
#copies-bar{
  flex:0 0 auto;height:34px;
  background:#0f172a;border-bottom:1px solid #1e293b;
  display:flex;align-items:center;justify-content:center;gap:10px;
}
#copies-bar label{font-size:11px;color:#64748b;font-weight:600;}
#copies-bar input{
  width:52px;padding:4px 6px;border-radius:5px;
  border:1px solid #334155;background:#1e293b;color:#f8fafc;
  text-align:center;font-size:13px;font-weight:700;
}

/* ── CANVAS (scrollable document area) ── */
#canvas{
  flex:1 1 auto;overflow:auto;
  display:flex;flex-direction:column;align-items:center;
  padding:24px 0 40px;
  background:radial-gradient(ellipse at 50% 0%,#1e3a5f 0%,#0f172a 60%);
}

/* ── document wrapper (zoom target) ── */
#doc-scaler{
  transform-origin:top center;
  transition:transform .15s ease;
  display:flex;flex-direction:column;align-items:center;
}

/* ── copy separator ── */
.copy-sep{
  display:block;
  width:210mm;height:20px;
  margin:0 auto;
  background:repeating-linear-gradient(90deg,#334155 0,#334155 8px,transparent 8px,transparent 18px);
  opacity:.4;
  page-break-after:always;
}
/* nasconde l'ultima striscia separatore (visibile come quadrati bianchi/grigi) */
.copy-sep:last-child { display: none !important; }

/* ── BOL document ── */
#bol-wrap{
  width:210mm;
  background:#fff;
  box-shadow:0 8px 40px rgba(0,0,0,.6),0 2px 8px rgba(0,0,0,.4);
  padding:10mm 10mm 8mm;
  border-radius:2px;
}

/* ── print media ── */
@media print {
  html,body{background:#fff;height:auto;overflow:visible;}
  #app-shell{display:block;height:auto;overflow:visible;}
  #toolbar,#fmt-bar,#zoom-bar,#copies-bar{display:none!important;}
  #canvas{
    overflow:visible;display:block;background:none;padding:0;
  }
  #doc-scaler{transform:none!important;display:block;}
  #bol-wrap{
    width:100%;max-width:none;box-shadow:none;border:none;
    padding:8mm 8mm;border-radius:0;
  }
  .copy-sep{
    display:block!important;
    background:none;border-top:2px dashed #aaa;
    height:0;width:100%;margin:10mm 0;
  }
  .copy-sep:last-of-type{display:none!important;}
  .ef,.ef-ta{
    border:none!important;background:transparent!important;
    font-family:inherit!important;font-size:inherit!important;
    color:inherit!important;resize:none!important;
    padding:0!important;width:100%!important;
  }
  .ef-ta{min-height:unset!important;}
  .prod-row td input{
    border:none!important;background:transparent!important;font-size:inherit!important;
  }
  /* Remove all colored backgrounds in print */
  .prod th{
    background:transparent!important;color:#000!important;
  }
  .prod-total,.prod-total-tbl tr{
    background:transparent!important;
  }
  .prod-total td{
    background:transparent!important;
  }
  .prod-add-btn{display:none!important;}
  .carrier td,.sec-tbl td,.sig-tbl td{background:transparent!important;}
}

/* ── logo + header ── */
.bol-header{width:100%;margin-bottom:4mm;border-bottom:2px solid #000;padding-bottom:4mm;}
.bol-header-inner{display:table;width:100%;border-collapse:collapse;}
.bol-side-left{display:table-cell;width:28%;vertical-align:middle;}
.bol-side-left .company-name{font-size:13px;font-weight:900;color:#111;line-height:1.35;}
.bol-side-left .company-addr{font-size:10px;color:#444;line-height:1.5;margin-top:2px;}
.bol-center{display:table-cell;vertical-align:middle;text-align:center;}
.bol-center img{max-height:80px;max-width:220px;display:block;margin:0 auto 4px;}
.bol-center h1{font-size:14px;font-weight:900;letter-spacing:2px;color:#111;text-transform:uppercase;}
.bol-side-right{display:table-cell;width:28%;vertical-align:middle;text-align:right;font-size:10px;color:#444;line-height:1.6;}
.bol-side-right .contact-label{font-size:8.5px;font-weight:700;text-transform:uppercase;color:#555;}

/* ── sections ── */
.sec{border:1px solid #444;margin-top:-1px;}
.sec-tbl{width:100%;border-collapse:collapse;}
.sec-tbl td{border-right:1px solid #444;padding:3px 5px;vertical-align:top;}
.sec-tbl td:last-child{border-right:none;}
.field-label{display:block;font-size:8.5px;text-transform:uppercase;font-weight:700;color:#333;letter-spacing:.04em;margin-bottom:2px;}
.field-value{display:block;min-height:14px;}
.field-tall{min-height:30px;}
.lg{font-size:14px;font-weight:900;}

/* ── editable fields ── */
.ef{
  width:100%;border:1px dashed #94a3b8;border-radius:3px;
  background:#fff;padding:2px 4px;font-family:Arial,Helvetica,sans-serif;
  font-size:11px;color:#111;outline:none;
}
.ef:focus{border-color:#3b82f6;background:#eff6ff;}
.ef-ta{
  width:100%;border:1px dashed #94a3b8;border-radius:3px;
  background:#fff;padding:2px 4px;font-family:Arial,Helvetica,sans-serif;
  font-size:11px;color:#111;outline:none;resize:vertical;min-height:36px;
}
.ef-ta:focus{border-color:#3b82f6;background:#eff6ff;}

/* ── product table ── */
.prod{width:100%;border-collapse:collapse;border:1px solid #444;margin-top:-1px;}
.prod th,.prod td{border:1px solid #888;padding:3px 5px;font-size:10px;}
.prod th{background:#fff;font-weight:900;font-size:9px;text-transform:uppercase;text-align:center;border-bottom:2px solid #333;}
.prod td{vertical-align:middle;}
.prod td input{width:100%;border:1px dashed #aab;background:#fff;padding:2px;font-size:10px;text-align:center;}
.prod td input:focus{border-color:#3b82f6;outline:none;}
.prod-desc{text-align:left!important;}
.prod-total{background:transparent;font-weight:900;}
.prod-total-tbl{width:100%;border-collapse:collapse;border:1px solid #444;margin-top:0;}
@media print {
  .prod-total-tbl { page-break-before:avoid; }
  .prod-add-btn { display:none!important; }
}
.prod-add-btn{margin:4px 0;font-size:10px;cursor:pointer;padding:3px 10px;border:1px solid #94a3b8;border-radius:4px;background:#f1f5f9;}
.prod-add-btn:hover{background:#e2e8f0;}

/* ── PRINT: azzeramento background definitivo ── */
@media print {
  .prod th,.prod thead th{background:#fff!important;color:#000!important;border-color:#444!important;}
  .prod-total,.prod-total-tbl .prod-total,.prod-total td{background:#fff!important;}
  .ef,.ef-ta{background:transparent!important;border:none!important;padding:0!important;width:100%!important;}
  .prod-row td input,.prod td input{background:transparent!important;border:none!important;}
  .prod-add-btn{display:none!important;}
  .carrier td,.sec-tbl td,.sig-tbl td{background:transparent!important;}
  body,html{background:#fff!important;color-scheme:light;}
  #app-shell,#canvas,#doc-scaler{background:#fff!important;}
  #bol-wrap,.bol-copy{background:#fff!important;}
  #toolbar,#fmt-bar,#zoom-bar,#copies-bar{display:none!important;}
}

/* ── carrier ── */
.carrier{width:100%;border-collapse:collapse;border:1px solid #444;margin-top:-1px;}
.carrier td{border-right:1px solid #444;padding:3px 5px;vertical-align:top;}
.carrier td:last-child{border-right:none;}

/* ── sig ── */
.sig-tbl{width:100%;border-collapse:collapse;border:1px solid #444;margin-top:-1px;}
.sig-tbl td{border-right:1px solid #444;padding:4px 6px;vertical-align:bottom;height:40px;}
.sig-tbl td:last-child{border-right:none;}
.sig-line{display:block;border-bottom:1px solid #333;margin-top:16px;margin-bottom:2px;}
.sig-label{font-size:8px;text-transform:uppercase;font-weight:700;color:#333;}
/* disclaimer strip removed */
</style>
</head>
<body>

<div id="app-shell">

<!-- ══ TOP TOOLBAR ══ -->
<div id="toolbar">
  <span class="t-title">📋 BOL &mdash; {$vBolNum}</span>
  <span class="t-badge">EDITABLE</span>
  <div class="t-sep"></div>
  <div class="t-spacer"></div>
  <button class="btn-print" id="btn-print-bol" onclick="doPrint()" title="Print (Ctrl+P)">🖨 Save & Print</button>
  <button class="btn-pdf" id="btn-pdf-dl" onclick="doPdf()" title="Download PDF">⬇️ PDF</button>
  <button class="btn-save" id="btn-save-bol" onclick="doSave()" title="Save changes to DB">💾 Save</button>
  <button class="btn-fs"    onclick="toggleFullscreen()" id="btnFs" title="Fullscreen (F)">⧆ Fullscreen</button>
</div>

<!-- ══ FORMAT TOOLBAR (row 2) ══ -->
<div id="fmt-bar" title="Select text inside a field then apply formatting">

  <!-- Font family -->
  <span class="fb-label">Font</span>
  <select id="fb-font" onchange="fmtExec('fontName', this.value)" title="Font family">
    <option value="Arial, Helvetica, sans-serif">Arial</option>
    <option value="'Times New Roman', Times, serif">Times New Roman</option>
    <option value="Courier, monospace">Courier</option>
    <option value="Georgia, serif">Georgia</option>
    <option value="Verdana, Geneva, sans-serif">Verdana</option>
    <option value="Tahoma, Geneva, sans-serif">Tahoma</option>
  </select>

  <!-- Font size -->
  <select id="fb-size" onchange="fmtSetFontSize(this.value)" title="Font size">
    <option value="">Size</option>
    <option value="7">7pt</option>
    <option value="8">8pt</option>
    <option value="9">9pt</option>
    <option value="10">10pt</option>
    <option value="11">11pt</option>
    <option value="12">12pt</option>
    <option value="13">13pt</option>
    <option value="14">14pt</option>
    <option value="16">16pt</option>
    <option value="18">18pt</option>
    <option value="20">20pt</option>
    <option value="24">24pt</option>
    <option value="28">28pt</option>
    <option value="32">32pt</option>
    <option value="36">36pt</option>
  </select>

  <div class="fb-sep"></div>

  <!-- Bold / Italic / Underline / Strikethrough -->
  <button id="fb-bold"   onclick="fmtToggle('bold')"          title="Bold (Ctrl+B)"><b>B</b></button>
  <button id="fb-italic" onclick="fmtToggle('italic')"        title="Italic (Ctrl+I)"><i>I</i></button>
  <button id="fb-under"  onclick="fmtToggle('underline')"     title="Underline (Ctrl+U)"><u>U</u></button>
  <button id="fb-strike" onclick="fmtToggle('strikeThrough')" title="Strikethrough"><s>S</s></button>

  <div class="fb-sep"></div>

  <!-- Alignment -->
  <button id="fb-al" onclick="fmtExec('justifyLeft')"    title="Align left">&#8676;</button>
  <button id="fb-ac" onclick="fmtExec('justifyCenter')"  title="Center">☰☰</button>
  <button id="fb-ar" onclick="fmtExec('justifyRight')"   title="Align right">&#8677;</button>
  <button id="fb-aj" onclick="fmtExec('justifyFull')"    title="Justify">≣</button>

  <div class="fb-sep"></div>

  <!-- Text color / Highlight -->
  <span class="fb-label">Color</span>
  <input type="color" id="fb-color"     value="#111111" title="Text color"
         oninput="fmtExec('foreColor', this.value)">
  <span class="fb-label">Hilite</span>
  <input type="color" id="fb-hilite"    value="#ffff00" title="Highlight"
         oninput="fmtExec('hiliteColor', this.value)">

  <div class="fb-sep"></div>

  <!-- Indent / List -->
  <button onclick="fmtExec('indent')"              title="Indent">⇥</button>
  <button onclick="fmtExec('outdent')"             title="Outdent">⇤</button>
  <button onclick="fmtExec('insertUnorderedList')" title="Bullet list">•</button>
  <button onclick="fmtExec('insertOrderedList')"   title="Numbered list">1.</button>

  <div class="fb-sep"></div>

  <!-- Subscript / Superscript -->
  <button onclick="fmtExec('subscript')"   title="Subscript">x₂</button>
  <button onclick="fmtExec('superscript')" title="Superscript">x²</button>

  <div class="fb-sep"></div>

  <!-- Clear formatting -->
  <button onclick="fmtClearFormat()" title="Clear all formatting" style="color:#f87171;">&#10005; Clear</button>

  <div class="fb-sep"></div>

  <!-- Apply to all fields shortcut -->
  <button onclick="fmtApplyFontToAll()" title="Apply current font+size to ALL editable fields"
          style="font-size:10px;color:#fbbf24;">&#8635; Apply to All</button>

  <!-- Active field indicator -->
  <div class="t-spacer"></div>
  <span class="fb-label">Editing:</span>
  <span class="fb-target" id="fb-target-lbl">— click a field —</span>
</div>

<!-- ══ ZOOM BAR ══ -->
<div id="zoom-bar">
  <button onclick="zoomStep(-10)" title="Zoom out (-)">−</button>
  <input type="range" id="zoom-slider" min="40" max="200" value="100" step="5"
         oninput="applyZoom(this.value)" style="--pct:50%">
  <button onclick="zoomStep(+10)" title="Zoom in (+)">+</button>
  <span id="zoom-label" onclick="resetZoom()" title="Click to reset to 100%">100%</span>
  <button onclick="fitPage()" title="Fit to window" style="font-size:12px;width:auto;padding:0 8px;">⊡ Fit</button>
  <span class="zoom-hint">+/− keys &nbsp;|&nbsp; F = fullscreen</span>
</div>

<!-- ══ COPIES BAR ══ -->
<div id="copies-bar">
  <label for="copiesInput">Copies:</label>
  <input type="number" id="copiesInput" value="1" min="1" max="20"
         onchange="onCopiesChange(this.value)">
</div>

<!-- ══ CANVAS (scrollable) ══ -->
<div id="canvas">
  <div id="doc-scaler">
    <!-- ══ BOL CONTENT (copies injected by JS) ══ -->
    <div id="bol-wrap"></div>
  </div>
</div>

</div><!-- #app-shell -->

<!-- ══ TEMPLATE (hidden) ══ -->
<template id="bol-template">
<div class="bol-copy">

  <!-- HEADER -->
  <div class="bol-header">
    <div class="bol-header-inner">
      <div class="bol-side-left">
        <div class="company-name">SM Produce LTD.</div>
        <div class="company-addr">577 Road 2, Oliver B.C<br>PO BOX 1954</div>
      </div>
      <div class="bol-center">
        <img src="{$logoB64}" alt="SM Produce Logo">
        <h1>BILL OF LADING</h1>
      </div>
      <div class="bol-side-right">
        <div class="contact-label">Contact</div>
        Email: mannproduce@hotmail.com<br>
        Phone: +1 250-485-8205<br>
        <div style="margin-top:4px;font-size:9px;color:#666;">
          BOL: <strong style="color:#111">{$vBolNum}</strong><br>
          Shipment: {$vRefNum}
        </div>
      </div>
    </div>
  </div>

  <!-- ROW 1: DATE / PICK UP / SHIP TO -->
  <div class="sec">
    <table class="sec-tbl"><tr>
      <td style="width:15%">
        <span class="field-label">Date</span>
        <input class="ef ef-date" type="date" value="{$vDate}" style="width:100%">
      </td>
      <td style="width:40%">
        <span class="field-label">Pick Up Location (Shipper)</span>
        <textarea class="ef-ta ef-pickup" rows="2">{$vPickup}</textarea>
      </td>
      <td style="width:45%">
        <span class="field-label">Ship To (Destination)</span>
        <textarea class="ef-ta ef-shipto" rows="3">{$vShipTo}</textarea>
      </td>
    </tr></table>
  </div>

  <!-- ROW 2: REF / CUST PO / PHYTO / CUSTOMER -->
  <div class="sec">
    <table class="sec-tbl"><tr>
      <td style="width:20%">
        <span class="field-label">Ref #</span>
        <input class="ef ef-ref" type="text" value="{$vRefNum}">
      </td>
      <td style="width:30%">
        <span class="field-label">Cust PO #</span>
        <input class="ef ef-custpo" type="text" value="{$vCustPO}" style="font-size:14px;font-weight:900;">
      </td>
      <td style="width:20%">
        <span class="field-label">Phyto</span>
        <span class="field-value">
          <input type="checkbox" class="ef-phyto-y"{$vPhytoY} onchange="phytoPick(&quot;Y&quot;,this)"> Y &nbsp;&nbsp;
          <input type="checkbox" class="ef-phyto-n"{$vPhytoN} onchange="phytoPick(&quot;N&quot;,this)"> N
        </span>
      </td>
      <td style="width:30%">
        <span class="field-label">Customer / Bill To</span>
        <input class="ef ef-customer" type="text" value="{$vCustomer}">
      </td>
    </tr></table>
  </div>

  <!-- ROW 3: LABEL / DEST CITY / AWB / NOTIFY / CONSIGNEE -->
  <div class="sec">
    <table class="sec-tbl"><tr>
      <td style="width:15%">
        <span class="field-label">Label</span>
        <input class="ef ef-label" type="text" value="{$vLabel}">
      </td>
      <td style="width:20%">
        <span class="field-label">Dest City</span>
        <input class="ef ef-destcity" type="text" value="{$vDestCity}">
      </td>
      <td style="width:15%">
        <span class="field-label">AWB #</span>
        <input class="ef ef-awb" type="text" value="{$vAwb}">
      </td>
      <td style="width:20%">
        <span class="field-label">Notify</span>
        <input class="ef ef-notify" type="text" value="{$vNotify}">
      </td>
      <td style="width:30%">
        <span class="field-label">Consignee</span>
        <input class="ef ef-consignee" type="text" value="{$vConsignee}">
      </td>
    </tr></table>
  </div>

  <!-- PRODUCT TABLE -->
  <table class="prod" id="prod-table-COPYIDX">
    <thead><tr>
      <th style="width:8%">CTNS</th>
      <th style="width:8%">PLTS</th>
      <th>PRODUCT DESCRIPTION</th>
      <th style="width:12%">PACK</th>
      <th style="width:12%">WEIGHT</th>
    </tr></thead>
    <tbody class="prod-tbody"></tbody>
  </table>
  <table class="prod prod-total-tbl" style="border-top:2px solid #444;">
    <tr class="prod-total">
      <td class="text-center total-ctns" style="width:10%"></td>
      <td class="text-center total-plts" style="width:10%"></td>
      <td style="font-size:10px;font-weight:900;width:36%">TOTALS</td>
      <td style="width:22%"></td>
      <td class="total-weight" style="width:22%"></td>
    </tr>
  </table>
  <button class="prod-add-btn" onclick="addProdRow(this)">+ Add Row</button>

  <!-- CARRIER ROW -->
  <div class="sec" style="margin-top:4px;">
    <table class="carrier"><tr>
      <td style="width:25%">
        <span class="field-label">Carrier</span>
        <input class="ef ef-carrier" type="text" value="{$vCarrier}">
      </td>
      <td style="width:20%">
        <span class="field-label">Keep Temp</span>
        <input class="ef ef-keeptemp" type="text" value="{$vKeepTemp}" placeholder="°C / °F">
      </td>
      <td style="width:20%">
        <span class="field-label">Recorder #</span>
        <input class="ef ef-recorder" type="text" value="{$vRecorderNum}">
      </td>
      <td style="width:15%">
        <span class="field-label">No. Pallets</span>
        <input class="ef ef-nopallets" type="number" value="{$vTotalPallets}">
      </td>
      <td style="width:20%">
        <span class="field-label">Total Weight</span>
        <input class="ef ef-totalweight" type="text" value="{$vTotalWeight}" placeholder="Total weight">
      </td>
    </tr></table>
  </div>

  <!-- SIGNATURES -->
  <table class="sig-tbl" style="margin-top:4px;">
    <tr>
      <td style="width:50%">
        <span class="sig-label">Driver Signature</span>
        <span class="sig-line"></span>
        <span class="sig-label">Print Name</span>
        <span class="sig-line"></span>
      </td>
      <td style="width:50%">
        <span class="sig-label">Received By</span>
        <span class="sig-line"></span>
        <span class="sig-label">Print Name</span>
        <span class="sig-line"></span>
      </td>
    </tr>
  </table>

</div><!-- .bol-copy -->
<hr class="copy-sep">
</template>

<script>
(function(){
  const varieties = {$jVarieties};
  const TOTALCASES   = {$totalCases};
  const TOTALPALLETS = {$totalPallets};

  // ── Zoom ─────────────────────────────────────────────────────────────────
  let currentZoom = 100;

  function applyZoom(val) {
    currentZoom = Math.min(200, Math.max(40, parseInt(val)));
    const scaler = document.getElementById('doc-scaler');
    const slider = document.getElementById('zoom-slider');
    const label  = document.getElementById('zoom-label');
    if (scaler) scaler.style.transform = 'scale(' + (currentZoom/100) + ')';
    if (slider) {
      slider.value = currentZoom;
      const pct = ((currentZoom - 40) / (200 - 40) * 100).toFixed(1);
      slider.style.setProperty('--pct', pct + '%');
    }
    if (label) label.textContent = currentZoom + '%';
  }
  window.applyZoom = applyZoom;

  function zoomStep(delta) { applyZoom(currentZoom + delta); }
  window.zoomStep = zoomStep;

  function resetZoom() { applyZoom(100); }
  window.resetZoom = resetZoom;

  function fitPage() {
    const canvas = document.getElementById('canvas');
    if (!canvas) return;
    // 210mm = ~794px at 96dpi; add padding
    const available = canvas.clientWidth - 48;
    const docPx = 794;
    const fit = Math.floor((available / docPx) * 100);
    applyZoom(Math.min(fit, 120));
  }
  window.fitPage = fitPage;

  // ── Fullscreen ────────────────────────────────────────────────────────────
  function toggleFullscreen() {
    const btn = document.getElementById('btnFs');
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen().catch(function(){});
      if (btn) btn.textContent = '✕ Exit Full';
    } else {
      document.exitFullscreen();
      if (btn) btn.textContent = '⛶ Fullscreen';
    }
  }
  window.toggleFullscreen = toggleFullscreen;

  document.addEventListener('fullscreenchange', function() {
    const btn = document.getElementById('btnFs');
    if (btn) btn.textContent = document.fullscreenElement ? '✕ Exit Full' : '⛶ Fullscreen';
  });

  // ── Keyboard shortcuts ────────────────────────────────────────────────────
  document.addEventListener('keydown', function(e) {
    // Ignore when typing in inputs
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomStep(10); }
    if (e.key === '-' || e.key === '_') { e.preventDefault(); zoomStep(-10); }
    if (e.key === '0')                  { e.preventDefault(); resetZoom(); }
    if (e.key === 'f' || e.key === 'F') { e.preventDefault(); toggleFullscreen(); }
    if (e.key === 'p' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); doPrint(); }
  });

  // ── Mousewheel zoom (Ctrl+scroll) ─────────────────────────────────────────
  document.getElementById('canvas')?.addEventListener('wheel', function(e) {
    if (!e.ctrlKey && !e.metaKey) return;
    e.preventDefault();
    zoomStep(e.deltaY < 0 ? 10 : -10);
  }, { passive: false });

  // ── Sync fields across copies ─────────────────────────────────────────────
  // NOTE: ef-prod-* classes are PER-ROW fields — skipped here to avoid
  // overwriting every row with the same value when the user edits one row.
  document.addEventListener('input', function(e) {
    const el  = e.target;
    const cls = Array.from(el.classList).find(function(c){ return c.startsWith('ef-'); });
    if (!cls) return;
    // Skip per-row product fields: each row is independent
    if (cls.startsWith('ef-prod-')) return;
    document.querySelectorAll('.' + cls).forEach(function(other) {
      if (other === el) return;
      if (other.type === 'checkbox') return;
      other.value = el.value;
    });
    if (el.type === 'checkbox') {
      document.querySelectorAll('.' + cls).forEach(function(other) {
        if (other !== el) other.checked = el.checked;
      });
    }
  });

  // ── Product table helpers ─────────────────────────────────────────────────
  function esc(str) {
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function updateTotals(table) {
    if (!table) return;
    let ctns = 0, plts = 0, wt = 0;
    table.querySelectorAll('tbody .prod-row').forEach(function(tr) {
      const cVal = tr.querySelector('.ef-prod-ctns')?.value || '';
      const pVal = tr.querySelector('.ef-prod-plts')?.value || '';
      // Weight: strip anything non-numeric (user may type number only, no "lbs")
      const wRaw = (tr.querySelector('.ef-prod-weight')?.value || '').replace(/[^0-9.]/g, '');
      ctns += parseInt(cVal,  10) || 0;
      plts += parseInt(pVal,  10) || 0;
      wt   += parseFloat(wRaw)   || 0;
    });
    const ctnsInt = Math.round(ctns);
    const pltsInt = Math.round(plts);
    const wtRnd   = Math.round(wt * 100) / 100;
    // Totals cells: look in same .bol-copy (tfoot moved outside table)
    const copyScope = table.closest('.bol-copy') || document;
    const tc = copyScope.querySelector('.total-ctns');
    const tp = copyScope.querySelector('.total-plts');
    const tw = copyScope.querySelector('.total-weight');
    if (tc) tc.textContent = ctnsInt > 0 ? ctnsInt : '';
    if (tp) tp.textContent = pltsInt > 0 ? pltsInt : '';
    // Total weight: only here we add " lbs"
    if (tw) tw.textContent = wtRnd > 0 ? wtRnd.toFixed(2) + ' lbs' : '';
    // Also update the carrier ef-totalweight field (same copy)
    const scope   = table.closest('.bol-copy') || document;
    const twInput = scope.querySelector ? scope.querySelector('.ef-totalweight') : null;
    if (twInput) twInput.value = wtRnd > 0 ? wtRnd.toFixed(2) : '';  // number only in input
  }

  function onProdInput(e) {
    const tgt = e.target;
    // Auto-calculate row weight when ctns changes: weight = ctns × wpkg
    if (tgt.classList.contains('ef-prod-ctns')) {
      const tr    = tgt.closest('tr');
      const wInp  = tr ? tr.querySelector('.ef-prod-weight') : null;
      if (wInp) {
        const wpkg = parseFloat(wInp.dataset.wpkg || 0) || 0;
        if (wpkg > 0) {
          const c = parseInt(tgt.value, 10) || 0;
          wInp.value = c > 0 ? (c * wpkg).toFixed(2) : '';
        }
      }
    }
    if (tgt.classList.contains('ef-prod-ctns') ||
        tgt.classList.contains('ef-prod-plts') ||
        tgt.classList.contains('ef-prod-weight')) {
      updateTotals(tgt.closest('table'));
    }
  }
  document.addEventListener('input',  onProdInput);
  document.addEventListener('change', onProdInput);

  function buildProdRows(tbody) {
    tbody.innerHTML = '';
    varieties.forEach(function(v) {
      const tr = document.createElement('tr');
      tr.className = 'prod-row';
      tr.innerHTML =
        '<td><input type="number" class="ef-prod-ctns" value="' + esc(v.cases||'') + '"></td>' +
        '<td><input type="number" class="ef-prod-plts" value="' + esc(v.pallets||'') + '"></td>' +
        '<td class="prod-desc"><input type="text" class="ef-prod-desc" style="width:100%" value="' +
          esc(v.product_description || v.variety || 'Unknown product') + '"></td>' +
        '<td><input type="text" class="ef-prod-pack" value="' + esc(v.pack_preset||v.pc_packaging||'Unknown') + '"></td>' +
        '<td><input type="text" class="ef-prod-weight" value="' + esc(v.row_weight > 0 ? v.row_weight.toFixed(2) : '') + '" data-wpkg="' + esc(v.weight_lbs > 0 ? v.weight_lbs.toFixed(4) : '0') + '"></td>';
      tbody.appendChild(tr);
    });
    updateTotals(tbody.closest('table'));
  }

  function addProdRow(btn) {
    // Cerca il tbody corretto (.prod-tbody) nella stessa copia del BOL,
    // evitando di aggiungere righe per errore alla tabella dei totali
    const copy  = btn.closest('.bol-copy');
    const tbody = copy ? copy.querySelector('.prod-tbody') : null;
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.className = 'prod-row';
    tr.innerHTML =
      '<td><input type="number" class="ef-prod-ctns" value=""></td>' +
      '<td><input type="number" class="ef-prod-plts" value=""></td>' +
      '<td class="prod-desc"><input type="text" class="ef-prod-desc" style="width:100%" value=""></td>' +
      '<td><input type="text" class="ef-prod-pack" value=""></td>' +
      '<td><input type="text" class="ef-prod-weight" value=""></td>';
    tbody.appendChild(tr);
    // Aggiorna i totali nella tabella principale
    updateTotals(tbody.closest('table'));
    // Adatta l'altezza del canvas dopo l'aggiunta della riga
    _adjustDocScalerHeight();
  }

  /* Aggiorna il margine bottom di #doc-scaler in modo che
     il canvas scrollabile riconosca l'altezza reale dopo zoom/aggiunta righe */
  function _adjustDocScalerHeight() {
    const scaler = document.getElementById('doc-scaler');
    if (!scaler) return;
    const scale     = currentZoom / 100;
    const naturalH  = scaler.scrollHeight;
    const extraH    = Math.max(0, naturalH * (scale - 1));
    scaler.style.marginBottom = extraH + 'px';
  }
  window._adjustDocScalerHeight = _adjustDocScalerHeight;
  window.addProdRow = addProdRow;

  // ── Build N copies ────────────────────────────────────────────────────────
  function buildCopies(n) {
    const wrap = document.getElementById('bol-wrap');
    const tpl  = document.getElementById('bol-template');
    if (!wrap || !tpl) return;

    // Save current field values from first copy
    const savedVals = {};
    wrap.querySelectorAll('[class*="ef-"]').forEach(function(el) {
      const cls = Array.from(el.classList).find(function(c){ return c.startsWith('ef-'); });
      if (!cls || cls in savedVals) return;
      savedVals[cls] = el.type === 'checkbox' ? el.checked : el.value;
    });

    // Save product rows
    const savedProdRows = [];
    wrap.querySelectorAll('.prod-row').forEach(function(tr) {
      savedProdRows.push({
        ctns:   tr.querySelector('.ef-prod-ctns')?.value   || '',
        plts:   tr.querySelector('.ef-prod-plts')?.value   || '',
        desc:   tr.querySelector('.ef-prod-desc')?.value   || '',
        pack:   tr.querySelector('.ef-prod-pack')?.value   || '',
        weight: tr.querySelector('.ef-prod-weight')?.value || '',
      });
    });

    wrap.innerHTML = '';

    for (let idx = 0; idx < n; idx++) {
      const frag = tpl.content.cloneNode(true);
      const div  = frag.querySelector('.bol-copy');

      // Build product rows
      const tbody = div.querySelector('.prod-tbody');
      if (tbody) {
        if (savedProdRows.length > 0) {
          savedProdRows.forEach(function(row) {
            const tr = document.createElement('tr');
            tr.className = 'prod-row';
            tr.innerHTML =
              '<td><input type="number" class="ef-prod-ctns" value="' + esc(row.ctns)   + '"></td>' +
              '<td><input type="number" class="ef-prod-plts" value="' + esc(row.plts)   + '"></td>' +
              '<td class="prod-desc"><input type="text" class="ef-prod-desc" style="width:100%" value="' + esc(row.desc) + '"></td>' +
              '<td><input type="text" class="ef-prod-pack"   value="' + esc(row.pack)   + '"></td>' +
              '<td><input type="text" class="ef-prod-weight" value="' + esc(row.weight) + '"></td>';
            tbody.appendChild(tr);
          });
          updateTotals(tbody.closest('table'));
        } else {
          buildProdRows(tbody);
        }
      }

      // Restore field values
      div.querySelectorAll('[class*="ef-"]').forEach(function(el) {
        const cls = Array.from(el.classList).find(function(c){ return c.startsWith('ef-'); });
        if (!cls || !(cls in savedVals)) return;
        if (el.type === 'checkbox') el.checked = savedVals[cls];
        else el.value = savedVals[cls];
      });

      wrap.appendChild(frag);
    }

    // First-time load: build prod rows from varieties
    if (Object.keys(savedVals).length === 0) {
      wrap.querySelectorAll('.prod-tbody').forEach(buildProdRows);
    }
    // After all copies are in the real DOM, recalculate totals so ef-totalweight is correct
    wrap.querySelectorAll('table.prod').forEach(updateTotals);
  }
  window.buildCopies = buildCopies;

  window.phytoPick = function(which, el) {
    const copy=el.closest('.bol-copy')||document;
    const y=copy.querySelector('.ef-phyto-y'), n=copy.querySelector('.ef-phyto-n');
    if(which==='Y' && y?.checked && n) n.checked=false;
    if(which==='N' && n?.checked && y) y.checked=false;
  };

  // ── Copies change ─────────────────────────────────────────────────────────
  function onCopiesChange(val) {
    const n = Math.max(1, Math.min(20, parseInt(val) || 1));
    document.getElementById('copiesInput').value = n;
    buildCopies(n);
  }
  window.onCopiesChange = onCopiesChange;

  // ── Generate PDF con valori manuali ─────────────────────────────────────
  window.doPdf = function() {
    const btn = document.getElementById('btn-pdf-dl');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ ...'; }
    // Collect product rows from first bol-copy
    const prodRows = [];
    document.querySelectorAll('.bol-copy')[0]
      ?.querySelectorAll('.prod-row').forEach(function(tr) {
        prodRows.push({
          ctns:   tr.querySelector('.ef-prod-ctns')?.value   || '',
          plts:   tr.querySelector('.ef-prod-plts')?.value   || '',
          desc:   tr.querySelector('.ef-prod-desc')?.value   || '',
          pack:   tr.querySelector('.ef-prod-pack')?.value   || '',
          weight: tr.querySelector('.ef-prod-weight')?.value || ''
        });
      });
    function ef(cls) {
      return (document.querySelector('.' + cls)?.value || '').trim();
    }
    // Build FormData for fetch POST
    const fd = new FormData();
    fd.append('action',          'bol_pdf_manual');
    fd.append('shipment_id',     ef('ef-ref') || ef('ef-bolnum') || '');
    fd.append('carrier',         ef('ef-carrier'));
    fd.append('ship_date',       ef('ef-date'));
    fd.append('dest_city',       ef('ef-destcity'));
    fd.append('pick_location',   ef('ef-pickup'));
    fd.append('ship_to_address', ef('ef-shipto'));
    fd.append('customer_name', ef('ef-customer'));
    fd.append('bol_label', ef('ef-label'));
    fd.append('bol_awb', ef('ef-awb'));
    fd.append('bol_notify', ef('ef-notify'));
    fd.append('bol_consignee', ef('ef-consignee'));
    fd.append('bol_keep_temp', ef('ef-keeptemp'));
    fd.append('bol_recorder', ef('ef-recorder'));
    const phyY=document.querySelector('.ef-phyto-y')?.checked;
    const phyN=document.querySelector('.ef-phyto-n')?.checked;
    fd.append('bol_phyto', phyY ? 'Y' : (phyN ? 'N' : ''));
    fd.append('prod_rows',       JSON.stringify(prodRows));
    // Use fetch to get PDF blob — avoids popup blockers
    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(function(resp) {
        if (!resp.ok) {
          return resp.text().then(function(t) { throw new Error(t || resp.status); });
        }
        return resp.blob();
      })
      .then(function(blob) {
        // Apre il PDF nel browser (visualizzazione inline) invece di scaricarlo
        const url = URL.createObjectURL(blob);
        const newTab = window.open(url, '_blank');
        if (!newTab) {
          // fallback se i popup sono bloccati: apri tramite link
          const a = document.createElement('a');
          a.href   = url;
          a.target = '_blank';
          a.rel    = 'noopener';
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
        }
        // Revoca l'URL dopo 2 minuti (tempo sufficiente per visualizzarlo)
        setTimeout(function() { URL.revokeObjectURL(url); }, 120000);
      })
      .catch(function(err) {
        alert('PDF error: ' + err.message);
      })
      .finally(function() {
        if (btn) { btn.disabled = false; btn.textContent = '⬇️ PDF'; }
      });
  };


  // ── Save to DB ───────────────────────────────────────────────────────────
  window.doSave = function() {
    const btn = document.getElementById('btn-save-bol');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ ...'; }
    function ef(cls) {
      return (document.querySelector('.' + cls)?.value || '').trim();
    }
    // Collect product rows from first bol-copy
    const prodRows = [];
    document.querySelectorAll('.bol-copy')[0]
      ?.querySelectorAll('.prod-row').forEach(function(tr) {
        prodRows.push({
          ctns:   tr.querySelector('.ef-prod-ctns')?.value   || '',
          plts:   tr.querySelector('.ef-prod-plts')?.value   || '',
          desc:   tr.querySelector('.ef-prod-desc')?.value   || '',
          pack:   tr.querySelector('.ef-prod-pack')?.value   || '',
          weight: tr.querySelector('.ef-prod-weight')?.value || ''
        });
      });
    const fd = new FormData();
    fd.append('action',          'save_bol_view');
    fd.append('shipment_id',     ef('ef-ref') || ef('ef-bolnum') || '');
    fd.append('carrier',         ef('ef-carrier'));
    fd.append('ship_date',       ef('ef-date'));
    fd.append('dest_city',       ef('ef-destcity'));
    fd.append('pick_location',   ef('ef-pickup'));
    fd.append('ship_to_address', ef('ef-shipto'));
    fd.append('customer_name', ef('ef-customer'));
    fd.append('bol_label', ef('ef-label'));
    fd.append('bol_awb', ef('ef-awb'));
    fd.append('bol_notify', ef('ef-notify'));
    fd.append('bol_consignee', ef('ef-consignee'));
    fd.append('bol_keep_temp', ef('ef-keeptemp'));
    fd.append('bol_recorder', ef('ef-recorder'));
    const phyY=document.querySelector('.ef-phyto-y')?.checked;
    const phyN=document.querySelector('.ef-phyto-n')?.checked;
    fd.append('bol_phyto', phyY ? 'Y' : (phyN ? 'N' : ''));
    fd.append('prod_rows',       JSON.stringify(prodRows));
    fetch(window.location.pathname, { method: 'POST', body: fd })
      .then(function(resp) { return resp.json(); })
      .then(function(data) {
        if (data.ok) {
          if (btn) { btn.textContent = '✔ Saved'; btn.disabled = false; }
          setTimeout(function() {
            if (btn) btn.textContent = '💾 Save';
          }, 2000);
        } else {
          alert('Save failed: ' + (data.err || data.error || 'Unknown error'));
          if (btn) { btn.disabled = false; btn.textContent = '💾 Save'; }
        }
      })
      .catch(function(err) {
        alert('Save error: ' + err.message);
        if (btn) { btn.disabled = false; btn.textContent = '💾 Save'; }
      });
  };

  // ── Save reviewed values, build exact PDF, then queue the selected BOL printer ──
  window.doPrint = async function() {
    const btn=document.getElementById('btn-print-bol');
    if(btn){btn.disabled=true;btn.textContent='⏳ Preparing…';}
    function ef(cls){return(document.querySelector('.'+cls)?.value||'').trim();}
    const prodRows=[];
    document.querySelectorAll('.bol-copy')[0]?.querySelectorAll('.prod-row').forEach(function(tr){
      prodRows.push({ctns:tr.querySelector('.ef-prod-ctns')?.value||'',plts:tr.querySelector('.ef-prod-plts')?.value||'',desc:tr.querySelector('.ef-prod-desc')?.value||'',pack:tr.querySelector('.ef-prod-pack')?.value||'',weight:tr.querySelector('.ef-prod-weight')?.value||''});
    });
    const phyY=document.querySelector('.ef-phyto-y')?.checked;
    const phyN=document.querySelector('.ef-phyto-n')?.checked;
    const phyto=phyY?'Y':(phyN?'N':'');
    function fill(fd){
      fd.append('shipment_id',ef('ef-ref')||ef('ef-bolnum')||'');
      fd.append('carrier',ef('ef-carrier')); fd.append('ship_date',ef('ef-date'));
      fd.append('dest_city',ef('ef-destcity')); fd.append('pick_location',ef('ef-pickup'));
      fd.append('ship_to_address',ef('ef-shipto')); fd.append('customer_name',ef('ef-customer'));
      fd.append('bol_label',ef('ef-label')); fd.append('bol_awb',ef('ef-awb'));
      fd.append('bol_notify',ef('ef-notify')); fd.append('bol_consignee',ef('ef-consignee'));
      fd.append('bol_keep_temp',ef('ef-keeptemp')); fd.append('bol_recorder',ef('ef-recorder'));
      fd.append('bol_phyto',phyto); fd.append('prod_rows',JSON.stringify(prodRows));
    }
    try{
      let fd=new FormData(); fd.append('action','save_bol_view'); fill(fd);
      let resp=await fetch(window.location.pathname,{method:'POST',body:fd});
      let saved=await resp.json(); if(!saved.ok) throw new Error(saved.err||'Could not save BOL');
      fd=new FormData(); fd.append('action','bol_pdf_manual'); fill(fd);
      resp=await fetch(window.location.pathname,{method:'POST',body:fd});
      if(!resp.ok) throw new Error((await resp.text())||'Could not generate PDF');
      await resp.blob();
      fd=new FormData(); fd.append('action','queue_bol_print'); fd.append('shipment_id',ef('ef-ref')||ef('ef-bolnum')||'');
      resp=await fetch(window.location.pathname,{method:'POST',body:fd});
      const q=await resp.json();
      if(q.ok&&q.queued) alert('BOL saved and queued to '+q.printer+(q.job_id?' · Job #'+q.job_id:''));
      else if(q.skipped) alert('BOL saved and PDF generated. Select a BOL printer in Shipments Manage to print automatically.');
      else throw new Error(q.err||'BOL print queue failed');
    }catch(e){alert('Print error: '+e.message);}
    finally{if(btn){btn.disabled=false;btn.textContent='🖨 Save & Print';}}
  };

  // ── Init ──────────────────────────────────────────────────────────────────
  buildCopies(1);

  // Auto fit on load
  window.addEventListener('load', function() {
    setTimeout(fitPage, 100);
  });

  // Re-fit on resize
  window.addEventListener('resize', function() {
    // Only re-fit if near fit (within ±5% of a fit zoom)
    fitPage();
  });

  /* ══════════════════════════════════════════════════════
     FORMAT BAR — rich-text formatting for editable fields
     Works on contenteditable divs and input/textarea fields.
     For inputs/textareas it applies CSS styles directly.
  ══════════════════════════════════════════════════════ */

  /* Track the last focused editable element */
  let _fmtTarget = null;
  let _fmtSavedRange = null;

  function fmtTrackFocus(el) {
    _fmtTarget = el;
    const lbl = document.getElementById('fb-target-lbl');
    if (lbl) {
      const tag = el.tagName.toLowerCase();
      const cls = el.className.replace(/\bef\b/,'').trim().replace(/ef-/g,'').trim();
      lbl.textContent = cls ? cls : (el.placeholder || tag);
    }
    fmtSyncState();
  }

  /* Attach focus tracking to all editable fields */
  function fmtAttachTrackers() {
    // inputs and textareas
    document.querySelectorAll('.ef, .ef-ta, .prod td input').forEach(function(el) {
      el.addEventListener('focus', function(){ fmtTrackFocus(el); });
      el.addEventListener('click', function(){ fmtTrackFocus(el); });
    });
  }

  /* execCommand wrapper — works on contenteditable selection */
  function fmtExec(cmd, val) {
    document.execCommand(cmd, false, val || null);
    fmtSyncState();
  }

  /* Toggle bold/italic/underline/strikethrough */
  function fmtToggle(cmd) {
    fmtExec(cmd);
  }

  /* Set font size (pt) on selected input or selection */
  function fmtSetFontSize(pt) {
    if (!pt) return;
    const el = _fmtTarget;
    if (!el) return;
    const pxVal = Math.round(parseInt(pt) * 1.333) + 'px';
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      el.style.fontSize = pxVal;
    } else {
      // contenteditable: use execCommand fontSize (1-7) then patch
      // map pt to 1-7: 1=8, 2=10, 3=12, 4=14, 5=18, 6=24, 7=36
      const ptNum = parseInt(pt);
      let sz = ptNum <= 8 ? 1 : ptNum <= 10 ? 2 : ptNum <= 12 ? 3 :
               ptNum <= 14 ? 4 : ptNum <= 18 ? 5 : ptNum <= 24 ? 6 : 7;
      document.execCommand('fontSize', false, sz);
      // Then fix <font size="N"> to <span style="font-size:Xpx">
      document.querySelectorAll('font[size]').forEach(function(font) {
        const span = document.createElement('span');
        span.style.fontSize = pxVal;
        span.innerHTML = font.innerHTML;
        font.parentNode.replaceChild(span, font);
      });
    }
    // Also apply directly to input/textarea element
    if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) {
      el.style.fontSize = pxVal;
    }
    fmtSyncState();
  }

  /* Apply current font+size to ALL editable fields */
  function fmtApplyFontToAll() {
    const fontSel = document.getElementById('fb-font');
    const sizeSel = document.getElementById('fb-size');
    const font = fontSel ? fontSel.value : null;
    const pt   = sizeSel ? sizeSel.value : null;
    document.querySelectorAll('.ef, .ef-ta, .prod td input').forEach(function(el) {
      if (font) el.style.fontFamily = font;
      if (pt)   el.style.fontSize   = Math.round(parseInt(pt) * 1.333) + 'px';
    });
  }
  window.fmtApplyFontToAll = fmtApplyFontToAll;

  /* Clear all inline formatting from selected text */
  function fmtClearFormat() {
    const el = _fmtTarget;
    if (!el) return;
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      el.style.fontFamily  = '';
      el.style.fontSize    = '';
      el.style.fontWeight  = '';
      el.style.fontStyle   = '';
      el.style.textDecoration = '';
      el.style.color       = '';
      el.style.background  = '';
      el.style.textAlign   = '';
    } else {
      document.execCommand('removeFormat');
    }
  }
  window.fmtClearFormat = fmtClearFormat;

  /* Sync toolbar button states (bold/italic/underline active) */
  function fmtSyncState() {
    const cmds = ['bold','italic','underline','strikeThrough',
                  'justifyLeft','justifyCenter','justifyRight','justifyFull'];
    const idMap = {
      bold:'fb-bold', italic:'fb-italic', underline:'fb-under',
      strikeThrough:'fb-strike',
      justifyLeft:'fb-al', justifyCenter:'fb-ac',
      justifyRight:'fb-ar', justifyFull:'fb-aj'
    };
    cmds.forEach(function(cmd) {
      const btn = document.getElementById(idMap[cmd]);
      if (!btn) return;
      try {
        btn.classList.toggle('active', document.queryCommandState(cmd));
      } catch(e) {}
    });
  }

  /* Init format bar after BOL copies are rendered */
  function fmtInit() {
    fmtAttachTrackers();
    // Keyboard shortcuts for active element
    document.addEventListener('keydown', function(e) {
      if (!_fmtTarget) return;
      if ((e.ctrlKey || e.metaKey)) {
        if (e.key === 'b') { e.preventDefault(); fmtToggle('bold'); }
        if (e.key === 'i') { e.preventDefault(); fmtToggle('italic'); }
        if (e.key === 'u') { e.preventDefault(); fmtToggle('underline'); }
      }
    });
    // Sync state on selection change
    document.addEventListener('selectionchange', fmtSyncState);
  }

  // expose
  window.fmtExec     = fmtExec;
  window.fmtToggle   = fmtToggle;
  window.fmtSetFontSize = fmtSetFontSize;

  // Init format bar
  fmtInit();

})();
</script>
</body>
</html>
HTML;
        exit;
    }

}



// ── Inline DDL repair ─────────────────────────────────────────────────────────
{
    $ddl = function(string $sql) use ($dbx): void {
        try {
            if ($dbx instanceof PDO)       $dbx->exec($sql);
            elseif ($dbx instanceof mysqli) $dbx->query($sql);
        } catch (Throwable $e) {}
    };
    $ddl("ALTER TABLE pallet_cases DROP INDEX uniq_pallet_case");
    $ddl("ALTER TABLE pallet_cases DROP INDEX idx_pallet");
    $ddl("ALTER TABLE pallet_cases MODIFY COLUMN pallet_id VARCHAR(60) NOT NULL DEFAULT ''");
    $ddl("ALTER TABLE pallet_cases ADD COLUMN case_serial VARCHAR(60) NOT NULL DEFAULT ''");
    $ddl("ALTER TABLE pallet_cases MODIFY COLUMN case_serial VARCHAR(60) NOT NULL DEFAULT ''");
    $ddl("ALTER TABLE pallet_cases ADD UNIQUE KEY uniq_pallet_case (pallet_id, case_serial)");
    $ddl("ALTER TABLE pallet_cases ADD KEY idx_pallet (pallet_id)");
    $ddl("ALTER TABLE shipment_pallets DROP INDEX uniq_ship");
    $ddl("ALTER TABLE shipment_pallets MODIFY COLUMN shipment_id VARCHAR(60) NOT NULL DEFAULT ''");
    $ddl("ALTER TABLE shipment_pallets MODIFY COLUMN pallet_id VARCHAR(60) NOT NULL DEFAULT ''");
    $ddl("ALTER TABLE shipment_pallets ADD UNIQUE KEY uniq_ship (shipment_id, pallet_id)");
    $ddl("ALTER TABLE shipment_pallets ADD COLUMN added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    $ddl("ALTER TABLE pallets   MODIFY COLUMN pallet_id   VARCHAR(60) NOT NULL");
    $ddl("ALTER TABLE shipments MODIFY COLUMN shipment_id VARCHAR(60) NOT NULL");
    $ddl("ALTER TABLE shipments ADD COLUMN bol_product_rows LONGTEXT NULL");
}

$action = $_POST['action'] ?? '';
$uid    = (int)($_SESSION['user_id'] ?? 0);

// ── Helpers ───────────────────────────────────────────────────────────────────
function pallet_rows_full($dbx, string $sid, int $limit = 200): array {
    // include case_count per pallet
    try {
        $rows = smp_db_fetch_all($dbx,
            "SELECT sp.id, sp.pallet_id,
                    DATE_FORMAT(COALESCE(sp.added_at,NOW()),'%Y-%m-%d %H:%i') AS added_at,
                    (SELECT COUNT(*) FROM pallet_cases pc WHERE pc.pallet_id=sp.pallet_id) AS cases_count
             FROM shipment_pallets sp
             WHERE sp.shipment_id=?
             ORDER BY sp.id DESC LIMIT " . (int)$limit,
            [$sid]
        );
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        try {
            $rows = smp_db_fetch_all($dbx,
                "SELECT id, pallet_id, NULL AS added_at, 0 AS cases_count
                 FROM shipment_pallets WHERE shipment_id=? ORDER BY id DESC LIMIT ".(int)$limit,
                [$sid]
            );
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e2) { return []; }
    }
}

function shipment_detail($dbx, string $sid): array {
    try {
        $r = smp_db_fetch_one($dbx,
            "SELECT po, customer_name, order_id,
                    DATE_FORMAT(ship_date,'%Y-%m-%d') AS ship_date
             FROM shipments WHERE shipment_id=?",
            [$sid]
        );
        return $r ?: [];
    } catch (Throwable $e) { return []; }
}

function get_ship_rule($dbx): array {
    $cfg=smp_get_shipment_print_settings($dbx);
    return [
        'printer_id'=>(int)($cfg['label_printer_id']??0),
        'template_id'=>(int)($cfg['label_template_id']??0),
    ];
}

/**
 * Compare PO expected cases/varieties vs what is actually in the shipment pallets.
 * Returns ['ok'=>1, 'all_ok'=>bool, 'po_qty'=>int, 'ship_qty'=>int,
 *          'po_name'=>str, 'variety_diffs'=>[...]]
 */
function compare_po_shipment($dbx, string $sid, int $orderId): array {
    // ── PO quantities per variety ─────────────────────────────────────────────
    $poQty = 0; $poVarieties = [];
    try {
        // Sum from order_lines joined to skus
        $lines = smp_db_fetch_all($dbx,
            "SELECT COALESCE(s.variety,'') AS variety, SUM(ol.quantity) AS qty
             FROM order_lines ol
             LEFT JOIN skus s ON s.SKU = ol.sku_id
             WHERE ol.order_id=?
             GROUP BY s.variety",
            [$orderId]
        );
        foreach ($lines as $l) {
            $v = trim((string)($l['variety'] ?? ''));
            $q = (int)($l['qty'] ?? 0);
            $poQty += $q;
            if ($v !== '') $poVarieties[$v] = ($poVarieties[$v] ?? 0) + $q;
        }
        // Fallback: quantity column directly on orders
        if ($poQty === 0) {
            $ord = smp_db_fetch_one($dbx,
                "SELECT COALESCE(quantity,0) AS qty FROM orders WHERE id=?", [$orderId]);
            $poQty = (int)($ord['qty'] ?? 0);
        }
    } catch (Throwable $e) {}

    // PO name
    $poName = '';
    try {
        $r = smp_db_fetch_one($dbx, "SELECT po FROM orders WHERE id=?", [$orderId]);
        $poName = (string)($r['po'] ?? '');
    } catch (Throwable $e) {}

    // ── Shipment actual cases per variety ─────────────────────────────────────
    $shipQty = 0; $shipVarieties = [];
    try {
        $cases = smp_db_fetch_all($dbx,
            "SELECT COALESCE(NULLIF(TRIM(pc.variety),''), 'Unknown') AS variety,
                    COUNT(*) AS qty
             FROM shipment_pallets sp
             JOIN pallet_cases pc ON pc.pallet_id = sp.pallet_id
             WHERE sp.shipment_id=?
             GROUP BY pc.variety",
            [$sid]
        );
        foreach ($cases as $c) {
            $v = trim((string)($c['variety'] ?? 'Unknown'));
            $q = (int)($c['qty'] ?? 0);
            $shipQty += $q;
            if ($v !== '') $shipVarieties[$v] = ($shipVarieties[$v] ?? 0) + $q;
        }
    } catch (Throwable $e) {}

    // ── Diffs ─────────────────────────────────────────────────────────────────
    $diffs = [];
    if ($poQty !== $shipQty) {
        $diffs[] = 'Total qty: ordered '.$poQty.', shipped '.$shipQty;
    }
    // Variety check (only if PO has variety info)
    if (!empty($poVarieties)) {
        $allV = array_unique(array_merge(array_keys($poVarieties), array_keys($shipVarieties)));
        foreach ($allV as $v) {
            $p = $poVarieties[$v] ?? 0;
            $s = $shipVarieties[$v] ?? 0;
            if ($p !== $s) {
                $diffs[] = trim($v).': ordered '.$p.', shipped '.$s;
            }
        }
    }

    return [
        'ok'           => 1,
        'all_ok'       => empty($diffs),
        'po_qty'       => $poQty,
        'ship_qty'     => $shipQty,
        'po_name'      => $poName,
        'variety_diffs'=> $diffs,
        'po_varieties' => $poVarieties,
        'ship_varieties'=> $shipVarieties,
    ];
}



// ── Helper: fetch product rows for BOL with pack preset + weight ──────────────
function fetchBolVarieties(object $db, string $sid, string $po): array
{
    /*
     * AUTHORITATIVE FLOW:
     * shipment_pallets -> pallet_cases -> casecodes
     *
     * pallet_cases tells us which CASES physically belong to the pallet.
     * casecodes is used as a fallback/augmentation for product metadata so that
     * older pallets with incomplete pallet_cases metadata still populate the BOL.
     */
    $rows = [];

    try {
        $rows = smp_db_fetch_all($db,
            "SELECT
                COALESCE(
                    NULLIF(TRIM(pc.grower),''),
                    NULLIF(TRIM(cc.grower),''),
                    'Unknown'
                ) AS grower,

                COALESCE(
                    NULLIF(CAST(pc.sku AS CHAR),''),
                    NULLIF(CAST(cc.SKU AS CHAR),''),
                    'Unknown'
                ) AS pc_sku,

                COALESCE(
                    NULLIF(TRIM(pc.variety),''),
                    NULLIF(TRIM(cc.variety),''),
                    'Unknown'
                ) AS variety,

                COALESCE(
                    NULLIF(TRIM(pc.packaging),''),
                    NULLIF(TRIM(cc.packaging),''),
                    'Unknown'
                ) AS pc_packaging,

                COALESCE(
                    NULLIF(TRIM(pc.size),''),
                    NULLIF(TRIM(cc.size),''),
                    'Unknown'
                ) AS size,

                COUNT(*) AS cases,
                COUNT(DISTINCT sp.pallet_id) AS pallets

             FROM shipment_pallets sp
             INNER JOIN pallet_cases pc
                     ON pc.pallet_id = sp.pallet_id
             LEFT JOIN casecodes cc
                    ON cc.serial = pc.case_serial

             WHERE sp.shipment_id=?

             GROUP BY
                COALESCE(NULLIF(TRIM(pc.grower),''),NULLIF(TRIM(cc.grower),''),'Unknown'),
                COALESCE(NULLIF(CAST(pc.sku AS CHAR),''),NULLIF(CAST(cc.SKU AS CHAR),''),'Unknown'),
                COALESCE(NULLIF(TRIM(pc.variety),''),NULLIF(TRIM(cc.variety),''),'Unknown'),
                COALESCE(NULLIF(TRIM(pc.packaging),''),NULLIF(TRIM(cc.packaging),''),'Unknown'),
                COALESCE(NULLIF(TRIM(pc.size),''),NULLIF(TRIM(cc.size),''),'Unknown')

             ORDER BY
                grower ASC,
                variety ASC,
                pc_packaging ASC,
                size ASC,
                pc_sku ASC",
            [$sid]
        ) ?? [];
    } catch (Throwable $e) {
        $rows = [];
    }

    /*
     * Secondary fallback for installations where pallet_cases metadata exists
     * but the case_serial -> casecodes join/schema is older.
     */
    if (!$rows) {
        try {
            $rows = smp_db_fetch_all($db,
                "SELECT
                    COALESCE(NULLIF(TRIM(pc.grower),''),'Unknown') AS grower,
                    COALESCE(NULLIF(CAST(pc.sku AS CHAR),''),'Unknown') AS pc_sku,
                    COALESCE(NULLIF(TRIM(pc.variety),''),'Unknown') AS variety,
                    COALESCE(NULLIF(TRIM(pc.packaging),''),'Unknown') AS pc_packaging,
                    COALESCE(NULLIF(TRIM(pc.size),''),'Unknown') AS size,
                    COUNT(*) AS cases,
                    COUNT(DISTINCT sp.pallet_id) AS pallets
                 FROM shipment_pallets sp
                 INNER JOIN pallet_cases pc ON pc.pallet_id=sp.pallet_id
                 WHERE sp.shipment_id=?
                 GROUP BY pc.grower,pc.sku,pc.variety,pc.packaging,pc.size
                 ORDER BY pc.grower,pc.variety,pc.packaging,pc.size,pc.sku",
                [$sid]
            ) ?? [];
        } catch (Throwable $e) {
            $rows = [];
        }
    }

    // Order data lives in the Orders PDO database, not necessarily in the
    // main mysqli database. Use the exact helpers used by orders.php/orders_add.php.
    //
    // PACK   = order_lines.packaging_preset
    // WEIGHT = CTNS * order_pack_presets.weight_lbs
    //
    // Product identity still comes exclusively from the real shipment pallets.
    $orderBySku = [];
    $orderByVariety = [];

    if ($po !== '') {
        try {
            require_once __DIR__ . '/../../config/orders_sql_lib.php';

            if (orders_sql_ready()) {
                orders_sql_init();

                $ord = orders_fetch_one_sql_by_po($po);
                $orderId = (int)($ord['id'] ?? 0);

                if ($orderId > 0) {
                    $orderLines = orders_fetch_lines_sql($orderId);

                    // Exact Packaging Preset -> weight map from the same Orders DB.
                    $presetWeights = [];
                    try {
                        $odb = orders_db();
                        if ($odb) {
                            $pr = $odb->query(
                                "SELECT label, COALESCE(weight_lbs,0) AS weight_lbs
                                 FROM order_pack_presets"
                            );
                            foreach (($pr ? $pr->fetchAll(PDO::FETCH_ASSOC) : []) as $pp) {
                                $label = trim((string)($pp['label'] ?? ''));
                                if ($label !== '') {
                                    $presetWeights[strtolower($label)] =
                                        (float)($pp['weight_lbs'] ?? 0);
                                }
                            }
                        }
                    } catch (Throwable $_e) {}

                    foreach ($orderLines as $ol) {
                        $sku = trim((string)($ol['sku_id'] ?? $ol['sku_code'] ?? ''));
                        $varietyKey = strtolower(trim((string)($ol['variety'] ?? '')));

                        // This is the "Packaging" selected in orders_add.php.
                        $packPreset = trim((string)($ol['packaging_preset'] ?? ''));

                        $weightPerCase = 0.0;
                        if ($packPreset !== '') {
                            $weightPerCase =
                                (float)($presetWeights[strtolower($packPreset)] ?? 0);
                        }

                        $info = [
                            'pack'=>$packPreset,
                            'weight'=>$weightPerCase,
                            'sku_packaging'=>trim((string)($ol['packaging'] ?? '')),
                            'size'=>trim((string)($ol['size'] ?? '')),
                            'variety'=>trim((string)($ol['variety'] ?? '')),
                        ];

                        if ($sku !== '') {
                            // If duplicate SKU lines exist, prefer the one that actually has
                            // a Packaging Preset / weight rather than replacing it with blank.
                            if (!isset($orderBySku[$sku])
                                || ($orderBySku[$sku]['pack'] === '' && $packPreset !== '')
                                || ((float)$orderBySku[$sku]['weight'] <= 0 && $weightPerCase > 0)) {
                                $orderBySku[$sku] = $info;
                            }
                        }

                        if ($varietyKey !== '') {
                            if (!isset($orderByVariety[$varietyKey])
                                || ($orderByVariety[$varietyKey]['pack'] === '' && $packPreset !== '')
                                || ((float)$orderByVariety[$varietyKey]['weight'] <= 0 && $weightPerCase > 0)) {
                                $orderByVariety[$varietyKey] = $info;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // The BOL still shows shipment products even if order enrichment fails.
        }
    }

    foreach ($rows as &$v) {
        $sku=trim((string)($v['pc_sku']??''));
        $variety=trim((string)($v['variety']??'Unknown'));
        $grower=trim((string)($v['grower']??'Unknown'));
        $pack=trim((string)($v['pc_packaging']??'Unknown'));
        $size=trim((string)($v['size']??'Unknown'));

        $match=null;
        if($sku!=='' && isset($orderBySku[$sku]))$match=$orderBySku[$sku];
        if(!$match){
            $vk=strtolower($variety);
            if(isset($orderByVariety[$vk]))$match=$orderByVariety[$vk];
        }

        // PACK on the BOL represents the Packaging selected on the ORDER.
        // The physical CASE packaging is used only as a fallback if the order
        // has no Packaging value.
        $orderPack=trim((string)($match['pack']??''));
        if($orderPack!==''){
            $pack=$orderPack;
        } elseif($pack==='' || strcasecmp($pack,'Unknown')===0){
            $pack='Unknown';
        }
        $v['pack_preset']=$pack;

        $weightPerCase=(float)($match['weight']??0);
        $v['weight_lbs']=$weightPerCase;
        $v['row_weight']=$weightPerCase>0
            ? round((int)$v['cases']*$weightPerCase,2)
            : 0.0;

        // PRODUCT DESCRIPTION on the BOL:
        // Variety + Size + Packaging from the SKU/CASE data.
        // This is intentionally different from the PACK column, which comes
        // from the Packaging selected on the order.
        $skuPackaging=trim((string)($v['pc_packaging']??''));

        $parts=[];
        if($variety!=='' && strcasecmp($variety,'Unknown')!==0)$parts[]=$variety;
        if($size!=='' && strcasecmp($size,'Unknown')!==0)$parts[]=$size;
        if($skuPackaging!=='' && strcasecmp($skuPackaging,'Unknown')!==0)$parts[]=$skuPackaging;

        $v['product_description']=implode(' | ',$parts);
        if($v['product_description']==='')$v['product_description']='Unknown product';
    }
    unset($v);

    return $rows;
}

// ── BOL HTML builder ──────────────────────────────────────────────────────────
function buildBolHtml(array $ship, array $pallets, array $varieties,
                      string $bolNum, string $logoB64,
                      int $totalCases, int $totalPallets, float $totalWeight = 0.0): string
{
    $he = function($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); };

    $po            = $he($ship['po']             ?? '');
    $customer      = $he($ship['customer_name']  ?? '');
    $carrier       = $he($ship['carrier']        ?? '');
    $destination   = $he($ship['destination']    ?? '');
    $shipper       = $he($ship['shipper']        ?? '');
    $shipDate      = $he($ship['ship_date']      ?? date('Y-m-d'));
    $sid           = $he($ship['shipment_id']    ?? '');
    $notes         = $he($ship['notes']          ?? '');
    $genDate       = date('Y-m-d H:i');
    $pickLocation  = $he($ship['pick_location']  ?? '');
    // ship_to_address: preserve line breaks → convert newlines to <br>
    $shipToRaw     = (string)($ship['ship_to_address'] ?? '');
    $shipToHtml    = nl2br($he($shipToRaw));
    $destCity      = $he($ship['dest_city']      ?? $ship['destination'] ?? '');
    $bolLabel=$he($ship['bol_label']??'');
    $bolAwb=$he($ship['bol_awb']??'');
    $bolNotify=$he($ship['bol_notify']??'');
    $bolConsignee=$he(trim((string)($ship['bol_consignee']??''))!==''?$ship['bol_consignee']:($ship['dest_city']??$ship['destination']??''));
    $bolKeepTemp=$he($ship['bol_keep_temp']??'');
    $bolRecorder=$he($ship['bol_recorder']??'');
    $bolPhyto=strtoupper(trim((string)($ship['bol_phyto']??'')));
    $phytoY=$bolPhyto==='Y'?'&#10003;':'&nbsp;';
    $phytoN=$bolPhyto==='N'?'&#10003;':'&nbsp;';

    // Total weight display
    $totalWeightDisp = $totalWeight > 0
        ? number_format($totalWeight, 2) . ' lbs'
        : 'TOTAL WEIGHT';

    // Logo: try logo/logo.png, resize inline, fallback to pre-embedded base64
    $logoHtml = '';
    if ($logoB64 !== '') {
        $logoHtml = '<img src="'.$logoB64.'" style="max-height:90px;max-width:220px;display:block;margin:0 auto 3pt;" alt="SM Produce">';
    }

    // Variety rows for product table
    $prodRows = '';
    foreach ($varieties as $v) {
        // Description comes from the real pallet CASE data.
        $desc = trim((string)($v['product_description'] ?? ''));
        if ($desc === '') {
            $descParts = [trim((string)($v['variety'] ?? ''))];
            $grower = trim((string)($v['grower'] ?? ''));
            if ($grower !== '') $descParts[] = 'Grower: '.$grower;
            $desc = implode(' | ', array_filter($descParts));
        }
        $packLabel  = htmlspecialchars(trim((string)($v['pack_preset'] ?? '')), ENT_QUOTES, 'UTF-8');
        $rowWeight  = (float)($v['row_weight'] ?? 0);
        $weightDisp = $rowWeight > 0 ? number_format($rowWeight, 2) : '&nbsp;';
        $prodRows .= '<tr>'
            . '<td class="tc" style="width:8%">'.(int)($v['cases']   ?? 0).'</td>'
            . '<td class="tc" style="width:8%">'.(int)($v['pallets'] ?? 0).'</td>'
            . '<td style="width:46%">'.htmlspecialchars($desc, ENT_QUOTES,'UTF-8').'</td>'
            . '<td class="tc" style="width:18%">' . $packLabel . '</td>'
            . '<td class="tc" style="width:20%">' . $weightDisp . '</td>'
            . '</tr>';
    }
    // No filler rows — table shows only real product lines

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Bill of Lading {$bolNum}</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 9pt;
    color: #000;
    background: #fff;
    padding: 12px 16px;
}
/* ── outer border: removed border from wrap (causes dompdf multi-page artifacts) ── */
.bol-wrap {
    width: 100%;
}
/* ── header band ── */
.hdr-band {
    border-bottom: 2px solid #000;
    padding: 8px 10px 6px 10px;
}
table.hdr-tbl { width:100%; border-collapse:collapse; }
table.hdr-tbl td { vertical-align:middle; padding:0; }
.company-name-hdr {
    font-size: 11pt;
    font-weight: bold;
    color: #111;
    line-height: 1.35;
}
.company-addr {
    font-size: 8pt;
    line-height: 1.5;
    color: #333;
    margin-top: 2pt;
}
.contact-info {
    text-align: right;
    font-size: 8pt;
    color: #333;
    line-height: 1.6;
}
.doc-title {
    text-align: center;
    font-size: 14pt;
    font-weight: bold;
    letter-spacing: 2px;
    color: #000;
    text-transform: uppercase;
    margin-top: 3pt;
}
.bol-num-box {
    text-align: right;
    font-size: 7.5pt;
    color: #444;
    line-height:1.6;
    margin-top: 4pt;
}
.bol-num-box strong { font-size:9pt; color:#000; }

/* ── generic section row ── */
.sec { border-bottom:1px solid #000; }
table.sec-tbl { width:100%; border-collapse:collapse; }
table.sec-tbl td {
    border-right: 1px solid #000;
    padding: 4px 7px 3px 7px;
    vertical-align:top;
}
table.sec-tbl td:last-child { border-right:none; }
.field-label {
    font-size: 7pt;
    font-weight: bold;
    text-transform: uppercase;
    color: #444;
    display: block;
    margin-bottom: 2px;
    letter-spacing: 0.3px;
}
.field-value {
    font-size: 9.5pt;
    font-weight: bold;
    min-height: 16px;
    display: block;
}
.field-value.lg { font-size:10.5pt; }
/* big multiline fields (SHIP TO, PICK UP) */
.field-tall { min-height: 50px; }

/* ── phyto checkboxes ── */
.chk-row { display:inline-block; }
.chk-box {
    display:inline-block;
    width:12px; height:12px;
    border:1.5px solid #000;
    vertical-align:middle;
    margin-right:2px;
    text-align:center;
    line-height:10px;
    font-size:9pt;
    font-weight:bold;
}
.chk-box.checked { background:#000; color:#fff; }

/* ── product table ── */
table.prod {
    width:100%;
    border-collapse:collapse;
}
table.prod thead { display:table-header-group; }
/* ── last-section: totals + carrier + signatures stay together on last page ── */
.bol-last-section {
    page-break-inside: avoid;
    page-break-before: auto;
}
.tot-summary {
    width: 100%;
    border-collapse: collapse;
    border-top: 2px solid #000;
    margin-top: 0;
}
.tot-summary td {
    background: transparent;
    font-weight: bold;
    font-size: 9.5pt;
    padding: 5px 6px;
    border-right: 1px solid #ccc;
    border-top: 1px solid #ccc;
    border-bottom: 1px solid #ccc;
}
.tot-summary td:last-child { border-right: none; }
.tot-summary td.tc { text-align: center; }
table.prod th {
    background: #1a1a1a;
    color: #fff;
    font-size: 8pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 5px 6px;
    text-align: center;
    border-right: 1px solid #555;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
table.prod th:last-child { border-right:none; }
table.prod td {
    border-bottom: 1px solid #ccc;
    border-right:  1px solid #ccc;
    padding: 5px 6px;
    font-size: 9pt;
    min-height: 20px;
}
table.prod td:last-child { border-right:none; }
table.prod td.tc { text-align:center; }
/* tot-row CSS moved to .tot-summary table */

/* ── carrier row ── */
.carrier-sec {
    border-top:2px solid #000;
    border-bottom:1px solid #000;
}

/* ── signature row ── */
.sig-sec { border-top:1px solid #000; }
table.sig-tbl { width:100%; border-collapse:collapse; }
table.sig-tbl td {
    padding: 6px 10px 8px 10px;
    border-right:1px solid #000;
    width:50%;
    vertical-align:bottom;
}
table.sig-tbl td:last-child { border-right:none; }
.sig-line {
    border-top: 1px solid #000;
    margin-top: 28px;
    padding-top: 3px;
    font-size: 7.5pt;
    color: #333;
}
.sig-name-line {
    border-top: 1px solid #000;
    margin-top: 8px;
    padding-top: 3px;
    font-size: 7.5pt;
    color: #333;
}

/* ── footer strip removed per user request ── */
/* .bol-footer was: background:#000; color:#fff; text-align:center; padding:6px 10px; */

/* ── print toolbar (hidden when printing) ── */
@media print { #bol-toolbar { display:none!important; } body { padding-top:0!important; } }
</style>
</head>
<body>

<div class="bol-wrap">

  <!-- ══ HEADER ══ -->
  <div class="hdr-band">
    <table class="hdr-tbl"><tr>
      <td style="width:28%;vertical-align:middle;padding:0 6px 0 0;">
        <div class="company-name-hdr">SM Produce LTD.</div>
        <div class="company-addr">577 Road 2, Oliver B.C<br>PO BOX 1954</div>
      </td>
      <td style="width:44%;text-align:center;vertical-align:middle;">
        {$logoHtml}
        <div class="doc-title">BILL OF LADING</div>
      </td>
      <td style="width:28%;vertical-align:top;padding:0 0 0 6px;">
        <div class="contact-info">
          Email: mannproduce@hotmail.com<br>
          Phone: +1 250-485-8205
        </div>
        <div class="bol-num-box">
          <span class="field-label">BOL #</span>
          <strong>{$bolNum}</strong><br>
          <span style="font-size:7pt;color:#555">Ship ID: {$sid}</span><br>
          <span style="font-size:7pt;color:#555">Generated: {$genDate}</span>
        </div>
      </td>
    </tr></table>
  </div>

  <!-- ══ ROW 1: DATE / PICK UP / SHIP TO ══ -->
  <div class="sec">
    <table class="sec-tbl"><tr>
      <td style="width:15%">
        <span class="field-label">Date</span>
        <span class="field-value">{$shipDate}</span>
      </td>
      <td style="width:40%">
        <span class="field-label">Pick Up Location (Shipper)</span>
        <span class="field-value field-tall">{$pickLocation}</span>
      </td>
      <td style="width:45%">
        <span class="field-label">Ship To (Destination)</span>
        <span class="field-value field-tall">{$shipToHtml}</span>
      </td>
    </tr></table>
  </div>

  <!-- ══ ROW 2: REF / CUST PO / PHYTO ══ -->
  <div class="sec">
    <table class="sec-tbl"><tr>
      <td style="width:20%">
        <span class="field-label">Ref #</span>
        <span class="field-value">{$sid}</span>
      </td>
      <td style="width:30%">
        <span class="field-label">Cust PO #</span>
        <span class="field-value lg">{$po}</span>
      </td>
      <td style="width:20%">
        <span class="field-label">Phyto</span>
        <span class="field-value">
          <span class="chk-box">{$phytoY}</span> Y &nbsp;&nbsp;
          <span class="chk-box">{$phytoN}</span> N
        </span>
      </td>
      <td style="width:30%">
        <span class="field-label">Customer / Bill To</span>
        <span class="field-value">{$customer}</span>
      </td>
    </tr></table>
  </div>

  <!-- ══ ROW 3: LABEL / DEST CITY / AWB / NOTIFY / CONSIGNEE ══ -->
  <div class="sec">
    <table class="sec-tbl"><tr>
      <td style="width:15%">
        <span class="field-label">Label</span>
        <span class="field-value">{$bolLabel}</span>
      </td>
      <td style="width:20%">
        <span class="field-label">Dest City</span>
        <span class="field-value">{$destCity}</span>
      </td>
      <td style="width:15%">
        <span class="field-label">AWB #</span>
        <span class="field-value">{$bolAwb}</span>
      </td>
      <td style="width:20%">
        <span class="field-label">Notify</span>
        <span class="field-value">{$bolNotify}</span>
      </td>
      <td style="width:30%">
        <span class="field-label">Consignee</span>
        <span class="field-value">{$bolConsignee}</span>
      </td>
    </tr></table>
  </div>

  <!-- ══ PRODUCT TABLE (rows only, no totals row here) ══ -->
  <table class="prod">
    <thead><tr>
      <th style="width:8%">CTNS</th>
      <th style="width:8%">PLTS</th>
      <th style="width:46%">PRODUCT DESCRIPTION</th>
      <th style="width:18%">PACK</th>
      <th style="width:20%">WEIGHT</th>
    </tr></thead>
    <tbody>
{$prodRows}
    </tbody>
  </table>

  <!-- ══ LAST SECTION: totals + carrier + signatures — kept together ══ -->
  <div class="bol-last-section">

    <!-- Totals summary row -->
    <table class="tot-summary">
      <tr>
        <td class="tc" style="width:8%">{$totalCases}</td>
        <td class="tc" style="width:8%">{$totalPallets}</td>
        <td style="width:46%;font-size:8.5pt;color:#444;"><strong>TOTALS</strong></td>
        <td class="tc" style="width:18%">&nbsp;</td>
        <td class="tc" style="width:20%">{$totalWeightDisp}</td>
      </tr>
    </table>

    <!-- Carrier row -->
    <div style="border-top:1px solid #aaa;padding:3px 0 2px 0;margin-top:2px;">
      <table style="width:100%;border-collapse:collapse;"><tr>
        <td style="width:28%;padding:2px 6px;">
          <span class="field-label">Carrier</span>
          <span style="display:block;border-bottom:1px solid #555;min-height:14px;font-size:9pt;">{$carrier}</span>
        </td>
        <td style="width:18%;padding:2px 6px;">
          <span class="field-label">Keep Temp</span>
          <span style="display:block;border-bottom:1px solid #555;min-height:14px;">{$bolKeepTemp}</span>
        </td>
        <td style="width:22%;padding:2px 6px;">
          <span class="field-label">Recorder #</span>
          <span style="display:block;border-bottom:1px solid #555;min-height:14px;">{$bolRecorder}</span>
        </td>
        <td style="width:16%;padding:2px 6px;">
          <span class="field-label">No. Pallets</span>
          <span style="display:block;border-bottom:1px solid #555;min-height:14px;font-size:9pt;">{$totalPallets}</span>
        </td>
        <td style="width:16%;padding:2px 6px;">
          <span class="field-label">Total Weight</span>
          <span style="display:block;border-bottom:1px solid #555;min-height:14px;font-size:9pt;">{$totalWeightDisp}</span>
        </td>
      </tr></table>
    </div>

  <!-- ══ NOTES (if any) ══ -->

HTML;

    if ($notes) {
        $html .= '  <div class="sec"><table class="sec-tbl"><tr><td>'
               . '<span class="field-label">Notes</span>'
               . '<span class="field-value" style="font-size:8.5pt">'.$notes.'</span>'
               . '</td></tr></table></div>' . "\n";
    }

    $html .= <<<SIG

  <!-- ══ SIGNATURES ══ -->
  <div style="border-top:1px solid #000;margin-top:6px;padding-top:4px;">
    <table style="width:100%;border-collapse:collapse;"><tr>
      <td style="width:50%;padding:2px 8px 2px 0;">
        <span style="display:block;font-size:7.5pt;color:#555;margin-bottom:22px;">DRIVER SIGNATURE</span>
        <span style="display:block;border-bottom:1px solid #333;"></span>
        <span style="display:block;font-size:7.5pt;color:#555;margin-top:14px;margin-bottom:10px;">PRINT NAME</span>
        <span style="display:block;border-bottom:1px solid #333;"></span>
      </td>
      <td style="width:50%;padding:2px 0 2px 8px;border-left:1px solid #ccc;">
        <span style="display:block;font-size:7.5pt;color:#555;margin-bottom:22px;">RECEIVED BY</span>
        <span style="display:block;border-bottom:1px solid #333;"></span>
        <span style="display:block;font-size:7.5pt;color:#555;margin-top:14px;margin-bottom:10px;">PRINT NAME</span>
        <span style="display:block;border-bottom:1px solid #333;"></span>
      </td>
    </tr></table>
  </div>

  <!-- footer strip removed -->

</div><!-- /bol-wrap -->
</body></html>
SIG;

    return $html;
}


// ── Dispatcher ────────────────────────────────────────────────────────────────
try {

    // ── open ──────────────────────────────────────────────────────────────────
    if ($action === 'open') {
        $shipIdIn = trim((string)($_POST['shipment_id'] ?? ''));
        $sid = smp_tc26_open_shipment($dbx, $uid, $shipIdIn);
        $st  = smp_tc26_shipment_status($dbx, $sid);
        $st['pallet_rows'] = pallet_rows_full($dbx, $sid);
        echo json_encode($st);
        exit;
    }

    // ── status ────────────────────────────────────────────────────────────────
    if ($action === 'status') {
        $sid = (string)($_POST['shipment_id'] ?? '');
        $st  = smp_tc26_shipment_status($dbx, $sid);
        if (!empty($st['ok'])) {
            $st['pallet_rows'] = pallet_rows_full($dbx, $sid);
            $det = shipment_detail($dbx, $sid);
            if ($det) $st = array_merge($st, $det);
        }
        echo json_encode($st);
        exit;
    }

    // ── scan (add pallet to shipment) ─────────────────────────────────────────
    if ($action === 'scan') {
        $sid = (string)($_POST['shipment_id'] ?? '');
        $pid = (string)($_POST['pallet_id']   ?? '');
        $st  = smp_tc26_add_pallet_to_shipment($dbx, $sid, $pid, $uid);
        if (!empty($st['ok'])) $st['pallet_rows'] = pallet_rows_full($dbx, $sid);
        echo json_encode($st);
        exit;
    }

    // ── closed_pallets ────────────────────────────────────────────────────────
    if ($action === 'closed_pallets') {
        $sid = (string)($_POST['shipment_id'] ?? '');
        try {
            // Pallets that are CLOSED and NOT already in this shipment
            $rows = smp_db_fetch_all($dbx,
                "SELECT p.pallet_id,
                        DATE_FORMAT(p.closed_at,'%Y-%m-%d %H:%i') AS closed_at,
                        DATE_FORMAT(p.created_at,'%Y-%m-%d %H:%i') AS created_at,
                        (SELECT COUNT(*) FROM pallet_cases pc WHERE pc.pallet_id=p.pallet_id) AS case_count
                 FROM pallets p
                 WHERE p.status = 'CLOSED'
                   AND p.pallet_id NOT IN (
                       SELECT pallet_id FROM shipment_pallets WHERE shipment_id=?
                   )
                 ORDER BY p.closed_at DESC, p.created_at DESC
                 LIMIT 200",
                [$sid]
            );
            echo json_encode(['ok'=>1,'pallets'=>$rows?:[]]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>0,'err'=>$e->getMessage()]);
        }
        exit;
    }

    // ── compare ───────────────────────────────────────────────────────────────
    if ($action === 'compare') {
        $sid     = (string)($_POST['shipment_id'] ?? '');
        $orderId = (int)($_POST['order_id']        ?? 0);
        if (!$orderId) {
            // try to read from shipment record
            $det = shipment_detail($dbx, $sid);
            $orderId = (int)($det['order_id'] ?? 0);
        }
        if (!$sid || !$orderId) {
            echo json_encode(['ok'=>0,'err'=>'Missing shipment_id or order_id']);
            exit;
        }
        echo json_encode(compare_po_shipment($dbx, $sid, $orderId));
        exit;
    }

    // ── bol_review_data: compact JSON dataset for TC26/mobile review ───────────
    if ($action === 'bol_review_data') {
        $sid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['shipment_id'] ?? ''));
        if ($sid === '') { echo json_encode(['ok'=>0,'err'=>'Missing shipment_id']); exit; }

        $ship = smp_db_fetch_one($dbx, 'SELECT * FROM shipments WHERE shipment_id=?', [$sid]);
        if (!$ship) { echo json_encode(['ok'=>0,'err'=>'Shipment not found']); exit; }

        // Order fields are the authoritative default for Dest City / Consignee.
        $orderExtra = [];
        try {
            require_once __DIR__ . '/../../config/orders_sql_lib.php';
            if (orders_sql_ready()) {
                orders_sql_init();
                $po = trim((string)($ship['po'] ?? ''));
                if ($po !== '') $orderExtra = orders_fetch_one_sql_by_po($po) ?? [];
            }
        } catch (Throwable $_e) {}

        $pickLocation = (string)($orderExtra['pick_location'] ?? '');
        $shipTo       = (string)($orderExtra['ship_to_address'] ?? '');
        $destCity     = trim((string)($orderExtra['dest_city'] ?? $ship['dest_city'] ?? $ship['destination'] ?? ''));
        $consignee    = trim((string)($ship['bol_consignee'] ?? ''));
        if ($consignee === '') $consignee = $destCity;

        $pallets = [];
        try {
            $pallets = smp_db_fetch_all($dbx,
                'SELECT sp.pallet_id, COUNT(pc.id) AS case_count
                 FROM shipment_pallets sp
                 LEFT JOIN pallet_cases pc ON pc.pallet_id=sp.pallet_id
                 WHERE sp.shipment_id=?
                 GROUP BY sp.pallet_id
                 ORDER BY sp.id ASC', [$sid]) ?? [];
        } catch (Throwable $_e) {}

        $po = trim((string)($ship['po'] ?? ''));
        $varieties = fetchBolVarieties($dbx, $sid, $po);

        // Prefer product rows already edited/saved from either desktop or TC26 review.
        $savedRows = @json_decode((string)($ship['bol_product_rows'] ?? ''), true);
        $productRows = [];
        if (is_array($savedRows) && $savedRows) {
            foreach ($savedRows as $r) {
                $productRows[] = [
                    'ctns'=>(int)($r['ctns'] ?? 0),
                    'plts'=>(int)($r['plts'] ?? 0),
                    'desc'=>(string)($r['desc'] ?? ''),
                    'pack'=>(string)($r['pack'] ?? ''),
                    'weight'=>(string)($r['weight'] ?? ''),
                ];
            }
        } else {
            foreach ($varieties as $v) {
                $desc = trim((string)($v['product_description'] ?? ''));
                if ($desc === '') {
                    $descParts = [trim((string)($v['variety'] ?? ''))];
                    $grower = trim((string)($v['grower'] ?? ''));
                    if ($grower !== '') $descParts[] = 'Grower: '.$grower;
                    $desc = implode(' | ', array_filter($descParts));
                }
                $productRows[] = [
                    'ctns'=>(int)($v['cases'] ?? 0),
                    'plts'=>(int)($v['pallets'] ?? 0),
                    'desc'=>$desc,
                    'pack'=>(string)($v['pack_preset'] ?? 'Unknown'),
                    'weight'=>(float)($v['row_weight'] ?? 0) > 0
                        ? number_format((float)$v['row_weight'], 2, '.', '')
                        : '',
                ];
            }
        }

        $bolNum = trim((string)($ship['bol_number'] ?? ''));
        if ($bolNum === '') {
            $bolNum = 'BOL-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/i','',$sid),0,8))
                    . '-' . date('ymd');
            try {
                smp_db_exec($dbx,
                    "UPDATE shipments SET bol_number=?
                     WHERE shipment_id=? AND (bol_number IS NULL OR bol_number='')",
                    [$bolNum,$sid]);
            } catch (Throwable $_e) {}
        }

        echo json_encode([
            'ok'=>1,
            'shipment'=>[
                'shipment_id'=>$sid,
                'bol_number'=>$bolNum,
                'ship_date'=>(string)($ship['ship_date'] ?? date('Y-m-d')),
                'po'=>(string)($ship['po'] ?? ''),
                'customer_name'=>(string)($ship['customer_name'] ?? ''),
                'carrier'=>(string)($ship['carrier'] ?? ''),
                'dest_city'=>$destCity,
                'pick_location'=>$pickLocation,
                'ship_to_address'=>$shipTo,
                'bol_label'=>(string)($ship['bol_label'] ?? ''),
                'bol_awb'=>(string)($ship['bol_awb'] ?? ''),
                'bol_notify'=>(string)($ship['bol_notify'] ?? ''),
                'bol_consignee'=>$consignee,
                'bol_keep_temp'=>(string)($ship['bol_keep_temp'] ?? ''),
                'bol_recorder'=>(string)($ship['bol_recorder'] ?? ''),
                'bol_phyto'=>strtoupper(trim((string)($ship['bol_phyto'] ?? ''))),
            ],
            'products'=>$productRows,
            'pallets'=>$pallets,
            'totals'=>[
                'cases'=>(int)array_sum(array_map(fn($r)=>(int)($r['ctns']??0),$productRows)),
                'pallets'=>(int)array_sum(array_map(fn($r)=>(int)($r['plts']??0),$productRows)),
                'weight'=>round(array_sum(array_map(function($r){
                    return (float)preg_replace('/[^0-9.]/','',(string)($r['weight']??''));
                },$productRows)),2),
            ]
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── save_order_info ───────────────────────────────────────────────────────
    // ── save_bol_view: salva carrier + prodotti dal viewer interattivo ─────────
    if ($action === 'save_bol_view') {
        $sid      = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['shipment_id'] ?? ''));
        $carrier=trim((string)($_POST['carrier']??''));
        $shipDate=trim((string)($_POST['ship_date']??''));
        $destCity=trim((string)($_POST['dest_city']??''));
        $pickLoc=trim((string)($_POST['pick_location']??''));
        $shipTo=trim((string)($_POST['ship_to_address']??''));
        $customer=trim((string)($_POST['customer_name']??''));
        $bolLabel=trim((string)($_POST['bol_label']??''));
        $bolAwb=trim((string)($_POST['bol_awb']??''));
        $bolNotify=trim((string)($_POST['bol_notify']??''));
        $bolCons=trim((string)($_POST['bol_consignee']??''));
        $bolTemp=trim((string)($_POST['bol_keep_temp']??''));
        $bolRec=trim((string)($_POST['bol_recorder']??''));
        $bolPhyto=strtoupper(trim((string)($_POST['bol_phyto']??'')));
        if(!in_array($bolPhyto,['Y','N'],true))$bolPhyto='';
        $prodJson=trim((string)($_POST['prod_rows']??'[]'));
        if ($sid === '') { echo json_encode(['ok'=>0,'err'=>'Missing sid']); exit; }
        // Save scalar fields to shipments table
        try {
            smp_db_exec($dbx,
                "UPDATE shipments SET carrier=?,ship_date=?,destination=?,dest_city=?,customer_name=?,bol_label=?,bol_awb=?,bol_notify=?,bol_consignee=?,bol_keep_temp=?,bol_recorder=?,bol_phyto=?,bol_product_rows=? WHERE shipment_id=?",
                [$carrier,$shipDate!==''?$shipDate:null,$destCity,$destCity,$customer,$bolLabel,$bolAwb,$bolNotify,$bolCons,$bolTemp,$bolRec,$bolPhyto,$prodJson,$sid]);
        } catch (Throwable $_e) {}
        // Save pick_location + ship_to_address to order via orders_sql_lib
        if ($pickLoc !== '' || $shipTo !== '') {
            try {
                require_once __DIR__ . '/../../config/orders_sql_lib.php';
                if (orders_sql_ready()) {
                    orders_sql_init();
                    $sRow = smp_db_fetch_one($dbx, 'SELECT po FROM shipments WHERE shipment_id=?', [$sid]);
                    $po   = trim((string)($sRow['po'] ?? ''));
                    if ($po !== '') {
                        if ($pickLoc !== '') {
                            smp_db_exec(orders_db(), 'UPDATE orders SET pick_location=? WHERE po=?', [$pickLoc, $po]);
                        }
                        if ($shipTo !== '') {
                            smp_db_exec(orders_db(), 'UPDATE orders SET ship_to_address=? WHERE po=?', [$shipTo, $po]);
                        }
                    }
                }
            } catch (Throwable $_e) {}
        }
        // Save product rows (update pallet_cases variety / packaging via sku)
        // For now: save weights back to order_lines if pack_preset / weight_lbs changed
        $prodRows = @json_decode($prodJson, true) ?: [];
        if ($prodRows) {
            try {
                require_once __DIR__ . '/../../config/orders_sql_lib.php';
                if (orders_sql_ready()) {
                    orders_sql_init();
                    $sRow = smp_db_fetch_one($dbx, 'SELECT po FROM shipments WHERE shipment_id=?', [$sid]);
                    $po   = trim((string)($sRow['po'] ?? ''));
                    if ($po !== '') {
                        foreach ($prodRows as $row) {
                            $pack   = trim((string)($row['pack']   ?? ''));
                            $wRaw   = preg_replace('/[^0-9.]/', '', (string)($row['weight'] ?? ''));
                            $weight = $wRaw !== '' ? (float)$wRaw : null;
                            $desc   = trim((string)($row['desc'] ?? ''));
                            if ($pack === '' || $weight === null) continue;
                            // Match by variety name (first part of desc before ·)
                            $variety = trim(explode('·', explode('|', $desc)[0])[0]);
                            if ($variety === '') continue;
                            try {
                                smp_db_exec(orders_db(),
                                    'UPDATE order_lines SET packaging_preset=?, weight_lbs=?
                                     WHERE po=? AND variety=?',
                                    [$pack, $weight, $po, $variety]);
                            } catch (Throwable $_e2) {}
                        }
                    }
                }
            } catch (Throwable $_e) {}
        }
        echo json_encode(['ok'=>1]);
        exit;
    }

    if ($action === 'save_order_info') {
        $sid      = trim((string)($_POST['shipment_id']   ?? ''));
        $po       = trim((string)($_POST['po']            ?? ''));
        $custName = trim((string)($_POST['customer_name'] ?? ''));
        $orderId  = (int)($_POST['order_id']              ?? 0);
        $shipDate = trim((string)($_POST['ship_date']     ?? ''));
        if ($sid === '') { echo json_encode(['ok'=>0,'err'=>'Missing shipment_id']); exit; }
        try {
            smp_db_exec($dbx,
                "UPDATE shipments SET po=?, customer_name=?, order_id=?, ship_date=?
                 WHERE shipment_id=?",
                [$po, $custName, $orderId ?: null, ($shipDate !== '' ? $shipDate : null), $sid]
            );
        } catch (Throwable $e) {
            try {
                smp_db_exec($dbx,
                    "UPDATE shipments SET po=?, customer_name=?, order_id=? WHERE shipment_id=?",
                    [$po, $custName, $orderId ?: null, $sid]
                );
            } catch (Throwable $e2) {}
        }
        echo json_encode(['ok'=>1,'msg'=>'Saved']);
        exit;
    }

    // ── close ─────────────────────────────────────────────────────────────────
    if ($action === 'close') {
        $sid       = (string)($_POST['shipment_id'] ?? '');
        $orderId   = (int)($_POST['order_id']       ?? 0);
        $printerId = (int)($_POST['printer_id']     ?? 0);
        if ($printerId === 0) {
            $rule = get_ship_rule($dbx);
            $printerId = (int)($rule['printer_id'] ?? 0);
        }

        // Close the shipment
        $st = smp_tc26_close_shipment($dbx, $sid, $uid, $printerId);
        if (!empty($st['ok'])) {
            $st['pallet_rows'] = pallet_rows_full($dbx, $sid);

            // Retrieve order_id from shipment if not passed
            if (!$orderId) {
                $det     = shipment_detail($dbx, $sid);
                $orderId = (int)($det['order_id'] ?? 0);
            }

            // Close the associated PO → SHIPPED
            $st['order_closed'] = false;
            if ($orderId > 0) {
                try {
                    smp_db_exec($dbx,
                        "UPDATE orders SET status='SHIPPED' WHERE id=?", [$orderId]);
                    $st['order_closed'] = true;
                } catch (Throwable $e) {}
            }

            // Run comparison
            if ($orderId > 0) {
                $cmp = compare_po_shipment($dbx, $sid, $orderId);
                $st['compare'] = $cmp;
            }
        }
        echo json_encode($st);
        exit;
    }

    // ── delete_shipment ───────────────────────────────────────────────────────
    if ($action === 'delete_shipment') {
        $sid = (string)($_POST['shipment_id'] ?? '');
        if ($sid === '') { echo json_encode(['ok'=>0,'err'=>'Missing shipment_id']); exit; }
        try {
            smp_db_exec($dbx, "DELETE FROM shipment_pallets WHERE shipment_id=?", [$sid]);
            smp_db_exec($dbx, "DELETE FROM shipments WHERE shipment_id=?", [$sid]);
            echo json_encode(['ok'=>1,'msg'=>'Deleted']);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>0,'err'=>$e->getMessage()]);
        }
        exit;
    }

    // ── remove_pallet ─────────────────────────────────────────────────────────
    if ($action === 'remove_pallet') {
        $rowId = (int)($_POST['id']            ?? 0);
        $sid   = (string)($_POST['shipment_id'] ?? '');
        $res   = smp_tc26_remove_pallet_from_shipment($dbx, $rowId, $sid);
        if (!empty($res['ok'])) {
            $res['pallet_rows'] = pallet_rows_full($dbx, $sid);
        }
        echo json_encode($res);
        exit;
    }

    // ── po_lookup_all ─────────────────────────────────────────────────────────
    if ($action === 'po_lookup_all') {
        try {
            $rows = smp_db_fetch_all($dbx,
                "SELECT o.id, o.po,
                        COALESCE(c.client_name, o.customer, '') AS customer_name,
                        COALESCE(o.product,'')                  AS product,
                        DATE_FORMAT(o.ship_date,'%Y-%m-%d')     AS ship_date,
                        COALESCE(o.status,'Open')               AS status
                 FROM orders o
                 LEFT JOIN order_clients c ON c.id = o.client_id
                 WHERE UPPER(COALESCE(o.status,'Open')) = 'OPEN'
                 ORDER BY o.ship_date ASC, o.id DESC
                 LIMIT 300",
                []
            );
            echo json_encode(['ok'=>1,'orders'=>$rows?:[]]);
        } catch (Throwable $e) {
            // fallback without join
            try {
                $rows2 = smp_db_fetch_all($dbx,
                    "SELECT id, po, COALESCE(customer,'') AS customer_name,
                            COALESCE(product,'') AS product,
                            DATE_FORMAT(ship_date,'%Y-%m-%d') AS ship_date,
                            COALESCE(status,'Open') AS status
                     FROM orders
                     WHERE UPPER(COALESCE(status,'Open')) = 'OPEN'
                     ORDER BY ship_date ASC, id DESC LIMIT 300", []
                );
                echo json_encode(['ok'=>1,'orders'=>$rows2?:[]]);
            } catch (Throwable $e2) {
                echo json_encode(['ok'=>0,'err'=>$e2->getMessage()]);
            }
        }
        exit;
    }

    // ── po_lookup (legacy / search) ───────────────────────────────────────────
    if ($action === 'po_lookup') {
        $q = trim((string)($_POST['po'] ?? ''));
        if ($q === '' || $q === '%') {
            // redirect to all
            $_POST['action'] = 'po_lookup_all';
            // re-dispatch — just repeat the query
            try {
                $rows = smp_db_fetch_all($dbx,
                    "SELECT o.id, o.po,
                            COALESCE(c.client_name, o.customer,'') AS customer_name,
                            COALESCE(o.product,'') AS product,
                            DATE_FORMAT(o.ship_date,'%Y-%m-%d') AS ship_date,
                            COALESCE(o.status,'Open') AS status
                     FROM orders o
                     LEFT JOIN order_clients c ON c.id = o.client_id
                     ORDER BY o.ship_date DESC, o.id DESC LIMIT 200", []
                );
                echo json_encode(['ok'=>1,'orders'=>$rows?:[]]);
            } catch (Throwable $e) { echo json_encode(['ok'=>1,'orders'=>[]]); }
            exit;
        }
        $like = '%'.$q.'%';
        try {
            $rows = smp_db_fetch_all($dbx,
                "SELECT o.id, o.po,
                        COALESCE(c.client_name, o.customer,'') AS customer_name,
                        COALESCE(o.product,'') AS product,
                        DATE_FORMAT(o.ship_date,'%Y-%m-%d') AS ship_date,
                        COALESCE(o.status,'Open') AS status
                 FROM orders o
                 LEFT JOIN order_clients c ON c.id = o.client_id
                 WHERE o.po LIKE ? OR COALESCE(c.client_name,'') LIKE ? OR COALESCE(o.customer,'') LIKE ?
                 ORDER BY o.ship_date DESC, o.id DESC LIMIT 15",
                [$like, $like, $like]
            );
            echo json_encode(['ok'=>1,'orders'=>$rows?:[]]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>0,'err'=>$e->getMessage()]);
        }
        exit;
    }


    // ── queue_bol_print ─────────────────────────────────────────────────────
    if ($action === 'queue_bol_print') {
        $sid=preg_replace('/[^A-Za-z0-9_\-]/','',(string)($_POST['shipment_id']??''));
        if($sid===''){echo json_encode(['ok'=>0,'err'=>'Missing shipment_id']);exit;}

        $cfg=smp_get_shipment_print_settings($dbx);
        $printer=trim((string)($cfg['bol_printer']??''));
        if($printer===''){
            echo json_encode(['ok'=>1,'queued'=>false,'skipped'=>true,'msg'=>'BOL auto-print disabled']);exit;
        }

        $safeSid=preg_replace('/[^A-Za-z0-9_\-]/','_',$sid);
        $pdfPath=sys_get_temp_dir().'/bol_'.$safeSid.'.pdf';
        if(!is_file($pdfPath)||filesize($pdfPath)<100){
            echo json_encode(['ok'=>0,'err'=>'BOL PDF has not been generated yet']);exit;
        }

        $printDb = ($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn']
            : ((($GLOBALS['mysqli'] ?? null) instanceof mysqli) ? $GLOBALS['mysqli'] : null);
        if($printDb instanceof mysqli) $GLOBALS['mysqli']=$printDb;
        $print=ebr_print_pdf_windows($pdfPath,$printer);

        echo json_encode([
            'ok'=>!empty($print['ok'])?1:0,
            'queued'=>!empty($print['queued']),
            'skipped'=>!empty($print['skipped']),
            'printer'=>$printer,
            'job_id'=>$print['job_id']??null,
            'err'=>$print['error']??null
        ]);
        exit;
    }

    // ── bol_product_debug ─────────────────────────────────────────────────────
    if ($action === 'bol_product_debug') {
        $sid=preg_replace('/[^A-Za-z0-9_\-]/','',(string)($_POST['shipment_id']??''));
        if($sid===''){echo json_encode(['ok'=>0,'err'=>'Missing shipment_id']);exit;}

        $ship=smp_db_fetch_one($dbx,"SELECT shipment_id,po,status FROM shipments WHERE shipment_id=? LIMIT 1",[$sid]);
        $palCount=smp_db_fetch_one($dbx,
            "SELECT COUNT(*) c FROM shipment_pallets WHERE shipment_id=?",[$sid]);
        $caseCount=smp_db_fetch_one($dbx,
            "SELECT COUNT(*) c
             FROM shipment_pallets sp
             JOIN pallet_cases pc ON pc.pallet_id=sp.pallet_id
             WHERE sp.shipment_id=?",[$sid]);
        $sample=smp_db_fetch_all($dbx,
            "SELECT sp.pallet_id,pc.case_serial,pc.grower,pc.sku,pc.variety,pc.packaging,pc.size
             FROM shipment_pallets sp
             JOIN pallet_cases pc ON pc.pallet_id=sp.pallet_id
             WHERE sp.shipment_id=?
             ORDER BY sp.id,pc.id LIMIT 10",[$sid])??[];
        $groups=fetchBolVarieties($dbx,$sid,trim((string)($ship['po']??'')));

        echo json_encode([
            'ok'=>1,
            'shipment'=>$ship,
            'shipment_pallet_count'=>(int)($palCount['c']??0),
            'shipment_case_count'=>(int)($caseCount['c']??0),
            'sample_cases'=>$sample,
            'bol_product_groups'=>$groups,
            'note'=>'PACK comes from order_lines.packaging_preset; WEIGHT comes from order_pack_presets.weight_lbs in the Orders PDO database.'
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── bol (generate PDF) ────────────────────────────────────────────────────
    if ($action === 'bol') {
        $sid = preg_replace('/[^A-Za-z0-9_\\-]/', '', (string)($_POST['shipment_id'] ?? ''));
        if ($sid === '') { echo json_encode(['ok'=>0,'err'=>'Missing shipment_id']); exit; }

        $ship = smp_db_fetch_one($dbx, 'SELECT * FROM shipments WHERE shipment_id=?', [$sid]);
        if (!$ship) { echo json_encode(['ok'=>0,'err'=>'Shipment not found']); exit; }

        // Fetch extra fields from orders table (pick_location, ship_to_address, dest_city)
        $orderExtra = [];
        try {
            require_once __DIR__ . '/../../config/orders_sql_lib.php';
            if (orders_sql_ready()) {
                orders_sql_init();
                $shipPo = trim((string)($ship['po'] ?? ''));
                if ($shipPo !== '') {
                    $orderExtra = orders_fetch_one_sql_by_po($shipPo) ?? [];
                }
            }
        } catch (Throwable $_e) {}
        // Merge extra fields into $ship so buildBolHtml can use them
        if ($orderExtra) {
            $ship['pick_location']   = $orderExtra['pick_location']   ?? '';
            $ship['ship_to_address'] = $orderExtra['ship_to_address'] ?? '';
            $ship['dest_city']       = $orderExtra['dest_city']       ?? $ship['destination'] ?? '';
        }

        // Pallets with case count
        $pallets = [];
        try {
            $pallets = smp_db_fetch_all($dbx,
                'SELECT sp.pallet_id, COUNT(pc.id) AS case_count
                 FROM shipment_pallets sp
                 LEFT JOIN pallet_cases pc ON pc.pallet_id = sp.pallet_id
                 WHERE sp.shipment_id=?
                 GROUP BY sp.pallet_id ORDER BY sp.id ASC', [$sid]) ?? [];
        } catch (Throwable $e) {}

        // Variety / pack_preset / weight breakdown — uses fetchBolVarieties for full PACK+WEIGHT data
        $varieties = [];
        try {
            $bolShipPo = trim((string)($ship['po'] ?? ''));
            $varieties = fetchBolVarieties($dbx, $sid, $bolShipPo);
        } catch (Throwable $e) {}

        // Logo as base64
        $logoPath = __DIR__ . '/../../logo/logo.png';
        $logoB64  = '';
        if (file_exists($logoPath)) {
            $logoB64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }

        // BOL number: use existing or generate
        $bolNum = trim((string)($ship['bol_number'] ?? ''));
        if ($bolNum === '') {
            $bolNum = 'BOL-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $sid), 0, 8))
                    . '-' . date('ymd');
            try {
                smp_db_exec($dbx,
                    "UPDATE shipments SET bol_number=?
                     WHERE shipment_id=? AND (bol_number IS NULL OR bol_number='')",
                    [$bolNum, $sid]);
            } catch (Throwable $e) {}
        }

        $totalCases   = (int)array_sum(array_column($pallets,   'case_count'));
        $totalPallets = count($pallets);
        $totalWeight  = round(array_sum(array_column($varieties, 'row_weight')), 2);

        // Generate HTML → PDF
        require_once __DIR__ . '/../../lib/dompdf/autoload.inc.php';
        $dompdfOpts = new \Dompdf\Options();
        $dompdfOpts->setIsRemoteEnabled(false);
        $dompdfOpts->setIsHtml5ParserEnabled(true);
        $dompdfOpts->setDefaultFont('DejaVu Sans');
        try {
            $dom = new \Dompdf\Dompdf($dompdfOpts);
            $dom->loadHtml(buildBolHtml($ship, $pallets, $varieties, $bolNum, $logoB64,
                                        $totalCases, $totalPallets, $totalWeight));
            $dom->setPaper('letter', 'portrait');
            $dom->render();
        } catch (Throwable $pdfErr) {
            echo json_encode(['ok'=>0,'err'=>'PDF render error: '.$pdfErr->getMessage()
                .' — Check mbstring, dom, xml PHP extensions.']);
            exit;
        }

        $safeSid  = preg_replace('/[^A-Za-z0-9_\\-]/', '_', $sid);
        $outFile  = sys_get_temp_dir() . '/bol_' . $safeSid . '.pdf';
        file_put_contents($outFile, $dom->output());

        $dlUrl = 'api/tc26_shipping_api.php?action=bol_download&sid=' . urlencode($sid);
        echo json_encode(['ok'=>1, 'url'=>$dlUrl, 'bol_number'=>$bolNum,
                          'total_cases'=>$totalCases, 'total_pallets'=>$totalPallets]);
        exit;
    }

    echo json_encode(['ok'=>0,'err'=>'Unknown action: '.$action]);

} catch (Throwable $e) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok'=>0,'err'=>'Server error','detail'=>$e->getMessage()]);
}
