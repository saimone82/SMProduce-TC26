from pathlib import Path

# Start from the existing 2.0.42 patch, then move this test build to a brand-new package
# so Android cannot confuse it with any previously installed TC26 build/signature.

gradle = Path('pallets-shipping-android/app/build.gradle')
s = gradle.read_text()
s = s.replace("applicationId 'com.smproduce.palletsshipping.tablet.tools'", "applicationId 'com.smproduce.palletsshipping.tc26final'")
s = s.replace('versionCode 62', 'versionCode 63')
s = s.replace("versionName '2.0.42-tc26'", "versionName '2.0.42-tc26-final'")
gradle.write_text(s)

manifest = Path('pallets-shipping-android/app/src/main/AndroidManifest.xml')
m = manifest.read_text()
m = m.replace('android:name=".MainActivity"', 'android:name="com.smproduce.palletsshipping.MainActivity"')
m = m.replace('android:label="Pallets / Shipping TC26 2.0.40"', 'android:label="Pallets / Shipping TC26 2.0.42"')
manifest.write_text(m)
