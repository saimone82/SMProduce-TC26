from pathlib import Path

java_dir = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping')
(java_dir / 'TC26FinalActivity.java').write_text(Path('tools/tc26_header_activity_2045.java.txt').read_text())

# Remove the large Modify Existing Pallet button from the Palletizing page.
# Modify remains available from the permanent top edit icon.
main = java_dir / 'MainActivity.java'
s = main.read_text()
old = '                Button editPallet=choice("Modify Existing Pallet","Modificar pallet existente",v->requestPalletEditPassword());editPallet.setBackgroundColor(Color.rgb(180,83,9)); break;'
new = '                break;'
if old not in s:
    raise SystemExit('Palletizing Modify button marker not found')
main.write_text(s.replace(old, new, 1))

# Keep Scanner's reported app version aligned.
scanner = java_dir / 'ScannerActivity.java'
ss = scanner.read_text().replace('2.0.44-tc26-final', '2.0.45-tc26')
scanner.write_text(ss)

# New side-by-side package so this build installs without signature conflicts.
gradle = Path('pallets-shipping-android/app/build.gradle')
g = gradle.read_text()
g = g.replace("applicationId 'com.smproduce.palletsshipping.tc26final244'", "applicationId 'com.smproduce.palletsshipping.tc26header245'")
g = g.replace('versionCode 65', 'versionCode 66')
g = g.replace("versionName '2.0.44-tc26-final'", "versionName '2.0.45-tc26'")
gradle.write_text(g)

manifest = Path('pallets-shipping-android/app/src/main/AndroidManifest.xml')
m = manifest.read_text()
m = m.replace('android:label="SM Produce TC26 2.0.44 FINAL"', 'android:label="Pallet Shipping"')
manifest.write_text(m)

tc26 = java_dir / 'Tc26MainActivity.java'
t = tc26.read_text().replace('2.0.44-tc26-final', '2.0.45-tc26')
tc26.write_text(t)
