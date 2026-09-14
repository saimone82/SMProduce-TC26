from pathlib import Path
import re

# ---- Main TC26 flow: cleaner buttons, no FRONT/REAR camera controls ----
main = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/MainActivity.java')
s = main.read_text()

s, n = re.subn(
    r'    Button button\(String s,int color\)\{[^\n]*\}\n',
    '''    Button button(String s,int color){
        Button b=new Button(this);b.setText(s);b.setTextColor(Color.WHITE);b.setTextSize(15);b.setAllCaps(false);
        b.setMinHeight(0);b.setMinWidth(0);b.setPadding(dp(12),0,dp(12),0);b.setStateListAnimator(null);b.setElevation(dp(1));
        android.graphics.drawable.GradientDrawable bg=new android.graphics.drawable.GradientDrawable();
        bg.setColor(color);bg.setCornerRadius(dp(10));bg.setStroke(dp(1),Color.argb(55,255,255,255));b.setBackground(bg);return b;
    }
''', s, count=1)
if n != 1: raise SystemExit('button() patch failed')

s, n = re.subn(
    r'    Button choice\(String en,String es,View\.OnClickListener l\)\{[^\n]*\}\n',
    '''    Button choice(String en,String es,View.OnClickListener l){
        Button b=button(tr(en,es),Color.rgb(25,118,210));b.setOnClickListener(l);
        body.addView(b,new LinearLayout.LayoutParams(-1,dp(58)));addSpace(10);return b;
    }
''', s, count=1)
if n != 1: raise SystemExit('choice() patch failed')

s, n = re.subn(
    r'    void addCameraButtons\(\)\{[^\n]*\}\n',
    '''    void addCameraButtons(){
        // Zebra TC26 uses the hardware scanner/DataWedge. Do not clutter operational
        // screens with FRONT/REAR camera controls.
    }
''', s, count=1)
if n != 1: raise SystemExit('addCameraButtons() patch failed')

main.write_text(s)

# ---- Full TC26 launcher: restore shipping popup and improve Modify Pallet status flow ----
tc = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/Tc26MainActivity.java')
t = tc.read_text()
t = t.replace('p.put("app_version","2.0.46-tc26")', 'p.put("app_version","2.0.47-tc26")', 1)

field_marker = '    private String loadedPalletStatus="";\n'
fields = '''    private String loadedPalletStatus="";
    private boolean shippingSummaryPending=false;
    private ShippingPalletDialog shippingPalletDialog;
    private Runnable deferredShippingPoCheck;
    private String palletScanFeedback;
'''
if field_marker not in t: raise SystemExit('TC26 field marker missing')
t = t.replace(field_marker, fields, 1)

# Rounded colored icon tiles using the existing TC26 barcode/pallet drawings.
t = t.replace(
    'ImageButton scanner=new ImageButton(this); scanner.setImageDrawable(new ScannerIcon()); scanner.setBackgroundColor(Color.TRANSPARENT); scanner.setPadding(dp(8),dp(8),dp(8),dp(8)); scanner.setContentDescription(tr("Scanner","Escáner")); scanner.setOnClickListener(v->requestScanner());',
    'ImageButton scanner=new ImageButton(this); scanner.setImageDrawable(new ScannerIcon()); android.graphics.drawable.GradientDrawable scannerBg=new android.graphics.drawable.GradientDrawable();scannerBg.setColor(Color.rgb(37,99,235));scannerBg.setCornerRadius(dp(10));scanner.setBackground(scannerBg); scanner.setPadding(dp(9),dp(9),dp(9),dp(9)); scanner.setContentDescription(tr("Scanner","Escáner")); scanner.setOnClickListener(v->requestScanner());', 1)
t = t.replace(
    'ImageButton modify=new ImageButton(this); modify.setImageDrawable(new ModifyIcon()); modify.setBackgroundColor(Color.TRANSPARENT); modify.setPadding(dp(7),dp(7),dp(7),dp(7)); modify.setContentDescription(tr("Modify Existing Pallet","Modificar pallet existente")); modify.setOnClickListener(v->requestPalletEditPassword());',
    'ImageButton modify=new ImageButton(this); modify.setImageDrawable(new ModifyIcon()); android.graphics.drawable.GradientDrawable modifyBg=new android.graphics.drawable.GradientDrawable();modifyBg.setColor(Color.rgb(180,83,9));modifyBg.setCornerRadius(dp(10));modify.setBackground(modifyBg); modify.setPadding(dp(8),dp(8),dp(8),dp(8)); modify.setContentDescription(tr("Modify Existing Pallet","Modificar pallet existente")); modify.setOnClickListener(v->requestPalletEditPassword());', 1)

