"""Only update APK identity/launcher; never rewrite the original 36 DEX files."""
from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET

root=Path(sys.argv[1])
ns='http://schemas.android.com/apk/res/android'
ET.register_namespace('android',ns)
file=root/'AndroidManifest.xml'
tree=ET.parse(file)
app=tree.getroot().find('application')
assert app is not None
app.set('{'+ns+'}label','Pallet Shipping 49 · Base 36')
launcher=app.find("activity[@{"+ns+"}name='com.smproduce.palletsshipping.TC26Activity']")
assert launcher is not None
launcher.set('{'+ns+'}name','com.smproduce.palletsshipping.Recovery36Activity')
tree.write(file,encoding='utf-8',xml_declaration=True)
yaml=root/'apktool.yml'
text=yaml.read_text()
for pattern,replacement in [
 (r'(?m)^  renameManifestPackage: .+$','  renameManifestPackage: com.smproduce.palletsshipping.tc26base36r49'),
 (r'(?m)^  versionCode: .+$','  versionCode: 70'),
 (r'(?m)^  versionName: .+$','  versionName: 2.0.49-base36')]:
 text,n=re.subn(pattern,replacement,text)
 assert n==1,(pattern,n)
yaml.write_text(text)
