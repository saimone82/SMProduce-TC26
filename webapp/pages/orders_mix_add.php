<?php
ob_start();
require_once __DIR__.'/../config/user_functions.php';
if (session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/users_local.php';
require_once __DIR__.'/../config/orders_sql_lib.php';
require_once __DIR__.'/../config/orders_mix_lib.php';
if (!user_has_permission('orders')) { http_response_code(403); exit('Access denied'); }
orders_sql_init();
$db=orders_db();
if(!$db) exit('Orders DB unavailable');
orders_mix_init($db);
$clients=orders_fetch_clients_sql();
$skus=orders_fetch_skus_sql();
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $po=trim((string)($_POST['po']??'')); $client=(int)($_POST['client_id']??0); $date=trim((string)($_POST['ship_date']??''));
  $qty=(int)($_POST['quantity']??0); $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['sku_ids']??[])),fn($v)=>$v>0)));
  $pack=trim((string)($_POST['packaging_preset']??''));
  if($po===''||$client<=0||$date===''||$qty<=0||count($ids)<2) $err='PO, client, ship date, quantity and at least 2 SKUs are required.';
  else {
    $saveErr=null;
    $oid=orders_create_sql(['po'=>$po,'client_id'=>$client,'ship_date'=>$date,'notes'=>trim((string)($_POST['notes']??'')),'pick_location'=>trim((string)($_POST['pick_location']??'')),'ship_to_address'=>trim((string)($_POST['ship_to_address']??'')),'dest_city'=>trim((string)($_POST['dest_city']??'')),'status'=>'Open','lines'=>[['sku_id'=>$ids[0],'quantity'=>$qty,'packaging_preset'=>$pack]]],$saveErr);
    if($oid>0){
      $st=$db->prepare('SELECT id FROM order_lines WHERE order_id=? ORDER BY id DESC LIMIT 1');$st->execute([$oid]);$lid=(int)$st->fetchColumn();
      orders_mix_set_line($db,$lid,$ids,true);
      header('Location: orders.php?created=1');exit;
    }
    $err='Unable to save MIX order. '.($saveErr??'');
  }
}
include '../includes/header.php';
?>
<div class="container-fluid py-4 px-3 px-xl-4"><div class="card shadow-sm"><div class="card-body p-4">
<div class="d-flex justify-content-between align-items-center mb-4"><div><h2 class="mb-1">New MIX Order</h2><div class="text-muted">One order line, multiple allowed SKUs, one combined target quantity.</div></div><a class="btn btn-outline-secondary" href="orders.php">Back</a></div>
<?php if($err):?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif;?>
<form method="post"><div class="row g-3">
<div class="col-md-4"><label class="form-label fw-bold">PO</label><input name="po" class="form-control" required></div>
<div class="col-md-4"><label class="form-label fw-bold">Client</label><select name="client_id" class="form-select" required><option value="">Select...</option><?php foreach($clients as $c):?><option value="<?=(int)$c['id']?>"><?=htmlspecialchars($c['client_name'])?></option><?php endforeach;?></select></div>
<div class="col-md-4"><label class="form-label fw-bold">Ship Date</label><input type="date" name="ship_date" value="<?=date('Y-m-d')?>" class="form-control" required></div>
<div class="col-md-8"><label class="form-label fw-bold">Allowed SKUs <span class="badge bg-warning text-dark">MIX</span></label><select name="sku_ids[]" class="form-select" multiple size="12" required><?php foreach($skus as $s): $label=($s['sku_id']??'').' — '.trim(($s['variety']??'').' / '.($s['packaging']??'').' / '.($s['size']??''));?><option value="<?=(int)$s['sku_id']?>"><?=htmlspecialchars($label)?></option><?php endforeach;?></select><div class="form-text">Ctrl/Cmd-click to select 2 or more SKUs. Any selected SKU counts toward the same target.</div></div>
<div class="col-md-4"><label class="form-label fw-bold">Combined Qty</label><input type="number" min="1" name="quantity" class="form-control form-control-lg" required><div class="form-text">Example: 33 total cases across all selected SKUs.</div><label class="form-label fw-bold mt-3">Packaging preset</label><input name="packaging_preset" class="form-control"></div>
<div class="col-md-4"><label class="form-label">Pick Up Location</label><input name="pick_location" class="form-control"></div><div class="col-md-4"><label class="form-label">Dest. City</label><input name="dest_city" class="form-control"></div><div class="col-md-4"><label class="form-label">Ship To</label><textarea name="ship_to_address" class="form-control" rows="2"></textarea></div>
<div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
<div class="col-12"><button class="btn btn-primary btn-lg">Save MIX Order</button></div>
</div></form></div></div></div>
<?php include '../includes/footer.php'; ?>