old_status = '''        LinearLayout status=new LinearLayout(this);status.setOrientation(LinearLayout.HORIZONTAL);Button open=button("OPEN",Color.rgb(37,99,235));Button partial=button("PARTIAL",Color.rgb(180,83,9));Button complete=button("COMPLETE",Color.rgb(22,101,52));Button del=button("DELETE",Color.rgb(185,28,28));for(Button b:new Button[]{open,partial,complete,del})b.setTextSize(11);open.setOnClickListener(v->saveOpen());partial.setOnClickListener(v->showSaveChoice("PARTIAL"));complete.setOnClickListener(v->showSaveChoice("COMPLETE"));del.setOnClickListener(v->showDeletePallet());status.addView(open,new LinearLayout.LayoutParams(0,dp(52),1));status.addView(partial,new LinearLayout.LayoutParams(0,dp(52),1));status.addView(complete,new LinearLayout.LayoutParams(0,dp(52),1));status.addView(del,new LinearLayout.LayoutParams(0,dp(52),1));body.addView(status);
'''
new_status = '''        LinearLayout status=new LinearLayout(this);status.setOrientation(LinearLayout.HORIZONTAL);
        Button open=button("OPEN",Color.rgb(37,99,235));Button partial=button("PARTIAL",Color.rgb(180,83,9));Button closed=button("CLOSED",Color.rgb(22,101,52));
        for(Button b:new Button[]{open,partial,closed})b.setTextSize(12);
        open.setOnClickListener(v->showSaveChoice("OPEN"));partial.setOnClickListener(v->showSaveChoice("PARTIAL"));closed.setOnClickListener(v->showSaveChoice("COMPLETE"));
        LinearLayout.LayoutParams stateLp=new LinearLayout.LayoutParams(0,dp(50),1);stateLp.setMargins(dp(2),0,dp(2),0);
        status.addView(open,new LinearLayout.LayoutParams(0,dp(50),1));status.addView(partial,new LinearLayout.LayoutParams(0,dp(50),1));status.addView(closed,new LinearLayout.LayoutParams(0,dp(50),1));body.addView(status);
        addSpace(8);Button del=button(tr("DELETE PALLET","ELIMINAR PALLET"),Color.rgb(127,29,29));del.setTextSize(12);del.setOnClickListener(v->showDeletePallet());body.addView(del,new LinearLayout.LayoutParams(-1,dp(46)));
'''
if old_status not in t: raise SystemExit('Modify status row not found')
t = t.replace(old_status,new_status,1)

t, n = re.subn(
    r'    private void showSaveChoice\(String status\)\{[^\n]*\}\n',
    '''    private void showSaveChoice(String status){
        String label="COMPLETE".equals(status)?"CLOSED":status;
        String[] choices={tr("SAVE ONLY","SOLO GUARDAR"),tr("PRINT LABEL","IMPRIMIR ETIQUETA")};
        new AlertDialog.Builder(this).setTitle(tr("Save pallet as ","Guardar pallet como ")+label)
                .setItems(choices,(d,w)->{if(w==0)saveStatus(status,false,0);else choosePrinter(status);})
                .setNegativeButton(tr("Cancel","Cancelar"),null).show();
    }
''', t, count=1)
if n != 1: raise SystemExit('showSaveChoice patch failed')

# This source is patched by patch_tc26_2046_modify_base_api.py immediately before us.
t, n = re.subn(
    r'    private void saveOpen\(\)\{call\(map\("action","pallet_set_open","pallet_id",palletId,"password",modifyPassword\),j->done\("Pallet "\+palletId\+" → OPEN"\)\);\}\n',
    '''    private void saveOpen(boolean print,int printerId){
        call(map("action","pallet_set_open","pallet_id",palletId,"password",modifyPassword),j->{
            if(print)printExisting("OPEN",printerId);
            else done("Pallet "+palletId+" → OPEN"+tr(" · saved without printing"," · guardado sin imprimir"));
        });
    }
''', t, count=1)
if n != 1: raise SystemExit('saveOpen patched form not found')

