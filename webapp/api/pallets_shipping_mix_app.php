<?php
/** MIX-aware wrapper for Pallets/Shipping app. Non-MIX actions fall through to the existing API. */
$act=trim((string)($_POST['action']??''));
if($act==='order_search'){
  require_once __DIR__.'/../includes/db.php';
  require_once __DIR__.'/../config/pallets_shipping_app.php';
  $cfg=require __DIR__.'/../config/pallets_shipping_app.php';
  $token=trim((string)($_SERVER['HTTP_X_APP_TOKEN']??''));
  header('Content-Type: application/json; charset=utf-8');
  if(empty($cfg['enabled'])||$token===''||!hash_equals((string)$cfg['token'],$token)){http_response_code(401);echo json_encode(['ok'=>0,'err'=>'Unauthorized']);exit;}
  $db=$pdo??$conn??$mysqli??null;if(!$db){echo json_encode(['ok'=>0,'err'=>'Database unavailable']);exit;}
  $q='%'.trim((string)($_POST['q']??'')).'%';
  $rows=smp_db_fetch_all($db,"SELECT o.id,o.po,COALESCE(o.customer,'') customer_name,o.status,
    CASE WHEN EXISTS(SELECT 1 FROM order_lines ol WHERE ol.order_id=o.id AND COALESCE(ol.is_mix,0)=1) THEN 1 ELSE 0 END has_mix
    FROM orders o WHERE UPPER(COALESCE(o.status,'OPEN'))='OPEN' AND (o.po LIKE ? OR COALESCE(o.customer,'') LIKE ?) ORDER BY o.id DESC LIMIT 50",[$q,$q]);
  echo json_encode(['ok'=>1,'orders'=>$rows]);exit;
}
require __DIR__.'/pallets_shipping_app.php';
