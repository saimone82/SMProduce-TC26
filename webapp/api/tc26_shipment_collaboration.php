<?php
declare(strict_types=1);

/**
 * Shared-shipment coordination for TC26 Zebra devices.
 * Pallet creation and pallet scans are intentionally NOT coordinated here.
 */

function tc26_collab_device_id(array $input): string {
    $id = trim((string)($input['client_device_id'] ?? ''));
    return preg_match('/^[A-Za-z0-9._:-]{8,160}$/', $id) ? $id : '';
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

function tc26_collab_mutation(string $action): bool {
    return in_array($action, [
        'shipment_set_order', 'shipment_scan_pallet',
        'shipment_remove_last', 'shipment_close'
    ], true);
}

function tc26_collab_assert_available($db, string $shipmentId, string $deviceId): ?array {
    if ($shipmentId === '' || $deviceId === '') return null;
    $lock = tc26_collab_lock($db, $shipmentId);
    if ($lock && !hash_equals((string)$lock['device_id'], $deviceId)) {
        return [
            'ok'=>0,
            'err'=>'Shipment is currently taken over by another Zebra. Palletizing remains available.',
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
    $expected = strtolower(trim((string)(
        $cfg['shipment_takeover_password_sha256']
        ?? $cfg['skip_po_password_sha256']
        ?? ''
    )));
    if ($expected === '' || !hash_equals($expected, hash('sha256', $password))) {
        return ['ok'=>0,'err'=>'Incorrect Shipment Take Over password'];
    }
    tc26_collab_setup($db);
    smp_db_exec($db,
        "INSERT INTO tc26_shipment_takeovers (shipment_id,device_id,taken_at,updated_at)
         VALUES (?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE device_id=VALUES(device_id),taken_at=NOW(),updated_at=NOW()",
        [$shipmentId, $deviceId]
    );
    tc26_collab_touch($db, $shipmentId, $deviceId);
    return ['ok'=>1,'shipment_taken_over'=>1,
            'msg'=>'Shipment taken over on this Zebra. Other Zebras can continue palletizing.'];
}

function tc26_collab_add_status($db, array $status, string $shipmentId, string $deviceId): array {
    if (empty($status['ok']) || $shipmentId === '' || $deviceId === '') return $status;
    tc26_collab_touch($db, $shipmentId, $deviceId);
    $others = tc26_collab_active_others($db, $shipmentId, $deviceId);
    $lock = tc26_collab_lock($db, $shipmentId);
    $status['active_other_zebras'] = $others;
    if ($others > 0) {
        $status['collaboration_notice'] =
            'Another Zebra is already working on this shipment. You can continue; pallets remain separate.';
    }
    if ($lock && !hash_equals((string)$lock['device_id'], $deviceId)) {
        $status['shipment_taken_over'] = 1;
        $status['collaboration_notice'] =
            'Another Zebra has taken over shipment operations. Palletizing remains available.';
    }
    return $status;
}
