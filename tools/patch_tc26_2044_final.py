from pathlib import Path

java_dir = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping')
java_dir.mkdir(parents=True, exist_ok=True)
(java_dir / 'TC26FinalActivity.java').write_text(Path('tools/tc26_final_activity_2044.java.txt').read_text())
(java_dir / 'ScannerActivity.java').write_text(Path('tools/tc26_scanner_activity.b64').read_text().replace('2.0.43-tc26','2.0.44-tc26-final'))

gradle = Path('pallets-shipping-android/app/build.gradle')
s = gradle.read_text()
s = s.replace("applicationId 'com.smproduce.palletsshipping.tablet.tools'", "applicationId 'com.smproduce.palletsshipping.tc26final244'")
s = s.replace('versionCode 62', 'versionCode 65')
s = s.replace("versionName '2.0.42-tc26'", "versionName '2.0.44-tc26-final'")
s = s.replace('https://smproduceprod.uk/api/pallets_shipping_tc26_app.php', 'https://smproduceprod.uk/api/pallets_shipping_app.php')
gradle.write_text(s)

manifest = Path('pallets-shipping-android/app/src/main/AndroidManifest.xml')
m = manifest.read_text()
m = m.replace('android:label="Pallets / Shipping TC26 2.0.40"', 'android:label="SM Produce TC26 2.0.44 FINAL"')
m = m.replace('android:name=".Tc26MainActivity"', 'android:name="com.smproduce.palletsshipping.TC26FinalActivity"')
if 'com.smproduce.palletsshipping.ScannerActivity' not in m:
    marker = '        <activity android:name="com.smproduce.palletsshipping.TC26FinalActivity"'
    scanner = '        <activity android:name="com.smproduce.palletsshipping.ScannerActivity" android:screenOrientation="portrait" android:exported="false"/>\n'
    m = m.replace(marker, scanner + marker)
manifest.write_text(m)

tc26 = java_dir / 'Tc26MainActivity.java'
t = tc26.read_text().replace('2.0.40-tc26', '2.0.44-tc26-final')
tc26.write_text(t)
