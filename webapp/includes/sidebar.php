<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!function_exists('user_has_section_perm')) { require_once __DIR__ . '/../config/user_functions.php'; }
require_once __DIR__ . '/../config/app.php';

$current = basename($_SERVER['PHP_SELF']);

/* ═══════════════════════════════════════════════════
   SIDEBAR BADGES — count query (lightweight, cached)
   ═══════════════════════════════════════════════════ */
$_sb_badges = ['orders'=>0,'bins_full'=>0,'bins_empty'=>0,
               'shipments'=>0,'labels_today'=>0,'users'=>0];

try {
    // Re-use $conn if already available (most pages include db.php before header)
    $__sb_conn = null;
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $__sb_conn = $conn;
    } else {
        // lazy connect — credentials from db.php
        $__sb_conn = @new mysqli('192.168.1.70','root','root1940','smproduce_prod',3306);
        if ($__sb_conn->connect_error) { $__sb_conn = null; }
        else { $__sb_conn->set_charset('utf8mb4'); }
    }

    if ($__sb_conn) {
        // Open orders
        $r = $__sb_conn->query("SELECT COUNT(*) FROM orders WHERE UPPER(status) NOT IN ('CLOSED','CANCELLED','DONE')");
        if ($r) { $_sb_badges['orders'] = (int)$r->fetch_row()[0]; }

        // Full Bins Inventory = only bins physically AVAILABLE now.
        // Historical / dumped / unavailable Full Bin rows must not inflate the sidebar badge.
        $r = $__sb_conn->query("
            SELECT COUNT(*)
            FROM bins_ingresso
            WHERE UPPER(COALESCE(status,''))='AVAILABLE'
        ");
        if ($r) { $_sb_badges['bins_full'] = (int)$r->fetch_row()[0]; }

        // Empty Bins = actual number of bins in stock, not number of database rows.
        // One empty_bins row can contain quantity > 1.
        $r = $__sb_conn->query("
            SELECT COALESCE(SUM(
                CASE
                    WHEN quantity > 0 THEN quantity
                    ELSE 0
                END
            ),0)
            FROM empty_bins
        ");
        if ($r) { $_sb_badges['bins_empty'] = (int)$r->fetch_row()[0]; }

        // Active shipments (not closed)
        $r = $__sb_conn->query("SELECT COUNT(*) FROM shipments WHERE UPPER(status) NOT IN ('CLOSED','DELIVERED','DONE')");
        if ($r) { $_sb_badges['shipments'] = (int)$r->fetch_row()[0]; }

        // Labels printed today
        $r = $__sb_conn->query("SELECT COUNT(*) FROM labels_history WHERE DATE(created_at)=CURDATE()");
        if ($r) { $_sb_badges['labels_today'] = (int)$r->fetch_row()[0]; }

        // Active users
        $r = $__sb_conn->query("SELECT COUNT(*) FROM users WHERE is_active=1");
        if ($r) { $_sb_badges['users'] = (int)$r->fetch_row()[0]; }
    }
} catch (Throwable $__e) { /* silently skip badges on DB error */ }

// Helper: render badge HTML (returns empty string if count=0)
if (!function_exists('_sb_badge')) {
    function _sb_badge(int $n, string $color='blue'): string {
        if ($n <= 0) return '';
        $label = $n > 999 ? '999+' : (string)$n;
        return '<span class="sb-badge sb-badge--'.$color.'">'.$label.'</span>';
    }
}

$binsFiles = [
    'bins_ingresso.php','empty_bin_receiving.php','dumping_bins.php',
    'bins_dumping.php','dumped_bins.php','bins_produzione.php',
    'print_bin_label.php','print_bin_label_group.php',
];
$labelsFiles = [
    'labels_print.php','label_templates.php','label_printers.php',
    'manual_case_labels.php',
    'label_rules.php','labels_history.php','label_preview.php',
    'print_case_label.php',
    'label_dashboard.php','label_case_designer.php','label_fields.php',
    'label_types.php','labels_settings.php',
    'labels_printers.php','labels_routing.php','zpl_designer.php',
    'zpl_templates.php','zpl_history.php','zpl_preview.php',
    'zpl_preview_custom.php','zpl_test_print.php',
];
$logisticsFiles = [
    'pallets_manage.php','shipments_manage.php',
];

$binsOpen        = in_array($current, $binsFiles,      true);
$labelsOpen      = in_array($current, $labelsFiles,    true);
$logisticsActive = in_array($current, $logisticsFiles, true);
?>
<style>
/* ═══════════════════════════════════════════════════════════════
   SIDEBAR v2  — dark theme, collapsible, aligned to Print Center
   ═══════════════════════════════════════════════════════════════ */

/* ── Layout spacer (flex child in header.php) ── */
#layoutSidenav_nav {
    width: 268px;
    flex-shrink: 0;
    transition: width .25s ease;
}
body.sidebar-collapsed #layoutSidenav_nav { width: 72px; }

