from pathlib import Path

p = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/MainActivity.java')
s = p.read_text()

old = '''    void scanPallet(String code){
        if(!online){queue.add("PALLET",shipmentId,code);palletCount++;ok(tr("Saved offline: ","Guardado sin conexión: ")+code);render();return;}
        callScanOrQueue(map("action","shipment_scan_pallet","shipment_id",shipmentId,"pallet_id",code),"PALLET",shipmentId,code,j->{palletCount=j.optInt("pallet_count",palletCount+1);shipmentCases=j.optInt("cases_count",shipmentCases);JSONObject m=j.optJSONObject("multi");if(m!=null)applyComparison(m,true);ok(code);render();if(selectedOrder!=null&&m==null)checkPoAfterScan();});
    }
'''
new = '''    void scanPallet(String code){
        final String palletCode=code==null?"":code.trim().toUpperCase(Locale.ROOT);
        if(!palletCode.matches("P\\\\d+")){error(tr("Scan a valid pallet code beginning with P","Escanee un código de pallet válido que comience con P"));return;}
        if(!online){
            if(queue.add("PALLET",shipmentId,palletCode)){palletCount++;ok(tr("Saved offline: ","Guardado sin conexión: ")+palletCode);}
            else error(tr("Pallet already scanned in this shipment","Pallet ya escaneado en este envío"));
            render();return;
        }
        callScanOrQueue(map("action","shipment_scan_pallet","shipment_id",shipmentId,"pallet_id",palletCode),"PALLET",shipmentId,palletCode,j->{palletCount=j.optInt("pallet_count",palletCount);shipmentCases=j.optInt("cases_count",shipmentCases);JSONObject m=j.optJSONObject("multi");if(m!=null)applyComparison(m,true);ok(palletCode);render();if(selectedOrder!=null&&m==null)checkPoAfterScan();});
    }
'''
if old not in s:
    raise SystemExit('scanPallet block not found')
s = s.replace(old, new, 1)

old = '''                choice("Resume Partial Pallet","Continuar pallet parcial",v->{step=Step.PALLET_RESUME;render();});
                Button editPallet=choice("Modify Existing Pallet","Modificar pallet existente",v->requestPalletEditPassword());editPallet.setBackgroundColor(Color.rgb(180,83,9)); break;'''
new = '''                choice("Resume Partial Pallet","Continuar pallet parcial",v->{step=Step.PALLET_RESUME;render();});
                break;'''
if old not in s:
    raise SystemExit('large Modify button block not found')
s = s.replace(old, new, 1)
p.write_text(s)
