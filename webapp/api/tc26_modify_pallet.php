<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors','0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/print_engine.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function tmp_out(array $data,int $status=200): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function tmp_norm(string $raw): string {
    $code=strtoupper(trim(preg_replace('/[\x00-\x20\x7F]+/','',$raw)??$raw));
    if($code!==''&&strlen($code)%2===0){$h=intdiv(strlen($code),2);if(substr($code,0,$h)===substr($code,$h))$code=substr($code,0,$h);}
    if(preg_match_all('/U\d{7}/',$code,$m)&&!empty($m[0])){$u=array_values(array_unique($m[0]));if(count($u)===1)return $u[0];}
    return $code;
}
function tmp_pin_ok(string $password): bool {
    // SHA-256 of the operational four-digit PIN. Clear text is intentionally not stored here.
    return hash_equals('aa82088246685c17ebf16d48877686b831ed384ffdc42e76494283c271704d7a',hash('sha256',$password));
}
function tmp_require_pin(array $input): void {
    if(!tmp_pin_ok((string)($input['password']??'')))tmp_out(['ok'=>0,'err'=>'Incorrect password'],403);
}
function tmp_case_lookup($db,string $serial): ?array {
    $sql="SELECT * FROM casecodes WHERE UPPER(TRIM(serial))=UPPER(TRIM(?)) LIMIT 1";
    if($db instanceof PDO){$st=$db->prepare($sql);$st->execute([$serial]);$r=$st->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
    if($db instanceof mysqli){$st=$db->prepare($sql);if(!$st)return null;$st->bind_param('s',$serial);$st->execute();$res=$st->get_result();$r=$res?$res->fetch_assoc():null;$st->close();return is_array($r)?$r:null;}
    return null;
}
function tmp_detail($db,string $pid): array {
    $p=smp_db_fetch_one($db,"SELECT pallet_id,UPPER(COALESCE(NULLIF(status,''),'OPEN')) status,COALESCE(is_partial,0) is_partial FROM pallets WHERE pallet_id=? LIMIT 1",[$pid]);
    if(!$p)return ['ok'=>0,'err'=>'Pallet not found'];
    $cases=smp_db_fetch_all($db,"SELECT id,case_serial,sku,variety,grower,lot,size,packaging,DATE_FORMAT(scanned_at,'%Y-%m-%d %H:%i:%s') scanned_at FROM pallet_cases WHERE pallet_id=? ORDER BY id DESC",[$pid]);
    return ['ok'=>1,'pallet_id'=>$pid,'status'=>(string)$p['status'],'is_partial'=>(int)$p['is_partial'],'cases_count'=>count($cases),'cases'=>$cases];
}
function tmp_closed_shipment($db,string $pid): ?array {
    try{return smp_db_fetch_one($db,"SELECT s.shipment_id FROM shipment_pallets sp JOIN shipments s ON s.shipment_id=sp.shipment_id WHERE sp.pallet_id=? AND UPPER(COALESCE(s.status,''))='CLOSED' LIMIT 1",[$pid])?:null;}catch(Throwable $e){return null;}
}
function tmp_begin($db): void { if($db instanceof PDO)$db->beginTransaction(); elseif($db instanceof mysqli)$db->begin_transaction(); }
function tmp_commit($db): void { if($db instanceof PDO)$db->commit(); elseif($db instanceof mysqli)$db->commit(); }
function tmp_rollback($db): void { try{if($db instanceof PDO&&$db->inTransaction())$db->rollBack();elseif($db instanceof mysqli)$db->rollback();}catch(Throwable $e){} }

$cfg=require __DIR__.'/../config/pallets_shipping_app.php';
if(empty($cfg['enabled']))tmp_out(['ok'=>0,'err'=>'App API disabled'],503);
$provided=trim((string)($_SERVER['HTTP_X_APP_TOKEN']??$_POST['token']??''));
if($provided===''||!hash_equals((string)$cfg['token'],$provided))tmp_out(['ok'=>0,'err'=>'Unauthorized'],401);
$dbx=$pdo??$conn??$mysqli??null;
if(!$dbx)tmp_out(['ok'=>0,'err'=>'Database unavailable'],503);
$input=$_POST;
if(stripos((string)($_SERVER['CONTENT_TYPE']??''),'application/json')!==false){$d=json_decode((string)file_get_contents('php://input'),true);if(is_array($d))$input=$d;}
$action=trim((string)($input['action']??''));

try{
    if($action==='ping')tmp_out(['ok'=>1,'api_version'=>'modify-1.0','server_time'=>date(DATE_ATOM)]);
    if($action==='verify_password'){tmp_require_pin($input);tmp_out(['ok'=>1]);}

    if($action==='edit_open'){
        tmp_require_pin($input);
        $pid=tmp_norm((string)($input['pallet_id']??''));
        if($pid==='')tmp_out(['ok'=>0,'err'=>'Missing pallet_id'],400);
        $closed=tmp_closed_shipment($dbx,$pid);
        if($closed)tmp_out(['ok'=>0,'err'=>'This pallet belongs to closed shipment '.$closed['shipment_id'].' and cannot be modified']);
        // Read-only open: do not change pallet status until an actual edit or final status selection occurs.
        tmp_out(tmp_detail($dbx,$pid));
    }

    if($action==='lookup_case'){
        tmp_require_pin($input);
        $serial=tmp_norm((string)($input['case_serial']??''));
        if($serial==='')tmp_out(['ok'=>0,'err'=>'Missing case_serial'],400);
        $row=smp_db_fetch_one($dbx,"SELECT pallet_id FROM pallet_cases WHERE case_serial=? ORDER BY id DESC LIMIT 1",[$serial]);
        tmp_out(['ok'=>1,'found'=>$row?1:0,'case_serial'=>$serial,'pallet_id'=>(string)($row['pallet_id']??'')]);
    }

    if($action==='edit_case'){
        tmp_require_pin($input);
        $pid=tmp_norm((string)($input['pallet_id']??''));
        $serial=tmp_norm((string)($input['case_serial']??''));
        $mode=strtoupper(trim((string)($input['mode']??'')));
        if($pid===''||$serial==='')tmp_out(['ok'=>0,'err'=>'Missing pallet or case code'],400);
        if(!in_array($mode,['ADD','REMOVE'],true))tmp_out(['ok'=>0,'err'=>'Invalid edit mode'],400);
        $closed=tmp_closed_shipment($dbx,$pid);
        if($closed)tmp_out(['ok'=>0,'err'=>'This pallet belongs to closed shipment '.$closed['shipment_id'].' and cannot be modified']);
        if(!smp_db_fetch_one($dbx,"SELECT pallet_id FROM pallets WHERE pallet_id=? LIMIT 1",[$pid]))tmp_out(['ok'=>0,'err'=>'Pallet not found']);
        smp_db_exec($dbx,"UPDATE pallets SET status='OPEN',is_partial=0,closed_at=NULL WHERE pallet_id=?",[$pid]);
        if($mode==='REMOVE'){
            $row=smp_db_fetch_one($dbx,"SELECT id FROM pallet_cases WHERE pallet_id=? AND case_serial=? LIMIT 1",[$pid,$serial]);
            if(!$row)tmp_out(['ok'=>0,'err'=>'Case '.$serial.' is not on this pallet']);
            $res=smp_tc26_remove_case($dbx,(int)$row['id'],$pid);if(empty($res['ok']))tmp_out($res);
            tmp_out(tmp_detail($dbx,$pid));
        }
        $case=tmp_case_lookup($dbx,$serial);
        if(!$case)tmp_out(['ok'=>0,'err'=>'Case '.$serial.' not found in casecodes']);
        $existing=smp_db_fetch_one($dbx,"SELECT pallet_id FROM pallet_cases WHERE case_serial=? LIMIT 1",[$serial]);
        if($existing&&strcasecmp((string)$existing['pallet_id'],$pid)===0)tmp_out(['ok'=>0,'err'=>'Case already scanned on this pallet']);
        if($existing)tmp_out(['ok'=>0,'err'=>'Case '.$serial.' already belongs to pallet '.$existing['pallet_id']]);
        $res=smp_tc26_add_case_to_pallet($dbx,$pid,$serial,[
            'user_id'=>0,
            'sku'=>(string)($case['SKU']??$case['sku']??''),
            'variety'=>(string)($case['variety']??$case['Variety']??''),
            'grower'=>(string)($case['grower']??$case['Grower']??''),
            'size'=>(string)($case['size']??$case['Size']??''),
            'packaging'=>(string)($case['packaging']??$case['Packaging']??''),
            'crop'=>(string)($case['crop']??$case['Crop']??''),
            'lot'=>(string)($case['lot']??$case['Lot']??''),
            'pack_date'=>(string)($case['pack_date']??$case['PackDate']??''),
        ]);
        if(empty($res['ok']))tmp_out($res);
        tmp_out(tmp_detail($dbx,$pid));
    }

    if($action==='printers'){
        $rows=smp_db_fetch_all($dbx,"SELECT id,COALESCE(NULLIF(TRIM(name),''),NULLIF(TRIM(printer_name),''),CONCAT('Printer ',id)) name,COALESCE(dpi,0) dpi FROM printers_list WHERE enabled=1 ORDER BY id ASC");
        tmp_out(['ok'=>1,'printers'=>$rows]);
    }

    if($action==='set_status'){
        tmp_require_pin($input);
        $pid=tmp_norm((string)($input['pallet_id']??''));
        $status=strtoupper(trim((string)($input['status']??'')));
        $print=filter_var($input['print_label']??false,FILTER_VALIDATE_BOOLEAN);
        $printerId=(int)($input['printer_id']??0);
        if($pid==='')tmp_out(['ok'=>0,'err'=>'Missing pallet_id'],400);
        if(!in_array($status,['OPEN','PARTIAL','COMPLETE'],true))tmp_out(['ok'=>0,'err'=>'Invalid status'],400);
        $closed=tmp_closed_shipment($dbx,$pid);
        if($closed)tmp_out(['ok'=>0,'err'=>'This pallet belongs to closed shipment '.$closed['shipment_id'].' and cannot be modified']);
        if($status==='OPEN'){
            smp_db_exec($dbx,"UPDATE pallets SET status='OPEN',is_partial=0,closed_at=NULL WHERE pallet_id=?",[$pid]);
            $res=tmp_detail($dbx,$pid);
        }elseif($status==='PARTIAL'){
            $res=smp_tc26_partial_pallet($dbx,$pid,0,0);
            if(empty($res['ok']))tmp_out($res);
            $res=array_merge(tmp_detail($dbx,$pid),$res);
        }else{
            $res=smp_tc26_close_pallet($dbx,$pid,0,0);
            if(empty($res['ok']))tmp_out($res);
            $res=array_merge(tmp_detail($dbx,$pid),$res);
        }
        $res['label_printed']=0;$res['print_skipped']=$print?0:1;
        if($print){
            if($printerId<=0)iftmp_out(['ok'=>0,'err'=>'Choose a printer before printing']);
            $printer=smp_db_fetch_one($dbx,"SELECT id FROM printers_list WHERE id=? AND enabled=1 LIMIT 1",[$printerId]);
            if(!$printer)tmp_out(['ok'=>0,'err'=>'Selected printer is not active']);
            $res['label_printed']=smp_tc26_print_pallet_label($dbx,$pid,$printerId,$status==='PARTIAL')?1:0;
            if(!$res['label_printed']){$res['ok']=0; $res['err']='Pallet saved but label could not be sent to the selected printer'; tmp_out($res);}
        }
        if(function_exists('ps_pallet_session_clear')){try{ps_pallet_session_clear($dbx,$pid);}catch(Throwable $e){}}
        tmp_out($res);
    }

    if($action==='delete_pallet'){
        tmp_require_pin($input);
        $pid=tmp_norm((string)($input['pallet_id']??''));
        if($pid==='')tmp_out(['ok'=>0,'err'=>'Missing pallet_id'],400);
        if(!smp_db_fetch_one($dbx,"SELECT pallet_id FROM pallets WHERE pallet_id=? LIMIT 1",[$pid]))tmp_out(['ok'=>0,'err'=>'Pallet not found']);
        $closed=tmp_closed_shipment($dbx,$pid);
        if($closed)tmp_out(['ok'=>0,'err'=>'This pallet belongs to closed shipment '.$closed['shipment_id'].' and cannot be deleted']);
        $count=(int)(smp_db_fetch_one($dbx,"SELECT COUNT(*) c FROM pallet_cases WHERE pallet_id=?",[$pid])['c']??0);
        tmp_begin($dbx);
        try{
            try{smp_db_exec($dbx,"DELETE FROM tc26_shipment_case_allocations WHERE pallet_id=?",[$pid]);}catch(Throwable $e){}
            smp_db_exec($dbx,"DELETE FROM shipment_pallets WHERE pallet_id=?",[$pid]);
            smp_db_exec($dbx,"DELETE FROM pallet_cases WHERE pallet_id=?",[$pid]);
            smp_db_exec($dbx,"DELETE FROM pallets WHERE pallet_id=?",[$pid]);
            tmp_commit($dbx);
        }catch(Throwable $e){tmp_rollback($dbx);throw $e;}
        if(function_exists('ps_pallet_session_clear')){try{ps_pallet_session_clear($dbx,$pid);}catch(Throwable $e){}}
        tmp_out(['ok'=>1,'deleted'=>1,'pallet_id'=>$pid,'released_cases'=>$count]);
    }

    tmp_out(['ok'=>0,'err'=>'Unknown action'],400);
}catch(Throwable $e){
    tmp_out(['ok'=>0,'err'=>'Server error','detail'=>$e->getMessage()],500);
}
