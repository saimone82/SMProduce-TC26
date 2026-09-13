from pathlib import Path
import re

main = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/MainActivity.java')
s = main.read_text()

# A mutation must never be accepted while the API is offline.
marker = '    String text(){return manual==null?"":manual.getText().toString().trim();}\n\n'
helper = '''    String text(){return manual==null?"":manual.getText().toString().trim();}\n\n    void offlineBlocked(){\n        error(tr("OFFLINE — operation blocked. Connect to the server and wait for ONLINE before continuing.",\n                 "SIN CONEXIÓN — operación bloqueada. Conéctese al servidor y espere EN LÍNEA antes de continuar."));\n    }\n\n'''
if marker not in s:
    raise SystemExit('text() marker missing')
s = s.replace(marker, helper, 1)

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
        if(!online){offlineBlocked();return;}
        call(map("action","pallet_new"),j->{palletId=j.optString("pallet_id");caseCount=j.optInt("cases_count");scannedCases.clear();casesExpanded=false;scanErrorMessage="";step=Step.PALLET_SCAN;render();});
    }
'''
if old not in s:
    raise SystemExit('newPallet offline block not found')
s = s.replace(old, new, 1)

s = s.replace(
    '        String code=raw==null?"":raw.trim();if(code.isEmpty()||busy)return;\n',
    '        String code=raw==null?"":raw.trim();if(code.isEmpty()||busy)return;if(!online){offlineBlocked();return;}\n',
    1)

old = '''    void scanCase(String code){
        scanErrorMessage="";
        if(!online){
            if(scannedCases.contains(code)||queue.exists("CASE",palletId,code)){showDuplicateCase();return;}
            if(queue.add("CASE",palletId,code)){caseCount++;scannedCases.add(code);ok(tr("Saved offline: ","Guardado sin conexión: ")+code);}
            else showDuplicateCase();
            render();return;
        }
        callScanOrQueue(map("action","pallet_scan_case","pallet_id",palletId,"case_serial",code),"CASE",palletId,code,j->{caseCount=j.optInt("cases_count",caseCount+1);loadCases(j);ok(code);render();});
    }
'''
new = '''    void scanCase(String code){
        scanErrorMessage="";
        if(!online){offlineBlocked();return;}
        callScanOrQueue(map("action","pallet_scan_case","pallet_id",palletId,"case_serial",code),"CASE",palletId,code,j->{caseCount=j.optInt("cases_count",caseCount+1);loadCases(j);ok(code);render();});
    }
'''
if old not in s:
    raise SystemExit('scanCase offline block not found')
s = s.replace(old, new, 1)

# 2.0.46 normalized pallet code; preserve that validation but remove its offline queue path.
pattern = re.compile(r'''    void scanPallet\(String code\)\{\n        final String palletCode=code==null\?"":code\.trim\(\)\.toUpperCase\(Locale\.ROOT\);\n        if\(!palletCode\.matches\("P\\\\d\+"\)\)\{error\(tr\("Scan a valid pallet code beginning with P","Escanee un código de pallet válido que comience con P"\)\);return;\}\n        if\(!online\)\{.*?\n        \}\n        callScanOrQueue\(map\("action","shipment_scan_pallet","shipment_id",shipmentId,"pallet_id",palletCode\),"PALLET",shipmentId,palletCode,j->\{palletCount=j\.optInt\("pallet_count",palletCount\);shipmentCases=j\.optInt\("cases_count",shipmentCases\);JSONObject m=j\.optJSONObject\("multi"\);if\(m!=null\)applyComparison\(m,true\);ok\(palletCode\);render\(\);if\(selectedOrder!=null&&m==null\)checkPoAfterScan\(\);\}\);\n    \}\n''', re.S)
replacement = '''    void scanPallet(String code){
        final String palletCode=code==null?"":code.trim().toUpperCase(Locale.ROOT);
        if(!palletCode.matches("P\\\\d+")){error(tr("Scan a valid pallet code beginning with P","Escanee un código de pallet válido que comience con P"));return;}
        if(!online){offlineBlocked();return;}
        callScanOrQueue(map("action","shipment_scan_pallet","shipment_id",shipmentId,"pallet_id",palletCode),"PALLET",shipmentId,palletCode,j->{palletCount=j.optInt("pallet_count",palletCount);shipmentCases=j.optInt("cases_count",shipmentCases);JSONObject m=j.optJSONObject("multi");if(m!=null)applyComparison(m,true);ok(palletCode);render();if(selectedOrder!=null&&m==null)checkPoAfterScan();});
    }
