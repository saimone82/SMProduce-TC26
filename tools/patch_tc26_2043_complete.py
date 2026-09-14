from pathlib import Path

# Restore the complete launcher + integrated scanner source.
java_dir = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping')
java_dir.mkdir(parents=True, exist_ok=True)
(java_dir / 'Tc26CompleteActivity.java').write_text(Path('tools/tc26_complete_activity.b64').read_text())
(java_dir / 'ScannerActivity.java').write_text(Path('tools/tc26_scanner_activity.b64').read_text())

# New side-by-side package for the tested complete build, and use the real API directly.
gradle = Path('pallets-shipping-android/app/build.gradle')
s = gradle.read_text()
s = s.replace("applicationId 'com.smproduce.palletsshipping.tablet.tools'", "applicationId 'com.smproduce.palletsshipping.tc26complete'")
s = s.replace('versionCode 62', 'versionCode 64')
s = s.replace("versionName '2.0.42-tc26'", "versionName '2.0.43-tc26-complete'")
s = s.replace('https://smproduceprod.uk/api/pallets_shipping_tc26_app.php', 'https://smproduceprod.uk/api/pallets_shipping_app.php')
gradle.write_text(s)

# Launch the subclass that contains the protected Modify Pallet logic and Scanner entry.
manifest = Path('pallets-shipping-android/app/src/main/AndroidManifest.xml')
m = manifest.read_text()
m = m.replace('android:label="Pallets / Shipping TC26 2.0.40"', 'android:label="Pallets / Shipping TC26 2.0.43"')
m = m.replace('android:name=".Tc26MainActivity"', 'android:name="com.smproduce.palletsshipping.Tc26CompleteActivity"')
if 'com.smproduce.palletsshipping.ScannerActivity' not in m:
    marker = '        <activity android:name="com.smproduce.palletsshipping.Tc26CompleteActivity"'
    scanner = '        <activity android:name="com.smproduce.palletsshipping.ScannerActivity" android:screenOrientation="portrait" android:exported="false"/>\n'
    m = m.replace(marker, scanner + marker)
manifest.write_text(m)

# Keep the app version reported to the server aligned with the build.
tc26 = java_dir / 'Tc26MainActivity.java'
t = tc26.read_text().replace('2.0.40-tc26', '2.0.43-tc26')
tc26.write_text(t)

# Add the protected read-only scanner API actions to the same endpoint used for ping/pallet/shipping.
api = Path('webapp/api/pallets_shipping_app.php')
a = api.read_text()
ping = "    if ($action === 'ping') ps_out(['ok'=>1, 'api_version'=>'1.4.2', 'server_time'=>date(DATE_ATOM)]);\n"
scanner_block = r'''

    if (in_array($action, ['scanner_unlock','scanner_case_lookup','scanner_pallet_lookup'], true)) {
        $scannerPasswordHash = 'aa82088246685c17ebf16d48877686b831ed384ffdc42e76494283c271704d7a';
        if (!hash_equals($scannerPasswordHash, hash('sha256', (string)($input['password'] ?? '')))) {
            ps_out(['ok'=>0,'err'=>'Incorrect scanner password'], 403);
        }
        if ($action === 'scanner_unlock') ps_out(['ok'=>1]);
        if ($action === 'scanner_case_lookup') {
            $serial = ps_normalize_scan_code((string)($input['case_serial'] ?? ''));
            if ($serial === '') ps_out(['ok'=>0,'err'=>'Missing case_serial'], 400);
            $row = smp_db_fetch_one($dbx,
                "SELECT pallet_id FROM pallet_cases WHERE case_serial=? ORDER BY id DESC LIMIT 1", [$serial]);
            ps_out(['ok'=>1,'found'=>$row ? 1 : 0,'case_serial'=>$serial,'pallet_id'=>(string)($row['pallet_id'] ?? '')]);
        }
        $pid = ps_normalize_scan_code((string)($input['pallet_id'] ?? ''));
        if ($pid === '') ps_out(['ok'=>0,'err'=>'Missing pallet_id'], 400);
        $pallet = smp_db_fetch_one($dbx, "SELECT pallet_id FROM pallets WHERE pallet_id=? LIMIT 1", [$pid]);
        $rows = $pallet ? smp_db_fetch_all($dbx,
            "SELECT case_serial FROM pallet_cases WHERE pallet_id=? ORDER BY id", [$pid]) : [];
        $cases = array_map(static fn(array $r): string => (string)$r['case_serial'], $rows);
        ps_out(['ok'=>1,'found'=>$pallet ? 1 : 0,'pallet_id'=>$pid,'case_count'=>count($cases),'cases'=>$cases]);
    }
'''
if 'scanner_case_lookup' not in a:
    if ping not in a:
        raise SystemExit('ping marker not found')
    a = a.replace(ping, ping + scanner_block, 1)
api.write_text(a)

# Fix the print validation typo in the Modify Pallet endpoint.
modify = Path('webapp/api/tc26_modify_pallet.php')
ms = modify.read_text()
ms = ms.replace("if($printerId<=0)iftmp_out(['ok'=>0,'err'=>'Choose a printer before printing']);",
                "if($printerId<=0) tmp_out(['ok'=>0,'err'=>'Choose a printer before printing']);")
modify.write_text(ms)
