<?php
/** MIX-aware wrapper. Intercepts compare; all other BOL/shipping actions use the existing API. */
$act=trim((string)($_POST['action']??$_GET['action']??''));
if($act!=='compare'){ require __DIR__.'/tc26_shipping_api.php'; exit; }
ob_start(); ini_set('display_errors','0'); error_reporting(0);
require_once __DIR__.'/../../includes/db.php';
$cfg=require __DIR__.'/../../config/pallets_shipping_app.php';
$token=trim((string)($_SERVER['HTTP_X_APP_TOKEN']??''));
ob_clean();header('Content-Type: application/json; charset=utf-8');
if(empty($cfg['enabled'])||$token===''||!hash_equals((string)$cfg['token'],$token)){http_response_code(401);echo json_encode(['ok'=>0,'err'=>'Unauthorized']);exit;}
$db=$pdo??$conn??$mysqli??null;if(!$db){echo json_encode(['ok'=>0,'err'=>'DB unavailable']);exit;}
$sid=trim((string)($_POST['shipment_id']??''));$oid=(int)($_POST['order_id']??0);
if($sid===''||$oid<=0){echo json_encode(['ok'=>0,'err'=>'Missing shipment/order']);exit;}
try{
  $lines=smp_db_fetch_all($db,"SELECT ol.id,ol.sku_id,ol.quantity,COALESCE(ol.is_mix,0) is_mix FROM order_lines ol WHERE ol.order_id=? ORDER BY ol.id",[$oid])?:[];
  $members=[];
  try{$m=smp_db_fetch_all($db,"SELECT ols.order_line_id,ols.sku_id FROM order_line_skus ols JOIN order_lines ol ON ol.id=ols.order_line_id WHERE ol.order_id=? ORDER BY ols.id",[$oid])?:[];foreach($m as $x)$members[(int)$x['order_line_id']][]=(string)$x['sku_id'];}catch(Throwable $e){}
  $actual=smp_db_fetch_all($db,"SELECT CAST(COALESCE(NULLIF(pc.sku,''),cc.SKU) AS CHAR) sku,COUNT(*) qty FROM shipment_pallets sp JOIN pallet_cases pc ON pc.pallet_id=sp.pallet_id LEFT JOIN casecodes cc ON cc.serial=pc.case_serial WHERE sp.shipment_id=? GROUP BY CAST(COALESCE(NULLIF(pc.sku,''),cc.SKU) AS CHAR)",[$sid])?:[];
  $pool=[];$shipQty=0;foreach($actual as $a){$k=(string)$a['sku'];$pool[$k]=(int)$a['qty'];$shipQty+=(int)$a['qty'];}
  $skuLines=[];$poQty=0;$poVars=[];$shipVars=[];
  foreach($lines as $ln){$lid=(int)$ln['id'];$target=(int)$ln['quantity'];$poQty+=$target;$allowed=$members[$lid]??[(string)$ln['sku_id']];if(!$allowed)$allowed=[(string)$ln['sku_id']];$loaded=0;$breakdown=[];
    foreach($allowed as $sku){$have=(int)($pool[$sku]??0);if($have<=0)continue;$take=min($have,max(0,$target-$loaded));if($take>0){$loaded+=$take;$pool[$sku]-=$take;$breakdown[$sku]=$take;}if($loaded>=$target)break;}
    $skuLines[]=['line_id'=>$lid,'sku'=>implode(' / ',$allowed),'allowed_skus'=>$allowed,'is_mix'=>count($allowed)>1?1:(int)$ln['is_mix'],'required'=>$target,'loaded'=>$loaded,'remaining'=>max(0,$target-$loaded),'over'=>false,'extra'=>false,'breakdown'=>$breakdown];
  }
  $unallocated=array_sum($pool);$allOk=($shipQty===$poQty&&$unallocated===0);foreach($skuLines as $l)if($l['loaded']!==$l['required'])$allOk=false;
  $ord=smp_db_fetch_one($db,'SELECT po FROM orders WHERE id=?',[$oid])?:[];
  echo json_encode(['ok'=>1,'all_ok'=>$allOk,'po_qty'=>$poQty,'ship_qty'=>$shipQty,'po_name'=>$ord['po']??'','sku_lines'=>$skuLines,'unallocated_cases'=>$unallocated,'po_varieties'=>$poVars,'ship_varieties'=>$shipVars],JSON_UNESCAPED_UNICODE);exit;
}catch(Throwable $e){echo json_encode(['ok'=>0,'err'=>'MIX compare failed: '.$e->getMessage()]);exit;}
