<?php
require_once __DIR__ . '/../config/user_functions.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../config/emailer.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/print_engine.php';
require_once __DIR__ . '/../config/orders_sql_lib.php';

if (!function_exists('user_has_permission') || !user_has_permission('settings')) {
    http_response_code(403);
    include __DIR__ . '/../includes/header.php';
        echo "<div class='container-fluid py-4'><h3 class='text-danger'>Access denied</h3></div>";
    include __DIR__ . '/../includes/footer.php';
    exit;
}

function sp_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function sp_cmd_run($cmd) {
    $out = @shell_exec($cmd . ' 2>&1');
    if ($out === null) $out = '';
    return trim((string)$out);
}

function sp_is_windows() {
    return (stripos(PHP_OS, 'WIN') === 0);
}

function sp_get_print_db() {
    // Prefer PDO when available, fallback to mysqli
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) return $GLOBALS['conn'];
    return null;
}
$printDb = sp_get_print_db();
$hasPrintTableHelpers = function_exists('smp_ensure_print_tables');
$hasCalcSettingsHelpers = function_exists('smp_get_calc_setting') && function_exists('smp_set_calc_setting');
$hasTemplatePathHelpers = function_exists('smp_get_template_path') && function_exists('smp_set_template_path');
$hasDbExecHelpers = function_exists('smp_db_exec') && function_exists('smp_db_fetch_all');
if ($printDb && $hasPrintTableHelpers) { smp_ensure_print_tables($printDb); }

/* ==========================================================
   FLASH (POST-Redirect-GET for service actions)
========================================================== */

$flash = $_SESSION['settings_flash'] ?? [];
unset($_SESSION['settings_flash']);

function sp_flash_set($key, $val) {
    $_SESSION['settings_flash'][$key] = $val;
}
function sp_flash_get($flash, $key, $default='') {
    return isset($flash[$key]) ? (string)$flash[$key] : $default;
}

/* ==========================================================
   EMAIL / ALERTS CONFIG LOADING
========================================================== */

$emailConfigFile  = __DIR__ . '/../config/email_settings.json';
$emailRecipientsFile = __DIR__ . '/../config/email_recipients.json';
$alertsConfigFile = __DIR__ . '/../config/production_alerts.json';

$emailCfg = [
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_user' => '',
    'smtp_pass' => '',
    'from_email'=> '',
    'from_name' => '',
    'use_tls'   => true,
];

if (is_file($emailConfigFile)) {
    $tmp = json_decode(@file_get_contents($emailConfigFile), true);
    if (is_array($tmp)) {
        $emailCfg = array_merge($emailCfg, $tmp);
    }
}

$emailRecipients = [
    'critical' => [],
    'warnings' => [],
    'reports'  => [],
];

if (is_file($emailRecipientsFile)) {
    $tmp = json_decode(@file_get_contents($emailRecipientsFile), true);
    if (is_array($tmp)) {
        $emailRecipients = array_merge($emailRecipients, $tmp);
    }
}

$alertsCfg = [
    'enabled'         => true,
    'interval_minutes'=> 10,
    'timeout_minutes' => 60,
];

if (is_file($alertsConfigFile)) {
    $tmp = json_decode(@file_get_contents($alertsConfigFile), true);
    if (is_array($tmp)) {
        $alertsCfg = array_merge($alertsCfg, $tmp);
    }
}



/* ==========================================================
   KEYENCE CONFIG / DIAGNOSTICS
========================================================== */

$keyenceConfigFile = __DIR__ . '/../config/keyence.json';
$keyenceCfg = [
    'ip'      => '192.168.1.180',
    'port'    => 9004,
    'enabled' => false,
];

if (is_file($keyenceConfigFile)) {
    $tmp = json_decode(@file_get_contents($keyenceConfigFile), true);
    if (is_array($tmp)) {
        $keyenceCfg = array_merge($keyenceCfg, $tmp);
    }
}

$keyenceCfg['ip'] = trim((string)($keyenceCfg['ip'] ?? '192.168.1.180'));
$keyenceCfg['port'] = (int)($keyenceCfg['port'] ?? 9004);
if ($keyenceCfg['port'] <= 0) $keyenceCfg['port'] = 9004;
$keyenceCfg['enabled'] = !empty($keyenceCfg['enabled']);

$keyence_ping_ok = false;
$local_port_in_use = false;

if (sp_is_windows()) {
    if ($keyenceCfg['ip'] !== '') {
        $pingCmd = 'ping -n 1 -w 800 ' . escapeshellarg($keyenceCfg['ip']);
        $pingOut = sp_cmd_run($pingCmd);
        $keyence_ping_ok = (stripos($pingOut, 'TTL=') !== false) || (stripos($pingOut, 'Reply from') !== false);
    }

    $netstatOut = sp_cmd_run('netstat -ano -p tcp');
    if ($netstatOut !== '') {
        $needle1 = ':' . (int)$keyenceCfg['port'] . ' ';
        $needle2 = ':' . (int)$keyenceCfg['port'] . "
";
        $needle3 = ':' . (int)$keyenceCfg['port'] . "
";
        $local_port_in_use = (stripos($netstatOut, $needle1) !== false) || (stripos($netstatOut, $needle2) !== false) || (stripos($netstatOut, $needle3) !== false);
    }
}

/* ==========================================================
   SERVICES
========================================================== */

$listener_service = 'SM Produce Barcode Listener'; // unified Keyence + USB HID scheduled task
$cloud_service    = 'Cloudflared';
$print_agent_service = 'SMProducePrintAgent';

$listener_status_file = __DIR__ . '/../barcode_listener/barcode_listener_status.json';
$listener_pid_file = __DIR__ . '/../barcode_listener/barcode_listener.pid';
$listener_state = [];
if (is_file($listener_status_file)) {
    $tmp = json_decode((string)@file_get_contents($listener_status_file), true);
    if (is_array($tmp)) $listener_state = $tmp;
}
$listener_pid = is_file($listener_pid_file) ? (int)trim((string)@file_get_contents($listener_pid_file)) : 0;
$listener_running = false;
if (sp_is_windows() && $listener_pid > 0) {
    $taskOut = sp_cmd_run('tasklist /FI "PID eq ' . $listener_pid . '" /NH');
    $listener_running = stripos($taskOut, (string)$listener_pid) !== false && stripos($taskOut, 'No tasks') === false;
}
$listener_stopped = !$listener_running;

$cloud_raw = sp_is_windows() ? sp_cmd_run('sc query ' . escapeshellarg($cloud_service)) : '';
$cloud_running = stripos($cloud_raw, 'RUNNING') !== false;
$cloud_stopped = stripos($cloud_raw, 'STOPPED') !== false;

$print_agent_raw = sp_is_windows() ? sp_cmd_run('sc query ' . escapeshellarg($print_agent_service)) : '';
$print_agent_running = stripos($print_agent_raw, 'RUNNING') !== false;
$print_agent_stopped = stripos($print_agent_raw, 'STOPPED') !== false;

/* ==========================================================
   Listener log paths
========================================================== */

$listener_log = __DIR__ . '/../barcode_listener/barcode_listener.log';
$listener_last = __DIR__ . '/../barcode_listener/barcode_listener_status.json';

function sp_tail_file($path, $maxLines = 200) {
    if (!is_file($path)) return 'No log yet.';
    $lines = @file($path);
    if (!is_array($lines)) return 'No log yet.';
    return implode('', array_slice($lines, -$maxLines));
}

$listener_log_txt  = sp_tail_file($listener_log, 200);
$last_msg = '(no data yet)';
if (is_file($listener_last)) {
    $last_msg = trim((string)@file_get_contents($listener_last));
    if ($last_msg === '') $last_msg = '(no data yet)';
}


/* ==========================================================
   ORDER PACKAGING PRESETS
========================================================== */

$packagingPresetsReady = false;
$packagingPresets = [];
$packagingPresetError = '';
$orderLocationPresets = ['pick' => [], 'city' => []];

try {
    if (orders_sql_ready()) {
        orders_sql_init();
        $ordersDb = orders_db();

        if ($ordersDb instanceof PDO) {
            // orders_sql_init() already creates/migrates this table.
            $packagingPresetsReady = true;
        }
    }
} catch (Throwable $e) {
    $packagingPresetError = $e->getMessage();
}

