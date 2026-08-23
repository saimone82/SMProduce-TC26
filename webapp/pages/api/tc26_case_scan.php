<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../../config/db_remote.php';
require_once __DIR__ . '/../../includes/production_scan_ingest.php';
function tc26_json(array $p,int $s=200): never { http_response_code($s); echo json_encode($p,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') tc26_json(['ok'=>false,'accepted'=>false,'error'=>'POST required'],405);
$cfgPath=__DIR__.'/../../config/tc26_case_scanner.json';
$cfg=is_file($cfgPath)?json_decode((string)file_get_contents($cfgPath),true):[];
$expected=trim((string)($cfg['api_key']??'')); $provided=trim((string)($_SERVER['HTTP_X_API_KEY']??''));
if($expected===''||$provided===''||!hash_equals($expected,$provided)) tc26_json(['ok'=>false,'accepted'=>false,'error'=>'Unauthorized'],401);
$code=strtoupper(trim((string)($_POST['code']??''))); $device=trim((string)($_POST['device']??'TC26')); if($device==='')$device='TC26'; $device=mb_substr($device,0,255);
if($code==='') tc26_json(['ok'=>false,'accepted'=>false,'error'=>'Missing barcode'],400);
try{$r=pscan_ingest($mysqli,$code,'handheld_hid',$device,false);if(($r['ok']??false)!==true)tc26_json($r,500);tc26_json($r,200);}catch(Throwable $e){tc26_json(['ok'=>false,'accepted'=>false,'reason'=>'server_exception','error'=>$e->getMessage(),'code'=>$code],500);}
