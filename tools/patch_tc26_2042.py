from pathlib import Path

java = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/MainActivity.java')
s = java.read_text()
old = '''    void scanPallet(String code){
        if(!online){queue.add("PALLET",shipmentId,code);palletCount++;ok(tr("Saved offline: ","Guardado sin conexión: ")+code);render();return;}
        callScanOrQueue(map("action","shipment_scan_pallet","shipment_id",shipmentId,"pallet_id",code),"PALLET",shipmentId,code,j->{palletCount=j.optInt("pallet_count",palletCount+1);shipmentCases=j.optInt("cases_count",shipmentCases);JSONObject m=j.optJSONObject("multi");if(m!=null)applyComparison(m,true);ok(code);render();if(selectedOrder!=null&&m==null)checkPoAfterScan();});
    }'''
new = '''    void scanPallet(String code){
        final String palletCode=code==null?"":code.trim().toUpperCase(Locale.ROOT);
        if(!palletCode.matches("P\\\\d+")){error(tr("Scan a valid pallet code beginning with P","Escanee un código de pallet válido que comience con P"));return;}
        if(!online){
            if(queue.add("PALLET",shipmentId,palletCode)){palletCount++;ok(tr("Saved offline: ","Guardado sin conexión: ")+palletCode);}
            else error(tr("Pallet already scanned in this shipment","Pallet ya escaneado en este envío"));
            render();return;
        }
        callScanOrQueue(map("action","shipment_scan_pallet","shipment_id",shipmentId,"pallet_id",palletCode),"PALLET",shipmentId,palletCode,j->{palletCount=j.optInt("pallet_count",palletCount);shipmentCases=j.optInt("cases_count",shipmentCases);JSONObject m=j.optJSONObject("multi");if(m!=null)applyComparison(m,true);ok(palletCode);render();if(selectedOrder!=null&&m==null)checkPoAfterScan();});
    }'''
if old not in s:
    raise SystemExit('scanPallet block not found')
java.write_text(s.replace(old,new))

gradle = Path('pallets-shipping-android/app/build.gradle')
s = gradle.read_text().replace('versionCode 61','versionCode 62').replace("versionName '2.0.41-tc26'","versionName '2.0.42-tc26'")
gradle.write_text(s)