function sp_pack_usage(PDO $db, string $label): int {
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM order_lines WHERE packaging_preset=?");
        $st->execute([$label]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function sp_order_location_usage(PDO $db, string $kind, string $label): int {
    $column = $kind === 'city' ? 'dest_city' : 'pick_location';
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM orders WHERE {$column}=?");
        $st->execute([$label]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}


/* ==========================================================
   RECEIVING PRESETS — Growers / Varieties / Bin Types
========================================================== */

$receivingPresetsReady = ($pdo instanceof PDO);
$receivingPresetError = '';

if ($receivingPresetsReady) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS carriers_list (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $pdo->exec("INSERT IGNORE INTO carriers_list(name)
                        SELECT DISTINCT TRIM(carrier)
                        FROM empty_bins
                        WHERE carrier IS NOT NULL AND TRIM(carrier)<>''");
        } catch (Throwable $ignore) {}

        try {
            $pdo->exec("INSERT IGNORE INTO carriers_list(name)
                        SELECT DISTINCT TRIM(carrier)
                        FROM shipments
                        WHERE carrier IS NOT NULL AND TRIM(carrier)<>''");
        } catch (Throwable $ignore) {}
    } catch (Throwable $e) {
        $receivingPresetError = $e->getMessage();
    }
}

function sp_receiving_preset_meta(string $kind): array {
    $map = [
        'grower' => [
            'table' => 'growers_list',
            'label' => 'Grower',
            'fk'    => 'grower_id',
        ],
        'variety' => [
            'table' => 'varieties_list',
            'label' => 'Variety',
            'fk'    => 'variety_id',
        ],
        'bin_type' => [
            'table' => 'bin_types_list',
            'label' => 'Bin Type',
            'fk'    => 'type_id',
        ],
        'carrier' => [
            'table' => 'carriers_list',
            'label' => 'Carrier',
            'fk'    => null,
        ],
    ];
    if (!isset($map[$kind])) throw new RuntimeException('Unknown Receiving Preset type.');
    return $map[$kind];
}

function sp_receiving_preset_usage(PDO $db,string $kind,int $id): array {
    $m = sp_receiving_preset_meta($kind);
    try {
        if ($kind === 'carrier') {
            $st=$db->prepare("SELECT name FROM carriers_list WHERE id=? LIMIT 1");
            $st->execute([$id]);
            $name=(string)($st->fetchColumn() ?: '');
            if ($name==='') return ['history'=>0,'available'=>0];

            $st=$db->prepare("
                SELECT COUNT(*)
                FROM empty_bins
                WHERE LOWER(TRIM(COALESCE(carrier,'')))=LOWER(TRIM(?))
            ");
            $st->execute([$name]);
            return ['history'=>(int)$st->fetchColumn(),'available'=>0];
        }

        $st=$db->prepare("
            SELECT
                COUNT(*) AS history_count,
                SUM(CASE WHEN UPPER(COALESCE(status,''))='AVAILABLE' THEN 1 ELSE 0 END) AS available_count
            FROM bins_ingresso
            WHERE {$m['fk']}=?
        ");
        $st->execute([$id]);
        $r=$st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'history'=>(int)($r['history_count']??0),
            'available'=>(int)($r['available_count']??0),
        ];
    } catch (Throwable $e) {
        return ['history'=>0,'available'=>0];
    }
}

/* ==========================================================
   POST handlers (services, log, email)
========================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Receiving Presets — Grower / Variety / Bin Type
    if (isset($_POST['receiving_preset_action'])) {
        $act  = trim((string)($_POST['receiving_preset_action'] ?? ''));
        $kind = trim((string)($_POST['receiving_preset_kind'] ?? ''));

        if (!$receivingPresetsReady || !($pdo instanceof PDO)) {
            sp_flash_set('receivingPresetFlash', 'Main database is not available.');
            header('Location: settings.php#receiving-presets-section');
            exit;
        }

        try {
            $meta = sp_receiving_preset_meta($kind);
            $table = $meta['table'];
            $labelName = $meta['label'];

            if ($act === 'save') {
                $id   = (int)($_POST['receiving_preset_id'] ?? 0);
                $name = trim((string)($_POST['receiving_preset_name'] ?? ''));

                if ($name === '') throw new RuntimeException($labelName.' name is required.');
                if (mb_strlen($name) > 100) throw new RuntimeException($labelName.' name is too long.');

                $dupe = $pdo->prepare(
                    "SELECT id FROM {$table}
                     WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) AND id<>?
                     LIMIT 1"
                );
                $dupe->execute([$name,$id]);
                if ($dupe->fetchColumn()) {
                    throw new RuntimeException($labelName.' "'.$name.'" already exists.');
                }

                if ($id > 0) {
                    $oldName = '';
                    if ($kind === 'carrier') {
                        $stOld=$pdo->prepare("SELECT name FROM carriers_list WHERE id=? LIMIT 1");
                        $stOld->execute([$id]);
                        $oldName=(string)($stOld->fetchColumn() ?: '');
                    }

                    $st=$pdo->prepare("UPDATE {$table} SET name=? WHERE id=?");
                    $st->execute([$name,$id]);

                    if ($kind === 'carrier' && $oldName !== '' && $oldName !== $name) {
                        $stHist=$pdo->prepare("
                            UPDATE empty_bins
                            SET carrier=?
                            WHERE LOWER(TRIM(COALESCE(carrier,'')))=LOWER(TRIM(?))
                        ");
                        $stHist->execute([$name,$oldName]);
                    }
                    if ($st->rowCount()===0) {
                        $chk=$pdo->prepare("SELECT id FROM {$table} WHERE id=?");
                        $chk->execute([$id]);
                        if (!$chk->fetchColumn()) throw new RuntimeException($labelName.' preset not found.');
                    }
                    sp_flash_set('receivingPresetFlash', $labelName.' updated.');
                } else {
                    $st=$pdo->prepare("INSERT INTO {$table}(name) VALUES(?)");
                    $st->execute([$name]);
                    sp_flash_set('receivingPresetFlash', $labelName.' added.');
                }
            }

            if ($act === 'delete') {
                $id=(int)($_POST['receiving_preset_id'] ?? 0);
                if ($id<=0) throw new RuntimeException('Invalid preset ID.');

                $st=$pdo->prepare("SELECT name FROM {$table} WHERE id=? LIMIT 1");
                $st->execute([$id]);
                $name=(string)($st->fetchColumn() ?: '');
                if ($name==='') throw new RuntimeException($labelName.' preset not found.');

                $usage=sp_receiving_preset_usage($pdo,$kind,$id);
                $history=(int)($usage['history']??0);
                if ($history>0) {
                    if ($kind === 'carrier') {
                        throw new RuntimeException(
                            $labelName.' "'.$name.'" is used by '.$history.
                            ' historical Empty Bin record'.($history===1?'':'s').
                            '. Rename it instead of deleting it.'
                        );
                    }
                    throw new RuntimeException(
                        $labelName.' "'.$name.'" is used by '.$history.
                        ' historical Full Bin record'.($history===1?'':'s').
                        '. Rename it instead of deleting it.'
                    );
                }

                $st=$pdo->prepare("DELETE FROM {$table} WHERE id=?");
                $st->execute([$id]);
                sp_flash_set('receivingPresetFlash', $labelName.' deleted.');
            }
        } catch (Throwable $e) {
            sp_flash_set('receivingPresetFlash', 'Receiving Presets: '.$e->getMessage());
        }

        header('Location: settings.php#receiving-presets-section');
        exit;
    }

    // Order Pick Location / Destination City Presets
    if (isset($_POST['order_location_preset_action'])) {
        $act  = trim((string)($_POST['order_location_preset_action'] ?? ''));
        $kind = trim((string)($_POST['order_location_preset_kind'] ?? ''));

        if (!$packagingPresetsReady || !isset($ordersDb) || !($ordersDb instanceof PDO)) {
            sp_flash_set('orderLocationPresetFlash', 'Orders database is not available.');
            header('Location: settings.php#order-location-presets-section');
            exit;
        }

        try {
            if (!in_array($kind, ['pick','city'], true)) {
                throw new RuntimeException('Invalid preset type.');
            }
            $table = $kind === 'city' ? 'order_city_presets' : 'order_pick_presets';
            $nameColumn = $kind === 'city' ? 'city_label' : 'label';
            $orderColumn = $kind === 'city' ? 'dest_city' : 'pick_location';
            $title = $kind === 'city' ? 'Destination City' : 'Pick Up Location';

            if ($act === 'save') {
                $id = (int)($_POST['order_location_preset_id'] ?? 0);
                $label = trim((string)($_POST['order_location_preset_label'] ?? ''));
                $address = trim((string)($_POST['order_location_preset_address'] ?? ''));
                if ($label === '') throw new RuntimeException($title.' is required.');
                if (mb_strlen($label) > 200) throw new RuntimeException($title.' is too long.');

                $dupe = $ordersDb->prepare("SELECT id FROM {$table} WHERE LOWER({$nameColumn})=LOWER(?) AND id<>? LIMIT 1");
                $dupe->execute([$label,$id]);
                if ($dupe->fetchColumn()) throw new RuntimeException('This '.$title.' already exists.');

                if ($id > 0) {
                    $old = $ordersDb->prepare("SELECT {$nameColumn} FROM {$table} WHERE id=? LIMIT 1");
                    $old->execute([$id]);
                    $oldLabel = (string)($old->fetchColumn() ?: '');
                    if ($oldLabel === '') throw new RuntimeException($title.' preset not found.');

                    $ordersDb->beginTransaction();
                    try {
                        if ($kind === 'city') {
                            $st = $ordersDb->prepare("UPDATE {$table} SET {$nameColumn}=?,ship_to_address=? WHERE id=?");
                            $st->execute([$label,$address,$id]);
                        } else {
                            $st = $ordersDb->prepare("UPDATE {$table} SET {$nameColumn}=? WHERE id=?");
                            $st->execute([$label,$id]);
                        }
                        if ($oldLabel !== $label) {
                            $st = $ordersDb->prepare("UPDATE orders SET {$orderColumn}=? WHERE {$orderColumn}=?");
                            $st->execute([$label,$oldLabel]);
                        }
                        $ordersDb->commit();
                    } catch (Throwable $e) {
                        if ($ordersDb->inTransaction()) $ordersDb->rollBack();
                        throw $e;
                    }
                    sp_flash_set('orderLocationPresetFlash', $title.' updated.');
                } else {
                    if ($kind === 'city') {
                        $st = $ordersDb->prepare("INSERT INTO {$table}(city_label,ship_to_address,sort_order) VALUES(?,?,0)");
                        $st->execute([$label,$address]);
                    } else {
                        $st = $ordersDb->prepare("INSERT INTO {$table}(label,sort_order) VALUES(?,0)");
                        $st->execute([$label]);
                    }
                    sp_flash_set('orderLocationPresetFlash', $title.' added.');
                }
            } elseif ($act === 'delete') {
                $id = (int)($_POST['order_location_preset_id'] ?? 0);
                $st = $ordersDb->prepare("SELECT {$nameColumn} FROM {$table} WHERE id=? LIMIT 1");
                $st->execute([$id]);
                $label = (string)($st->fetchColumn() ?: '');
                if ($label === '') throw new RuntimeException($title.' preset not found.');
                $usage = sp_order_location_usage($ordersDb,$kind,$label);
                if ($usage > 0) throw new RuntimeException('This preset is used by '.$usage.' order'.($usage===1?'':'s').'. Rename it instead of deleting it.');
                $ordersDb->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);
                sp_flash_set('orderLocationPresetFlash', $title.' deleted.');
            }
        } catch (Throwable $e) {
            sp_flash_set('orderLocationPresetFlash', 'Order Presets: '.$e->getMessage());
        }
        header('Location: settings.php#order-location-presets-section');
        exit;
    }

    // Order Packaging Presets
    if (isset($_POST['packaging_preset_action'])) {
        $act = (string)$_POST['packaging_preset_action'];

        if (!$packagingPresetsReady || !isset($ordersDb) || !($ordersDb instanceof PDO)) {
            sp_flash_set('packagingPresetFlash', 'Orders database is not available.');
            header('Location: settings.php#packaging-presets-section');
            exit;
        }

        try {
            if ($act === 'save') {
                $id        = (int)($_POST['preset_id'] ?? 0);
                $label     = trim((string)($_POST['preset_label'] ?? ''));
                $weight    = max(0, (float)($_POST['preset_weight_lbs'] ?? 0));
                $sortOrder = 0;

                if ($label === '') {
                    throw new RuntimeException('Packaging name is required.');
                }
                if (mb_strlen($label) > 200) {
                    throw new RuntimeException('Packaging name is too long.');
                }

                $dupe = $ordersDb->prepare(
                    "SELECT id FROM order_pack_presets
                     WHERE LOWER(label)=LOWER(?) AND id<>? LIMIT 1"
                );
                $dupe->execute([$label,$id]);
                if ($dupe->fetchColumn()) {
                    throw new RuntimeException('A Packaging Preset with this name already exists.');
                }

                if ($id > 0) {
                    $old = $ordersDb->prepare(
                        "SELECT label FROM order_pack_presets WHERE id=? LIMIT 1"
                    );
                    $old->execute([$id]);
                    $oldLabel = (string)($old->fetchColumn() ?: '');
                    if ($oldLabel === '') {
                        throw new RuntimeException('Packaging Preset not found.');
                    }

                    $ordersDb->beginTransaction();
                    try {
                        $st = $ordersDb->prepare(
                            "UPDATE order_pack_presets
                             SET label=?,weight_lbs=?,sort_order=?
                             WHERE id=?"
                        );
                        $st->execute([$label,$weight,$sortOrder,$id]);

                        // order_lines stores the selected preset as text.
                        // Keep existing orders linked when a preset is renamed.
                        if ($oldLabel !== $label) {
                            $st = $ordersDb->prepare(
                                "UPDATE order_lines
                                 SET packaging_preset=?
                                 WHERE packaging_preset=?"
                            );
                            $st->execute([$label,$oldLabel]);

                            try {
                                $st = $ordersDb->prepare(
                                    "UPDATE orders
                                     SET packaging_preset=?
                                     WHERE packaging_preset=?"
                                );
                                $st->execute([$label,$oldLabel]);
                            } catch (Throwable $ignore) {}
                        }

                        $ordersDb->commit();
                    } catch (Throwable $e) {
                        if ($ordersDb->inTransaction()) $ordersDb->rollBack();
                        throw $e;
                    }

                    sp_flash_set('packagingPresetFlash', 'Packaging Preset updated.');
                } else {
                    $st = $ordersDb->prepare(
                        "INSERT INTO order_pack_presets(label,weight_lbs,sort_order)
                         VALUES(?,?,?)"
                    );
                    $st->execute([$label,$weight,$sortOrder]);
                    sp_flash_set('packagingPresetFlash', 'Packaging Preset added.');
                }
            }

            if ($act === 'delete') {
                $id = (int)($_POST['preset_id'] ?? 0);

                $st = $ordersDb->prepare(
                    "SELECT label FROM order_pack_presets WHERE id=? LIMIT 1"
                );
                $st->execute([$id]);
                $label = (string)($st->fetchColumn() ?: '');

                if ($label === '') {
                    throw new RuntimeException('Packaging Preset not found.');
                }

                $usage = sp_pack_usage($ordersDb,$label);
                if ($usage > 0) {
                    throw new RuntimeException(
                        'This Packaging Preset is used by '.$usage.
                        ' order line'.($usage===1?'':'s').
                        '. Edit/rename it instead of deleting it.'
                    );
                }

                $st = $ordersDb->prepare(
                    "DELETE FROM order_pack_presets WHERE id=?"
                );
                $st->execute([$id]);

                sp_flash_set('packagingPresetFlash', 'Packaging Preset deleted.');
            }
        } catch (Throwable $e) {
            sp_flash_set('packagingPresetFlash', 'Packaging Presets: '.$e->getMessage());
        }

        header('Location: settings.php#packaging-presets-section');
        exit;
    }


    // Auto-report config
    if (isset($_POST['alerts_action']) && $_POST['alerts_action'] === 'save_auto_report') {
        $alertsCfg['auto_report_enabled'] = !empty($_POST['auto_report_enabled']);
        $alertsCfg['auto_report_delay']   = max(1, (int)($_POST['auto_report_delay'] ?? 60));
        @file_put_contents($alertsConfigFile, json_encode($alertsCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Save recipients
        $recipFile2 = __DIR__ . '/../config/email_recipients.json';
        $recipData2 = [];
        if (is_file($recipFile2)) {
            $tmp2 = json_decode(@file_get_contents($recipFile2), true);
            if (is_array($tmp2)) $recipData2 = $tmp2;
        }
        $rawR = trim((string)($_POST['report_recipients'] ?? ''));
        $recipData2['reports'] = array_values(array_filter(array_map('trim', explode(',', $rawR))));
        @file_put_contents($recipFile2, json_encode($recipData2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        sp_flash_set('alertsFlash', 'Auto-report settings saved.');
        header('Location: settings.php#alerts-section');
        exit;
    }

    // Clear listener log
    if (isset($_POST['clear_listener_log'])) {
        if (is_file($listener_log)) {
            @file_put_contents($listener_log, '');
            sp_flash_set('logFlash', 'Listener log cleared.');
        } else {
            sp_flash_set('logFlash', 'No log file found to clear.');
        }
        header('Location: settings.php#keyence-section');
        exit;
    }

    // Cloudflare service actions
    if (isset($_POST['cloud_action'])) {
        $ca = (string)$_POST['cloud_action'];
        $out = '';

        if (!sp_is_windows()) {
            $out = "This host is not Windows. Service controls are unavailable.";
        } else {
            if ($ca === 'start')   $out = sp_cmd_run('sc start ' . escapeshellarg($cloud_service));
            if ($ca === 'stop')    $out = sp_cmd_run('sc stop ' . escapeshellarg($cloud_service));
            if ($ca === 'restart') $out = sp_cmd_run('sc stop ' . escapeshellarg($cloud_service)) . "\n" . sp_cmd_run('sc start ' . escapeshellarg($cloud_service));
            if ($ca === 'status')  $out = sp_cmd_run('sc query ' . escapeshellarg($cloud_service));
        }

        sp_flash_set('cloudConsole', $out !== '' ? $out : 'Done.');
        header('Location: settings.php#cloudflare-section');
        exit;
    }

    // Print Agent service actions (NSSM)
    if (isset($_POST['print_agent_action'])) {
        $pa = (string)$_POST['print_agent_action'];
        $out = '';

        if (!sp_is_windows()) {
            $out = "This host is not Windows. Service controls are unavailable.";
        } else {
            if ($pa === 'start')   $out = sp_cmd_run('nssm start ' . escapeshellarg($print_agent_service));
            if ($pa === 'stop')    $out = sp_cmd_run('nssm stop ' . escapeshellarg($print_agent_service));
            if ($pa === 'restart') $out = sp_cmd_run('nssm restart ' . escapeshellarg($print_agent_service));
            if ($pa === 'status')  $out = sp_cmd_run('sc query ' . escapeshellarg($print_agent_service));
        }

        sp_flash_set('printAgentConsole', $out !== '' ? $out : 'Done.');
        header('Location: settings.php#print-agent-section');
        exit;
    }

    // EMAIL (SMTP + recipients)
    if (isset($_POST['email_action'])) {
        $action = (string)$_POST['email_action'];

        if ($action === 'save_smtp' || $action === 'test_smtp') {
            $emailCfg['smtp_host'] = trim((string)($_POST['smtp_host'] ?? ''));
            $emailCfg['smtp_port'] = (int)($_POST['smtp_port'] ?? 587);
            if ($emailCfg['smtp_port'] <= 0) $emailCfg['smtp_port'] = 587;

            $emailCfg['smtp_user'] = trim((string)($_POST['smtp_user'] ?? ''));
            $smtpPass = (string)($_POST['smtp_pass'] ?? '');
            if ($smtpPass !== '***MASKED***') {
                $emailCfg['smtp_pass'] = $smtpPass;
            }
            $emailCfg['from_email'] = trim((string)($_POST['from_email'] ?? ''));
            $emailCfg['from_name']  = trim((string)($_POST['from_name'] ?? ''));
            $emailCfg['use_tls']    = !empty($_POST['use_tls']);

            @file_put_contents($emailConfigFile, json_encode($emailCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ($action === 'test_smtp') {
                $test_to = trim((string)($_POST['test_to'] ?? ''));
                if ($test_to === '') {
                    sp_flash_set('emailFlash', 'Test: please provide a destination address.');
                } else {
                    $ok = sp_send_test_email($emailCfg, $test_to);
                    sp_flash_set('emailFlash', $ok ? 'Test email sent.' : 'Failed to send test email. Check logs / SMTP settings.');
                }
            } else {
                sp_flash_set('emailFlash', 'SMTP settings saved.');
            }

            header('Location: settings.php#email-section');
            exit;
        }

        if ($action === 'save_recipients') {
            $emailRecipients['critical'] = array_filter(array_map('trim', explode(',', (string)($_POST['recip_critical'] ?? ''))));
            $emailRecipients['warnings'] = array_filter(array_map('trim', explode(',', (string)($_POST['recip_warnings'] ?? ''))));
            $emailRecipients['reports']  = array_filter(array_map('trim', explode(',', (string)($_POST['recip_reports'] ?? ''))));

            @file_put_contents($emailRecipientsFile, json_encode($emailRecipients, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            sp_flash_set('recipFlash', 'Email recipients saved.');

            header('Location: settings.php#email-section');
            exit;
        }
    }

    // Alerts config
    if (isset($_POST['alerts_action']) && $_POST['alerts_action'] === 'save_alerts') {
        $alertsCfg['enabled']          = !empty($_POST['alerts_enabled']);
        $alertsCfg['interval_minutes'] = max(1, (int)($_POST['interval_minutes'] ?? 10));
        $alertsCfg['timeout_minutes']  = max(1, (int)($_POST['timeout_minutes'] ?? 60));

        @file_put_contents($alertsConfigFile, json_encode($alertsCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        sp_flash_set('alertsFlash', 'Alert settings saved.');
        header('Location: settings.php#alerts-section');
        exit;
    }

    // Keyence basic config
    if (isset($_POST['save_keyence_settings'])) {
        $keyenceConfigFile = __DIR__ . '/../config/keyence.json';
        $keyenceCfg = [
            'ip'      => trim((string)($_POST['keyence_ip'] ?? '192.168.1.180')),
            'port'    => (int)($_POST['keyence_port'] ?? 9004),
            'enabled' => !empty($_POST['keyence_enabled']),
        ];
        if ($keyenceCfg['port'] <= 0) $keyenceCfg['port'] = 9004;
        @file_put_contents($keyenceConfigFile, json_encode($keyenceCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Keep the unified background listener on the same port/enabled state.
        $unifiedCfgFile = __DIR__ . '/../config/barcode_listener.json';
        $unifiedCfg = is_file($unifiedCfgFile) ? json_decode((string)@file_get_contents($unifiedCfgFile), true) : [];
        if (!is_array($unifiedCfg)) $unifiedCfg = [];
        $unifiedCfg['enabled'] = true;
        $unifiedCfg['keyence_enabled'] = !empty($keyenceCfg['enabled']);
        $unifiedCfg['keyence_mode'] = 'server';
        $unifiedCfg['keyence_bind'] = '0.0.0.0';
        $unifiedCfg['keyence_port'] = (int)$keyenceCfg['port'];
        @file_put_contents($unifiedCfgFile, json_encode($unifiedCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        sp_flash_set('keyenceSettingsFlash', 'Keyence settings saved for the unified listener.');
        header('Location: settings.php#keyence-section');
        exit;
    }

    // Calculated fields sources
    if (isset($_POST['save_calc_sources']) && $printDb && $hasCalcSettingsHelpers) {
        smp_set_calc_setting($printDb, 'pallet_cases_table',   $_POST['pallet_cases_table'] ?? '');
        smp_set_calc_setting($printDb, 'pallet_cases_column',  $_POST['pallet_cases_column'] ?? '');
        smp_set_calc_setting($printDb, 'case_lookup_table',    $_POST['case_lookup_table'] ?? '');
        smp_set_calc_setting($printDb, 'case_lookup_column',   $_POST['case_lookup_column'] ?? '');
        smp_set_calc_setting($printDb, 'shipping_source_table',$_POST['shipping_source_table'] ?? '');
        smp_set_calc_setting($printDb, 'shipping_source_column',$_POST['shipping_source_column'] ?? '');
        sp_flash_set('calcFlash', 'Calculation sources saved.');
        header('Location: settings.php#calc-section');
        exit;
    }

    // ZPL template directories per module
    if (isset($_POST['save_zpl_paths']) && $printDb && $hasTemplatePathHelpers) {
        $zplModules = ['bins', 'pallets', 'shipping'];
        foreach ($zplModules as $mod) {
            $dir = trim((string)($_POST['zpl_'.$mod] ?? ''));
            $dir = str_replace('\\','/',$dir);
            $dir = preg_replace('#/+#','/',$dir);
            if ($dir === '') {
                smp_set_template_path($printDb, $mod, '');
            } else {
                if (substr($dir, -1) !== '/') $dir .= '/';
                smp_set_template_path($printDb, $mod, $dir);
            }
        }
        sp_flash_set('zplFlash', 'ZPL template paths saved.');
        header('Location: settings.php#zpl-section');
        exit;
    }

    // Printer-specific template dirs
    if (isset($_POST['save_printer_tpl_paths']) && $printDb && $hasDbExecHelpers) {
        $printerId = (int)($_POST['printer_id'] ?? 0);
        if ($printerId > 0) {
            $zplModules = ['bins', 'pallets', 'shipping'];
            foreach ($zplModules as $mod) {
                $dir = trim((string)($_POST['ptpl_'.$mod] ?? ''));
                $dir = str_replace('\\','/',$dir);
                $dir = preg_replace('#/+#','/',$dir);
                if ($dir === '') continue;
                if (substr($dir, -1) !== '/') $dir .= '/';

                $projectRoot = realpath(__DIR__ . '/..');
                $real = realpath($projectRoot . '/' . $dir);
                if ($real && strpos($real, $projectRoot) === 0 && is_dir($real)) {
                    smp_db_exec($printDb,
                        "INSERT INTO print_printer_template_paths (printer_id, module, template_dir, active)
                         VALUES (?,?,?,1)
                         ON DUPLICATE KEY UPDATE template_dir=VALUES(template_dir), active=1",
                        [$printerId, $mod, $dir]
                    );
                }
            }
            sp_flash_set('zplFlash', 'Printer-specific ZPL templates saved.');
        }
        header('Location: settings.php#zpl-section');
        exit;
    }
}

/* ==========================================================
   FLASH MESSAGES for view
========================================================== */

$emailFlash   = sp_flash_get($flash, 'emailFlash', '');
$recipFlash   = sp_flash_get($flash, 'recipFlash', '');
$alertsFlash  = sp_flash_get($flash, 'alertsFlash', '');
$calcFlash    = sp_flash_get($flash, 'calcFlash', '');
$zplFlash     = sp_flash_get($flash, 'zplFlash', '');
$keyenceConsoleFlash = sp_flash_get($flash, 'keyenceConsole', '');
$cloudConsoleFlash   = sp_flash_get($flash, 'cloudConsole', '');
$printAgentConsoleFlash = sp_flash_get($flash, 'printAgentConsole', '');
$logFlash            = sp_flash_get($flash, 'logFlash', '');
$keyenceSettingsFlash= sp_flash_get($flash, 'keyenceSettingsFlash', '');
$packagingPresetFlash = sp_flash_get($flash, 'packagingPresetFlash', '');
$orderLocationPresetFlash = sp_flash_get($flash, 'orderLocationPresetFlash', '');
$receivingPresetFlash = sp_flash_get($flash, 'receivingPresetFlash', '');

/* ==========================================================
   LOAD AUX DATA FOR FORMS (only if we have DB)
========================================================== */

$calcSettings = [
    'pallet_cases_table'    => '',
    'pallet_cases_column'   => '',
    'case_lookup_table'     => '',
    'case_lookup_column'    => '',
    'shipping_source_table' => '',
    'shipping_source_column'=> '',
];
if ($printDb && $hasCalcSettingsHelpers) {
    $calcSettings['pallet_cases_table']     = smp_get_calc_setting($printDb, 'pallet_cases_table');
    $calcSettings['pallet_cases_column']    = smp_get_calc_setting($printDb, 'pallet_cases_column');
    $calcSettings['case_lookup_table']      = smp_get_calc_setting($printDb, 'case_lookup_table');
    $calcSettings['case_lookup_column']     = smp_get_calc_setting($printDb, 'case_lookup_column');
    $calcSettings['shipping_source_table']  = smp_get_calc_setting($printDb, 'shipping_source_table');
    $calcSettings['shipping_source_column'] = smp_get_calc_setting($printDb, 'shipping_source_column');
}

$zplPaths = [
    'bins'     => '',
    'pallets'  => '',
    'shipping' => '',
];
if ($printDb && $hasTemplatePathHelpers) {
    foreach (['bins','pallets','shipping'] as $mod) {
        $zplPaths[$mod] = smp_get_template_path($printDb, $mod);
    }
}

$printers = [];
if ($printDb && !$hasCalcSettingsHelpers) {
    $calcFlash = 'Calculation settings helpers are not loaded in this build. The service controls still work.';
}
if ($printDb && !$hasTemplatePathHelpers) {
    $zplFlash = 'ZPL template path helpers are not loaded in this build.';
}



$receivingGrowers = [];
$receivingVarieties = [];
$receivingBinTypes = [];
$receivingCarriers = [];

if ($receivingPresetsReady && $pdo instanceof PDO) {
    try {
        $receivingGrowers = $pdo->query(
            "SELECT g.id,g.name,
                    (SELECT COUNT(*) FROM bins_ingresso bi WHERE bi.grower_id=g.id) AS history_count,
                    (SELECT COUNT(*) FROM bins_ingresso bi WHERE bi.grower_id=g.id AND UPPER(COALESCE(bi.status,''))='AVAILABLE') AS available_count
             FROM growers_list g
             ORDER BY g.name ASC,g.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $receivingVarieties = $pdo->query(
            "SELECT v.id,v.name,
                    (SELECT COUNT(*) FROM bins_ingresso bi WHERE bi.variety_id=v.id) AS history_count,
                    (SELECT COUNT(*) FROM bins_ingresso bi WHERE bi.variety_id=v.id AND UPPER(COALESCE(bi.status,''))='AVAILABLE') AS available_count
             FROM varieties_list v
             ORDER BY v.name ASC,v.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $receivingBinTypes = $pdo->query(
            "SELECT bt.id,bt.name,
                    (SELECT COUNT(*) FROM bins_ingresso bi WHERE bi.type_id=bt.id) AS history_count,
                    (SELECT COUNT(*) FROM bins_ingresso bi WHERE bi.type_id=bt.id AND UPPER(COALESCE(bi.status,''))='AVAILABLE') AS available_count
             FROM bin_types_list bt
             ORDER BY bt.name ASC,bt.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $receivingCarriers = $pdo->query(
            "SELECT c.id,c.name,
                    (SELECT COUNT(*) FROM empty_bins eb
                     WHERE LOWER(TRIM(COALESCE(eb.carrier,'')))=LOWER(TRIM(c.name))) AS history_count,
                    0 AS available_count
             FROM carriers_list c
             ORDER BY c.name ASC,c.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $receivingPresetError = $e->getMessage();
        $receivingPresetsReady = false;
    }
}

if ($packagingPresetsReady && isset($ordersDb) && $ordersDb instanceof PDO) {
    try {
        $orderLocationPresets['pick'] = $ordersDb->query(
            "SELECT p.id,p.label,
                    (SELECT COUNT(*) FROM orders o WHERE o.pick_location=p.label) AS usage_count
             FROM order_pick_presets p ORDER BY p.label ASC,p.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $orderLocationPresets['city'] = $ordersDb->query(
            "SELECT p.id,p.city_label,p.ship_to_address,
                    (SELECT COUNT(*) FROM orders o WHERE o.dest_city=p.city_label) AS usage_count
             FROM order_city_presets p ORDER BY p.city_label ASC,p.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $packagingPresets = $ordersDb->query(
            "SELECT
                p.id,
                p.label,
                COALESCE(p.weight_lbs,0) AS weight_lbs,
                COALESCE(p.sort_order,0) AS sort_order,
                (SELECT COUNT(*)
                   FROM order_lines ol
                  WHERE ol.packaging_preset=p.label) AS usage_count
             FROM order_pack_presets p
             ORDER BY p.label ASC,p.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $packagingPresetError = $e->getMessage();
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-4">

  <h2 class="mb-4">System Settings</h2>

  <!-- ========================= EMAIL ========================= -->
  
<style>
.bl-live-dot{width:12px;height:12px;border-radius:50%;display:inline-block;background:#6c757d;box-shadow:0 0 0 3px rgba(108,117,125,.15)}
.bl-live-dot.is-online{background:#198754;box-shadow:0 0 0 3px rgba(25,135,84,.18)}
.bl-live-dot.is-offline{background:#dc3545;box-shadow:0 0 0 3px rgba(220,53,69,.18)}
.bl-live-dot-lg{width:18px;height:18px}
.bl-status-box{border:1px solid #dee2e6;border-radius:.5rem;padding:.75rem 1rem;height:100%;background:#f8f9fa}
.bl-status-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:#6c757d;font-weight:700}
.bl-status-value{font-weight:700;margin-top:.2rem}

.bl-main-status-box{
  border:1px solid #dee2e6;
  border-radius:.65rem;
  padding:1rem 1.1rem;
  background:#f8f9fa;
  height:100%;
}
.bl-main-status-label{
  color:#6c757d;
  text-transform:uppercase;
  letter-spacing:.04em;
  font-size:.72rem;
  font-weight:700;
}
.bl-main-status-value{
  font-size:1.05rem;
  font-weight:800;
  margin:.25rem 0;
}
</style>
<div class="card mb-4 shadow-sm" id="email-section">
    <div class="card-header bg-primary text-white"><strong>Email / SMTP settings</strong></div>
    <div class="card-body">
      <?php if ($emailFlash): ?>
        <div class="alert alert-info"><?= sp_h($emailFlash) ?></div>
      <?php endif; ?>

      <form method="post" class="mb-4">
        <input type="hidden" name="email_action" value="save_smtp">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">SMTP host</label>
            <input type="text" class="form-control" name="smtp_host" value="<?= sp_h($emailCfg['smtp_host']) ?>" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Port</label>
            <input type="number" class="form-control" name="smtp_port" value="<?= (int)$emailCfg['smtp_port'] ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Username</label>
            <input type="text" class="form-control" name="smtp_user" value="<?= sp_h($emailCfg['smtp_user']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Password</label>
            <input type="password" class="form-control" name="smtp_pass" value="***MASKED***">
          </div>
          <div class="col-md-4">
            <label class="form-label">From email</label>
            <input type="email" class="form-control" name="from_email" value="<?= sp_h($emailCfg['from_email']) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">From name</label>
            <input type="text" class="form-control" name="from_name" value="<?= sp_h($emailCfg['from_name']) ?>">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="use_tls" value="1" id="use_tls" <?= !empty($emailCfg['use_tls']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="use_tls">Use TLS</label>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex justify-content-between">
          <button type="submit" class="btn btn-primary">Save SMTP settings</button>
          <div class="d-flex gap-2">
            <input type="email" class="form-control form-control-sm" name="test_to" placeholder="test recipient email">
            <button type="submit" class="btn btn-outline-secondary btn-sm" name="email_action" value="test_smtp">Send test</button>
          </div>
        </div>
      </form>

      <h5>Email recipients</h5>
      <?php if ($recipFlash): ?>
        <div class="alert alert-info"><?= sp_h($recipFlash) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="email_action" value="save_recipients">
        <div class="mb-3">
          <label class="form-label">Critical alerts (comma separated)</label>
          <textarea class="form-control" name="recip_critical" rows="2"><?= sp_h(implode(', ', $emailRecipients['critical'] ?? [])) ?></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Warnings (comma separated)</label>
          <textarea class="form-control" name="recip_warnings" rows="2"><?= sp_h(implode(', ', $emailRecipients['warnings'] ?? [])) ?></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Reports (comma separated)</label>
          <textarea class="form-control" name="recip_reports" rows="2"><?= sp_h(implode(', ', $emailRecipients['reports'] ?? [])) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Save recipients</button>
      </form>
    </div>
  </div>

  <!-- ========================= ALERTS + AUTO-REPORT ========================= -->
  <div class="card mb-4 shadow-sm border-success" id="alerts-section">
    <div class="card-header bg-success text-white d-flex align-items-center gap-2">
      <strong>📧 Production Alerts &amp; Auto-Report</strong>
    </div>
    <div class="card-body">
      <?php if ($alertsFlash): ?>
        <div class="alert alert-success"><?= sp_h($alertsFlash) ?></div>
      <?php endif; ?>

      <div class="row g-4">

        <!-- Inactivity alerts -->
        <div class="col-lg-6">
          <h6 class="fw-bold text-secondary text-uppercase small mb-3">⚠ Inactivity Alerts</h6>
          <form method="post">
            <input type="hidden" name="alerts_action" value="save_alerts">
            <div class="row g-3 align-items-end">
              <div class="col-12">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" role="switch"
                         name="alerts_enabled" value="1" id="alerts_enabled"
                         <?= !empty($alertsCfg['enabled']) ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="alerts_enabled">
                    Enable inactivity alerts
                  </label>
                  <div class="form-text">Send alert if no scan for N minutes.</div>
                </div>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Timeout (minutes)</label>
                <input type="number" name="timeout_minutes" class="form-control form-control-sm"
                       value="<?= (int)($alertsCfg['timeout_minutes'] ?? 60) ?>">
              </div>
              <div class="col-6 text-end">
                <button type="submit" class="btn btn-success btn-sm">Save</button>
              </div>
            </div>
          </form>
        </div>

        <!-- Auto-report -->
        <div class="col-lg-6">
          <h6 class="fw-bold text-secondary text-uppercase small mb-3">📊 Auto Production Report</h6>
          <?php
          $arCfg = $alertsCfg;
          $recipFile = __DIR__ . '/../config/email_recipients.json';
          $recipRaw  = [];
          if (is_file($recipFile)) {
              $tmp = json_decode(@file_get_contents($recipFile), true);
              if (is_array($tmp)) $recipRaw = $tmp;
          }
          $arRecipList = $recipRaw['reports'] ?? $recipRaw['to'] ?? [];
          ?>
          <form method="post" id="arForm">
            <input type="hidden" name="alerts_action" value="save_auto_report">
            <div class="row g-3">
              <div class="col-12">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" role="switch"
                         name="auto_report_enabled" value="1" id="ar_enabled"
                         <?= !empty($arCfg['auto_report_enabled']) ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="ar_enabled">
                    Enable auto-report
                  </label>
                  <div class="form-text">Send report automatically N minutes after the last barcode scan.</div>
                </div>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Delay (minutes)</label>
                <input type="number" name="auto_report_delay" class="form-control form-control-sm"
                       min="1" max="480"
                       value="<?= (int)($arCfg['auto_report_delay'] ?? 60) ?>">
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold">Report recipients <span class="text-muted">(comma-separated)</span></label>
                <input type="text" name="report_recipients" class="form-control form-control-sm"
                       placeholder="email1@company.com, email2@company.com"
                       value="<?= sp_h(implode(', ', $arRecipList)) ?>">
              </div>
              <div class="col-12 d-flex justify-content-between align-items-center">
                <button type="submit" class="btn btn-success btn-sm">Save auto-report settings</button>
                <button type="button" class="btn btn-outline-success btn-sm" id="sendTestReportBtn"
                        onclick="sendTestReport(this)">
                  📧 Send Report Now
                </button>
              </div>
              <?php if (!empty($arCfg['last_report_sent_at'])): ?>
              <div class="col-12">
                <div class="alert alert-light border py-1 px-3 mb-0 small">
                  Last report sent: <strong><?= sp_h($arCfg['last_report_sent_at']) ?></strong>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </form>
        </div>

      </div><!-- /row -->
    </div><!-- /card-body -->
  </div>

  <script>
  function sendTestReport(btn) {
      const orig = btn.innerHTML;
      btn.disabled = true; btn.innerHTML = '⏳ Sending…';
      fetch('production_report_api.php?action=send_now')
          .then(r => r.json()).then(d => {
              alert((d.ok ? '✅ ' : '❌ ') + d.message);
          })
          .catch(() => alert('❌ Request failed.'))
          .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
  }
  </script>

  <!-- ========================= BARCODE LISTENER ========================= -->
  <div class="card mb-4 shadow-sm border-primary" id="keyence-section">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
      <strong>Barcode Listener — Keyence + USB HID</strong>
      <span class="d-inline-flex align-items-center gap-2">
        <span id="blLiveDot" class="bl-live-dot <?= $listener_running ? 'is-online' : 'is-offline' ?>"></span>
        <strong id="blLiveText"><?= $listener_running ? 'ONLINE' : 'OFFLINE' ?></strong>
      </span>
    </div>

    <div class="card-body">
      <?php if ($logFlash): ?><div class="alert alert-info py-2 mb-3"><?= sp_h($logFlash) ?></div><?php endif; ?>

      <div id="blInstallWarning" class="alert alert-warning d-none mb-3">
        <strong>Listener not installed.</strong>
        Run once as Administrator:
        <code>C:\xampp\htdocs\smproduce\barcode_listener\install_barcode_listener.ps1</code>
      </div>

      <div id="blActionMessage" class="alert py-2 d-none mb-3" role="status" aria-live="polite"></div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <div class="bl-main-status-box">
            <div class="bl-main-status-label">Keyence TCP</div>
            <div id="blKeyenceSummary" class="bl-main-status-value">Checking…</div>
            <div class="small text-muted">Listener port: <strong>9004</strong></div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="bl-main-status-box">
            <div class="bl-main-status-label">USB HID Handheld</div>
            <div id="blHidSummary" class="bl-main-status-value">Checking…</div>
            <div class="small text-muted">Background Raw Input listener</div>
          </div>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2 align-items-center">
        <button type="button" id="blStartBtn" class="btn btn-success">▶ Start</button>
        <button type="button" id="blStopBtn" class="btn btn-danger">■ Stop</button>
        <button type="button" id="blRestartBtn" class="btn btn-warning">↻ Restart</button>
        <button type="button" id="blDetailsBtn" class="btn btn-outline-primary"
                data-bs-toggle="modal" data-bs-target="#barcodeListenerModal">
          Status details
        </button>

        <span id="blLiveExtra" class="small text-muted ms-md-auto"></span>
      </div>

      <div class="mt-3">
        <button type="button" class="btn btn-outline-dark btn-sm"
                data-bs-toggle="collapse" data-bs-target="#collapseListenerLog" aria-expanded="false">
          Listener Log
        </button>
      </div>

      <div class="collapse" id="collapseListenerLog">
        <div class="d-flex justify-content-between align-items-center mt-3">
          <label class="form-label fw-semibold mb-0">Recent listener events</label>
          <form method="post" class="m-0">
            <button type="submit" name="clear_listener_log" value="1"
                    class="btn btn-outline-danger btn-sm"
                    onclick="return confirm('Clear listener log?');">Clear log</button>
          </form>
        </div>
        <pre class="bg-light p-3 rounded mt-2"
             style="min-height:70px;max-height:320px;overflow:auto;white-space:pre-wrap;"><?= sp_h($listener_log_txt) ?></pre>
      </div>
    </div>
  </div>

  <div class="modal fade" id="barcodeListenerModal" tabindex="-1" aria-labelledby="barcodeListenerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="barcodeListenerModalLabel">Barcode Listener Status</h5>
            <div class="small text-muted">Keyence TCP + USB HID handheld</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex align-items-center gap-3 mb-3">
            <span id="blModalDot" class="bl-live-dot bl-live-dot-lg"></span>
            <div>
              <div id="blModalStatus" class="fs-4 fw-bold">Checking…</div>
              <div id="blModalUpdated" class="small text-muted"></div>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-sm-6 col-lg-4"><div class="bl-status-box"><div class="bl-status-label">Windows PID</div><div id="blModalPid" class="bl-status-value">—</div></div></div>
            <div class="col-sm-6 col-lg-4"><div class="bl-status-box"><div class="bl-status-label">Scheduled Task</div><div id="blModalTask" class="bl-status-value">—</div></div></div>
            <div class="col-sm-6 col-lg-4"><div class="bl-status-box"><div class="bl-status-label">Keyence TCP</div><div id="blModalKeyence" class="bl-status-value">—</div></div></div>
            <div class="col-sm-6 col-lg-4"><div class="bl-status-box"><div class="bl-status-label">USB HID</div><div id="blModalHid" class="bl-status-value">—</div></div></div>
            <div class="col-sm-6 col-lg-8"><div class="bl-status-box"><div class="bl-status-label">Last production scan</div><div id="blModalLastScan" class="bl-status-value text-break">—</div></div></div>
          </div>
          <div id="blModalErrorWrap" class="alert alert-danger mt-3 d-none"><strong>Last error:</strong> <span id="blModalError"></span></div>
        </div>
        <div class="modal-footer justify-content-between">
          <div class="small text-muted">Status refreshes automatically every 2 seconds.</div>
          <div class="d-flex gap-2">
            <button type="button" id="blModalStartBtn" class="btn btn-success btn-sm">▶ Start</button>
            <button type="button" id="blModalStopBtn" class="btn btn-danger btn-sm">■ Stop</button>
            <button type="button" id="blModalRestartBtn" class="btn btn-warning btn-sm">↻ Restart</button>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ========================= PRINT AGENT ========================= -->
  <div class="card mb-4 shadow-sm border-dark" id="print-agent-section">
    <div class="card-header bg-dark text-white"><strong>Print Agent (SMProducePrintAgent)</strong></div>
    <div class="card-body">

      <div class="row mb-3 text-center">
        <div class="col-md-4 mb-2">
          <h6 class="mb-1">Background listener</h6>
          <?php if ($print_agent_running): ?>
            <span class="badge bg-success px-3 py-2">RUNNING</span>
          <?php elseif ($print_agent_stopped): ?>
            <span class="badge bg-danger px-3 py-2">STOPPED</span>
          <?php else: ?>
            <span class="badge bg-secondary px-3 py-2">UNKNOWN</span>
          <?php endif; ?>
          <div class="small text-muted mt-1"><code><?= sp_h($print_agent_service) ?></code></div>
        </div>
        <div class="col-md-4 mb-2">
          <h6 class="mb-1">Auto start (NSSM)</h6>
          <span class="badge bg-info px-3 py-2">Managed by NSSM</span>
          <div class="small text-muted mt-1">Use <code>nssm edit <?= sp_h($print_agent_service) ?></code> if you need to change executable or working dir.</div>
        </div>
        <div class="col-md-4 mb-2">
          <h6 class="mb-1">Last status raw</h6>
          <button class="btn btn-outline-dark btn-sm" type="button"
                  data-bs-toggle="collapse" data-bs-target="#collapsePrintAgentRaw"
                  aria-expanded="false" aria-controls="collapsePrintAgentRaw">
            Toggle service output
          </button>
        </div>
      </div>

      <form method="post" class="mb-3 d-flex flex-wrap gap-2 justify-content-center">
        <button name="print_agent_action" value="start" class="btn btn-success btn-sm">Start agent</button>
        <button name="print_agent_action" value="stop" class="btn btn-danger btn-sm">Stop agent</button>
        <button name="print_agent_action" value="restart" class="btn btn-warning btn-sm">Restart agent</button>
        <button name="print_agent_action" value="status" class="btn btn-outline-primary btn-sm">Query status</button>
      </form>

      <div class="collapse" id="collapsePrintAgentRaw">
        <label class="form-label fw-semibold mt-2">sc query output</label>
        <pre class="bg-light p-3 rounded" style="min-height:70px; white-space:pre-wrap;"><?= sp_h($print_agent_raw ?: 'Ready.') ?></pre>
      </div>

      <div class="text-muted small mt-3">
        The Print Agent polls <code>print_jobs</code> and sends ZPL to the label printers linked to exits.
        Use <strong>Case Label Settings → UNITEC</strong> to configure exits, SKUs, templates and default printers.
      </div>

    </div>
  </div>

  <!-- ========================= CLOUDFLARE ========================= -->
  <div class="card mb-5 shadow-sm border-success" id="cloudflare-section">
    <div class="card-header bg-success text-white"><strong>Cloudflare Tunnel</strong></div>
    <div class="card-body text-center">

      <div class="mb-3">
        <h6 class="mb-1">Cloudflared service</h6>
        <?php if ($cloud_running): ?><span class="badge bg-success px-3 py-2">Active</span>
        <?php elseif ($cloud_stopped): ?><span class="badge bg-danger px-3 py-2">Stopped</span>
        <?php else: ?><span class="badge bg-secondary px-3 py-2">Unknown</span><?php endif; ?>
        <div class="small text-muted mt-1"><code><?= sp_h($cloud_service) ?></code></div>
      </div>

      <form method="post" class="mb-3 d-flex flex-wrap gap-2 justify-content-center">
        <button name="cloud_action" value="start" class="btn btn-success btn-sm">Start tunnel</button>
        <button name="cloud_action" value="stop" class="btn btn-danger btn-sm">Stop tunnel</button>
        <button name="cloud_action" value="restart" class="btn btn-warning btn-sm">Restart tunnel</button>
        <button name="cloud_action" value="status" class="btn btn-outline-primary btn-sm">Query status</button>
      </form>

      <div class="collapse" id="collapseCloudConsole">
        <label class="form-label fw-semibold">Cloudflared console output</label>
        <pre class="bg-dark text-light p-3 rounded" style="min-height:70px; max-height:320px; overflow:auto; white-space:pre-wrap;"><?= sp_h($cloud_raw ?: 'Ready.') ?></pre>
      </div>

      <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCloudConsole" aria-expanded="false">
        Toggle Cloudflared console output
      </button>

    </div>
  </div>



  <!-- ========================= RECEIVING PRESETS ========================= -->
  <div class="card mb-5 shadow-sm border-success" id="receiving-presets-section">
    <div class="card-header bg-success text-white">
      <strong>🍎 Receiving Presets</strong>
      <div class="small opacity-75">
        Central management for Growers, Varieties, Bin Types and Carriers used by Receiving
      </div>
    </div>

    <div class="card-body">

      <?php if ($receivingPresetFlash): ?>
        <div class="alert alert-info py-2"><?= sp_h($receivingPresetFlash) ?></div>
      <?php endif; ?>

      <?php if (!$receivingPresetsReady): ?>
        <div class="alert alert-danger mb-0">
          Receiving Presets are unavailable.
          <?= $receivingPresetError ? '<div class="small mt-1">'.sp_h($receivingPresetError).'</div>' : '' ?>
        </div>
      <?php else: ?>

        <div class="alert alert-light border mb-4">
          You can add, rename and delete presets here.
          <strong>The Add buttons in Full Bin Receiving and the other operational pages remain available.</strong>
          Grower, Variety and Bin Type keep their existing Full Bin behavior.
          Carrier presets are used by Empty Bin Receiving and are kept synchronized when renamed.
          <strong>Available now</strong> applies to Full Bin presets.
          <strong>History</strong> shows how many historical records use each preset.
          Delete is blocked when History is greater than zero.
        </div>

        <div class="row g-4">

          <?php
          $presetSections = [
              [
                  'kind'=>'grower',
                  'title'=>'Growers',
                  'icon'=>'👨‍🌾',
                  'rows'=>$receivingGrowers,
                  'placeholder'=>'Example: Sunshine Orchards'
              ],
              [
                  'kind'=>'variety',
                  'title'=>'Varieties',
                  'icon'=>'🍒',
                  'rows'=>$receivingVarieties,
                  'placeholder'=>'Example: Sweet Cherry'
              ],
              [
                  'kind'=>'bin_type',
                  'title'=>'Bin Types',
                  'icon'=>'📦',
                  'rows'=>$receivingBinTypes,
                  'placeholder'=>'Example: Wood'
              ],
              [
                  'kind'=>'carrier',
                  'title'=>'Carriers',
                  'icon'=>'🚚',
                  'rows'=>$receivingCarriers,
                  'placeholder'=>'Example: ABC Transport'
              ],
          ];
          ?>

          <?php foreach ($presetSections as $sec): ?>
            <div class="col-xl-3 col-lg-6">
              <div class="border rounded-3 h-100 overflow-hidden">
                <div class="bg-light border-bottom px-3 py-2 d-flex justify-content-between align-items-center">
                  <strong><?= sp_h($sec['icon'].' '.$sec['title']) ?></strong>
                  <span class="badge bg-secondary"><?= count($sec['rows']) ?></span>
                </div>

                <div class="p-3 border-bottom">
                  <form method="post" class="d-flex gap-2">
                    <input type="hidden" name="receiving_preset_action" value="save">
                    <input type="hidden" name="receiving_preset_kind" value="<?= sp_h($sec['kind']) ?>">
                    <input type="text"
                           name="receiving_preset_name"
                           class="form-control form-control-sm"
                           maxlength="100"
                           required
                           placeholder="<?= sp_h($sec['placeholder']) ?>">
                    <button class="btn btn-sm btn-success" type="submit">Add</button>
                  </form>
                </div>

                <div class="p-2" style="max-height:430px;overflow:auto;">
                  <?php if (!$sec['rows']): ?>
                    <div class="text-muted text-center py-4 small">No presets configured.</div>
                  <?php endif; ?>

                  <?php foreach ($sec['rows'] as $row): ?>
                    <?php
                      $history=(int)($row['history_count']??0);
                      $available=(int)($row['available_count']??0);
                    ?>
                    <div class="border rounded-2 p-2 mb-2">
                      <form method="post" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="receiving_preset_action" value="save">
                        <input type="hidden" name="receiving_preset_kind" value="<?= sp_h($sec['kind']) ?>">
                        <input type="hidden" name="receiving_preset_id" value="<?= (int)$row['id'] ?>">

                        <input type="text"
                               name="receiving_preset_name"
                               class="form-control form-control-sm fw-semibold"
                               maxlength="100"
                               required
                               value="<?= sp_h($row['name']) ?>">

                        <button class="btn btn-sm btn-outline-primary" type="submit" title="Rename / Save">
                          Save
                        </button>
                      </form>

                      <div class="d-flex justify-content-between align-items-center mt-2 gap-2 flex-wrap">
                        <div class="small">
                          <span class="badge bg-success-subtle text-success border border-success-subtle me-1">
                            Available now: <?= $available ?>
                          </span>
                          <span class="badge bg-secondary-subtle text-secondary border">
                            History: <?= $history ?>
                          </span>
                        </div>

                        <form method="post"
                              onsubmit="return confirm('Delete <?= sp_h(addslashes($sec['title'])) ?> preset <?= sp_h(addslashes($row['name'])) ?>?');">
                          <input type="hidden" name="receiving_preset_action" value="delete">
                          <input type="hidden" name="receiving_preset_kind" value="<?= sp_h($sec['kind']) ?>">
                          <input type="hidden" name="receiving_preset_id" value="<?= (int)$row['id'] ?>">

                          <button type="submit"
                                  class="btn btn-sm btn-outline-danger"
                                  <?= $history>0?'disabled title="Preset is referenced by Full Bin history"':'' ?>>
                            Delete
                          </button>
                        </form>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

              </div>
            </div>
          <?php endforeach; ?>

        </div>

        <div class="small text-muted mt-3">
          These are the same database tables already used by <code>bins_ingresso.php</code>:
          <code>growers_list</code>, <code>varieties_list</code>, <code>bin_types_list</code> and <code>carriers_list</code>.
          This page is the central management screen; the existing <strong>+ New</strong> quick-add controls remain available in Receiving pages.
        </div>

      <?php endif; ?>
    </div>
  </div>


  <!-- ========================= ORDER LOCATION PRESETS ========================= -->
  <div class="card mb-5 shadow-sm border-info" id="order-location-presets-section">
    <div class="card-header bg-info text-dark">
      <strong>🚚 Order Location Presets</strong>
      <div class="small">Central management for Pick Up Locations and Destination Cities used by New Order</div>
    </div>
    <div class="card-body">
      <?php if ($orderLocationPresetFlash): ?>
        <div class="alert alert-info py-2"><?= sp_h($orderLocationPresetFlash) ?></div>
      <?php endif; ?>

      <?php if (!$packagingPresetsReady): ?>
        <div class="alert alert-danger mb-0">Orders database is not available.</div>
      <?php else: ?>
        <div class="row g-4">
          <div class="col-xl-5">
            <div class="border rounded-3 p-3 h-100">
              <h5>Pick Up Locations</h5>
              <form method="post" class="row g-2 mb-3">
                <input type="hidden" name="order_location_preset_action" value="save">
                <input type="hidden" name="order_location_preset_kind" value="pick">
                <div class="col-9"><input type="text" name="order_location_preset_label" class="form-control" maxlength="200" placeholder="New location" required></div>
                <div class="col-3 d-grid"><button class="btn btn-info" type="submit">Add</button></div>
              </form>
              <?php if (!$orderLocationPresets['pick']): ?>
                <div class="text-muted small py-3 text-center">No Pick Up Locations configured.</div>
              <?php endif; ?>
              <?php foreach ($orderLocationPresets['pick'] as $pp): ?>
                <div class="border-top py-2">
                  <form method="post" class="row g-2 align-items-center">
                    <input type="hidden" name="order_location_preset_action" value="save">
                    <input type="hidden" name="order_location_preset_kind" value="pick">
                    <input type="hidden" name="order_location_preset_id" value="<?= (int)$pp['id'] ?>">
                    <div class="col"><input type="text" name="order_location_preset_label" class="form-control form-control-sm" maxlength="200" value="<?= sp_h($pp['label']) ?>" required></div>
                    <div class="col-auto"><button class="btn btn-sm btn-outline-primary" type="submit">Save</button></div>
                  </form>
                  <form method="post" class="text-end mt-1" onsubmit="return confirm('Delete Pick Up Location <?= sp_h(addslashes($pp['label'])) ?>?');">
                    <input type="hidden" name="order_location_preset_action" value="delete">
                    <input type="hidden" name="order_location_preset_kind" value="pick">
                    <input type="hidden" name="order_location_preset_id" value="<?= (int)$pp['id'] ?>">
                    <button class="btn btn-sm btn-link text-danger p-0" type="submit" <?= (int)$pp['usage_count']>0?'disabled title="Preset is used by existing orders"':'' ?>>Delete</button>
                    <span class="small text-muted ms-2"><?= (int)$pp['usage_count'] ?> orders</span>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="col-xl-7">
            <div class="border rounded-3 p-3 h-100">
              <h5>Destination Cities / Ship To</h5>
              <form method="post" class="row g-2 mb-3">
                <input type="hidden" name="order_location_preset_action" value="save">
                <input type="hidden" name="order_location_preset_kind" value="city">
                <div class="col-md-4"><input type="text" name="order_location_preset_label" class="form-control" maxlength="200" placeholder="City / destination" required></div>
                <div class="col-md-6"><textarea name="order_location_preset_address" class="form-control" rows="1" placeholder="Ship To address"></textarea></div>
                <div class="col-md-2 d-grid"><button class="btn btn-info" type="submit">Add</button></div>
              </form>
              <?php if (!$orderLocationPresets['city']): ?>
                <div class="text-muted small py-3 text-center">No Destination Cities configured.</div>
              <?php endif; ?>
              <?php foreach ($orderLocationPresets['city'] as $cp): ?>
                <div class="border-top py-2">
                  <form method="post" class="row g-2 align-items-start">
                    <input type="hidden" name="order_location_preset_action" value="save">
                    <input type="hidden" name="order_location_preset_kind" value="city">
                    <input type="hidden" name="order_location_preset_id" value="<?= (int)$cp['id'] ?>">
                    <div class="col-md-4"><input type="text" name="order_location_preset_label" class="form-control form-control-sm" maxlength="200" value="<?= sp_h($cp['city_label']) ?>" required></div>
                    <div class="col-md-6"><textarea name="order_location_preset_address" class="form-control form-control-sm" rows="2"><?= sp_h($cp['ship_to_address']) ?></textarea></div>
                    <div class="col-md-2 d-grid"><button class="btn btn-sm btn-outline-primary" type="submit">Save</button></div>
                  </form>
                  <form method="post" class="text-end mt-1" onsubmit="return confirm('Delete Destination City <?= sp_h(addslashes($cp['city_label'])) ?>?');">
                    <input type="hidden" name="order_location_preset_action" value="delete">
                    <input type="hidden" name="order_location_preset_kind" value="city">
                    <input type="hidden" name="order_location_preset_id" value="<?= (int)$cp['id'] ?>">
                    <button class="btn btn-sm btn-link text-danger p-0" type="submit" <?= (int)$cp['usage_count']>0?'disabled title="Preset is used by existing orders"':'' ?>>Delete</button>
                    <span class="small text-muted ms-2"><?= (int)$cp['usage_count'] ?> orders</span>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="small text-muted mt-3">This is the only management screen for these presets. The <strong>+ New</strong> quick-add buttons remain available in New Order.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ========================= ORDER PACKAGING PRESETS ========================= -->
  <div class="card mb-5 shadow-sm border-primary" id="packaging-presets-section">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <strong>📦 Order Packaging Presets</strong>
        <div class="small opacity-75">Packaging choices used by Orders and BOL weight calculation</div>
      </div>
      <?php if ($packagingPresetsReady): ?>
        <button type="button" class="btn btn-light btn-sm fw-semibold"
                data-bs-toggle="collapse" data-bs-target="#newPackagingPreset">
          + Add Packaging Preset
        </button>
      <?php endif; ?>
    </div>

    <div class="card-body">

      <?php if ($packagingPresetFlash): ?>
        <div class="alert alert-info py-2"><?= sp_h($packagingPresetFlash) ?></div>
      <?php endif; ?>

      <?php if (!$packagingPresetsReady): ?>
        <div class="alert alert-danger mb-0">
          Orders database is not available.
          <?= $packagingPresetError ? '<div class="small mt-1">'.sp_h($packagingPresetError).'</div>' : '' ?>
        </div>
      <?php else: ?>

        <div class="alert alert-light border mb-3">
          <strong>PACK on the BOL</strong> uses the Packaging Preset selected on the order.
          <strong>Weight / Case</strong> is multiplied by the real CTNS in the shipment to calculate BOL Weight.
        </div>

        <div class="collapse mb-4" id="newPackagingPreset">
          <div class="border rounded-3 p-3 bg-light">
            <form method="post" class="row g-3 align-items-end">
              <input type="hidden" name="packaging_preset_action" value="save">

              <div class="col-lg-7">
                <label class="form-label fw-semibold">Packaging</label>
                <input type="text" name="preset_label" class="form-control"
                       maxlength="200" required
                       placeholder="Example: 5 lb Bag">
              </div>

              <div class="col-lg-3">
                <label class="form-label fw-semibold">Weight / Case (lbs)</label>
                <input type="number" name="preset_weight_lbs"
                       class="form-control" min="0" step="0.01" value="0.00">
              </div>

              <div class="col-lg-2 d-grid">
                <button class="btn btn-primary" type="submit">Add</button>
              </div>
            </form>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Packaging</th>
                <th style="width:220px">Weight / Case (lbs)</th>
                <th class="text-end" style="width:185px">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$packagingPresets): ?>
              <tr>
                <td colspan="3" class="text-center text-muted py-4">
                  No Packaging Presets configured.
                </td>
              </tr>
            <?php endif; ?>

            <?php foreach ($packagingPresets as $pp): ?>
              <tr>
                <form method="post">
                  <input type="hidden" name="packaging_preset_action" value="save">
                  <input type="hidden" name="preset_id" value="<?= (int)$pp['id'] ?>">

                  <td>
                    <input type="text" name="preset_label"
                           class="form-control form-control-sm fw-semibold"
                           maxlength="200" required
                           value="<?= sp_h($pp['label']) ?>">
                  </td>

                  <td>
                    <input type="number" name="preset_weight_lbs"
                           class="form-control form-control-sm"
                           min="0" step="0.01"
                           value="<?= number_format((float)$pp['weight_lbs'],2,'.','') ?>">
                  </td>
                  <?php $used=(int)$pp['usage_count']; ?>

                  <td class="text-end">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                      Save
                    </button>
                </form>

                    <form method="post" class="d-inline"
                          onsubmit="return confirm('Delete Packaging Preset <?= sp_h(addslashes($pp['label'])) ?>?');">
                      <input type="hidden" name="packaging_preset_action" value="delete">
                      <input type="hidden" name="preset_id" value="<?= (int)$pp['id'] ?>">
                      <button type="submit"
                              class="btn btn-sm btn-outline-danger"
                              <?= $used>0?'disabled title="Preset is used by existing orders"':'' ?>>
                        Delete
                      </button>
                    </form>
                  </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="small text-muted mt-3">
          Presets are listed alphabetically. Renaming a preset updates existing
          <code>order_lines.packaging_preset</code> records automatically.
          The system still prevents deletion of a preset that is already used by an order.
        </div>

      <?php endif; ?>
    </div>
  </div>

</div>

<!-- MODALS: show DOS outputs after actions (auto-open when flash exists) -->
<div class="modal fade" id="dosModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="dosModalTitle">Console Output</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre class="bg-dark text-light p-3 rounded" style="white-space:pre-wrap;" id="dosModalBody"></pre>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // Auto-open modal if we have console output from last action
  const keyenceOut = <?= json_encode($keyenceConsoleFlash) ?>;
  const cloudOut   = <?= json_encode($cloudConsoleFlash) ?>;
  const printAgentOut = <?= json_encode($printAgentConsoleFlash) ?>;

  function showModal(title, body){
    const el = document.getElementById('dosModal');
    const t  = document.getElementById('dosModalTitle');
    const b  = document.getElementById('dosModalBody');
    if(!el || !t || !b) return;
    t.textContent = title;
    b.textContent = body || 'Done.';
    try{
      const m = new bootstrap.Modal(el);
      m.show();
    }catch(e){}
  }

  if (keyenceOut && keyenceOut.trim() !== '') {
    showModal('Keyence Listener Service Output', keyenceOut);
  } else if (cloudOut && cloudOut.trim() !== '') {
    showModal('Cloudflared Service Output', cloudOut);
  } else if (printAgentOut && printAgentOut.trim() !== '') {
    showModal('Print Agent Service Output', printAgentOut);
  }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
(function(){
  const endpoint='listener_control.php';
  let busy=false;
  const el=id=>document.getElementById(id);
  const text=(id,v)=>{const n=el(id);if(n)n.textContent=v};
  function setDot(id,on){const n=el(id);if(!n)return;n.classList.toggle('is-online',!!on);n.classList.toggle('is-offline',!on)}
  function fmtAge(sec){if(sec===null||sec===undefined)return'';sec=Number(sec);if(sec<5)return'updated now';if(sec<60)return`updated ${sec}s ago`;return`updated ${Math.floor(sec/60)}m ago`}
  function render(s){
    const on=!!s.running;
    setDot('blLiveDot',on);setDot('blModalDot',on);
    text('blLiveText',on?'ONLINE':'OFFLINE');
    text('blLiveExtra',on?`PID ${s.pid||'—'} · task ${s.task_state||'—'}`:(s.task_installed?'Listener stopped':'Windows task not installed'));
    text('blKeyenceSummary',
      s.keyence_enabled===false
        ? 'DISABLED'
        : (s.keyence_listening ? 'LISTENING' : (on ? 'NOT LISTENING' : 'OFFLINE'))
    );
    text('blHidSummary',s.hid_enabled?(on?'ACTIVE':'OFFLINE'):'DISABLED');
    const iw=el('blInstallWarning');
    if(iw) iw.classList.toggle('d-none',!!s.task_installed);
    text('blModalStatus',on?'ONLINE':'OFFLINE');
    text('blModalUpdated',[s.updated_at||'',fmtAge(s.status_age_seconds)].filter(Boolean).join(' · '));
    text('blModalPid',s.pid||'—');
    text('blModalTask',s.task_installed?(s.task_state||'INSTALLED'):'NOT INSTALLED');
    text('blModalKeyence',s.keyence_enabled===false?'DISABLED':`${s.keyence_listening?'LISTENING':(on?'NOT LISTENING':'OFFLINE')} · port ${s.keyence_port||9004}`);
    text('blModalHid',s.hid_enabled?(on?'ACTIVE':'OFFLINE'):'DISABLED');
    text('blModalLastScan',s.last_scan||'—');
    const badge=el('blMainStatusBadge');
    if(badge){badge.textContent=on?'ONLINE':'OFFLINE';badge.classList.toggle('bg-success',on);badge.classList.toggle('bg-danger',!on);badge.classList.remove('bg-secondary')}
    const ew=el('blModalErrorWrap');
    if(ew){const has=!!s.last_error;ew.classList.toggle('d-none',!has);text('blModalError',s.last_error||'')}
    ['blStartBtn','blModalStartBtn'].forEach(id=>{const b=el(id);if(b)b.disabled=busy||on});
    ['blStopBtn','blModalStopBtn'].forEach(id=>{const b=el(id);if(b)b.disabled=busy||!on});
    ['blRestartBtn','blModalRestartBtn'].forEach(id=>{const b=el(id);if(b)b.disabled=busy});
  }
  function showMessage(kind,msg){const b=el('blActionMessage');if(!b)return;b.className='alert py-2 alert-'+kind;b.textContent=msg}
  async function request(action='status'){
    const fd=new FormData();fd.append('action',action);
    const r=await fetch(endpoint,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store',body:fd});
    const raw=await r.text();let d;
    try{d=JSON.parse(raw)}catch(e){throw new Error(raw.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim()||'Invalid listener response')}
    if(!r.ok||d.ok===false)throw new Error(d.error||'Listener command failed');
    return d;
  }
  async function refresh(){if(busy)return;try{render(await request('status'))}catch(e){setDot('blLiveDot',false);setDot('blModalDot',false);text('blLiveText','STATUS ERROR')}}
  async function act(action){
    if(busy)return;busy=true;
    ['blStartBtn','blStopBtn','blRestartBtn','blModalStartBtn','blModalStopBtn','blModalRestartBtn'].forEach(id=>{const b=el(id);if(b)b.disabled=true});
    showMessage('info',(action==='start'?'Starting':action==='stop'?'Stopping':'Restarting')+' listener…');
    try{
      const d=await request(action);render(d);
      showMessage(d.running?'success':'warning',action==='stop'?'Barcode Listener is OFFLINE.':(d.running?'Barcode Listener is ONLINE.':'Listener command completed.'));
    }catch(e){showMessage('danger',e.message||'Listener command failed')}
    finally{busy=false;await refresh()}
  }
  [['blStartBtn','start'],['blStopBtn','stop'],['blRestartBtn','restart'],['blModalStartBtn','start'],['blModalStopBtn','stop'],['blModalRestartBtn','restart']].forEach(([id,a])=>el(id)?.addEventListener('click',()=>act(a)));
  el('barcodeListenerModal')?.addEventListener('shown.bs.modal',refresh);
  refresh();setInterval(refresh,2000);
})();
</script>
