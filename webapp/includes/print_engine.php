<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/label_engine.php';

$SMP_DB = $pdo ?: $conn;

function smp_db_exec($db,$sql,$params=[]){
    if($db instanceof PDO){
        $st=$db->prepare($sql);
        return $st->execute($params);
    }
    if($db instanceof mysqli){
        if(!$params) return $db->query($sql);
        $st=$db->prepare($sql);
        if(!$st) return false;
        $types=str_repeat('s',count($params));
        $st->bind_param($types,...$params);
        $ok=$st->execute();
        $st->close();
        return $ok;
    }
    return false;
}

/**
 * Like smp_db_exec but returns the number of rows actually inserted/updated/deleted.
 * Returns 0 when INSERT IGNORE is silently ignored (duplicate key).
 * Returns -1 on error.
 * Sets $errOut (passed by reference) to the DB error string when an error occurs.
 */
function smp_db_exec_rows($db, $sql, $params = [], &$errOut = null): int {
    try {
        if ($db instanceof PDO) {
            $st = $db->prepare($sql);
            $st->execute($params);
            return (int)$st->rowCount();
        }
        if ($db instanceof mysqli) {
            if (!$params) {
                $db->query($sql);
                if ($db->affected_rows < 0) {
                    $errOut = $db->error ?: 'mysqli query error';
                    return -1;
                }
                return (int)$db->affected_rows;
            }
            $st = $db->prepare($sql);
            if (!$st) {
                $errOut = $db->error ?: 'mysqli prepare failed';
                return -1;
            }
            $types = str_repeat('s', count($params));
            $st->bind_param($types, ...$params);
            $execOk = $st->execute();
            $rows = (int)$db->affected_rows;
            if (!$execOk || $rows < 0) {
                $errOut = $st->error ?: $db->error ?: 'mysqli execute error';
                $st->close();
                return -1;
            }
            $st->close();
            return $rows;
        }
    } catch (Throwable $e) {
        $errOut = $e->getMessage();
        return -1;
    }
    return -1;
}

