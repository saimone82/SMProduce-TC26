from pathlib import Path

p = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/Tc26MainActivity.java')
s = p.read_text()
marker = '    private void saveOpen(){'
if marker not in s:
    raise SystemExit('saveOpen marker not found')
method = '''    @Override void finishPallet(boolean complete){
        if(!complete){super.finishPallet(false);return;}
        if(!online||queue.countFor(palletId)>0){error(tr("Wait for synchronization before finishing","Espere la sincronización antes de finalizar"));return;}
        Map<String,String> params=map("action","pallet_close","pallet_id",palletId);
        Success success=j->{
            if(j.optInt("label_printed",0)!=1){error(tr("Pallet saved, but the label was not sent. Check the printer selected in Pallets Manage.","Pallet guardado, pero la etiqueta no fue enviada. Compruebe la impresora seleccionada en Pallets Manage."));return;}
            done(tr("Pallet closed as complete and sent to print","Pallet cerrado como completo y enviado a imprimir"));
        };
        if(!PalletCompleteDialog.intercept(this,BuildConfig.API_URL,params,success))call(params,success);
    }

'''
s = s.replace(marker, method + marker, 1)
p.write_text(s)
