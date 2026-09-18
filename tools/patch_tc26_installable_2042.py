from pathlib import Path

# Start from the current 2.0.41 source, then apply the 2.0.42 scan-count fix.
exec(Path('tools/patch_tc26_2042.py').read_text(), {})

# Use a brand-new persistent package id so Android cannot reject the APK because
# an older TC26 build with a different certificate is still installed/cached.
gradle = Path('pallets-shipping-android/app/build.gradle')
s = gradle.read_text()
s = s.replace("applicationId 'com.smproduce.palletsshipping.tablet.tools'", "applicationId 'com.smproduce.palletsshipping.tc26stable'")
s = s.replace('versionCode 62', 'versionCode 2042')
s = s.replace("versionName '2.0.42-tc26'", "versionName '2.0.42-tc26-stable'")
gradle.write_text(s)

# Use the fully-qualified Activity name because applicationId intentionally differs
# from the Java namespace.
manifest = Path('pallets-shipping-android/app/src/main/AndroidManifest.xml')
m = manifest.read_text().replace('android:name=".MainActivity"', 'android:name="com.smproduce.palletsshipping.MainActivity"')
manifest.write_text(m)