function smp_ensure_tc26_tables($db){

    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS pallets (
        pallet_id VARCHAR(50) PRIMARY KEY,
        status VARCHAR(20) DEFAULT 'OPEN',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        closed_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS pallet_cases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pallet_id VARCHAR(50) NOT NULL,
        case_serial VARCHAR(50) NOT NULL,
        variety VARCHAR(120) NULL,
        grower VARCHAR(120) NULL,
        size VARCHAR(60) NULL,
        packaging VARCHAR(120) NULL,
        sku INT NULL,
        crop VARCHAR(120) NULL,
        scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_pallet_case (pallet_id, case_serial),
        KEY idx_pallet (pallet_id),
        KEY idx_case_serial (case_serial)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS shipments (
        shipment_id VARCHAR(50) PRIMARY KEY,
        status VARCHAR(20) DEFAULT 'OPEN',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        closed_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    smp_db_exec($db,"CREATE TABLE IF NOT EXISTS shipment_pallets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id VARCHAR(50),
        pallet_id VARCHAR(50),
        UNIQUE KEY uniq_ship (shipment_id,pallet_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Always run column migrations so extra columns (added_at, is_partial, etc.)
    // are present even if open_pallet/open_shipment hasn't been called yet.
    if (function_exists('smp_tc26_migrate_extra_columns')) {
        smp_tc26_migrate_extra_columns($db);
    }
}


if (!function_exists('smp_zpl_escape')) {
    function smp_zpl_escape(string $s): string
    {
        return str_replace(["\\", "^", "~"], [' ', ' ', ' '], trim($s));
    }
}

if (!function_exists('smp_fetch_bin_label_data')) {
    function smp_fetch_bin_label_data(mysqli $mysqli, int $binId): ?array
    {
        $sql = "SELECT bi.id, bi.group_id, bi.lot, bi.date,
                       bi.receipt_id, bi.batch_position, bi.batch_total,
                       gp.name AS grower,
                       vl.name AS variety,
                       tl.name AS type,
                       r.receiving_date,
                       r.created_at AS receipt_created_at
                FROM bins_ingresso bi
                LEFT JOIN growers_list gp   ON bi.grower_id = gp.id
                LEFT JOIN varieties_list vl ON bi.variety_id = vl.id
                LEFT JOIN bin_types_list tl ON bi.type_id = tl.id
                LEFT JOIN full_bin_receipts r ON r.id = bi.receipt_id
                WHERE bi.id = ?
                LIMIT 1";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $binId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return null;
        }

        $barcode = 'FBIN-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT);
        $barcodeNumeric = str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT);
        $batchPosition = max(1,(int)($row['batch_position'] ?? 1));
        $batchTotal = max($batchPosition,(int)($row['batch_total'] ?? 1));
        $dateAdded = trim((string)($row['receiving_date'] ?? ''));
        if ($dateAdded === '') $dateAdded = (string)($row['date'] ?? '');

        return [
            'id' => (int)$row['id'],
            'group_id' => isset($row['group_id']) ? (int)$row['group_id'] : null,
            'receipt_id' => isset($row['receipt_id']) ? (int)$row['receipt_id'] : null,

            // Full Bin barcode format used on label and in scans: FBIN-00123.
            'serial' => $barcode,
            'barcode' => $barcode,
            'barcode_print' => $barcode,

            // Numeric aliases remain available for legacy templates/tools.
            'barcode_numeric' => $barcodeNumeric,
            'barcode_number' => $barcodeNumeric,

            'grower' => (string)($row['grower'] ?? ''),
            'variety' => (string)($row['variety'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'bin_type' => (string)($row['type'] ?? ''),
            'lot' => (string)($row['lot'] ?? ''),

            'date' => $dateAdded,
            'date_added' => $dateAdded,
            'record_date' => $dateAdded,
            'inserted_at' => (string)($row['receipt_created_at'] ?? ''),

            'batch_position' => $batchPosition,
            'batch_total' => $batchTotal,
            'bin_order_current' => $batchPosition,
            'bin_order_total' => $batchTotal,
            'bin_order' => $batchPosition . ' of ' . $batchTotal,
        ];
    }
}

if (!function_exists('smp_default_bin_zpl')) {
    function smp_default_bin_zpl(array $data): string
    {
        $barcode = smp_zpl_escape((string)($data['barcode'] ?? ''));
        $grower  = smp_zpl_escape((string)($data['grower'] ?? ''));
        $variety = smp_zpl_escape((string)($data['variety'] ?? ''));
        $type    = smp_zpl_escape((string)($data['type'] ?? ''));
        $lot     = smp_zpl_escape((string)($data['lot'] ?? ''));
        $date    = smp_zpl_escape((string)($data['date'] ?? ''));

        $zpl  = "^XA\n";
        $zpl .= "^PW800\n";
        $zpl .= "^LL600\n";
        $zpl .= "^FO50,30^BCN,120,Y,N,N\n";
        $zpl .= "^FD{$barcode}^FS\n";
        $zpl .= "^FO50,170^A0N,40,40^FD{$barcode}^FS\n";

        $y = 220;
        $lineH = 30;
        if ($grower !== '') { $zpl .= "^FO50,{$y}^A0N,28,28^FDGrower: {$grower}^FS\n"; $y += $lineH; }
        if ($variety !== '') { $zpl .= "^FO50,{$y}^A0N,28,28^FDVariety: {$variety}^FS\n"; $y += $lineH; }
        if ($type !== '') { $zpl .= "^FO50,{$y}^A0N,28,28^FDType: {$type}^FS\n"; $y += $lineH; }
        if ($lot !== '') { $zpl .= "^FO50,{$y}^A0N,28,28^FDLot: {$lot}^FS\n"; $y += $lineH; }
        if ($date !== '') { $zpl .= "^FO50,{$y}^A0N,24,24^FDDate: {$date}^FS\n"; $y += $lineH; }
        $zpl .= "^XZ\n";
        return $zpl;
    }
}

if (!function_exists('smp_get_full_bin_template_by_id')) {
    function smp_get_full_bin_template_by_id(int $templateId): ?array
    {
        if ($templateId <= 0) return null;
        try {
            return le_db_fetch_one(
                "SELECT *
                 FROM print_templates
                 WHERE id=?
                   AND label_type='full_bins'
                   AND is_active=1
                 LIMIT 1",
                [$templateId]
            );
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('smp_get_full_bin_saved_settings')) {
    function smp_get_full_bin_saved_settings(mysqli $mysqli): array
    {
        try {
            $t=$mysqli->query("SHOW TABLES LIKE 'full_bin_print_settings'");
            if(!$t || $t->num_rows===0) return ['printer_id'=>0,'template_id'=>0];
            $r=$mysqli->query("SELECT label_printer_id,label_template_id FROM full_bin_print_settings WHERE id=1 LIMIT 1");
            $row=$r?$r->fetch_assoc():null;
            return [
                'printer_id'=>(int)($row['label_printer_id']??0),
                'template_id'=>(int)($row['label_template_id']??0),
            ];
        } catch(Throwable $e) {
            return ['printer_id'=>0,'template_id'=>0];
        }
    }
}

if (!function_exists('smp_get_printer_by_id')) {
    function smp_get_printer_by_id(int $printerId): ?array
    {
        if ($printerId <= 0) {
            return null;
        }
        try {
            return le_db_fetch_one(
                "SELECT * FROM printers_list WHERE id = ? AND active = 1 LIMIT 1",
                [$printerId]
            );
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('smp_get_default_active_printer')) {
    function smp_get_default_active_printer(): ?array
    {
        try {
            $row = le_db_fetch_one("SELECT * FROM printers_list WHERE active = 1 AND is_default = 1 ORDER BY id ASC LIMIT 1");
            if ($row) {
                return $row;
            }
            return le_db_fetch_one("SELECT * FROM printers_list WHERE active = 1 ORDER BY id ASC LIMIT 1");
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('smp_render_bin_label_zpl')) {
    function smp_render_bin_label_zpl(array $data, ?array $template): array
    {
        $templateId = null;
        $templateName = null;
        if ($template && !empty($template['id'])) {
            $templateId = (int)$template['id'];
            $templateName = (string)($template['name'] ?? '');
            try {
                if (function_exists('le_render_template')) {
                    $zpl = le_render_template($template, $data);
                    if (trim((string)$zpl) !== '') {
                        return [$zpl, $templateId, $templateName];
                    }
                }
            } catch (Throwable $e) {
            }
        }
        // No automatic rule/template fallback for Full Bins.
        // If no template is selected, fail at printBinLabel instead of silently using another template.
        return ['', $templateId, $templateName];
    }
}

if (!function_exists('printBinLabel')) {
    function printBinLabel(mysqli $mysqli, int $binId, int $printerId = 0, int $templateId = 0): bool
    {
        $data = smp_fetch_bin_label_data($mysqli, $binId);
        if (!$data) return false;

        // Full Bin printer/template have ONE source of truth:
        // full_bin_print_settings, managed only from bins_ingresso.php.
        $saved = smp_get_full_bin_saved_settings($mysqli);
        if ($printerId <= 0)  $printerId  = (int)$saved['printer_id'];
        if ($templateId <= 0) $templateId = (int)$saved['template_id'];

        if ($printerId <= 0 || $templateId <= 0) {
            return false;
        }

        $printer = smp_get_printer_by_id($printerId);
        if (!$printer || empty($printer['printer_ip'])) return false;

        $template = smp_get_full_bin_template_by_id($templateId);
        if (!$template) return false;

        [$zpl, $resolvedTemplateId] = smp_render_bin_label_zpl($data, $template);
        if (trim((string)$zpl) === '') return false;

        $result = le_send_to_printer(
            (string)$printer['printer_ip'],
            (int)($printer['printer_port'] ?? 9100),
            $zpl
        );
        $ok = is_array($result) ? !empty($result['ok']) : false;

        if (function_exists('le_log_print_history_record')) {
            le_log_print_history_record([
                'template_id' => $resolvedTemplateId,
                'printer_id' => (int)$printer['id'],
                'serial' => $data['barcode'],
                'status' => $ok ? 'printed' : 'error',
            ]);
        }
        return $ok;
    }
}


/* ═══════════════════════════════════════════════════════════════════════════
   TC26 – generic DB helpers
   ═══════════════════════════════════════════════════════════════════════════ */

if (!function_exists('smp_db_fetch_all')) {
    function smp_db_fetch_all($db, string $sql, array $params = []): array {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql);
                $st->execute($params);
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            if ($db instanceof mysqli) {
                if (!$params) {
                    $res = $db->query($sql);
                    if (!$res) return [];
                    $out = [];
                    while ($r = $res->fetch_assoc()) $out[] = $r;
                    return $out;
                }
                $st = $db->prepare($sql);
                if (!$st) return [];
                $types = str_repeat('s', count($params));
                $st->bind_param($types, ...$params);
                $st->execute();
                $res = $st->get_result();
                $out = [];
                if ($res) while ($r = $res->fetch_assoc()) $out[] = $r;
                $st->close();
                return $out;
            }
        } catch (Throwable $e) {}
        return [];
    }
}

if (!function_exists('smp_db_fetch_one')) {
    function smp_db_fetch_one($db, string $sql, array $params = []): ?array {
        $rows = smp_db_fetch_all($db, $sql . ' LIMIT 1', $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('smp_db_last_insert_id')) {
    function smp_db_last_insert_id($db): int {
        if ($db instanceof PDO)    return (int)$db->lastInsertId();
        if ($db instanceof mysqli) return (int)$db->insert_id;
        return 0;
    }
}

if (!function_exists('smp_get_calc_setting')) {
    function smp_get_calc_setting($db, string $key, string $default = ''): string {
        try {
            $row = smp_db_fetch_one($db,
                "SELECT setting_value FROM calc_settings WHERE setting_key=? LIMIT 1", [$key]);
            if ($row && isset($row['setting_value'])) return (string)$row['setting_value'];
        } catch (Throwable $e) {}
        return $default;
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   TC26 – ensure tables (extended: added_at on shipment_pallets, pack_date on pallet_cases)
   ═══════════════════════════════════════════════════════════════════════════ */

if (!function_exists('smp_tc26_migrate_extra_columns')) {
    function smp_tc26_migrate_extra_columns($db): void {
        // Full schema migration - MySQL 5.7+ safe.
        //
        // CRITICAL: ALTER TABLE / CREATE INDEX are DDL statements — they CANNOT be
        // executed via PDO::prepare()/execute() on MySQL (prepare returns false).
        // We must use PDO::exec() for DDL and mysqli::query() for DDL.
        // smp_db_exec() uses prepare/execute and is therefore WRONG for DDL here.
        //
        // $ddl() runs raw DDL and silently ignores errors (column already exists, etc.)
        $ddl = function(string $sql) use ($db): void {
            try {
                if ($db instanceof PDO) {
                    $db->exec($sql);          // PDO::exec() works for DDL
                } elseif ($db instanceof mysqli) {
                    $db->query($sql);         // mysqli::query() works for DDL
                }
            } catch (Throwable $e) { /* ignore: column/index already exists */ }
        };
        // ── pallets table ─────────────────────────────────────────────────
        // Convert pallet_id to VARCHAR if it was INT on a legacy install, then widen.
        // pallet_id is the PRIMARY KEY — no secondary index to drop before MODIFY.
        $ddl("ALTER TABLE pallets MODIFY COLUMN pallet_id VARCHAR(60) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE pallets MODIFY COLUMN pallet_id VARCHAR(60) NOT NULL"); // strip DEFAULT from PK
        $ddl("ALTER TABLE pallets ADD COLUMN status VARCHAR(20) DEFAULT 'OPEN'");
        $ddl("ALTER TABLE pallets MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'OPEN'");
        $ddl("ALTER TABLE pallets ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $ddl("ALTER TABLE pallets ADD COLUMN closed_at TIMESTAMP NULL");
        $ddl("ALTER TABLE pallets ADD COLUMN is_partial TINYINT(1) NOT NULL DEFAULT 0");

        // ── pallet_cases table ────────────────────────────────────────────
        // On legacy installs pallet_id may be INT — convert to VARCHAR so string IDs work.
        // MUST drop any index covering pallet_id BEFORE MODIFY, then re-add after.
        $ddl("ALTER TABLE pallet_cases DROP INDEX uniq_pallet_case");
        $ddl("ALTER TABLE pallet_cases DROP INDEX idx_pallet");
        $ddl("ALTER TABLE pallet_cases DROP INDEX idx_case_serial");
        $ddl("ALTER TABLE pallet_cases MODIFY COLUMN pallet_id VARCHAR(60) NOT NULL DEFAULT ''");
        // case_serial must exist (old installs may lack it)
        $ddl("ALTER TABLE pallet_cases ADD COLUMN case_serial VARCHAR(50) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE pallet_cases MODIFY COLUMN case_serial VARCHAR(60) NOT NULL DEFAULT ''");
        // Re-add indexes after column type change
        $ddl("ALTER TABLE pallet_cases ADD UNIQUE KEY uniq_pallet_case (pallet_id, case_serial)");
        $ddl("ALTER TABLE pallet_cases ADD KEY idx_pallet (pallet_id)");
        $ddl("ALTER TABLE pallet_cases ADD KEY idx_case_serial (case_serial)");
        $ddl("ALTER TABLE pallet_cases ADD COLUMN scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $ddl("ALTER TABLE pallet_cases ADD COLUMN pack_date DATE NULL");
        $ddl("ALTER TABLE pallet_cases ADD COLUMN grower VARCHAR(120) NULL");
        $ddl("ALTER TABLE pallet_cases ADD COLUMN variety VARCHAR(120) NULL");
        $ddl("ALTER TABLE pallet_cases ADD COLUMN size VARCHAR(60) NULL");
        $ddl("ALTER TABLE pallet_cases ADD COLUMN packaging VARCHAR(120) NULL");
        $ddl("ALTER TABLE pallet_cases ADD COLUMN sku INT NULL");
        $ddl("ALTER TABLE pallet_cases ADD COLUMN crop VARCHAR(120) NULL");
        $ddl("ALTER TABLE pallet_cases ADD COLUMN lot VARCHAR(120) NULL");

        // ── shipments table ───────────────────────────────────────────────
        // shipment_id is PRIMARY KEY — no secondary index to drop before MODIFY.
        $ddl("ALTER TABLE shipments MODIFY COLUMN shipment_id VARCHAR(60) NOT NULL");
        $ddl("ALTER TABLE shipments ADD COLUMN status VARCHAR(20) DEFAULT 'OPEN'");
        $ddl("ALTER TABLE shipments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $ddl("ALTER TABLE shipments ADD COLUMN closed_at TIMESTAMP NULL");
        $ddl("ALTER TABLE shipments ADD COLUMN order_id INT NULL DEFAULT NULL");
        $ddl("ALTER TABLE shipments ADD COLUMN po VARCHAR(100) NULL DEFAULT NULL");

        // ── shipment_pallets table ────────────────────────────────────────
        // On legacy installs shipment_id/pallet_id may be INT — convert to VARCHAR.
        // DROP composite unique key first, MODIFY both columns, then re-add.
        $ddl("ALTER TABLE shipment_pallets DROP INDEX uniq_ship");
        $ddl("ALTER TABLE shipment_pallets MODIFY COLUMN shipment_id VARCHAR(60) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipment_pallets MODIFY COLUMN pallet_id VARCHAR(60) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipment_pallets ADD UNIQUE KEY uniq_ship (shipment_id, pallet_id)");
        $ddl("ALTER TABLE shipment_pallets ADD COLUMN added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

        // ── Fix NOT NULL columns missing DEFAULT on old installs ──────────
        $ddl("ALTER TABLE pallets MODIFY COLUMN case_count INT NOT NULL DEFAULT 0");
        $ddl("ALTER TABLE shipments MODIFY COLUMN pallet_count INT NOT NULL DEFAULT 0");
        $ddl("ALTER TABLE shipments ADD COLUMN case_count INT NOT NULL DEFAULT 0");
        $ddl("ALTER TABLE shipments MODIFY COLUMN case_count INT NOT NULL DEFAULT 0");

        // ── shipments extra columns ───────────────────────────────────────
        $ddl("ALTER TABLE shipments ADD COLUMN customer_name VARCHAR(200) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments MODIFY COLUMN customer_name VARCHAR(200) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments ADD COLUMN shipper VARCHAR(200) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments MODIFY COLUMN shipper VARCHAR(200) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments ADD COLUMN carrier VARCHAR(200) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments MODIFY COLUMN carrier VARCHAR(200) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments ADD COLUMN bol_number VARCHAR(100) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments MODIFY COLUMN bol_number VARCHAR(100) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments ADD COLUMN notes TEXT");
        $ddl("ALTER TABLE shipments ADD COLUMN destination VARCHAR(200) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments ADD COLUMN ship_date DATE NULL DEFAULT NULL");
        // colonne collegate agli ordini
        $ddl("ALTER TABLE shipments ADD COLUMN pick_location VARCHAR(255) NULL DEFAULT NULL");
        $ddl("ALTER TABLE shipments ADD COLUMN ship_to_address TEXT NULL DEFAULT NULL");
        $ddl("ALTER TABLE shipments ADD COLUMN dest_city VARCHAR(100) NULL DEFAULT NULL");
        $ddl("ALTER TABLE shipments ADD COLUMN bol_label VARCHAR(200) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments ADD COLUMN bol_awb VARCHAR(200) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments ADD COLUMN bol_notify VARCHAR(255) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments ADD COLUMN bol_consignee VARCHAR(255) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments ADD COLUMN bol_keep_temp VARCHAR(100) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments ADD COLUMN bol_recorder VARCHAR(150) NOT NULL DEFAULT ''");
        $ddl("ALTER TABLE shipments ADD COLUMN bol_phyto CHAR(1) NOT NULL DEFAULT ''");
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   TC26 – ID generation
   ═══════════════════════════════════════════════════════════════════════════ */

if (!function_exists('smp_tc26_gen_id')) {
    /**
     * Generate short human-readable IDs:
     *   prefix='PAL'  → P + 6 digits  e.g. P031547
     *   prefix='SHIP' → S + 6 digits  e.g. S031523
     *
     * Format: Letter + MMDD + 2 random digits (00-99)
     *   P  0315  47   →  P031547
     *   S  0315  23   →  S031523
     *
     * 100 possible suffixes per day = plenty for normal warehouse volumes.
     * Duplicate key on INSERT handles the rare collision (caller retries via UPSERT).
     */
    function smp_tc26_gen_id(string $prefix): string {
        $letter = (strtoupper($prefix) === 'SHIP') ? 'S' : 'P';
        $mmdd   = date('md');                          // 4 digits: month+day
        $rnd    = str_pad((string)random_int(0, 99), 2, '0', STR_PAD_LEFT); // 2 digits
        return $letter . $mmdd . $rnd;
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   TC26 – PALLET functions
   ═══════════════════════════════════════════════════════════════════════════ */

if (!function_exists('smp_tc26_open_pallet')) {
    /**
     * Open (or re-open) a pallet.
     * If $palletId is provided and already exists → return its status.
     * If $palletId is provided and doesn't exist → create it.
     * If $palletId is empty → auto-generate.
     * Returns pallet_id string.
     */
    function smp_tc26_open_pallet($db, int $uid = 0, string $palletId = ''): string {
        smp_tc26_migrate_extra_columns($db);
        if ($palletId === '') {
            $palletId = smp_tc26_gen_id('PAL');
        }
        // upsert – if already exists keep it, else insert
        // include case_count=0 for servers where column has no DEFAULT
        smp_db_exec($db,
            "INSERT INTO pallets (pallet_id, status, created_at, case_count) VALUES (?,?,NOW(),0)
             ON DUPLICATE KEY UPDATE status = IF(status='OPEN', 'OPEN', status)",
            [$palletId, 'OPEN']
        );
        return $palletId;
    }
}

if (!function_exists('smp_tc26_pallet_status')) {
    function smp_tc26_pallet_status($db, string $palletId): array {
        if ($palletId === '') return ['ok'=>0,'err'=>'Missing pallet_id'];
        $row = smp_db_fetch_one($db,
            "SELECT pallet_id, status, is_partial,
                    DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') AS created_at,
                    DATE_FORMAT(closed_at,'%Y-%m-%d %H:%i:%s') AS closed_at
             FROM pallets WHERE pallet_id=?",
            [$palletId]);
        if (!$row) return ['ok'=>0,'err'=>'Pallet not found'];
        $cnt = smp_db_fetch_one($db,
            "SELECT COUNT(*) AS c FROM pallet_cases WHERE pallet_id=?", [$palletId]);
        $row['ok'] = 1;
        $row['cases_count'] = (int)($cnt['c'] ?? 0);
        return $row;
    }
}

if (!function_exists('smp_lookup_casecode_by_scan')) {
    /** Resolve both raw and formatted barcodes across legacy casecodes schemas. */
    function smp_lookup_casecode_by_scan($db, string $serial): ?array {
        $configuredTable = preg_replace('/[^A-Za-z0-9_]/','',smp_get_calc_setting($db,'case_lookup_table','casecodes'));
        $configured = preg_replace('/[^A-Za-z0-9_]/','',smp_get_calc_setting($db,'case_lookup_key','serial'));
        if($serial==='') return null;
        $tables=array_values(array_unique(array_filter([$configuredTable,'casecodes'])));
        $keys=array_values(array_unique(array_filter([$configured,'serial','id','Serial','SerialFormatted','serial_formatted'])));
        foreach($tables as $tbl){
            foreach($keys as $key){
                try{
                    $row=smp_db_fetch_one($db,"SELECT * FROM `$tbl` WHERE UPPER(TRIM(CAST(`$key` AS CHAR)))=UPPER(TRIM(?)) LIMIT 1",[$serial]);
                    if($row) return $row;
                }catch(Throwable $e){ /* table/column not present */ }
            }
        }
        return null;
    }
}

if (!function_exists('smp_tc26_add_case_to_pallet')) {
    function smp_tc26_add_case_to_pallet($db, string $palletId, string $serial, $opts_or_uid = []): array {
        // Normalize $opts_or_uid: accept both legacy int $uid and new array $opts
        if (is_int($opts_or_uid)) {
            $opts = ['user_id' => $opts_or_uid];
        } else {
            $opts = is_array($opts_or_uid) ? $opts_or_uid : [];
        }
        $uid = (int)($opts['user_id'] ?? 0);
        if ($palletId === '') return ['ok'=>0,'err'=>'Missing pallet_id'];
        if ($serial   === '') return ['ok'=>0,'err'=>'Missing case serial'];

        // verify pallet is open
        $pal = smp_db_fetch_one($db, "SELECT status FROM pallets WHERE pallet_id=?", [$palletId]);
        if (!$pal) return ['ok'=>0,'err'=>'Pallet not found'];
        $palStatus = strtoupper((string)($pal['status'] ?? ''));
        if ($palStatus === 'CLOSED')
            return ['ok'=>0,'err'=>'Pallet is already closed'];

        // lookup master table (configurable; graceful fallback)
        $variety = $grower = $size = $packaging = $crop = $lot = null;
        $sku     = null;
        $packDate = null;
        $caseFound = false;
        try {
            $row=smp_lookup_casecode_by_scan($db,$serial);
            if ($row) {
                    $caseFound = true;
                    $variety  = $row['variety']   ?? $row['Variety']   ?? null;
                    $grower   = $row['grower']    ?? $row['Grower']    ?? null;
                    $size     = $row['size']      ?? $row['Size']      ?? null;
                    $packaging= $row['packaging'] ?? $row['Packaging'] ?? null;
                    $sku      = $row['SKU']       ?? $row['sku']       ?? null;
                    $crop     = $row['crop']      ?? $row['Crop']      ?? null;
                    $lot      = $row['lot']       ?? $row['Lot']       ?? null;
                    $packDate = $row['pack_date'] ?? $row['PackDate']  ?? null;
            }
        } catch (Throwable $e) { /* no master table – proceed with nulls */ }

        // ── OPTS OVERRIDE: caller-supplied values take priority over casecodes lookup ─
        if (!empty($opts['variety']))   $variety   = (string)$opts['variety'];
        if (!empty($opts['grower']))    $grower    = (string)$opts['grower'];
        if (!empty($opts['size']))      $size      = (string)$opts['size'];
        if (!empty($opts['packaging'])) $packaging = (string)$opts['packaging'];
        if (!empty($opts['sku']))       $sku       = (string)$opts['sku'];
        if (!empty($opts['crop']))      $crop      = (string)$opts['crop'];
        if (!empty($opts['lot']))       $lot       = (string)$opts['lot'];
        if (!empty($opts['pack_date'])) $packDate  = (string)$opts['pack_date'];

        // Do not create anonymous pallet rows: they make the case count look
        // correct while the pallet composition is displayed as "Unknown".
        if (!$caseFound && trim((string)$sku)==='' && trim((string)$variety)==='' &&
            trim((string)$grower)==='' && trim((string)$packaging)==='' &&
            trim((string)$size)==='') {
            return ['ok'=>0,'err'=>'Case not found in casecodes — scan not added to pallet'];
        }

        // ── PRE-CHECK: is this case already on the pallet? ───────────────────
        // Do this BEFORE the INSERT so we can distinguish "duplicate" from
        // "insert error". This approach works regardless of PDO driver behaviour.
        try {
            $existing = smp_db_fetch_one($db,
                "SELECT id FROM pallet_cases WHERE pallet_id=? AND case_serial=? LIMIT 1",
                [$palletId, $serial]
            );
        } catch (Throwable $e) { $existing = null; }
        if ($existing) return ['ok'=>0,'err'=>'Case already scanned on this pallet'];

        // ── INSERT: try full schema first, fall back to minimal ───────────────
        // NOTE: with mysqli, errors don't throw exceptions — smp_db_exec_rows returns -1.
        // The $errOut reference captures the actual DB error string for diagnostics.
        // Before inserting, run an inline DDL repair in case pallet_cases.pallet_id
        // is still INT on this server (error 1366 would otherwise silently zero-coerce).
        {
            $pc_ddl = function(string $sql) use ($db): void {
                try {
                    if ($db instanceof PDO)    $db->exec($sql);
                    elseif ($db instanceof mysqli) $db->query($sql);
                } catch (Throwable $e2) {}
            };
            $pc_ddl("ALTER TABLE pallet_cases DROP INDEX uniq_pallet_case");
            $pc_ddl("ALTER TABLE pallet_cases DROP INDEX idx_pallet");
            $pc_ddl("ALTER TABLE pallet_cases MODIFY COLUMN pallet_id VARCHAR(60) NOT NULL DEFAULT ''");
            $pc_ddl("ALTER TABLE pallet_cases ADD COLUMN case_serial VARCHAR(60) NOT NULL DEFAULT ''");
            $pc_ddl("ALTER TABLE pallet_cases MODIFY COLUMN case_serial VARCHAR(60) NOT NULL DEFAULT ''");
            $pc_ddl("ALTER TABLE pallet_cases ADD UNIQUE KEY uniq_pallet_case (pallet_id, case_serial)");
            $pc_ddl("ALTER TABLE pallet_cases ADD KEY idx_pallet (pallet_id)");
        }
        $insertOk = false;
        $insertErr = '';

        // Attempt 1: full schema (all optional columns)
        $dbErr1 = '';
        try {
            $r1 = smp_db_exec_rows($db,
                "INSERT INTO pallet_cases
                     (pallet_id, case_serial, variety, grower, size, packaging, sku, crop, lot, pack_date, scanned_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,NOW())",
                [$palletId, $serial, $variety, $grower, $size, $packaging, $sku, $crop, $lot, $packDate],
                $dbErr1
            );
        } catch (Throwable $e) { $dbErr1 = $e->getMessage(); $r1 = -1; }

        if ($r1 > 0) {
            $insertOk = true;
        } else {
            if ($dbErr1) $insertErr = 'full: '.$dbErr1;
            // Attempt 2: minimal with scanned_at
            $dbErr2 = '';
            try {
                $r2 = smp_db_exec_rows($db,
                    "INSERT INTO pallet_cases (pallet_id, case_serial, scanned_at) VALUES (?,?,NOW())",
                    [$palletId, $serial],
                    $dbErr2
                );
            } catch (Throwable $e2) { $dbErr2 = $e2->getMessage(); $r2 = -1; }

            if ($r2 > 0) {
                $insertOk = true;
            } else {
                if ($dbErr2) $insertErr .= ' | min: '.$dbErr2;
                // Attempt 3: bare minimum (pallet_id + case_serial only)
                $dbErr3 = '';
                try {
                    smp_db_exec($db,
                        "INSERT INTO pallet_cases (pallet_id, case_serial) VALUES (?,?)",
                        [$palletId, $serial]
                    );
                    // Verify the row was actually inserted (catches silent mysqli failures)
                    $verify = smp_db_fetch_one($db,
                        "SELECT id FROM pallet_cases WHERE pallet_id=? AND case_serial=? LIMIT 1",
                        [$palletId, $serial]
                    );
                    $insertOk = ($verify !== null);
                    if (!$insertOk) $insertErr .= ' | bare: insert+verify failed';
                } catch (Throwable $e3) {
                    $dbErr3 = $e3->getMessage();
                    $insertErr .= ' | bare: '.$dbErr3;
                }
            }
        }

        if (!$insertOk) {
            return ['ok'=>0,'err'=>'Insert failed — check DB column types (pallet_id must be VARCHAR). '.$insertErr];
        }

        return smp_tc26_pallet_status($db, $palletId);
    }
}

if (!function_exists('smp_repair_pallet_case_metadata')) {
    /** Backfill legacy pallet rows that contain only case_serial. */
    function smp_repair_pallet_case_metadata($db, string $palletId): int {
        if ($palletId === '') return 0;
        $rows = smp_db_fetch_all($db,
            "SELECT id,case_serial,sku,variety,grower,size,packaging,crop,lot,pack_date
             FROM pallet_cases WHERE pallet_id=?", [$palletId]) ?? [];
        $fixed=0;
        foreach($rows as $pc){
            $hasData = trim((string)($pc['sku']??''))!=='' || trim((string)($pc['variety']??''))!=='' ||
                       trim((string)($pc['grower']??''))!=='' || trim((string)($pc['packaging']??''))!=='' ||
                       trim((string)($pc['size']??''))!=='';
            if($hasData) continue;
            $cc=smp_lookup_casecode_by_scan($db,(string)$pc['case_serial']);
            if(!$cc) continue;
            $vals=[
                $cc['SKU']??$cc['sku']??null, $cc['variety']??$cc['Variety']??null,
                $cc['grower']??$cc['Grower']??null, $cc['size']??$cc['Size']??null,
                $cc['packaging']??$cc['Packaging']??null, $cc['crop']??$cc['Crop']??null,
                $cc['lot']??$cc['Lot']??null, $cc['pack_date']??$cc['PackDate']??null,
                (int)$pc['id']
            ];
            if(smp_db_exec($db,
                "UPDATE pallet_cases SET sku=?,variety=?,grower=?,size=?,packaging=?,crop=?,lot=?,pack_date=? WHERE id=?",
                $vals)) $fixed++;
        }
        return $fixed;
    }
}

if (!function_exists('smp_tc26_remove_case')) {
    function smp_tc26_remove_case($db, int $caseId, string $palletId): array {
        if (!$caseId) return ['ok'=>0,'err'=>'Missing id'];
        $ok = smp_db_exec($db,
            "DELETE FROM pallet_cases WHERE id=?" . ($palletId !== '' ? " AND pallet_id=?" : ''),
            $palletId !== '' ? [$caseId, $palletId] : [$caseId]
        );
        return ['ok' => $ok ? 1 : 0, 'err' => $ok ? null : 'Delete failed'];
    }
}

if (!function_exists('smp_tc26_close_pallet')) {
    function smp_tc26_close_pallet($db, string $palletId, int $uid = 0, int $printerId = 0): array {
        if ($palletId === '') return ['ok'=>0,'err'=>'Missing pallet_id'];
        smp_db_exec($db,
            "UPDATE pallets SET status='CLOSED', is_partial=0, closed_at=NOW() WHERE pallet_id=?", [$palletId]);
        $st = smp_tc26_pallet_status($db, $palletId);
        if ($printerId > 0 && function_exists('smp_tc26_print_pallet_label')) {
            smp_tc26_print_pallet_label($db, $palletId, $printerId, false);
        }
        return $st;
    }
}

if (!function_exists('smp_tc26_partial_pallet')) {
    /**
     * Mark a pallet as PARTIAL (open but explicitly flagged as incomplete).
     * Prints a "PARTIAL PALLET" label if a printer is supplied.
     */
    function smp_tc26_partial_pallet($db, string $palletId, int $uid = 0, int $printerId = 0): array {
        if ($palletId === '') return ['ok'=>0,'err'=>'Missing pallet_id'];
        smp_db_exec($db,
            "UPDATE pallets SET is_partial=1, status='PARTIAL' WHERE pallet_id=?", [$palletId]);
        $st = smp_tc26_pallet_status($db, $palletId);
        if ($printerId > 0 && function_exists('smp_tc26_print_pallet_label')) {
            smp_tc26_print_pallet_label($db, $palletId, $printerId, true);
        }
        return $st;
    }
}

if (!function_exists('smp_tc26_print_pallet_label')) {
    function smp_tc26_print_pallet_label(
        $db,string $palletId,int $printerId=0,bool $isPartial=false
    ): bool {
        try {
            /* ── Pallet header ───────────────────────────────────────────── */
            $pal=smp_db_fetch_one($db,
                "SELECT pallet_id,
                        DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at
                 FROM pallets
                 WHERE pallet_id=? LIMIT 1",
                [$palletId]
            );
            if(!$pal)return false;

            // Ensure legacy serial-only rows contribute their real composition.
            smp_repair_pallet_case_metadata($db,$palletId);

            /* ── Real CASE content ──────────────────────────────────────── */
            $groupRows=smp_db_fetch_all($db,
                "SELECT
                    COALESCE(NULLIF(TRIM(grower),''),'Unknown') AS grower,
                    COALESCE(NULLIF(TRIM(variety),''),'Unknown') AS variety,
                    COALESCE(NULLIF(TRIM(size),''),'Unknown') AS size,
                    COALESCE(NULLIF(TRIM(packaging),''),'Unknown') AS packaging,
                    COUNT(*) AS cases
                 FROM pallet_cases
                 WHERE pallet_id=?
                 GROUP BY grower,variety,size,packaging
                 ORDER BY grower ASC,variety ASC,size ASC,packaging ASC",
                [$palletId]
            )??[];

            $totalRow=smp_db_fetch_one($db,
                "SELECT COUNT(*) AS c FROM pallet_cases WHERE pallet_id=?",
                [$palletId]
            );
            $totalCases=(int)($totalRow['c']??0);

            /* ── Lot / Load comes from CASES actually on this pallet ───── */
            $lotRows=smp_db_fetch_all($db,
                "SELECT DISTINCT TRIM(lot) AS lot
                 FROM pallet_cases
                 WHERE pallet_id=? AND lot IS NOT NULL AND TRIM(lot)<>''
                 ORDER BY TRIM(lot)",
                [$palletId]
            )??[];

            $lots=[];
            foreach($lotRows as $lr){
                $v=trim((string)($lr['lot']??''));
                if($v!=='')$lots[]=$v;
            }
            if(count($lots)===0){
                $lotLoad='';
            }elseif(count($lots)===1){
                $lotLoad=$lots[0];
            }else{
                $shown=array_slice($lots,0,2);
                $lotLoad='MULTIPLE: '.implode(', ',$shown);
                if(count($lots)>2)$lotLoad.=' +'.(count($lots)-2);
            }

            /* ── Printer selected in Pallets Manage ─────────────────────── */
            $printer=null;
            if($printerId<=0){
                try{
                    $cfg=smp_db_fetch_one($db,
                        "SELECT label_printer_id
                         FROM pallet_print_settings WHERE id=1 LIMIT 1",[]);
                    $printerId=(int)($cfg['label_printer_id']??0);
                }catch(Throwable $e){}
            }
            if($printerId>0)$printer=smp_get_printer_by_id($printerId);
            if(!$printer)$printer=smp_get_default_active_printer();
            if(!$printer||empty($printer['printer_ip']))return false;

            /* ── Final active pallet template ───────────────────────────── */
            $template=le_db_fetch_one(
                "SELECT * FROM print_templates
                 WHERE label_type='pallet' AND is_active=1
                 ORDER BY updated_at DESC,id DESC LIMIT 1",[]
            );
            if(!$template)return false;

            $data=[
                'pallet_id'=>$palletId,
                'barcode'=>$palletId,
                'id'=>$palletId,

                'created_at'=>(string)($pal['created_at']??''),
                'date_created'=>(string)($pal['created_at']??''),
                'date'=>(string)($pal['created_at']??''),

                'lot_load'=>$lotLoad,
                'lot'=>$lotLoad,

                'case_count'=>$totalCases,
                'cases'=>$totalCases,
                'total_cases'=>$totalCases,

                'product_group_count'=>count($groupRows),
            ];

            // Five physical rows fit safely on the real 4x6 renderer.
            for($i=1;$i<=5;$i++){
                $g=$groupRows[$i-1]??[];
                $data["group{$i}_grower"]=(string)($g['grower']??'');
                $data["group{$i}_variety"]=(string)($g['variety']??'');
                $data["group{$i}_size"]=(string)($g['size']??'');
                $data["group{$i}_packaging"]=(string)($g['packaging']??'');
                $data["group{$i}_cases"]=isset($g['cases'])?(int)$g['cases']:'';
            }

            $more=max(0,count($groupRows)-5);
            $data['group_more_count']=$more;
            $data['group_more_line']=$more>0?'+'.$more.' MORE GROUPS':'';

            // PALLET labels are physically 4 x 6 inches.  Do not trust an old
            // template page size here: older designer records may still contain a
            // larger canvas and would make the Zebra print past the 4x6 stock.
            $template['width_in']=4.0;
            $template['height_in']=6.0;
            $targetDpi=function_exists('le_effective_printer_dpi')
                ? le_effective_printer_dpi($printer)
                : (int)($printer['dpi']??300);
            if($targetDpi<=0)$targetDpi=300;

            $zpl=trim((string)le_render_template($template,$data,$targetDpi));
            if($zpl==='')return false;

            $res=le_send_to_printer(
                (string)$printer['printer_ip'],
                (int)($printer['printer_port']??9100),
                $zpl
            );
            return is_array($res)?!empty($res['ok']):(bool)$res;
        }catch(Throwable $e){
            return false;
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   TC26 – SHIPMENT functions
   ═══════════════════════════════════════════════════════════════════════════ */

if (!function_exists('smp_tc26_open_shipment')) {
    function smp_tc26_open_shipment($db, int $uid = 0, string $shipmentId = ''): string {
        smp_tc26_migrate_extra_columns($db);
        if ($shipmentId === '') {
            $shipmentId = smp_tc26_gen_id('SHIP');
        }
        // include pallet_count=0 for servers where column has no DEFAULT
        smp_db_exec($db,
            "INSERT INTO shipments (shipment_id, status, created_at, pallet_count) VALUES (?,?,NOW(),0)
             ON DUPLICATE KEY UPDATE status = IF(status='OPEN','OPEN',status)",
            [$shipmentId, 'OPEN']
        );
        return $shipmentId;
    }
}

if (!function_exists('smp_tc26_shipment_status')) {
    function smp_tc26_shipment_status($db, string $shipmentId): array {
        if ($shipmentId === '') return ['ok'=>0,'err'=>'Missing shipment_id'];
        $row = smp_db_fetch_one($db,
            "SELECT shipment_id, status,
                    DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') AS created_at,
                    DATE_FORMAT(closed_at,'%Y-%m-%d %H:%i:%s') AS closed_at
             FROM shipments WHERE shipment_id=?",
            [$shipmentId]);
        if (!$row) return ['ok'=>0,'err'=>'Shipment not found'];
        $cnt = smp_db_fetch_one($db,
            "SELECT COUNT(*) AS c FROM shipment_pallets WHERE shipment_id=?", [$shipmentId]);
        $caseCnt = smp_db_fetch_one($db,
            "SELECT COUNT(pc.id) AS c
             FROM shipment_pallets sp
             JOIN pallet_cases pc ON pc.pallet_id = sp.pallet_id
             WHERE sp.shipment_id=?", [$shipmentId]);
        $row['ok']           = 1;
        $row['pallet_count'] = (int)($cnt['c'] ?? 0);
        $row['cases_count']  = (int)($caseCnt['c'] ?? 0);
        return $row;
    }
}

if (!function_exists('smp_tc26_add_pallet_to_shipment')) {
    function smp_tc26_add_pallet_to_shipment($db, string $shipmentId, string $palletId, int $uid = 0): array {
        if ($shipmentId === '') return ['ok'=>0,'err'=>'Missing shipment_id'];
        if ($palletId   === '') return ['ok'=>0,'err'=>'Missing pallet_id'];

        $ship = smp_db_fetch_one($db, "SELECT status FROM shipments WHERE shipment_id=?", [$shipmentId]);
        if (!$ship) return ['ok'=>0,'err'=>'Shipment not found'];
        if (strtoupper((string)($ship['status'] ?? '')) === 'CLOSED')
            return ['ok'=>0,'err'=>'Shipment is already closed'];

        $pal = smp_db_fetch_one($db, "SELECT status FROM pallets WHERE pallet_id=?", [$palletId]);
        if (!$pal) return ['ok'=>0,'err'=>'Pallet not found'];

        // ── PRE-CHECK: is this pallet already linked to this shipment? ────────
        try {
            $existing = smp_db_fetch_one($db,
                "SELECT id FROM shipment_pallets WHERE shipment_id=? AND pallet_id=? LIMIT 1",
                [$shipmentId, $palletId]
            );
        } catch (Throwable $e) { $existing = null; }
        if ($existing) return ['ok'=>0,'err'=>'Pallet already in this shipment'];

        // ── INSERT: do not include added_at (uses DEFAULT CURRENT_TIMESTAMP) ─
        // On legacy DBs shipment_pallets.shipment_id may still be INT — if we get
        // error 1366 (Incorrect integer value) we run an inline DDL repair and retry.
        $insertAttempts = 0;
        $insertMaxAttempts = 2;
        while ($insertAttempts < $insertMaxAttempts) {
            $insertAttempts++;
            try {
                smp_db_exec($db,
                    "INSERT INTO shipment_pallets (shipment_id, pallet_id) VALUES (?,?)",
                    [$shipmentId, $palletId]
                );
                // Success — break out of retry loop
                break;
            } catch (Throwable $e) {
                $errMsg = $e->getMessage();
                // Error 1366 = column is still INT, needs DDL repair
                if ($insertAttempts < $insertMaxAttempts && (
                    strpos($errMsg, '1366') !== false ||
                    stripos($errMsg, 'Incorrect integer value') !== false
                )) {
                    // Run inline DDL repair then retry
                    try {
                        if ($db instanceof PDO) {
                            $db->exec("ALTER TABLE shipment_pallets DROP INDEX uniq_ship");
                        } elseif ($db instanceof mysqli) {
                            $db->query("ALTER TABLE shipment_pallets DROP INDEX uniq_ship");
                        }
                    } catch (Throwable $ignored) {}
                    try {
                        if ($db instanceof PDO) {
                            $db->exec("ALTER TABLE shipment_pallets MODIFY COLUMN shipment_id VARCHAR(60) NOT NULL DEFAULT ''");
                            $db->exec("ALTER TABLE shipment_pallets MODIFY COLUMN pallet_id VARCHAR(60) NOT NULL DEFAULT ''");
                            $db->exec("ALTER TABLE shipment_pallets ADD UNIQUE KEY uniq_ship (shipment_id, pallet_id)");
                        } elseif ($db instanceof mysqli) {
                            $db->query("ALTER TABLE shipment_pallets MODIFY COLUMN shipment_id VARCHAR(60) NOT NULL DEFAULT ''");
                            $db->query("ALTER TABLE shipment_pallets MODIFY COLUMN pallet_id VARCHAR(60) NOT NULL DEFAULT ''");
                            $db->query("ALTER TABLE shipment_pallets ADD UNIQUE KEY uniq_ship (shipment_id, pallet_id)");
                        }
                    } catch (Throwable $ignored) {}
                    // continue to retry
                } else {
                    return ['ok'=>0,'err'=>'DB error: '.$errMsg];
                }
            }
        }

        return smp_tc26_shipment_status($db, $shipmentId);
    }
}

if (!function_exists('smp_tc26_remove_pallet_from_shipment')) {
    function smp_tc26_remove_pallet_from_shipment($db, int $rowId, string $shipmentId): array {
        if (!$rowId) return ['ok'=>0,'err'=>'Missing id'];
        $ok = smp_db_exec($db,
            "DELETE FROM shipment_pallets WHERE id=?" . ($shipmentId !== '' ? " AND shipment_id=?" : ''),
            $shipmentId !== '' ? [$rowId, $shipmentId] : [$rowId]
        );
        return ['ok' => $ok ? 1 : 0, 'err' => $ok ? null : 'Delete failed'];
    }
}


/* ── Shipment print settings: ONE source of truth ───────────────────────── */
if (!function_exists('smp_ensure_shipment_print_settings')) {
    function smp_ensure_shipment_print_settings($db): void {
        smp_db_exec($db, "
            CREATE TABLE IF NOT EXISTS shipment_print_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                label_template_id INT NULL,
                label_printer_id INT NULL,
                bol_printer VARCHAR(255) NULL,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        smp_db_exec($db,
            "INSERT IGNORE INTO shipment_print_settings(id,label_template_id,label_printer_id,bol_printer)
             VALUES(1,NULL,NULL,NULL)"
        );
    }
}
if (!function_exists('smp_get_shipment_print_settings')) {
    function smp_get_shipment_print_settings($db): array {
        smp_ensure_shipment_print_settings($db);
        $row = smp_db_fetch_one($db,
            "SELECT label_template_id,label_printer_id,bol_printer
             FROM shipment_print_settings WHERE id=1 LIMIT 1", []);
        return [
            'label_template_id'=>(int)($row['label_template_id']??0),
            'label_printer_id'=>(int)($row['label_printer_id']??0),
            'bol_printer'=>trim((string)($row['bol_printer']??'')),
        ];
    }
}
if (!function_exists('smp_save_shipment_print_settings')) {
    function smp_save_shipment_print_settings($db,int $templateId,int $printerId,string $bolPrinter): bool {
        smp_ensure_shipment_print_settings($db);
        return (bool)smp_db_exec($db,"
            INSERT INTO shipment_print_settings(id,label_template_id,label_printer_id,bol_printer)
            VALUES(1,?,?,?)
            ON DUPLICATE KEY UPDATE
                label_template_id=VALUES(label_template_id),
                label_printer_id=VALUES(label_printer_id),
                bol_printer=VALUES(bol_printer),
                updated_at=CURRENT_TIMESTAMP
        ",[
            $templateId>0?$templateId:null,
            $printerId>0?$printerId:null,
            trim($bolPrinter)!==''?trim($bolPrinter):null
        ]);
    }
}

if (!function_exists('smp_tc26_close_shipment')) {
    function smp_tc26_close_shipment($db,string $shipmentId,int $uid=0,int $printerId=0): array {
        if ($shipmentId==='') return ['ok'=>0,'err'=>'Missing shipment_id'];

        smp_db_exec($db,
            "UPDATE shipments SET status='CLOSED',closed_at=NOW() WHERE shipment_id=?",
            [$shipmentId]
        );

        $cfg=smp_get_shipment_print_settings($db);
        if($printerId<=0) $printerId=(int)$cfg['label_printer_id'];

        $labelPrinted=false;
        $labelError=null;

        if($printerId>0 && (int)$cfg['label_template_id']>0){
            $labelPrinted=smp_tc26_print_shipment_label(
                $db,$shipmentId,$printerId,(int)$cfg['label_template_id']
            );
            if(!$labelPrinted) $labelError='Shipment closed, but label printing failed.';
        } else {
            $labelError='Shipment closed. Configure Shipment Label Template and Shipment Label Printer in Shipments Manage.';
        }

        $st=smp_tc26_shipment_status($db,$shipmentId);
        $st['label_printed']=$labelPrinted?1:0;
        $st['label_error']=$labelError;
        return $st;
    }
}

if (!function_exists('smp_tc26_print_shipment_label')) {
    function smp_tc26_print_shipment_label(
        $db,string $shipmentId,int $printerId=0,int $templateId=0
    ): bool {
        try {
            $cfg=smp_get_shipment_print_settings($db);
            if($printerId<=0) $printerId=(int)($cfg['label_printer_id']??0);
            if($templateId<=0) $templateId=(int)($cfg['label_template_id']??0);

            // Shipments Manage is the only source for shipment label printer/template.
            if($printerId<=0 || $templateId<=0) return false;

            $printer=smp_get_printer_by_id($printerId);
            if(!$printer || empty($printer['printer_ip'])) return false;

            $template=smp_db_fetch_one($db,
                "SELECT * FROM print_templates
                 WHERE id=? AND label_type='shipping' AND is_active=1 LIMIT 1",
                [$templateId]
            );
            if(!$template) return false;

            $ship=smp_db_fetch_one($db,
                "SELECT shipment_id,po,customer_name,order_id,destination,dest_city,
                        ship_date,status,created_at,closed_at
                 FROM shipments
                 WHERE shipment_id=? LIMIT 1",
                [$shipmentId]
            );
            if(!$ship) return false;

            // Real physical content of the shipment.
            $palRow=smp_db_fetch_one($db,
                "SELECT COUNT(DISTINCT pallet_id) AS c
                 FROM shipment_pallets WHERE shipment_id=?",
                [$shipmentId]
            );
            $palCnt=(int)($palRow['c']??0);

            $caseRow=smp_db_fetch_one($db,
                "SELECT COUNT(*) AS c
                 FROM shipment_pallets sp
                 INNER JOIN pallet_cases pc ON pc.pallet_id=sp.pallet_id
                 WHERE sp.shipment_id=?",
                [$shipmentId]
            );
            $caseCnt=(int)($caseRow['c']??0);

            // Product groups always come from CASES that are actually on shipment pallets.
            $groups=[];
            try {
                $groups=smp_db_fetch_all($db,
                    "SELECT
                        COALESCE(NULLIF(TRIM(pc.grower),''),NULLIF(TRIM(cc.grower),''),'') AS grower,
                        COALESCE(NULLIF(TRIM(pc.variety),''),NULLIF(TRIM(cc.variety),''),'') AS variety,
                        COALESCE(NULLIF(TRIM(pc.size),''),NULLIF(TRIM(cc.size),''),'') AS size,
                        COALESCE(NULLIF(TRIM(pc.packaging),''),NULLIF(TRIM(cc.packaging),''),'') AS packaging,
                        COUNT(*) AS cases
                     FROM shipment_pallets sp
                     INNER JOIN pallet_cases pc ON pc.pallet_id=sp.pallet_id
                     LEFT JOIN casecodes cc ON cc.serial=pc.case_serial
                     WHERE sp.shipment_id=?
                     GROUP BY
                        COALESCE(NULLIF(TRIM(pc.grower),''),NULLIF(TRIM(cc.grower),''),''),
                        COALESCE(NULLIF(TRIM(pc.variety),''),NULLIF(TRIM(cc.variety),''),''),
                        COALESCE(NULLIF(TRIM(pc.size),''),NULLIF(TRIM(cc.size),''),''),
                        COALESCE(NULLIF(TRIM(pc.packaging),''),NULLIF(TRIM(cc.packaging),''),'')
                     ORDER BY grower,variety,size,packaging",
                    [$shipmentId]
                )??[];
            } catch(Throwable $e) {
                $groups=[];
            }

            // Orders DB is separate on this installation. Use it only to enrich
            // Customer / Ship To / Destination; shipment pallets remain authoritative
            // for pallet/case/product totals.
            $orderExtra=[];
            $po=trim((string)($ship['po']??''));
            if($po!==''){
                try {
                    require_once __DIR__.'/../config/orders_sql_lib.php';
                    if(orders_sql_ready()){
                        orders_sql_init();
                        $orderExtra=orders_fetch_one_sql_by_po($po)??[];
                    }
                } catch(Throwable $e) {}
            }

            $customer=trim((string)($ship['customer_name']??''));
            if($customer===''){
                $customer=trim((string)($orderExtra['customer']??$orderExtra['client_name']??''));
            }

            $destCity=trim((string)($orderExtra['dest_city']??$ship['dest_city']??$ship['destination']??''));
            $shipTo=trim((string)($orderExtra['ship_to_address']??''));

            // Break Ship To into compact label-friendly lines.
            $shipToLines=preg_split('/\R+/', $shipTo) ?: [];
            $shipToLines=array_values(array_filter(array_map('trim',$shipToLines),fn($v)=>$v!==''));
            $shipTo1=(string)($shipToLines[0]??'');
            $shipTo2=(string)($shipToLines[1]??'');
            if($shipTo1==='' && $destCity!=='') $shipTo1=$destCity;
            if($shipTo2==='' && count($shipToLines)>2){
                $shipTo2=implode(' ',array_slice($shipToLines,2));
            }

            $shipDate=trim((string)($ship['ship_date']??''));
            if($shipDate==='') $shipDate=date('Y-m-d');

            $data=[
                'shipment_id'=>$shipmentId,
                'barcode'=>$shipmentId,
                'id'=>$shipmentId,

                'po'=>$po,
                'customer_name'=>$customer,
                'customer'=>$customer,

                'destination'=>$destCity,
                'dest_city'=>$destCity,
                'ship_to_1'=>$shipTo1,
                'ship_to_2'=>$shipTo2,

                'ship_date'=>$shipDate,
                'date'=>$shipDate,
                'print_date'=>date('Y-m-d'),

                'pallet_count'=>$palCnt,
                'pallets'=>$palCnt,
                'total_pallets'=>$palCnt,

                'case_count'=>$caseCnt,
                'cases'=>$caseCnt,
                'total_cases'=>$caseCnt,
            ];

            // Up to 5 real shipment-content rows on the 4x6 label.
            for($i=1;$i<=5;$i++){
                $g=$groups[$i-1]??[];
                $grower=trim((string)($g['grower']??''));
                $variety=trim((string)($g['variety']??''));
                $size=trim((string)($g['size']??''));
                $pack=trim((string)($g['packaging']??''));
                $qty=(int)($g['cases']??0);

                $detailParts=array_values(array_filter([$variety,$size,$pack],fn($v)=>$v!==''));
                $detail=$detailParts?implode(' | ',$detailParts):'';
                if($detail!=='' && $qty>0) $detail.=' | '.$qty.' CS';

                $data["group{$i}_grower"]=$grower;
                $data["group{$i}_variety"]=$variety;
                $data["group{$i}_size"]=$size;
                $data["group{$i}_packaging"]=$pack;
                $data["group{$i}_cases"]=$qty?:'';
                $data["group{$i}_detail"]=$detail;
                // Kept for compatibility with any older shipment template.
                $data["group{$i}_line"]=trim($grower.($detail!==''?' | '.$detail:''));
            }

            $data['content_group_count']=count($groups);
            $data['more_groups']=count($groups)>5 ? '+'.(count($groups)-5).' MORE GROUPS' : '';

            if(!function_exists('le_render_template')) return false;
            // SHIPPING labels use the same physical 4 x 6 stock as pallet labels.
            // Force the physical page at print time while preserving the actual
            // selected printer DPI (203/300/600), so geometry remains 4x6.
            $template['width_in']=4.0;
            $template['height_in']=6.0;
            $targetDpi=function_exists('le_effective_printer_dpi')
                ? le_effective_printer_dpi($printer)
                : (int)($printer['dpi']??300);
            if($targetDpi<=0)$targetDpi=300;

            $zpl=trim((string)le_render_template($template,$data,$targetDpi));
            if($zpl==='') return false;

            if(!function_exists('le_send_to_printer')) return false;
            $res=le_send_to_printer(
                (string)$printer['printer_ip'],
                (int)($printer['printer_port']??9100),
                $zpl
            );
            return is_array($res)?!empty($res['ok']):(bool)$res;
        } catch(Throwable $e){
            return false;
        }
    }
}
