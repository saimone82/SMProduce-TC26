<?php
/** Mixed-SKU order line helpers.
 * A MIX line has one target quantity and any number of allowed SKUs.
 * Existing normal order lines remain unchanged.
 */

function orders_mix_init(PDO $db): void {
    try { $db->exec("ALTER TABLE order_lines ADD COLUMN is_mix TINYINT(1) NOT NULL DEFAULT 0 AFTER quantity"); } catch (Throwable $e) {}
    $db->exec("CREATE TABLE IF NOT EXISTS order_line_skus (
        id INT NOT NULL AUTO_INCREMENT,
        order_line_id INT NOT NULL,
        sku_id INT NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_order_line_sku (order_line_id, sku_id),
        KEY idx_ols_line (order_line_id),
        KEY idx_ols_sku (sku_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function orders_mix_set_line(PDO $db, int $lineId, array $skuIds, bool $isMix): void {
    if ($lineId <= 0) return;
    $ids = array_values(array_unique(array_filter(array_map('intval', $skuIds), fn($v) => $v > 0)));
    if (!$ids) return;
    $db->prepare("UPDATE order_lines SET is_mix=? WHERE id=?")->execute([$isMix ? 1 : 0, $lineId]);
    $db->prepare("DELETE FROM order_line_skus WHERE order_line_id=?")->execute([$lineId]);
    $st = $db->prepare("INSERT INTO order_line_skus(order_line_id,sku_id) VALUES(?,?)");
    foreach ($ids as $skuId) $st->execute([$lineId, $skuId]);
}

function orders_mix_members(PDO $db, int $lineId, int $fallbackSku = 0): array {
    orders_mix_init($db);
    $st = $db->prepare("SELECT sku_id FROM order_line_skus WHERE order_line_id=? ORDER BY id");
    $st->execute([$lineId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (!$ids && $fallbackSku > 0) $ids = [$fallbackSku];
    return $ids;
}

function orders_mix_fetch_lines(PDO $db, int $orderId): array {
    orders_mix_init($db);
    $st = $db->prepare("SELECT ol.id,ol.order_id,ol.sku_id,ol.quantity,COALESCE(ol.is_mix,0) is_mix,
                               COALESCE(ol.packaging_preset,'') packaging_preset
                        FROM order_lines ol WHERE ol.order_id=? ORDER BY ol.id");
    $st->execute([$orderId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['allowed_skus'] = orders_mix_members($db, (int)$row['id'], (int)$row['sku_id']);
        $row['is_mix'] = (int)$row['is_mix'];
    }
    unset($row);
    return $rows;
}
