from pathlib import Path
import re

main = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/MainActivity.java')
s = main.read_text()

# A hard guard used whenever an operation could mutate production data.
marker = '    String text(){return manual==null?"":manual.getText().toString().trim();}\n\n'
guard = '''    String text(){return manual==null?"":manual.getText().toString().trim();}\n\n    boolean requireOnlineOperation(){\n        if(online)return true;\n        error(tr("OFFLINE — connect to the server before continuing. No operation was saved.",\n                 "SIN CONEXIÓN — conéctese al servidor antes de continuar. No se guardó ninguna operación."));\n        return false;\n    }\n\n'''
if marker not in s: raise SystemExit('text marker missing')
s = s.replace(marker, guard, 1)

# Never create a local/fake pallet while offline.
old = '''    void newPallet(){
        if(!online){
            palletId="OFFP-"+System.currentTimeMillis();
            queue.add("CREATE_PALLET",palletId,palletId);
            caseCount=0;scannedCases.clear();casesExpanded=false;scanErrorMessage="";step=Step.PALLET_SCAN;render();
            ok(tr("Offline pallet created","Pallet sin conexión creado"));return;
        }
        call(map("action","pallet_new"),j->{palletId=j.optString("pallet_id");caseCount=j.optInt("cases_count");scannedCases.clear();casesExpanded=false;scanErrorMessage="";step=Step.PALLET_SCAN;render();});
    }
'''
new = '''    void newPallet(){
        if(!requireOnlineOperation())return;
        call(map("action","pallet_new"),j->{palletId=j.optString("pallet_id");caseCount=j.optInt("cases_count");scannedCases.clear();casesExpanded=false;scanErrorMessage="";step=Step.PALLET_SCAN;render();});
    }
'''
if old not in s: raise SystemExit('newPallet offline block not found')
s = s.replace(old,new,1)

# Global scan lock: no case/pallet/state-changing scan is accepted while offline.
old = '    void onScan(String raw){\n        String code=raw==null?"":raw.trim();if(code.isEmpty()||busy)return;\n'
new = '    void onScan(String raw){\n        if(!online){requireOnlineOperation();return;}\n        String code=raw==null?"":raw.trim();if(code.isEmpty()||busy)return;\n'
if old not in s: raise SystemExit('onScan marker not found')
s = s.replace(old,new,1)

# Palletizing scan: no offline queue.
s, n = re.subn(
    r'''    void scanCase\(String code\)\{\n        scanErrorMessage="";\n        if\(!online\)\{.*?\n        \}\n        callScanOrQueue''',
    '''    void scanCase(String code){\n        scanErrorMessage="";\n        if(!requireOnlineOperation())return;\n        callScanOrQueue''',
    s, count=1, flags=re.S)
if n != 1: raise SystemExit('scanCase offline block patch failed')

# Shipping pallet scan: no offline queue.
s, n = re.subn(
    r'''    void scanPallet\(String code\)\{\n        final String palletCode=.*?\n        if\(!palletCode\.matches\("P\\\\d\+"\)\)\{.*?\}\n        if\(!online\)\{.*?\n        \}\n        callScanOrQueue''',
    '''    void scanPallet(String code){\n        final String palletCode=code==null?"":code.trim().toUpperCase(Locale.ROOT);\n        if(!palletCode.matches("P\\\\d+")){error(tr("Scan a valid pallet code beginning with P","Escanee un código de pallet válido que comience con P"));return;}\n        if(!requireOnlineOperation())return;\n        callScanOrQueue''',
    s, count=1, flags=re.S)
if n != 1: raise SystemExit('scanPallet offline block patch failed')

# Remove-last must also be server-confirmed; do not modify local counters offline.
s, n = re.subn(
    r'''    void removeLast\(boolean cases\)\{\n        if\(!online\)\{.*? return; \}\n        call\(''',
    '''    void removeLast(boolean cases){\n        if(!requireOnlineOperation())return;\n        call(''',
    s, count=1, flags=re.S)
if n != 1: raise SystemExit('removeLast offline branch patch failed')

# Most important protection: a network exception during a scan is NOT queued locally.
old_fragment = '''        catch(Exception e){boolean added=queue.add(type,parent,code);runOnUiThread(()->{busy=false;setOnlineState(false);if(!added&&type.equals("CASE")){showDuplicateCase();return;}if(added){if(type.equals("CASE")){caseCount++;if(!scannedCases.contains(code))scannedCases.add(code);}else palletCount++;ok(tr("Saved offline: ","Guardado sin conexión: ")+code);}render();});}});\n'''
new_fragment = '''        catch(Exception e){runOnUiThread(()->{busy=false;setOnlineState(false);error(tr("Connection lost — operation NOT saved. Reconnect and scan again.","Conexión perdida — operación NO guardada. Reconecte y escanee de nuevo."));render();});}});\n'''
if old_fragment not in s: raise SystemExit('callScanOrQueue catch not found')
s = s.replace(old_fragment,new_fragment,1)

# Never replay any legacy offline queue automatically in this locked version.
s, n = re.subn(
    r'''    void syncQueue\(\)\{io\.execute\(\(\)->\{.*?\}\);\}\n''',
    '''    void syncQueue(){\n        // Offline mutation is intentionally disabled. Nothing is queued or replayed.\n    }\n''',
    s, count=1, flags=re.S)
if n != 1: raise SystemExit('syncQueue patch failed')

# Remove the misleading synchronization message from scan screens.
s = s.replace('String scanStatus=tr("Ready to scan","Listo para escanear")+(online?"":"\\n"+tr("Scans will be synchronized automatically","Las lecturas se sincronizarán automáticamente"));',
              'String scanStatus=online?tr("Ready to scan","Listo para escanear"):tr("OFFLINE — operations disabled","SIN CONEXIÓN — operaciones deshabilitadas");',1)

# Version/package identity for a side-by-side install without debug-signature collisions.
gradle = Path('pallets-shipping-android/app/build.gradle')
g = gradle.read_text()
g = g.replace("applicationId 'com.smproduce.palletsshipping.tc26complete247'", "applicationId 'com.smproduce.palletsshipping.tc26complete248'")
g = g.replace('versionCode 68','versionCode 69')
g = g.replace("versionName '2.0.47-tc26'", "versionName '2.0.48-tc26'")
gradle.write_text(g)

tc = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/Tc26MainActivity.java')
t = tc.read_text().replace('p.put("app_version","2.0.47-tc26")','p.put("app_version","2.0.48-tc26")')
tc.write_text(t)
scanner = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/ScannerActivity.java')
sc = scanner.read_text().replace('app_version=2.0.47-tc26','app_version=2.0.48-tc26')
scanner.write_text(sc)

# Safety assertions: these strings must be gone from the generated 2.0.48 source.
for forbidden in ['OFFP-','Offline pallet created','Saved offline: ','Scans will be synchronized automatically','queue.add(type,parent,code)']:
    if forbidden in s: raise SystemExit('forbidden offline mutation remains: '+forbidden)
for required in ['requireOnlineOperation()','operation NOT saved','Offline mutation is intentionally disabled']:
    if required not in s: raise SystemExit('missing safety marker: '+required)
main.write_text(s)
