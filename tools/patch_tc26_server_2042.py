from pathlib import Path
p = Path('webapp/api/pallets_shipping_app.php')
s = p.read_text()
old = """    $st = smp_tc26_shipment_status($db, $sid);\n    if (empty($st['ok'])) return $st;"""
new = """    $st = smp_tc26_shipment_status($db, $sid);\n    if (empty($st['ok'])) return $st;\n    $pc = smp_db_fetch_one($db, \"SELECT COUNT(DISTINCT pallet_id) c FROM shipment_pallets WHERE shipment_id=?\", [$sid]);\n    $st['pallet_count'] = (int)($pc['c'] ?? 0);"""
if old not in s:
    raise SystemExit('shipment detail block not found')
s = s.replace(old, new, 1)
old = """    if ($action === 'pallet_new') {\n        $pid = smp_tc26_open_pallet($dbx, $uid, '');\n        ps_out(ps_pallet_detail($dbx, $pid));\n    }"""
new = """    if ($action === 'pallet_new') {\n        $empty = smp_db_fetch_one($dbx, \"SELECT p.pallet_id FROM pallets p LEFT JOIN pallet_cases pc ON pc.pallet_id=p.pallet_id WHERE UPPER(COALESCE(NULLIF(TRIM(p.status),''),'OPEN'))='OPEN' GROUP BY p.pallet_id HAVING COUNT(pc.id)=0 ORDER BY MIN(p.created_at) ASC,p.pallet_id ASC LIMIT 1\");\n        $pid = !empty($empty['pallet_id']) ? (string)$empty['pallet_id'] : smp_tc26_open_pallet($dbx, $uid, '');\n        ps_out(ps_pallet_detail($dbx, $pid));\n    }"""
if old not in s:
    raise SystemExit('pallet_new block not found')
s = s.replace(old, new, 1)
old = """        $pid = ps_normalize_scan_code((string)($input['pallet_id'] ?? ''));\n        $existing = smp_db_fetch_one($dbx,"""
new = """        $pid = ps_normalize_scan_code((string)($input['pallet_id'] ?? ''));\n        if (!preg_match('/^P\\d+$/', $pid)) ps_out(['ok'=>0,'err'=>'Invalid pallet code']);\n        $existing = smp_db_fetch_one($dbx,"""
if old not in s:
    raise SystemExit('shipment scan block not found')
s = s.replace(old, new, 1)
p.write_text(s)
