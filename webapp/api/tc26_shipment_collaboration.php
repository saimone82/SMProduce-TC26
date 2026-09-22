<?php
declare(strict_types=1);

/**
 * Shared-shipment coordination for TC26 Zebra devices.
 * Pallet creation itself is intentionally never blocked.
 */

/**
 * MySQL advisory locks make the check-and-insert sequence atomic across
 * several Zebra HTTP requests. A busy lock fails safely; no duplicate is saved.
 */
function tc26_collab_critical($db, string $scope, callable $operation): array {
    $key = 'smp-tc26-' . substr(hash('sha256', $scope), 0, 52);
    $locked = false;
    try {
        $row = smp_db_fetch_one($db, 'SELECT GET_LOCK(?, 8) AS locked', [$key]);
        if ((int)($row['locked'] ?? 0) !== 1) {
            return ['ok'=>0,'err'=>'Another Zebra is completing this scan. Please scan once more.'];
        }
        $locked = true;
        return $operation();
    } catch (Throwable $e) {
        return ['ok'=>0,'err'=>'Server could not safely coordinate this concurrent scan. Please retry.'];
    } finally {
        if ($locked) {
            try { smp_db_fetch_one($db, 'SELECT RELEASE_LOCK(?) AS released', [$key]); }
            catch (Throwable $ignored) {}
        }
    }
}


function tc26_collab_device_id(array $input): string {
    $id = trim((string)($input['client_device_id'] ?? ''));
    return preg_match('/^[A-Za-z0-9._:-]{8,160}$/', $id) ? $id : '';
}

/**
 * A browser session is a first-class shipment client too.  It receives a
 * private random identity so web actions use the exact same owner gate as a
 * Zebra and cannot bypass label/close controls.
 */
function tc26_collab_web_device_id(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['tc26_collab_web_device_id'])) {
        $_SESSION['tc26_collab_web_device_id'] = 'web-' . bin2hex(random_bytes(20));
    }
    return (string)$_SESSION['tc26_collab_web_device_id'];
}

/** Claim or refresh the one allowed shipment owner atomically. */
function tc26_collab_enter_shipment($db, string $shipmentId, string $deviceId): array {
    if ($shipmentId === '') return ['ok'=>0,'err'=>'Missing shipment_id'];
    return tc26_collab_critical($db, 'shipment:'.$shipmentId,
        function() use ($db, $shipmentId, $deviceId): array {
            return tc26_collab_claim_shipment($db, $shipmentId, $deviceId);
        });
}

