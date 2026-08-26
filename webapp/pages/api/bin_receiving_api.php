<?php
// SM Produce Bins Receiving API v1.1.2
header('Content-Type: application/json; charset=utf-8');

$TOKEN = 'SMTC26_SECURE_2026';
$incoming = (string)($_SERVER['HTTP_X_APP_TOKEN'] ?? $_GET['token'] ?? '');
if (!hash_equals($TOKEN, $incoming)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user'] = [
    'username' => 'Bins Receiving Tablet',
    'name' => 'Bins Receiving Tablet',
    'role' => 'warehouse',
];

require_once __DIR__ . '/../../config/db_remote.php';
$action = trim((string)($_REQUEST['api_action'] ?? 'presets'));

if ($action === 'presets') {
    $readNames = function(string $table) use ($mysqli): array {
        $out=[];
        $q=$mysqli->query("SELECT name FROM `$table` ORDER BY name ASC");
        if ($q) while($r=$q->fetch_assoc()) $out[]=(string)$r['name'];
        return $out;
    };
    echo json_encode([
        'ok'=>true,
        'growers'=>$readNames('growers_list'),
        'binTypes'=>$readNames('bin_types_list'),
        'varieties'=>$readNames('varieties_list'),
    ]);
    exit;
}

if ($action === 'add_grower') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Grower name is required']);
        exit;
    }
    if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);
    $stmt = $mysqli->prepare("INSERT IGNORE INTO growers_list(name) VALUES(?)");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Cannot prepare grower insert']);
        exit;
    }
    $stmt->bind_param('s', $name);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'Cannot save grower']);
        exit;
    }
    echo json_encode(['ok'=>true,'name'=>$name,'msg'=>'Grower saved']);
    exit;
}

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_SERVER['REQUEST_METHOD'] = 'POST';
$wantPrint = ((string)($_POST['print'] ?? '1') !== '0');
$_POST['skip_print'] = $wantPrint ? '0' : '1';

if ($action === 'save_empty') {
    $_POST['save_empty'] = '1';
    $_POST['grower'] = trim((string)($_POST['grower'] ?? ''));
    $_POST['type'] = trim((string)($_POST['type'] ?? ''));
    $_POST['quantity'] = max(0, (int)($_POST['quantity'] ?? 0));
    $_POST['date'] = trim((string)($_POST['date'] ?? date('Y-m-d')));
    $_POST['carrier'] = trim((string)($_POST['carrier'] ?? ''));
    $_POST['notes'] = trim((string)($_POST['notes'] ?? ''));
    require __DIR__ . '/../empty_bin_receiving.php';
    exit;
}

if ($action === 'save_full') {
    $_POST['action'] = 'add';
    $_POST['grower'] = trim((string)($_POST['grower'] ?? ''));
    $_POST['type'] = trim((string)($_POST['type'] ?? ''));
    $_POST['variety'] = trim((string)($_POST['variety'] ?? ''));
    $_POST['lot'] = trim((string)($_POST['lot'] ?? ''));
    $_POST['quantity'] = max(1, (int)($_POST['quantity'] ?? 1));
    $_POST['date'] = trim((string)($_POST['date'] ?? date('Y-m-d')));
    $_POST['notes'] = trim((string)($_POST['notes'] ?? ''));
    require __DIR__ . '/../bins_ingresso.php';
    exit;
}

http_response_code(400);
echo json_encode(['ok'=>false,'error'=>'Unknown action']);
