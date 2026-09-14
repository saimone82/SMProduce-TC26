"""Fail the build for lost base code, packaged ABI stubs or unresolved app calls."""
import hashlib
import sys
import zipfile
from loguru import logger
logger.remove()
from androguard.core.apk import APK
from androguard.core.dex import DEX

base_path,out_path=sys.argv[1:3]
assert hashlib.sha256(open(base_path,'rb').read()).hexdigest()=='c37446076d1f7d7cbf6fd8c02583d0e02b942eb5424b383a246e56f7ef74168a'
with zipfile.ZipFile(base_path) as base,zipfile.ZipFile(out_path) as out:
 for name in base.namelist():
  if name.endswith('.dex') or name.startswith('assets/'):
   assert out.read(name)==base.read(name),'Original 36 file changed: '+name
 assert 'classes7.dex' in out.namelist()
 assert len([n for n in out.namelist() if n.endswith('.dex')])==7

apk=APK(out_path)
assert apk.get_package()=='com.smproduce.palletsshipping.tc26base36r49'
assert apk.get_androidversion_name()=='2.0.49-base36'
assert apk.get_androidversion_code()=='70'
assert apk.get_main_activity()=='com.smproduce.palletsshipping.Recovery36Activity'
classes={}
for raw in apk.get_all_dex():
 for c in DEX(raw).get_classes():
  assert c.get_name() not in classes,'Duplicate class: '+c.get_name()
  classes[c.get_name()]=c
recovery=classes['Lcom/smproduce/palletsshipping/Recovery36Activity;']
assert recovery.get_superclassname()=='Lcom/smproduce/palletsshipping/TC26Activity;'

def resolve(owner,name,desc,kind):
 while owner in classes:
  c=classes[owner]
  members=c.get_methods() if kind=='method' else c.get_fields()
  if any(x.get_name()==name and x.get_descriptor().replace(' ','')==desc.replace(' ','') for x in members):return True
  owner=c.get_superclassname()
 return not owner.startswith('Lcom/smproduce/')

with zipfile.ZipFile(out_path) as z:overlay=DEX(z.read('classes7.dex'))
for c in overlay.get_classes():
 assert c.get_name().startswith('Lcom/smproduce/palletsshipping/Recovery36Activity'), 'Compile-only stub packaged: '+c.get_name()
for item in overlay.get_methods():
 owner,name,desc=item.get_class_name(),item.get_name(),item.get_descriptor()
 if owner.startswith('Lcom/smproduce/'):
  assert resolve(owner,name,desc,'method'),('Unresolved method',owner,name,desc)
for item in overlay.get_fields():
 owner,name,desc=item.get_class_name(),item.get_name(),item.get_descriptor()
 if owner.startswith('Lcom/smproduce/'):
  assert resolve(owner,name,desc,'field'),('Unresolved field',owner,name,desc)

methods={m.get_name():m for m in recovery.get_methods()}
for name in ['onScan','goNext','requestScanner','requestPalletEditPassword','callUrl','callScanOrQueue']:
 assert any('requireNetwork' in i.get_output() for i in methods[name].get_instructions()),'Offline guard absent: '+name
assert any('networkAvailable' in i.get_output() for i in methods['requestUrl'].get_instructions())
assert all(i.get_name()=='return-void' for i in methods['syncQueue'].get_instructions())
assert not any('->add(' in i.get_output() and 'QueueDb' in i.get_output() for m in recovery.get_methods() for i in m.get_instructions())
assert not any(i.get_output().strip('"')=='ping' for m in recovery.get_methods() for i in m.get_instructions() if i.get_name().startswith('const-string'))
print('PASS: all 6 original 36 DEX files and voice assets unchanged; no duplicate classes/stubs; app ABI resolved; offline entry guards and inert sync queue verified.')
print('APK_SHA256',hashlib.sha256(open(out_path,'rb').read()).hexdigest())