function tc26_collab_setup($db): void {
    static $ready = false;
    if ($ready) return;
    smp_db_exec($db, "CREATE TABLE IF NOT EXISTS tc26_shipment_device_activity (
        shipment_id VARCHAR(96) NOT NULL,
        device_id VARCHAR(160) NOT NULL,
        started_at DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        PRIMARY KEY (shipment_id, device_id),
        KEY tc26_ship_device_seen (shipment_id, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    smp_db_exec($db, "CREATE TABLE IF NOT EXISTS tc26_shipment_takeovers (
        shipment_id VARCHAR(96) NOT NULL PRIMARY KEY,
        device_id VARCHAR(160) NOT NULL,
        taken_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY tc26_ship_takeover_seen (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function tc26_collab_touch($db, string $shipmentId, string $deviceId): void {
    if ($shipmentId === '' || $deviceId === '') return;
    tc26_collab_setup($db);
    smp_db_exec($db,
        "INSERT INTO tc26_shipment_device_activity (shipment_id,device_id,started_at,last_seen)
         VALUES (?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE last_seen=NOW()",
        [$shipmentId, $deviceId]
    );
}

function tc26_collab_active_others($db, string $shipmentId, string $deviceId): int {
    if ($shipmentId === '' || $deviceId === '') return 0;
    tc26_collab_setup($db);
    $row = smp_db_fetch_one($db,
        "SELECT COUNT(*) AS c
           FROM tc26_shipment_device_activity
          WHERE shipment_id=? AND device_id<>?
            AND last_seen >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)",
        [$shipmentId, $deviceId]
    );
    return (int)($row['c'] ?? 0);
}

function tc26_collab_lock($db, string $shipmentId): ?array {
    if ($shipmentId === '') return null;
    tc26_collab_setup($db);
    // A takeover is temporary protection for the active work period, never permanent.
    smp_db_exec($db,
        "DELETE FROM tc26_shipment_takeovers
          WHERE updated_at < DATE_SUB(NOW(), INTERVAL 8 HOUR)"
    );
    $row = smp_db_fetch_one($db,
        "SELECT shipment_id,device_id,taken_at,updated_at
           FROM tc26_shipment_takeovers WHERE shipment_id=? LIMIT 1",
        [$shipmentId]
    );
    return is_array($row) ? $row : null;
}

/**
 * A shipment belongs to one Zebra at a time.  The takeovers table is the
 * durable ownership record; an inactive owner automatically expires after
 * eight hours in tc26_collab_lock().
 */
function tc26_collab_claim_shipment($db, string $shipmentId, string $deviceId): array {
    if ($shipmentId === '' || $deviceId === '') {
        return ['ok'=>0,'err'=>'Update this Zebra to the Multi-Zebra version before opening a shipment.'];
    }
    $owner = tc26_collab_lock($db, $shipmentId);
    if ($owner && !hash_equals((string)$owner['device_id'], $deviceId)) {
        return [
            'ok'=>0,
            'err'=>'This shipment is already open on another Zebra. Press Take Over and enter password 2424.',
            'shipment_in_use'=>1,
        ];
    }
    tc26_collab_setup($db);
    if (!$owner) {
        smp_db_exec($db,
            "INSERT INTO tc26_shipment_takeovers (shipment_id,device_id,taken_at,updated_at)
             VALUES (?,?,NOW(),NOW())",
            [$shipmentId, $deviceId]
        );
    } else {
        smp_db_exec($db,
            "UPDATE tc26_shipment_takeovers SET updated_at=NOW()
              WHERE shipment_id=? AND device_id=?",
            [$shipmentId, $deviceId]
        );
    }
    tc26_collab_touch($db, $shipmentId, $deviceId);
    return ['ok'=>1];
}

function tc26_collab_mutation(string $action): bool {
    return in_array($action, [
        'shipment_set_order', 'shipment_scan_pallet',
        'shipment_remove_last', 'shipment_close'
    ], true);
}

function tc26_collab_assert_available($db, string $shipmentId, string $deviceId): ?array {
    if ($shipmentId === '') return ['ok'=>0,'err'=>'Missing shipment_id'];
    if ($deviceId === '') {
        return ['ok'=>0,'err'=>'Update this Zebra to the Multi-Zebra version before changing or printing a shipment.'];
    }
    $lock = tc26_collab_lock($db, $shipmentId);
    if ($lock && !hash_equals((string)$lock['device_id'], $deviceId)) {
        return [
            'ok'=>0,
            'err'=>'This shipment is already open by another device. Press Take Over and enter password 2424.',
            'shipment_in_use'=>1,
            'shipment_taken_over'=>1,
        ];
    }
    if ($lock) {
        smp_db_exec($db,
            "UPDATE tc26_shipment_takeovers SET updated_at=NOW()
              WHERE shipment_id=? AND device_id=?",
            [$shipmentId, $deviceId]
        );
    }
    return null;
}

function tc26_collab_takeover($db, array $cfg, string $shipmentId, string $deviceId, string $password): array {
    if ($shipmentId === '' || $deviceId === '') {
        return ['ok'=>0,'err'=>'This version of the app is required for Shipment Take Over.'];
    }
    if (!hash_equals('2424', trim($password))) {
        return ['ok'=>0,'err'=>'Incorrect Shipment Take Over password'];
    }
    // Same advisory lock as shipment scans: ownership changes and shipment
    // mutations can never pass each other at the same instant.
    return tc26_collab_critical($db, 'shipment:'.$shipmentId,
        function() use ($db, $shipmentId, $deviceId): array {
            tc26_collab_setup($db);
            smp_db_exec($db,
                "INSERT INTO tc26_shipment_takeovers (shipment_id,device_id,taken_at,updated_at)
                 VALUES (?,?,NOW(),NOW())
                 ON DUPLICATE KEY UPDATE device_id=VALUES(device_id),taken_at=NOW(),updated_at=NOW()",
                [$shipmentId, $deviceId]
            );
            tc26_collab_touch($db, $shipmentId, $deviceId);
            return ['ok'=>1,'shipment_taken_over'=>1,
                    'msg'=>'Shipment taken over on this Zebra. Palletizing on every Zebra remains available.'];
        });
}

function tc26_collab_add_status($db, array $status, string $shipmentId, string $deviceId): array {
    if (empty($status['ok']) || $shipmentId === '' || $deviceId === '') return $status;
    tc26_collab_touch($db, $shipmentId, $deviceId);
    $lock = tc26_collab_lock($db, $shipmentId);
    $status['active_other_zebras'] = 0;
    if ($lock && !hash_equals((string)$lock['device_id'], $deviceId)) {
        $status['shipment_in_use'] = 1;
        $status['shipment_taken_over'] = 1;
        $status['collaboration_notice'] =
            'Shipment already open on another device. Take Over requires password 2424.';
    }
    return $status;
}