/* ── Sidebar panel (position:fixed) ── */
#sidebarMenu {
    width: 268px;
    background: #f0f2f7;
    height: 100vh;
    border-right: 1px solid #dde3ee;
    position: fixed;
    left: 0; top: 0;
    z-index: 1000;
    box-shadow: 2px 0 20px rgba(0,0,0,.07);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: width .25s ease;
}
body.sidebar-collapsed #sidebarMenu { width: 72px; }

/* ── Top area (logo + toggle) ── */
.sb-top {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 14px;
    height: 60px;
    border-bottom: 1px solid #dde3ee;
    flex-shrink: 0;
    background: #f0f2f7;
}

.sb-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    overflow: hidden;
    min-width: 0;
    flex: 1;
    text-decoration: none;
}
.sb-logo img {
    height: 38px;
    width: 38px;
    border-radius: 10px;
    flex-shrink: 0;
    object-fit: contain;
    box-shadow: 0 2px 8px rgba(99,102,241,.25);
}
.sb-logo-text {
    font-size: 15px;
    font-weight: 700;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    letter-spacing: -.2px;
    transition: opacity .2s, width .2s;
}
body.sidebar-collapsed .sb-logo-text {
    opacity: 0;
    width: 0;
    pointer-events: none;
}

/* ── Toggle button ── */
.sb-toggle {
    width: 28px; height: 28px;
    border-radius: 8px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    color: #64748b;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: background .15s, color .15s, transform .25s;
    font-size: 14px;
    line-height: 1;
    padding: 0;
}
.sb-toggle:hover { background: #e2e8f0; color: #334155; }
body.sidebar-collapsed .sb-toggle { transform: rotate(180deg); }

/* ── Scroll area ── */
.sb-scroll {
    flex: 1 1 auto;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 10px 0 20px;
    scroll-behavior: auto;
}
.sb-scroll::-webkit-scrollbar { width: 4px; }
.sb-scroll::-webkit-scrollbar-thumb { background: #c2cad8; border-radius: 4px; }
.sb-scroll::-webkit-scrollbar-track { background: transparent; }

/* ── Section label ── */
.sb-section {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #94a3b8;
    padding: 14px 18px 4px;
    white-space: nowrap;
    overflow: hidden;
    transition: opacity .15s, height .2s;
}
body.sidebar-collapsed .sb-section {
    opacity: 0;
    height: 0;
    padding-top: 0;
    padding-bottom: 0;
    pointer-events: none;
}

/* ── Nav item ── */
.sb-item,
.sb-summary {
    display: flex;
    align-items: center;
    gap: 11px;
    width: calc(100% - 16px);
    margin: 1px 8px;
    padding: 8px 10px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 500;
    color: #475569;
    text-decoration: none;
    cursor: pointer;
    border: 0;
    background: transparent;
    text-align: left;
    box-sizing: border-box;
    white-space: nowrap;
    overflow: hidden;
    /* Hover slide + shadow animation */
    transition: background .18s ease, color .18s ease,
                transform .18s cubic-bezier(.34,1.3,.64,1),
                box-shadow .18s ease;
    position: relative;
    will-change: transform;
}
.sb-item:hover,
.sb-summary:hover {
    background: #ffffff;
    color: #1e293b;
    text-decoration: none;
    transform: translateX(4px);
    box-shadow: 0 2px 10px rgba(0,0,0,.08);
}
.sb-item.active,
.sb-summary.active {
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(37,99,235,.12);
}
.sb-item.sub  { padding-left: 24px; font-size: 13px; }
.sb-item.sub2 { padding-left: 40px; font-size: 12.5px; }

/* ── Icon badge (colored pill) — bounce + shadow on hover ── */
.sb-icon {
    width: 30px; min-width: 30px; height: 30px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-size: 17px;
    line-height: 1;
    transition: transform .22s cubic-bezier(.34,1.56,.64,1),
                box-shadow .22s ease,
                filter .22s ease;
    will-change: transform;
}
.sb-item:hover .sb-icon,
.sb-summary:hover .sb-icon {
    transform: scale(1.18) rotate(-4deg);
    box-shadow: 0 6px 16px rgba(0,0,0,.22);
    filter: brightness(1.1) saturate(1.2);
}
.sb-item.active .sb-icon,
.sb-summary.active .sb-icon {
    transform: scale(1.08);
    box-shadow: 0 4px 12px rgba(37,99,235,.30);
}
.sb-item.sub  .sb-icon,
.sb-item.sub2 .sb-icon {
    width: 26px; min-width: 26px; height: 26px;
    border-radius: 7px;
    font-size: 14px;
}

/* ── Per-section gradient colors ── */
.ic-blue    { background: linear-gradient(135deg,#3b82f6,#6366f1); }
.ic-indigo  { background: linear-gradient(135deg,#6366f1,#818cf8); }
.ic-violet  { background: linear-gradient(135deg,#8b5cf6,#a78bfa); }
.ic-purple  { background: linear-gradient(135deg,#7c3aed,#9333ea); }
.ic-emerald { background: linear-gradient(135deg,#059669,#10b981); }
.ic-green   { background: linear-gradient(135deg,#16a34a,#22c55e); }
.ic-teal-g  { background: linear-gradient(135deg,#0d9488,#14b8a6); }
.ic-red     { background: linear-gradient(135deg,#dc2626,#ef4444); }
.ic-amber   { background: linear-gradient(135deg,#d97706,#f59e0b); }
.ic-cyan    { background: linear-gradient(135deg,#0891b2,#06b6d4); }
.ic-sky     { background: linear-gradient(135deg,#0284c7,#38bdf8); }
.ic-blue2   { background: linear-gradient(135deg,#1d4ed8,#3b82f6); }
.ic-slate   { background: linear-gradient(135deg,#475569,#64748b); }
.ic-fuchsia { background: linear-gradient(135deg,#a21caf,#d946ef); }
.ic-pink    { background: linear-gradient(135deg,#db2777,#ec4899); }
.ic-orange  { background: linear-gradient(135deg,#ea580c,#f97316); }
.ic-indigo2 { background: linear-gradient(135deg,#4338ca,#6366f1); }
.ic-teal    { background: linear-gradient(135deg,#0f766e,#14b8a6); }
.ic-teal2   { background: linear-gradient(135deg,#0891b2,#2dd4bf); }
.ic-teal3   { background: linear-gradient(135deg,#047857,#10b981); }
.ic-teal4   { background: linear-gradient(135deg,#1d4ed8,#0ea5e9); }
.ic-teal5   { background: linear-gradient(135deg,#7c3aed,#06b6d4); }
.ic-gray    { background: linear-gradient(135deg,#374151,#6b7280); }
.ic-rose    { background: linear-gradient(135deg,#be123c,#f43f5e); }

/* ── Label (text) ── */
.sb-lbl {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: opacity .15s, width .15s;
}
body.sidebar-collapsed .sb-lbl,
body.sidebar-collapsed .sb-arrow { opacity: 0; width: 0; pointer-events: none; }

/* ── Arrow ── */
.sb-arrow {
    margin-left: auto;
    font-size: 11px;
    color: #94a3b8;
    flex-shrink: 0;
    transition: transform .2s, opacity .15s;
}
details.sb-group[open] > summary .sb-arrow { transform: rotate(90deg); }

/* ── Tooltip in collapsed mode ── */
body.sidebar-collapsed .sb-item,
body.sidebar-collapsed .sb-summary { position: relative; }
body.sidebar-collapsed .sb-item::after,
body.sidebar-collapsed .sb-summary::after {
    content: attr(data-tip);
    position: absolute;
    left: calc(100% + 10px);
    top: 50%;
    transform: translateY(-50%);
    background: #1e293b;
    color: #f1f5f9;
    font-size: 12px;
    font-weight: 500;
    padding: 5px 10px;
    border-radius: 8px;
    border: 1px solid #334155;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    z-index: 9999;
    box-shadow: 0 4px 12px rgba(0,0,0,.25);
    transition: opacity .15s;
}
body.sidebar-collapsed .sb-item:hover::after,
body.sidebar-collapsed .sb-summary:hover::after { opacity: 1; }

/* ── Groups ── */
.sb-group { margin: 0; padding: 0; }
.sb-group summary { list-style: none; cursor: pointer; user-select: none; }
.sb-group summary::-webkit-details-marker { display: none; }
.sb-submenu { margin-top: 2px; }

/* ── Divider ── */
.sb-divider {
    height: 1px;
    background: #dde3ee;
    margin: 6px 14px;
}

/* ── Footer ── */
.sb-footer {
    flex: 0 0 auto;
    padding: 8px;
    border-top: 1px solid #dde3ee;
    background: #f0f2f7;
}

/* ═══ PRINT ═══ */
@media print {
    #sidebarMenu,
    #layoutSidenav_nav { display: none !important; }
}

/* ── Emoji icons rendered inside colored badge ── */

/* ═══ Numeric badge (notification pill) ═══ */
.sb-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    font-size: 10px;
    font-weight: 700;
    line-height: 1;
    margin-left: auto;
    flex-shrink: 0;
    letter-spacing: 0;
    /* pulse animation on load */
    animation: sb-badge-pop .3s cubic-bezier(.34,1.56,.64,1) both;
}
@keyframes sb-badge-pop {
    from { transform: scale(0); opacity: 0; }
    to   { transform: scale(1); opacity: 1; }
}
/* color variants */
.sb-badge--blue    { background: #3b82f6; color: #fff; }
.sb-badge--indigo  { background: #6366f1; color: #fff; }
.sb-badge--emerald { background: #10b981; color: #fff; }
.sb-badge--amber   { background: #f59e0b; color: #fff; }
.sb-badge--cyan    { background: #06b6d4; color: #fff; }
.sb-badge--teal    { background: #14b8a6; color: #fff; }
.sb-badge--orange  { background: #f97316; color: #fff; }
.sb-badge--rose    { background: #f43f5e; color: #fff; }
.sb-badge--slate   { background: #64748b; color: #fff; }
/* Active item: invert badge colors for contrast */
.sb-item.active .sb-badge,
.sb-summary.active .sb-badge { background: #1d4ed8; }
/* Collapsed: hide badge text, show dot */
body.sidebar-collapsed .sb-badge {
    position: absolute;
    top: 4px; right: 4px;
    min-width: 8px; height: 8px;
    padding: 0;
    font-size: 0;
    border-radius: 50%;
}
</style>

<nav id="sidebarMenu">

    <!-- TOP: logo + toggle -->
    <div class="sb-top">
        <a href="/chooser.php" class="sb-logo">
            <img src="/logo/logo.png" alt="SM Produce">
            <span class="sb-logo-text">SM Produce</span>
        </a>
        <button class="sb-toggle" id="sbToggleBtn" title="Toggle sidebar" onclick="sbToggle()">‹</button>
    </div>

    <!-- SCROLL AREA -->
    <div class="sb-scroll" id="sidebarScrollArea">


<?php if (user_has_section_perm('main')): ?>
        <!-- ── Main ── -->
        <div class="sb-section">Main</div>
        <details class="sb-group" data-sidebar-key="main">
            <summary class="sb-summary <?= in_array($current,['dashboard_report.php','production_summary.php','production_board.php'],true)?'active':'' ?>" data-tip="Main">
                <span class="sb-icon ic-blue">🏠</span>
                <span class="sb-lbl">Main</span>
                <span class="sb-arrow">›</span>
            </summary>
            <div class="sb-submenu">
                <a href="/pages/dashboard_report.php" class="sb-item sub <?= $current==='dashboard_report.php'?'active':'' ?>" data-tip="Report">
                    <span class="sb-icon ic-indigo">📈</span><span class="sb-lbl">Report</span>
                </a>

                <?php if (user_has_section_perm('production')): ?>
                <a href="/pages/production_summary.php" class="sb-item sub <?= $current==='production_summary.php'?'active':'' ?>" data-tip="Production Summary">
                    <span class="sb-icon ic-amber">🏭</span><span class="sb-lbl">Production Summary</span>
                </a>
                <a href="/pages/production_board.php" class="sb-item sub <?= $current==='production_board.php'?'active':'' ?>" data-tip="Production Live Board" target="_blank">
                    <span class="sb-icon ic-green">📺</span><span class="sb-lbl">Production Live Board</span>
                </a>
                <?php endif; ?>
            </div>
        </details>

        <div class="sb-divider"></div>


<?php endif; // section:main ?>

<?php if (user_has_section_perm('unitec')): ?>
        <!-- ── UNiTEC ── -->
        <div class="sb-section">UNiTEC</div>
        <a href="/pages/case_label_control.php" class="sb-item <?= in_array($current,['case_label_control.php','case_label_settings.php'],true)?'active':'' ?>" data-tip="Exit UNiTEC">
            <span class="sb-icon ic-violet">🔄</span><span class="sb-lbl">Exit UNiTEC</span>
        </a>
        <a href="/pages/exit_sku_config.php" class="sb-item <?= $current==='exit_sku_config.php'?'active':'' ?>" data-tip="Edit SKU">
            <span class="sb-icon ic-purple">✏️</span><span class="sb-lbl">Edit SKU</span>
        </a>

        <div class="sb-divider"></div>


<?php endif; // section:unitec ?>

<?php if (user_has_section_perm('bins')): ?>
        <!-- ── Bins ── -->
        <div class="sb-section">Bins</div>
        <details class="sb-group" data-sidebar-key="bins">
            <summary class="sb-summary <?= $binsOpen?'active':'' ?>" data-tip="Bins Menu">
                <span class="sb-icon ic-emerald">📦</span>
                <span class="sb-lbl">Bins Menu</span>
                <?= _sb_badge($_sb_badges['bins_full'] + $_sb_badges['bins_empty'], 'emerald') ?>
                <span class="sb-arrow">›</span>
            </summary>
            <div class="sb-submenu">
                <a href="/pages/bins_ingresso.php" class="sb-item sub <?= $current==='bins_ingresso.php'?'active':'' ?>" data-tip="Full Bins">
                    <span class="sb-icon ic-green">🗃️</span><span class="sb-lbl">Full Bins Inventory</span><?= _sb_badge($_sb_badges['bins_full'], 'emerald') ?>
                </a>
                <a href="/pages/empty_bin_receiving.php" class="sb-item sub <?= $current==='empty_bin_receiving.php'?'active':'' ?>" data-tip="Empty Bins">
                    <span class="sb-icon ic-teal-g">📭</span><span class="sb-lbl">Empty Bins</span><?= _sb_badge($_sb_badges['bins_empty'], 'teal') ?>
                </a>
                <a href="/pages/dumping_bins.php" class="sb-item sub <?= $current==='dumping_bins.php'?'active':'' ?>" data-tip="Dumping Bins">
                    <span class="sb-icon ic-red">♻️</span><span class="sb-lbl">Dumping Bins</span>
                </a>
            </div>
        </details>

        <div class="sb-divider"></div>


<?php endif; // section:bins ?>


<?php if (user_has_section_perm('orders')): ?>
        <!-- ── Orders ── -->
        <div class="sb-section">Orders</div>
        <a href="/pages/orders.php" class="sb-item <?= $current==='orders.php'?'active':'' ?>" data-tip="Orders">
            <span class="sb-icon ic-orange">🛒</span><span class="sb-lbl">Orders</span><?= _sb_badge($_sb_badges['orders'], 'orange') ?>
        </a>
        <a href="/pages/orders_add.php" class="sb-item <?= $current==='orders_add.php'?'active':'' ?>" data-tip="New Order">
            <span class="sb-icon ic-orange">➕</span><span class="sb-lbl">New Order</span>
        </a>

        <div class="sb-divider"></div>


<?php endif; // section:orders ?>

<?php if (user_has_section_perm('logistics') || user_has_section_perm('tc26')): ?>
        <!-- ── Logistics ── -->
        <div class="sb-section">Logistics</div>
        <details class="sb-group" data-sidebar-key="logistics">
            <summary class="sb-summary <?= ($logisticsActive || in_array($current,['tc26_shipping.php','tc26_pallet.php'],true))?'active':'' ?>" data-tip="Logistics Menu">
                <span class="sb-icon ic-cyan">🚛</span>
                <span class="sb-lbl">Logistics Menu</span>
                <?= _sb_badge($_sb_badges['shipments'], 'cyan') ?>
                <span class="sb-arrow">›</span>
            </summary>
            <div class="sb-submenu">
                <?php if (user_has_section_perm('logistics')): ?>
                <a href="/pages/pallets_manage.php" class="sb-item sub <?= $current==='pallets_manage.php'?'active':'' ?>" data-tip="Pallets">
                    <span class="sb-icon ic-cyan">🪣</span><span class="sb-lbl">Pallets</span>
                </a>
                <a href="/pages/shipments_manage.php" class="sb-item sub <?= $current==='shipments_manage.php'?'active':'' ?>" data-tip="Shipments">
                    <span class="sb-icon ic-sky">🚢</span><span class="sb-lbl">Shipments</span><?= _sb_badge($_sb_badges['shipments'], 'cyan') ?>
                </a>
                <?php endif; ?>

                <?php if (user_has_section_perm('tc26')): ?>
                <a href="/pages/tc26_shipping.php" class="sb-item sub <?= $current==='tc26_shipping.php'?'active':'' ?>" data-tip="TC26 Shipping">
                    <span class="sb-icon ic-sky">📡</span><span class="sb-lbl">TC26 Shipping</span>
                </a>
                <a href="/pages/tc26_pallet.php" class="sb-item sub <?= $current==='tc26_pallet.php'?'active':'' ?>" data-tip="TC26 Pallet">
                    <span class="sb-icon ic-blue2">📦</span><span class="sb-lbl">TC26 Pallet</span>
                </a>
                <?php endif; ?>
            </div>
        </details>

        <div class="sb-divider"></div>


<?php endif; // section:logistics ?>


<?php if (user_has_section_perm('labels')): ?>
        <!-- ── Labels ── -->
        <div class="sb-section">Labels</div>
        <details class="sb-group" data-sidebar-key="labels">
            <summary class="sb-summary <?= $labelsOpen?'active':'' ?>" data-tip="Label System">
                <span class="sb-icon ic-teal">🏷️</span>
                <span class="sb-lbl">Label System</span>
                <?= _sb_badge($_sb_badges['labels_today'], 'teal') ?>
                <span class="sb-arrow">›</span>
            </summary>
            <div class="sb-submenu">
                <a href="/pages/label_print_center.php" class="sb-item sub <?= $current==='label_print_center.php'?'active':'' ?>" data-tip="Print Center">
                    <span class="sb-icon ic-teal2">🖨️</span><span class="sb-lbl">Print Center</span>
                </a>
                <a href="/pages/manual_case_labels.php" class="sb-item sub <?= $current==='manual_case_labels.php'?'active':'' ?>" data-tip="Manual Case Labels">
                    <span class="sb-icon ic-teal3">🧾</span><span class="sb-lbl">Manual Case Labels</span>
                </a>
                <a href="/pages/label_history.php" class="sb-item sub <?= $current==='label_history.php'?'active':'' ?>" data-tip="Label History">
                    <span class="sb-icon ic-teal3">🕐</span><span class="sb-lbl">Label History</span><?= _sb_badge($_sb_badges['labels_today'], 'teal') ?>
                </a>
                <a href="/pages/label_templates.php" class="sb-item sub <?= $current==='label_templates.php'?'active':'' ?>" data-tip="Templates">
                    <span class="sb-icon ic-teal4">📐</span><span class="sb-lbl">Templates</span>
                </a>
                <a href="/pages/label_printers.php" class="sb-item sub <?= $current==='label_printers.php'?'active':'' ?>" data-tip="Printers">
                    <span class="sb-icon ic-indigo">🖨️</span><span class="sb-lbl">Printers</span>
                </a>
                <a href="/pages/label_rules.php" class="sb-item sub <?= $current==='label_rules.php'?'active':'' ?>" data-tip="Routing Rules">
                    <span class="sb-icon ic-teal5">🔀</span><span class="sb-lbl">Routing Rules</span>
                </a>
            </div>
        </details>

        <div class="sb-divider"></div>


<?php endif; // section:labels ?>



<?php if (user_has_section_perm('settings') || user_has_section_perm('ibc') || user_has_section_perm('users')): ?>
        <!-- ── Settings ── -->
        <div class="sb-section">Settings</div>
        <details class="sb-group" data-sidebar-key="settings">
            <summary class="sb-summary <?= in_array($current,['settings.php','ibc_manager.php','users.php'],true)?'active':'' ?>" data-tip="Settings">
                <span class="sb-icon ic-gray">⚙️</span>
                <span class="sb-lbl">Settings</span>
                <span class="sb-arrow">›</span>
            </summary>
            <div class="sb-submenu">
                <?php if (user_has_section_perm('settings')): ?>
                <a href="/pages/settings.php" class="sb-item sub <?= $current==='settings.php'?'active':'' ?>" data-tip="General Settings">
                    <span class="sb-icon ic-gray">⚙️</span><span class="sb-lbl">General Settings</span>
                </a>
                <?php endif; ?>

                <?php if (user_has_section_perm('ibc')): ?>
                <a href="/pages/ibc_manager.php" class="sb-item sub <?= $current==='ibc_manager.php'?'active':'' ?>" data-tip="IBC Manager">
                    <span class="sb-icon ic-pink">🧪</span><span class="sb-lbl">IBC Manager</span>
                </a>
                <?php endif; ?>

                <?php if (user_has_section_perm('users')): ?>
                <a href="/pages/users.php" class="sb-item sub <?= $current==='users.php'?'active':'' ?>" data-tip="Users">
                    <span class="sb-icon ic-indigo2">👥</span><span class="sb-lbl">Users</span><?= _sb_badge($_sb_badges['users'], 'indigo') ?>
                </a>
                <?php endif; ?>
            </div>
        </details>

<?php endif; // section:settings ?>
    </div><!-- /sb-scroll -->

    <!-- FOOTER: logout -->
    <div class="sb-footer">
        <a href="<?= htmlspecialchars(sp_cherry_bridge_url($_SESSION['user'] ?? [], sp_cherry_entry_target()), ENT_QUOTES, 'UTF-8') ?>" class="sb-item" data-tip="Switch App" style="border-bottom:1px solid rgba(255,255,255,.06);margin-bottom:4px;">
            <span class="sb-icon" style="font-size:16px;">⇄</span><span class="sb-lbl" style="font-size:12px;color:#fbbf24;">Switch App 🍒</span>
        </a>
        <a href="/auth/logout.php" class="sb-item" data-tip="Logout">
            <span class="sb-icon ic-rose">🚪</span><span class="sb-lbl">Logout</span>
        </a>
    </div>

</nav>

<script>
(function () {
    /* ── Collapse toggle ── */
    var COLLAPSE_KEY = 'smproduce_sb_collapsed';

    function sbApply(collapsed) {
        if (collapsed) {
            document.body.classList.add('sidebar-collapsed');
        } else {
            document.body.classList.remove('sidebar-collapsed');
        }
    }

    window.sbToggle = function () {
        var isCollapsed = document.body.classList.contains('sidebar-collapsed');
        var next = !isCollapsed;
        sbApply(next);
        try { localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0'); } catch(e){}
    };

    /* Restore collapse state ASAP (before paint) */
    (function () {
        try {
            var stored = localStorage.getItem(COLLAPSE_KEY);
            if (stored === '1') sbApply(true);
        } catch(e) {}
    })();

    /* ── Scroll position + groups closed by default ── */
    var SCROLL_KEY = 'smproduce_sidebar_scroll_top';
    var scrollArea = document.getElementById('sidebarScrollArea');
    if (!scrollArea) return;

    // Every page load starts with all grouped menus closed.
    // Users can open them manually during the current page.
    document.querySelectorAll('#sidebarMenu details[data-sidebar-key]').forEach(function(el) {
        el.open = false;
    });

    // Remove any old saved open-state left by previous versions.
    try { localStorage.removeItem('smproduce_sidebar_open_state'); } catch(e){}

    function saveScroll() {
        try { sessionStorage.setItem(SCROLL_KEY, String(scrollArea.scrollTop || 0)); } catch(e){}
    }
    function restoreScroll() {
        try {
            var raw = sessionStorage.getItem(SCROLL_KEY);
            if (raw !== null) scrollArea.scrollTop = parseInt(raw, 10) || 0;
        } catch(e){}
    }

    restoreScroll();
    window.addEventListener('load', restoreScroll);
    setTimeout(restoreScroll, 0);
    setTimeout(restoreScroll, 100);

    scrollArea.addEventListener('scroll', saveScroll, { passive: true });
    window.addEventListener('beforeunload', saveScroll);
    document.querySelectorAll('#sidebarMenu a.sb-item').forEach(function(link) {
        link.addEventListener('click', saveScroll);
    });
})();

    /* ── Emoji icons: no init needed ── */

</script>