'''
s, n = pattern.subn(lambda m: replacement, s, count=1)
if n != 1:
    raise SystemExit('scanPallet post-2046 block not found')

s, n = re.subn(
    r'''    void removeLast\(boolean cases\)\{\n        if\(!online\)\{.*?return; \}\n        call\(''',
    '''    void removeLast(boolean cases){\n        if(!online){offlineBlocked();return;}\n        call(''',
    s, count=1, flags=re.S)
if n != 1:
    raise SystemExit('removeLast offline path not found')

# Network failure during a scan is also a hard failure. Never convert it into an offline queue entry.
pattern = re.compile(r'''    void callScanOrQueue\(Map<String,String> data,String type,String parent,String code,Success success\)\{.*?\n    \}\n    void callUrl''', re.S)
replacement = '''    void callScanOrQueue(Map<String,String> data,String type,String parent,String code,Success success){
        if(busy)return;
        if(!online){offlineBlocked();return;}
        busy=true;
        io.execute(()->{try{
            JSONObject j=request(data);
            runOnUiThread(()->{busy=false;if(j.optInt("ok")==1)success.run(j);else{
                String msg=localizeError(j.optString("err",tr("Operation failed","Operación fallida")));
                String low=msg.toLowerCase();
                if(low.contains("already scanned")||low.contains("ya fue escaneada")||low.contains("ya escaneada")){
                    scanErrorMessage=tr("CASE ALREADY SCANNED","CAJA YA ESCANEADA");tone.startTone(ToneGenerator.TONE_CDMA_ABBR_ALERT,350);render();
                }else error(msg);
            }});
        }catch(Exception e){
            runOnUiThread(()->{busy=false;setOnlineState(false);offlineBlocked();render();});
        }});
    }
    void callUrl'''
s, n = pattern.subn(lambda m: replacement, s, count=1)
if n != 1:
    raise SystemExit('callScanOrQueue function not found')

# No old local queue is ever auto-submitted by this protected build.
s, n = re.subn(r'    void syncQueue\(\)\{.*?\n    \}\n    void refreshCurrent',
                 '    void syncQueue(){ /* Offline mutation queue intentionally disabled. */ }\n    void refreshCurrent',
                 s, count=1, flags=re.S)
if n != 1:
    raise SystemExit('syncQueue function not found')

s = s.replace(
    'String scanStatus=tr("Ready to scan","Listo para escanear")+(online?"":"\\n"+tr("Scans will be synchronized automatically","Las lecturas se sincronizarán automáticamente"));',
    'String scanStatus=online?tr("Ready to scan","Listo para escanear"):tr("OFFLINE — OPERATIONS BLOCKED","SIN CONEXIÓN — OPERACIONES BLOQUEADAS");',
    1)

# Version reported by the Android base is provided by Tc26MainActivity, but keep package/version unique for install safety.
main.write_text(s)

# TC26 activity + scanner report the new cumulative build number.
tc = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/Tc26MainActivity.java')
t = tc.read_text().replace('p.put("app_version","2.0.47-tc26")','p.put("app_version","2.0.48-tc26")',1)
tc.write_text(t)

scanner = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/ScannerActivity.java')
sc = scanner.read_text().replace('app_version=2.0.47-tc26','app_version=2.0.48-tc26')
scanner.write_text(sc)

gradle = Path('pallets-shipping-android/app/build.gradle')
g = gradle.read_text()
g = g.replace("applicationId 'com.smproduce.palletsshipping.tc26complete247'", "applicationId 'com.smproduce.palletsshipping.tc26complete248'")
g = g.replace('versionCode 68','versionCode 69')
g = g.replace("versionName '2.0.47-tc26'", "versionName '2.0.48-tc26'")
gradle.write_text(g)

# Guardrails: these strings/paths must not survive in the protected source.
for forbidden in ['Offline pallet created','Saved offline: ','Scans will be synchronized automatically','queue.add("CREATE_PALLET"']:
    if forbidden in s:
        raise SystemExit('offline mutation path remains: '+forbidden)
for required in ['offlineBlocked()','OFFLINE — OPERATIONS BLOCKED','Offline mutation queue intentionally disabled']:
    if required not in s:
        raise SystemExit('hard-online guard missing: '+required)
