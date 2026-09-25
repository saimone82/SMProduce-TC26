<?php
declare(strict_types=1);

// Shared dumping history for every handheld.
// History groups are Grower + Variety (not Grower only).
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Connection: close');

const DUMPING_HISTORY_API_KEY = 'SM-DUMPING-2026';
const DUMPING_HISTORY_VERSION = 1;
const DUMPING_GROUP_MARKER = 1073741824; // 0x40000000, keeps synthetic IDs positive on Android.
const DUMPING_GROUP_GROWER_MAX = 16383;
const DUMPING_GROUP_VARIETY_MAX = 65535;

function dumping_history_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    $data['service'] = 'dumping_history';
    $data['history_version'] = DUMPING_HISTORY_VERSION;
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function dumping_history_group_id(int $growerId, int $varietyId): int
{
    // Android v2.2 sends only one integer (grower_id) when a summary row is opened.
    // Pack both database IDs into that existing positive 32-bit field.
    if ($growerId < 0 || $growerId > DUMPING_GROUP_GROWER_MAX ||
        $varietyId < 0 || $varietyId > DUMPING_GROUP_VARIETY_MAX) {
        throw new RuntimeException('Dumping group id is outside supported range');
    }
    return DUMPING_GROUP_MARKER | ($growerId << 16) | $varietyId;
}

function dumping_history_decode_group_id(int $groupId): array
{
    // Small positive values are accepted as legacy grower-only filters.
    if ($groupId < DUMPING_GROUP_MARKER) {
        return [$groupId, 0, false];
    }
    $growerId = ($groupId >> 16) & 0x3fff;
    $varietyId = $groupId & 0xffff;
    return [$growerId, $varietyId, true];
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    dumping_history_reply(['ok' => false, 'reason' => 'post_required'], 405);
}

$key = $_POST['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
if (!is_string($key) || !hash_equals(DUMPING_HISTORY_API_KEY, trim($key))) {
    dumping_history_reply(['ok' => false, 'reason' => 'invalid_key'], 403);
}

$offset = filter_var($_POST['offset'] ?? 0, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 2147483600],
]);
$limit = filter_var($_POST['limit'] ?? 100, FILTER_VALIDATE_INT);
$scope = strtolower(trim((string)($_POST['scope'] ?? 'today')));
$requestedGroupId = filter_var($_POST['grower_id'] ?? -1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => -1, 'max_range' => 2147483647],
]);

if ($offset === false || $limit !== 100 || $offset % 100 !== 0) {
    dumping_history_reply(['ok' => false, 'reason' => 'invalid_page'], 422);
}
if (!in_array($scope, ['today', 'all'], true)) {
    dumping_history_reply(['ok' => false, 'reason' => 'invalid_scope'], 422);
}
if ($requestedGroupId === false) {
    dumping_history_reply(['ok' => false, 'reason' => 'invalid_grower'], 422);
}