old_save = '''    private void saveStatus(String status,boolean print,int printerId){
        if("COMPLETE".equals(status)){saveComplete(print,printerId);return;}
        call(map("action","pallet_partial_no_print","pallet_id",palletId,"password",modifyPassword),j->{if(print)printExisting(status,printerId);else done("Pallet "+palletId+" → "+status+tr(" · saved without printing"," · guardado sin imprimir"));});
    }
'''
new_save = '''    private void saveStatus(String status,boolean print,int printerId){
        if("OPEN".equals(status)){saveOpen(print,printerId);return;}
        if("COMPLETE".equals(status)){saveComplete(print,printerId);return;}
        call(map("action","pallet_partial_no_print","pallet_id",palletId,"password",modifyPassword),j->{
            if(print)printExisting("PARTIAL",printerId);
            else done("Pallet "+palletId+" → PARTIAL"+tr(" · saved without printing"," · guardado sin imprimir"));
        });
    }
'''
if old_save not in t: raise SystemExit('saveStatus patched form not found')
t = t.replace(old_save,new_save,1)
t = t.replace('done("Pallet "+palletId+" → COMPLETE"+tr(" · saved without printing"," · guardado sin imprimir"))',
              'done("Pallet "+palletId+" → CLOSED"+tr(" · saved without printing"," · guardado sin imprimir"))',1)

insert_marker = '    @Override protected void onActivityResult(int request,int result,Intent data)'
if insert_marker not in t: raise SystemExit('activity result marker missing')
shipping_methods = '''    @Override void callScanOrQueue(Map<String,String> params,String type,String parent,String serial,Success success){
        if(!"shipment_scan_pallet".equals(params.get("action"))){super.callScanOrQueue(params,type,parent,serial,success);return;}
        super.callScanOrQueue(params,type,parent,serial,result->{
            boolean duplicate=result.optInt("duplicate_ignored",0)==1||result.optBoolean("duplicate_ignored",false);
            palletScanFeedback=duplicate?tr("Pallet already present","Pallet ya presente"):tr("Pallet added","Pallet añadido");
            shippingSummaryPending=true;
            success.run(result);
            showShippingPalletSummary(serial,duplicate,result.optJSONObject("scanned_pallet"));
            palletScanFeedback=null;
        });
    }

    @Override void ok(String message){if(palletScanFeedback==null)super.ok(message);}

    private void showShippingPalletSummary(String serial,boolean duplicate,JSONObject summary){
        if(isFinishing()||isDestroyed()){shippingSummaryPending=false;return;}
        shippingPalletDialog=new ShippingPalletDialog(this,spanish,serial,duplicate,summary,()->{
            shippingPalletDialog=null;shippingSummaryPending=false;
            Runnable pending=deferredShippingPoCheck;deferredShippingPoCheck=null;if(pending!=null)pending.run();
        });
        shippingPalletDialog.show();
    }

    @Override void checkPoAfterScan(boolean showWarning){
        if(shippingSummaryPending){deferredShippingPoCheck=()->super.checkPoAfterScan(showWarning);return;}
        super.checkPoAfterScan(showWarning);
    }

    @Override void onScan(String value){if(shippingSummaryPending)return;super.onScan(value);}
    @Override void goBack(){if(shippingSummaryPending)return;super.goBack();}
    @Override void goNext(){if(shippingSummaryPending)return;super.goNext();}

'''
t = t.replace(insert_marker, shipping_methods + insert_marker,1)

# Extend existing destroy/reset safely.
t = t.replace('    @Override void resetHome(){editMode=EDIT_LOOKUP;editDirty=false;modifyPassword="";loadedPalletStatus="";super.resetHome();}\n}',
'''    @Override void resetHome(){editMode=EDIT_LOOKUP;editDirty=false;modifyPassword="";loadedPalletStatus="";shippingSummaryPending=false;deferredShippingPoCheck=null;super.resetHome();}
    @Override public void onDestroy(){if(shippingPalletDialog!=null)shippingPalletDialog.dismiss();shippingPalletDialog=null;deferredShippingPoCheck=null;super.onDestroy();}
}''',1)

for required in ['ShippingPalletDialog','SAVE ONLY','PRINT LABEL','"CLOSED"','showSaveChoice("OPEN")','pallet_set_open','pallet_partial_no_print','pallet_print_label']:
    if required not in t: raise SystemExit('missing '+required)
if 'saveOpen()' in t: raise SystemExit('old saveOpen call remains')
tc.write_text(t)

# ---- Scanner version string only; scanner stays read-only by design ----
scanner = Path('pallets-shipping-android/app/src/main/java/com/smproduce/palletsshipping/ScannerActivity.java')
sc = scanner.read_text().replace('app_version=2.0.46-tc26','app_version=2.0.47-tc26')
scanner.write_text(sc)

# ---- Install as a distinct TC26 package so the transient GitHub debug key cannot block installation ----
gradle = Path('pallets-shipping-android/app/build.gradle')
g = gradle.read_text()
g = g.replace("applicationId 'com.smproduce.palletsshipping.tc26complete246'", "applicationId 'com.smproduce.palletsshipping.tc26complete247'")
g = g.replace('versionCode 67','versionCode 68')
g = g.replace("versionName '2.0.46-tc26'", "versionName '2.0.47-tc26'")
gradle.write_text(g)