$transactionOpen = false;
try {
    require_once __DIR__ . '/../config/db_remote.php';
    require_once __DIR__ . '/../includes/dumped_bins_schema.php';

    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new RuntimeException('Database connection unavailable');
    }
    if (!smp_has_dumped_at_column($mysqli)) {
        dumping_history_reply(['ok' => false, 'reason' => 'dump_timestamp_unavailable'], 503);
    }

    $mysqli->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $mysqli->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT | MYSQLI_TRANS_START_READ_ONLY);
    $transactionOpen = true;

    $serverRow = $mysqli->query("SELECT NOW() AS server_time, DATE_FORMAT(CURDATE(), '%Y-%m-%d') AS business_date");
    if (!$serverRow) throw new RuntimeException('Unable to read server time');
    $server = $serverRow->fetch_assoc();
    $serverRow->free();

    $timeExpr = 'COALESCE(bi.dumped_at,bi.updated_at,bi.date)';
    $where = "bi.status='DUMPED'";
    $types = '';
    $params = [];

    if ($scope === 'today') {
        $where .= " AND DATE($timeExpr)=CURDATE()";
    }

    $filterGrowerId = 0;
    $filterVarietyId = 0;
    $isCompositeGroup = false;
    if ($requestedGroupId >= 0) {
        [$filterGrowerId, $filterVarietyId, $isCompositeGroup] = dumping_history_decode_group_id((int)$requestedGroupId);
        $where .= ' AND bi.grower_id=?';
        $types .= 'i';
        $params[] = $filterGrowerId;
        if ($isCompositeGroup) {
            $where .= ' AND COALESCE(bi.variety_id,0)=?';
            $types .= 'i';
            $params[] = $filterVarietyId;
        }
    }

    // The summary shown on the handheld is intentionally split by Grower + Variety.
    $summaryWhere = "bi.status='DUMPED'";
    if ($scope === 'today') {
        $summaryWhere .= " AND DATE($timeExpr)=CURDATE()";
    }
    $summarySql = "
        SELECT
            COALESCE(bi.grower_id,0) AS grower_id,
            COALESCE(bi.variety_id,0) AS variety_id,
            COALESCE(NULLIF(TRIM(gp.name),''),'Unknown Grower') AS grower,
            COALESCE(NULLIF(TRIM(vl.name),''),'Unknown Variety') AS variety,
            COUNT(*) AS total
        FROM bins_ingresso bi
        LEFT JOIN growers_list gp ON gp.id=bi.grower_id
        LEFT JOIN varieties_list vl ON vl.id=bi.variety_id
        WHERE $summaryWhere
        GROUP BY COALESCE(bi.grower_id,0), COALESCE(bi.variety_id,0), gp.name, vl.name
        ORDER BY grower ASC, variety ASC
    ";
    $summaryResult = $mysqli->query($summarySql);
    if (!$summaryResult) throw new RuntimeException('Unable to read dumping group totals');

    $growerTotals = [];
    while ($g = $summaryResult->fetch_assoc()) {
        $gid = (int)$g['grower_id'];
        $vid = (int)$g['variety_id'];
        $groupId = dumping_history_group_id(max(0, $gid), max(0, $vid));
        $growerName = trim((string)$g['grower']);
        $varietyName = trim((string)$g['variety']);
        $label = $growerName;
        if ($varietyName !== '' && strcasecmp($varietyName, 'Unknown Variety') !== 0) {
            $label .= ' · ' . $varietyName;
        }
        $growerTotals[] = [
            'grower_id' => $groupId,
            'grower' => $label,
            'variety' => $varietyName,
            'total' => (int)$g['total'],
        ];
    }
    $summaryResult->free();

    $countSql = "SELECT COUNT(*) AS total FROM bins_ingresso bi WHERE $where";
    $countStmt = $mysqli->prepare($countSql);
    if (!$countStmt) throw new RuntimeException('Unable to prepare dumped bin count');
    if ($types === 'i') {
        $countStmt->bind_param('i', $filterGrowerId);
    } elseif ($types === 'ii') {
        $countStmt->bind_param('ii', $filterGrowerId, $filterVarietyId);
    }
    if (!$countStmt->execute()) throw new RuntimeException('Unable to read dumped bin total');
    $countResult = $countStmt->get_result();
    $total = (int)($countResult->fetch_assoc()['total'] ?? 0);
    $countResult->free();
    $countStmt->close();

    if ($offset >= $total) {
        $offset = $total === 0 ? 0 : intdiv($total - 1, $limit) * $limit;
    }

    $listSql = "
        SELECT
            bi.id,
            COALESCE(bi.barcode,'') AS barcode,
            COALESCE(NULLIF(TRIM(gp.name),''),'Unknown Grower') AS grower,
            COALESCE(NULLIF(TRIM(vl.name),''),'') AS variety,
            COALESCE(NULLIF(TRIM(tl.name),''),'') AS type,
            COALESCE(bi.lot,'') AS lot,
            $timeExpr AS dumped_at
        FROM bins_ingresso bi
        LEFT JOIN growers_list gp ON gp.id=bi.grower_id
        LEFT JOIN varieties_list vl ON vl.id=bi.variety_id
        LEFT JOIN bin_types_list tl ON tl.id=bi.type_id
        WHERE $where
        ORDER BY $timeExpr DESC, bi.id DESC
        LIMIT ? OFFSET ?
    ";
    $listStmt = $mysqli->prepare($listSql);
    if (!$listStmt) throw new RuntimeException('Unable to prepare dumping history');
    if ($types === '') {
        $listStmt->bind_param('ii', $limit, $offset);
    } elseif ($types === 'i') {
        $listStmt->bind_param('iii', $filterGrowerId, $limit, $offset);
    } else {
        $listStmt->bind_param('iiii', $filterGrowerId, $filterVarietyId, $limit, $offset);
    }
    if (!$listStmt->execute()) throw new RuntimeException('Unable to read dumping history');

    $result = $listStmt->get_result();
    $bins = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        if (trim((string)$row['barcode']) === '') {
            $row['barcode'] = 'FBIN-' . str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT);
        }
        $row['dumped_at'] = (string)($row['dumped_at'] ?? '');
        $bins[] = $row;
    }
    $result->free();
    $listStmt->close();

    $mysqli->commit();
    $transactionOpen = false;

    dumping_history_reply([
        'ok' => true,
        'scope' => $scope,
        'grower_id' => (int)$requestedGroupId,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'has_more' => $offset + count($bins) < $total,
        'server_time' => (string)($server['server_time'] ?? ''),
        'business_date' => (string)($server['business_date'] ?? ''),
        'grower_totals' => $growerTotals,
        'bins' => $bins,
    ]);
} catch (Throwable $error) {
    if ($transactionOpen && isset($mysqli) && $mysqli instanceof mysqli) {
        @$mysqli->rollback();
    }
    error_log('[Dumping history] ' . $error->getMessage());
    dumping_history_reply(['ok' => false, 'reason' => 'server_error'], 500);
}
