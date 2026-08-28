package com.smproduce.palletsshipping;

import android.app.*;
import android.content.*;
import android.database.Cursor;
import android.database.sqlite.*;
import android.graphics.Color;
import android.media.AudioManager;
import android.media.ToneGenerator;
import android.net.*;
import android.os.*;
import android.text.InputType;
import android.view.*;
import android.view.inputmethod.InputMethodManager;
import android.widget.*;
import org.json.*;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.util.*;
import java.util.concurrent.*;

public class MainActivity extends Activity {
    enum Step { HOME, PALLET_MODE, PALLET_RESUME, PALLET_SCAN, PALLET_FINISH,
                SHIP_MODE, SHIP_RESUME, SHIP_ORDER, SHIP_SCAN, SHIP_FINISH, DONE }
    Step step = Step.HOME;
    boolean spanish = false, online = false, busy = false;
    String palletId = "", shipmentId = "";
    int caseCount = 0, palletCount = 0, shipmentCases = 0;
    JSONObject selectedOrder;
    boolean shipmentMismatch = false;
    String shipmentCompareMessage = "";
    String scanErrorMessage = "";
    JSONArray shipmentSkuLines = new JSONArray();
    boolean casesExpanded = false;
    final ArrayList<String> scannedCases = new ArrayList<>();
    final ExecutorService io = Executors.newSingleThreadExecutor();
    final ToneGenerator tone = new ToneGenerator(AudioManager.STREAM_NOTIFICATION, 90);
    QueueDb queue;
    LinearLayout root, body, nav;
    TextView title, subtitle, progress, network, counter, detail;
    Button lang, back, next;
    EditText manual;
    BroadcastReceiver scannerReceiver, networkReceiver;
    final StringBuilder keyScanBuffer = new StringBuilder();
    long lastKeyAt = 0;
    String lastScanCode = "";
    long lastScanAt = 0;
    boolean probing = false;
    final Handler healthHandler = new Handler(Looper.getMainLooper());
    final Runnable healthRunnable = new Runnable(){
        @Override public void run(){ probeServer(); healthHandler.postDelayed(this, online?12000:3000); }
    };

    String tr(String en, String es) { return spanish ? es : en; }
    int dp(int n){ return Math.round(n * getResources().getDisplayMetrics().density); }

    @Override public void onCreate(Bundle b) {
        super.onCreate(b); queue = new QueueDb(this); buildShell(); registerReceivers(); configureDataWedge(); checkNetwork(); render();healthHandler.postDelayed(healthRunnable,1000);
    }
    @Override protected void onResume(){ super.onResume(); configureDataWedge(); }
    @Override public void onDestroy(){ super.onDestroy();healthHandler.removeCallbacks(healthRunnable);try{unregisterReceiver(scannerReceiver);}catch(Exception ignored){} try{unregisterReceiver(networkReceiver);}catch(Exception ignored){} io.shutdownNow(); tone.release(); }

    void buildShell(){
        root = new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL); root.setBackgroundColor(Color.rgb(11,22,34));
        LinearLayout header = new LinearLayout(this); header.setGravity(Gravity.CENTER_VERTICAL); header.setPadding(dp(18),dp(12),dp(12),dp(12)); header.setBackgroundColor(Color.rgb(19,32,51));
        LinearLayout htext = new LinearLayout(this); htext.setOrientation(LinearLayout.VERTICAL);
        TextView app = tv("Pallets / Shipping",20,Color.WHITE); app.setTypeface(null,1); htext.addView(app);
        network = tv("",12,Color.LTGRAY); htext.addView(network);
        header.addView(htext,new LinearLayout.LayoutParams(0,-2,1));
        lang = button("EN",Color.rgb(25,118,210)); lang.setOnClickListener(v->{spanish=!spanish; lang.setText(spanish?"ES":"EN"); render();}); header.addView(lang,new LinearLayout.LayoutParams(dp(64),dp(46)));
        root.addView(header);
        progress=tv("",12,Color.rgb(110,140,170)); progress.setPadding(dp(18),dp(12),dp(18),0); root.addView(progress);
        body=new LinearLayout(this); body.setOrientation(LinearLayout.VERTICAL); body.setGravity(Gravity.CENTER_HORIZONTAL); body.setPadding(dp(18),dp(16),dp(18),dp(12));
        ScrollView scroll=new ScrollView(this);scroll.setFillViewport(true);scroll.addView(body,new ScrollView.LayoutParams(-1,-2));
        root.addView(scroll,new LinearLayout.LayoutParams(-1,0,1));
        nav=new LinearLayout(this); nav.setPadding(dp(14),dp(10),dp(14),dp(14)); nav.setGravity(Gravity.CENTER);
        back=button("Back",Color.rgb(52,65,85)); next=button("Next",Color.rgb(25,118,210));
        back.setOnClickListener(v->goBack()); next.setOnClickListener(v->goNext());
        nav.addView(back,new LinearLayout.LayoutParams(0,dp(56),1)); Space sp=new Space(this); nav.addView(sp,new LinearLayout.LayoutParams(dp(12),1)); nav.addView(next,new LinearLayout.LayoutParams(0,dp(56),1)); root.addView(nav);
        setContentView(root);
    }

    TextView tv(String s,int size,int color){ TextView v=new TextView(this);v.setText(s);v.setTextSize(size);v.setTextColor(color);v.setPadding(0,dp(5),0,dp(5));return v; }
    Button button(String s,int color){ Button b=new Button(this);b.setText(s);b.setTextColor(Color.WHITE);b.setTextSize(16);b.setAllCaps(false);b.setBackgroundColor(color);return b; }
    void addSpace(int h){ Space s=new Space(this);body.addView(s,new LinearLayout.LayoutParams(1,dp(h))); }
    Button choice(String en,String es,View.OnClickListener l){Button b=button(tr(en,es),Color.rgb(25,118,210));b.setOnClickListener(l);body.addView(b,new LinearLayout.LayoutParams(-1,dp(64)));addSpace(14);return b;}

    void render(){
        body.removeAllViews(); manual=null; counter=null; detail=null;
        back.setText(tr("Back","Atrás")); next.setText(tr("Next","Siguiente"));
        back.setVisibility(step==Step.HOME?View.INVISIBLE:View.VISIBLE); next.setVisibility(View.GONE);
        int current=1,total=1;
        if(step.name().startsWith("PALLET")){ total=4; current=step==Step.PALLET_MODE?1:step==Step.PALLET_RESUME?2:step==Step.PALLET_SCAN?3:4; }
        if(step.name().startsWith("SHIP")){ total=5; current=step==Step.SHIP_MODE?1:step==Step.SHIP_RESUME?2:step==Step.SHIP_ORDER?3:step==Step.SHIP_SCAN?4:5; }
        progress.setText(total==1?"":tr("Step "+current+" of "+total,"Paso "+current+" de "+total));
        switch(step){
            case HOME: heading(tr("What would you like to do?","¿Qué desea hacer?"),tr("Choose one operation","Seleccione una operación"));
                choice("Palletizing","Paletización",v->{step=Step.PALLET_MODE;render();});
                choice("Shipping","Envíos",v->newShipment()); break;
            case PALLET_MODE: heading(tr("Palletizing","Paletización"),tr("What would you like to do?","¿Qué desea hacer?"));
                choice("New Pallet","Nuevo pallet",v->newPallet());
                choice("Resume Partial Pallet","Continuar pallet parcial",v->{step=Step.PALLET_RESUME;render();}); break;
            case PALLET_RESUME: scanQuestion(tr("Scan the partial pallet label","Escanee la etiqueta del pallet parcial"),tr("The pallet must have PARTIAL status","El pallet debe tener estado PARCIAL")); break;
            case PALLET_SCAN: scanScreen(true); break;
            case PALLET_FINISH: heading(tr("What would you like to do?","¿Qué desea hacer?"),palletId+" · "+caseCount+" "+tr("cases","cajas"));
                choice("Close as Complete","Cerrar como completo",v->finishPallet(true));
                choice("Close as Partial","Cerrar como parcial",v->finishPallet(false)); break;
            case SHIP_MODE: newShipment(); break;
            case SHIP_RESUME: scanQuestion(tr("Scan the open shipment label","Escanee la etiqueta del envío abierto"),tr("The shipment must still be open","El envío debe estar abierto")); break;
            case SHIP_ORDER: orderScreen(); break;
            case SHIP_SCAN: scanScreen(false); break;
            case SHIP_FINISH: heading(tr("Review the shipment","Revise el envío"),shipmentId);
                detail=tv((selectedOrder==null?tr("No PO selected","Sin PO"):selectedOrder.optString("po")+" · "+selectedOrder.optString("customer_name"))+"\n"+palletCount+" "+tr("pallets","pallets")+" · "+shipmentCases+" "+tr("cases","cajas"),18,Color.WHITE);body.addView(detail);
                addSpace(18); choice("Save and Continue Later","Guardar y continuar después",v->done(tr("Shipment saved","Envío guardado")));
                choice("Close Shipment","Cerrar envío",v->closeShipment()); break;
            case DONE: heading(tr("Completed","Terminado"),subtitle==null?"":subtitle.getText().toString());
                choice("Back to Home","Volver al inicio",v->resetHome()); break;
        }
    }

    void heading(String a,String b){ title=tv(a,27,Color.WHITE);title.setGravity(Gravity.CENTER);title.setTypeface(null,1);body.addView(title,new LinearLayout.LayoutParams(-1,-2)); subtitle=tv(b,16,Color.rgb(150,170,190));subtitle.setGravity(Gravity.CENTER);body.addView(subtitle,new LinearLayout.LayoutParams(-1,-2));addSpace(28); }
    void scanQuestion(String q,String hint){ heading(q,hint); manual=new EditText(this);manual.setHint(tr("Scan or type ID","Escanee o escriba el ID"));manual.setTextColor(Color.WHITE);manual.setHintTextColor(Color.GRAY);manual.setSingleLine();manual.setInputType(InputType.TYPE_CLASS_TEXT);body.addView(manual,new LinearLayout.LayoutParams(-1,dp(58)));addSpace(12);next.setVisibility(View.VISIBLE);next.setText(tr("Continue","Continuar")); }

    void scanScreen(boolean cases){
        String id=cases?palletId:shipmentId;
        heading(cases?tr("Scan the cases","Escanee las cajas"):tr("Scan the pallet labels","Escanee las etiquetas de los pallets"),id);
        counter=tv(cases?caseCount+" "+tr("CASES","CAJAS"):palletCount+" "+tr("PALLETS","PALLETS"),34,Color.rgb(102,187,106));counter.setGravity(Gravity.CENTER);counter.setTypeface(null,1);body.addView(counter,new LinearLayout.LayoutParams(-1,-2));
        String scanStatus=tr("Ready to scan","Listo para escanear")+(online?"":"\n"+tr("Scans will be synchronized automatically","Las lecturas se sincronizarán automáticamente"));
        if(!cases&&!shipmentCompareMessage.isEmpty())scanStatus+="\n\n"+shipmentCompareMessage;
        if(cases&&!scanErrorMessage.isEmpty())scanStatus+="\n\n"+scanErrorMessage;
        detail=tv(scanStatus,16,cases&&!scanErrorMessage.isEmpty()?Color.rgb(239,68,68):(shipmentMismatch?Color.rgb(255,143,0):Color.WHITE));detail.setGravity(Gravity.CENTER);body.addView(detail,new LinearLayout.LayoutParams(-1,-2));
        if(!cases&&selectedOrder!=null)renderShipmentSkuProgress();
        addSpace(16);Button remove=choice("Remove Last","Eliminar último",v->removeLast(cases));remove.setBackgroundColor(Color.rgb(198,40,40));
        if(cases){
            Button toggle=choice(casesExpanded?"Hide scanned cases":"Scanned cases",casesExpanded?"Ocultar cajas escaneadas":"Cajas escaneadas",v->{casesExpanded=!casesExpanded;render();});
            toggle.setText(toggle.getText()+" ("+scannedCases.size()+")");toggle.setBackgroundColor(Color.rgb(52,65,85));
            if(casesExpanded){
                LinearLayout list=new LinearLayout(this);list.setOrientation(LinearLayout.VERTICAL);list.setPadding(dp(14),dp(8),dp(14),dp(8));list.setBackgroundColor(Color.rgb(19,32,51));
                if(scannedCases.isEmpty())list.addView(tv(tr("No cases scanned","No hay cajas escaneadas"),14,Color.LTGRAY));
                else for(int i=scannedCases.size()-1;i>=0;i--)list.addView(tv("• "+scannedCases.get(i),15,Color.WHITE));
                body.addView(list,new LinearLayout.LayoutParams(-1,-2));addSpace(10);
            }
        }
        next.setVisibility(View.VISIBLE);next.setText(tr("Finish","Finalizar"));
    }

    void renderShipmentSkuProgress(){
        TextView label=tv(tr("PO CONTENT · LOADED / REQUIRED","CONTENIDO PO · CARGADO / REQUERIDO"),13,Color.rgb(148,163,184));
        label.setTypeface(null,1);label.setPadding(0,dp(12),0,dp(7));body.addView(label,new LinearLayout.LayoutParams(-1,-2));
        if(shipmentSkuLines==null||shipmentSkuLines.length()==0){
            TextView loading=tv(tr("Loading PO lines…","Cargando líneas del PO…"),15,Color.LTGRAY);
            body.addView(loading,new LinearLayout.LayoutParams(-1,-2));return;
        }
        for(int i=0;i<shipmentSkuLines.length();i++){
            JSONObject line=shipmentSkuLines.optJSONObject(i);if(line==null)continue;
            int required=line.optInt("required",0),loaded=line.optInt("loaded",0);
            boolean complete=required>0&&loaded==required;
            boolean over=loaded>required||line.optBoolean("extra",false);
            LinearLayout card=new LinearLayout(this);card.setOrientation(LinearLayout.VERTICAL);card.setPadding(dp(12),dp(9),dp(12),dp(9));
            card.setBackgroundColor(complete?Color.rgb(22,101,52):Color.rgb(127,29,29));
            String meta=line.optString("variety");
            if(!line.optString("size").isEmpty())meta+=(meta.isEmpty()?"":" · ")+line.optString("size");
            if(!line.optString("packaging").isEmpty())meta+=(meta.isEmpty()?"":" · ")+line.optString("packaging");
            TextView name=tv("SKU "+line.optString("sku")+(meta.isEmpty()?"":" · "+meta),15,Color.WHITE);name.setTypeface(null,1);card.addView(name);
            String state=complete?tr("COMPLETE","COMPLETO"):over?tr("TOO MANY / NOT IN PO","DEMASIADO / NO ESTÁ EN PO"):tr("MISSING ","FALTAN ")+Math.max(0,required-loaded);
            TextView qty=tv(loaded+" / "+required+" "+tr("CASES","CAJAS")+"  ·  "+state,17,Color.WHITE);qty.setTypeface(null,1);card.addView(qty);
            LinearLayout.LayoutParams cp=new LinearLayout.LayoutParams(-1,-2);cp.setMargins(0,0,0,dp(7));body.addView(card,cp);
        }
    }

    void orderScreen(){
        heading(tr("Select the order","Seleccione el pedido"),tr("Search by PO or customer","Busque por PO o cliente"));
        manual=new EditText(this);manual.setHint(tr("PO or customer","PO o cliente"));manual.setSingleLine();manual.setTextColor(Color.WHITE);manual.setHintTextColor(Color.GRAY);body.addView(manual,new LinearLayout.LayoutParams(-1,dp(58)));addSpace(12);
        choice("Search","Buscar",v->searchOrders(manual.getText().toString()));
        Button skip=choice("Continue without PO","Continuar sin PO",v->requestSkipPassword());skip.setBackgroundColor(Color.rgb(82,96,115));
    }

    void goNext(){
        if(step==Step.PALLET_RESUME){String id=text();if(id.isEmpty()){error(tr("Scan the pallet label","Escanee la etiqueta"));return;}resumePallet(id);}
        else if(step==Step.PALLET_SCAN){step=Step.PALLET_FINISH;render();}
        else if(step==Step.SHIP_RESUME){String id=text();if(id.isEmpty()){error(tr("Scan the shipment label","Escanee la etiqueta del envío"));return;}resumeShipment(id);}
        else if(step==Step.SHIP_SCAN){step=Step.SHIP_FINISH;render();}
    }
    void goBack(){
        if(step==Step.PALLET_MODE||step==Step.SHIP_MODE){step=Step.HOME;}
        else if(step==Step.PALLET_RESUME){step=Step.PALLET_MODE;}
        else if(step==Step.PALLET_SCAN){step=Step.PALLET_MODE;}
        else if(step==Step.PALLET_FINISH){step=Step.PALLET_SCAN;}
        else if(step==Step.SHIP_RESUME){step=Step.SHIP_MODE;}
        else if(step==Step.SHIP_ORDER){shipmentId="";selectedOrder=null;step=Step.HOME;}
        else if(step==Step.SHIP_SCAN){step=Step.SHIP_ORDER;}
        else if(step==Step.SHIP_FINISH){step=Step.SHIP_SCAN;} render();
    }
    String text(){return manual==null?"":manual.getText().toString().trim();}

    void newPallet(){
        if(!online){
            palletId="OFFP-"+System.currentTimeMillis();
            queue.add("CREATE_PALLET",palletId,palletId);
            caseCount=0;scannedCases.clear();casesExpanded=false;scanErrorMessage="";step=Step.PALLET_SCAN;render();
            ok(tr("Offline pallet created","Pallet sin conexión creado"));return;
        }
        call(map("action","pallet_new"),j->{palletId=j.optString("pallet_id");caseCount=j.optInt("cases_count");scannedCases.clear();casesExpanded=false;scanErrorMessage="";step=Step.PALLET_SCAN;render();});
    }
    void resumePallet(String id){if(!online){error(tr("Connect to verify the partial pallet","Conéctese para verificar el pallet parcial"));return;}call(map("action","pallet_resume","pallet_id",id),j->{palletId=j.optString("pallet_id");caseCount=j.optInt("cases_count");loadCases(j);casesExpanded=false;scanErrorMessage="";step=Step.PALLET_SCAN;render();});}
    void newShipment(){if(busy)return;if(!online){error(tr("Connect to create a shipment","Conéctese para crear un envío"));return;}call(map("action","shipment_new"),j->{shipmentId=j.optString("shipment_id");step=Step.SHIP_ORDER;render();searchOrders("");});}
    void resumeShipment(String id){if(!online){error(tr("Connect to verify the shipment","Conéctese para verificar el envío"));return;}call(map("action","shipment_resume","shipment_id",id),j->{shipmentId=j.optString("shipment_id");palletCount=j.optInt("pallet_count");shipmentCases=j.optInt("cases_count");step=Step.SHIP_SCAN;render();});}

    void onScan(String raw){
        String code=raw==null?"":raw.trim();if(code.isEmpty()||busy)return;
        // Some Zebra/DataWedge configurations deliver the same scan through
        // both Intent Output and Keystroke Output. Accept it only once.
        if(code.length()%2==0){String a=code.substring(0,code.length()/2);String b=code.substring(code.length()/2);if(a.equals(b))code=a;}
        long now=System.currentTimeMillis();
        if(code.equals(lastScanCode)&&now-lastScanAt<1500){if(step==Step.PALLET_SCAN&&!online)showDuplicateCase();return;}
        lastScanCode=code;lastScanAt=now;
        if(step==Step.PALLET_RESUME){manual.setText(code);resumePallet(code);return;}
        if(step==Step.SHIP_RESUME){manual.setText(code);resumeShipment(code);return;}
        if(step==Step.PALLET_SCAN){scanCase(code);return;}
        if(step==Step.SHIP_SCAN){scanPallet(code);return;}
        if(manual!=null)manual.setText(code);
    }
    void scanCase(String code){
        scanErrorMessage="";
        if(!online){
            if(scannedCases.contains(code)||queue.exists("CASE",palletId,code)){showDuplicateCase();return;}
            if(queue.add("CASE",palletId,code)){caseCount++;scannedCases.add(code);ok(tr("Saved offline: ","Guardado sin conexión: ")+code);}
            else showDuplicateCase();
            render();return;
        }
        callScanOrQueue(map("action","pallet_scan_case","pallet_id",palletId,"case_serial",code),"CASE",palletId,code,j->{caseCount=j.optInt("cases_count",caseCount+1);loadCases(j);ok(code);render();});
    }
    void scanPallet(String code){
        if(!online){queue.add("PALLET",shipmentId,code);palletCount++;ok(tr("Saved offline: ","Guardado sin conexión: ")+code);render();return;}
        callScanOrQueue(map("action","shipment_scan_pallet","shipment_id",shipmentId,"pallet_id",code),"PALLET",shipmentId,code,j->{palletCount=j.optInt("pallet_count",palletCount+1);shipmentCases=j.optInt("cases_count",shipmentCases);ok(code);render();if(selectedOrder!=null)checkPoAfterScan();});
    }
    void removeLast(boolean cases){
        if(!online){ if(queue.removeLast(cases?"CASE":"PALLET",cases?palletId:shipmentId)){if(cases){caseCount=Math.max(0,caseCount-1);if(!scannedCases.isEmpty())scannedCases.remove(scannedCases.size()-1);scanErrorMessage="";}else palletCount=Math.max(0,palletCount-1);render();}else error(tr("Nothing offline to remove","Nada sin conexión para eliminar")); return; }
        call(map("action",cases?"pallet_remove_last":"shipment_remove_last",cases?"pallet_id":"shipment_id",cases?palletId:shipmentId),j->{if(cases){caseCount=j.optInt("cases_count");if(!scannedCases.isEmpty())scannedCases.remove(scannedCases.size()-1);scanErrorMessage="";}else{palletCount=j.optInt("pallet_count");shipmentCases=j.optInt("cases_count");shipmentMismatch=false;shipmentCompareMessage="";}render();if(!cases&&selectedOrder!=null)checkPoAfterScan();});
    }
    void finishPallet(boolean complete){ if(!online||queue.countFor(palletId)>0){error(tr("Wait for synchronization before finishing","Espere la sincronización antes de finalizar"));return;}call(map("action",complete?"pallet_close":"pallet_partial","pallet_id",palletId),j->{if(j.optInt("label_printed",0)!=1){error(tr("Pallet saved, but the label was not sent. Check the printer selected in Pallets Manage.","Pallet guardado, pero la etiqueta no fue enviada. Compruebe la impresora seleccionada en Pallets Manage."));return;}done(complete?tr("Pallet closed as complete and sent to print","Pallet cerrado como completo y enviado a imprimir"):tr("Pallet closed as partial and sent to print","Pallet cerrado como parcial y enviado a imprimir"));}); }
    void closeShipment(){
        if(!online||queue.countFor(shipmentId)>0){error(tr("Wait for synchronization before closing","Espere la sincronización antes de cerrar"));return;}
        if(palletCount<=0){error(tr("Scan at least one pallet","Escanee al menos un pallet"));return;}
        if(selectedOrder!=null){
            callUrl(BuildConfig.SHIPPING_API_URL,map("action","compare","shipment_id",shipmentId,"order_id",selectedOrder.optString("id")),cmp->{
                applyComparison(cmp,false);
                if(cmp.optBoolean("all_ok",false))performShipmentClose();else requestMismatchOverride();
            });
            return;
        }
        performShipmentClose();
    }
    void performShipmentClose(){
        Map<String,String> closeData=map("action","shipment_close","shipment_id",shipmentId);
        if(selectedOrder!=null)closeData.put("order_id",selectedOrder.optString("id"));
        call(closeData,closed->{
            final boolean orderFailed=closed.optInt("order_expected",0)==1&&closed.optInt("order_closed",0)!=1;
            callUrl(BuildConfig.SHIPPING_API_URL,map("action","bol","shipment_id",shipmentId),bol->{
                callUrl(BuildConfig.SHIPPING_API_URL,map("action","queue_bol_print","shipment_id",shipmentId),printed->{
                    if(orderFailed)done(tr("Shipment closed and printed, but the PO could not be set to SHIPPED. Check the order in the webapp.","Envío cerrado e impreso, pero el PO no pudo marcarse como SHIPPED. Compruebe el pedido en la webapp."));
                    else done(tr("Shipment closed, PO set to SHIPPED. Label and BOL sent to print","Envío cerrado, PO marcado como SHIPPED. Etiqueta y BOL enviados a imprimir"));
                });
            });
        });
    }
    void done(String msg){step=Step.DONE;render();subtitle.setText(msg);}
    void resetHome(){palletId="";shipmentId="";caseCount=palletCount=shipmentCases=0;selectedOrder=null;shipmentMismatch=false;shipmentCompareMessage="";scanErrorMessage="";shipmentSkuLines=new JSONArray();casesExpanded=false;scannedCases.clear();step=Step.HOME;render();}

    void searchOrders(String q){ if(!online){error(tr("Order search requires connection","La búsqueda requiere conexión"));return;}call(map("action","order_search","q",q),j->showOrders(j.optJSONArray("orders"))); }
    void requestSkipPassword(){
        final EditText input=new EditText(this);input.setInputType(InputType.TYPE_CLASS_TEXT|InputType.TYPE_TEXT_VARIATION_PASSWORD);
        new AlertDialog.Builder(this).setTitle(tr("Password required","Contraseña requerida")).setView(input)
            .setPositiveButton(tr("Continue","Continuar"),(d,w)->verifySkipPassword(input.getText().toString()))
            .setNegativeButton(tr("Cancel","Cancelar"),null).show();
    }
    void verifySkipPassword(String password){
        call(map("action","verify_skip_po_password","password",password),j->{selectedOrder=null;step=Step.SHIP_SCAN;render();});
    }
    void requestMismatchOverride(){
        final EditText input=new EditText(this);input.setInputType(InputType.TYPE_CLASS_TEXT|InputType.TYPE_TEXT_VARIATION_PASSWORD);
        new AlertDialog.Builder(this).setTitle(tr("PO discrepancy","Diferencia con el PO"))
            .setMessage(shipmentCompareMessage+"\n\n"+tr("Enter the override password to close anyway.","Introduzca la contraseña para cerrar de todos modos."))
            .setView(input).setPositiveButton(tr("Override and Close","Anular y cerrar"),(d,w)->
                call(map("action","verify_skip_po_password","password",input.getText().toString()),j->performShipmentClose()))
            .setNegativeButton(tr("Go Back","Volver"),null).show();
    }
    void checkPoAfterScan(){
        checkPoAfterScan(true);
    }
    void checkPoAfterScan(boolean warnOnMismatch){
        callUrl(BuildConfig.SHIPPING_API_URL,map("action","compare","shipment_id",shipmentId,"order_id",selectedOrder.optString("id")),cmp->{
            boolean dangerous=applyComparison(cmp,true);render();
            if(dangerous&&warnOnMismatch)new AlertDialog.Builder(this).setTitle(tr("Pallet does not match the PO","El pallet no coincide con el PO"))
                .setMessage(shipmentCompareMessage)
                .setPositiveButton(tr("Remove Last Pallet","Eliminar último pallet"),(d,w)->removeLast(false))
                .setNegativeButton(tr("Keep and Review","Mantener y revisar"),null).show();
        });
    }
    boolean applyComparison(JSONObject cmp,boolean duringScan){
        JSONArray lines=cmp.optJSONArray("sku_lines");shipmentSkuLines=lines==null?new JSONArray():lines;
        int ordered=cmp.optInt("po_qty",0),scanned=cmp.optInt("ship_qty",0);JSONObject po=cmp.optJSONObject("po_varieties"),ship=cmp.optJSONObject("ship_varieties");
        boolean dangerous=scanned>ordered&&ordered>0;ArrayList<String>issues=new ArrayList<>();
        if(dangerous)issues.add(tr("Too many cases: ","Demasiadas cajas: ")+scanned+" / "+ordered);
        for(int i=0;i<shipmentSkuLines.length();i++){JSONObject line=shipmentSkuLines.optJSONObject(i);if(line!=null&&(line.optBoolean("over",false)||line.optBoolean("extra",false))){dangerous=true;issues.add("SKU "+line.optString("sku")+": "+line.optInt("loaded")+" / "+line.optInt("required"));}}
        if(ship!=null){Iterator<String>it=ship.keys();while(it.hasNext()){String v=it.next();int s=ship.optInt(v),p=po==null?0:po.optInt(v,0);if(p==0||s>p){dangerous=true;issues.add(v+": "+s+" / "+p);}}}
        int remaining=Math.max(0,ordered-scanned);
        if(issues.isEmpty())shipmentCompareMessage=remaining>0?tr("PO compatible · Remaining cases: ","PO compatible · Cajas restantes: ")+remaining:tr("PO matches the shipment","El PO coincide con el envío");
        else shipmentCompareMessage=tr("Discrepancy: ","Diferencia: ")+android.text.TextUtils.join("; ",issues);
        shipmentMismatch=dangerous||(!duringScan&&!cmp.optBoolean("all_ok",false));
        if(!duringScan&&!cmp.optBoolean("all_ok",false)&&issues.isEmpty())shipmentCompareMessage=tr("Order incomplete · Remaining cases: ","Pedido incompleto · Cajas restantes: ")+remaining;
        return dangerous;
    }
    void showOrders(JSONArray a){
        if(a==null||a.length()==0){error(tr("No open orders found","No se encontraron pedidos abiertos"));return;}
        String[] names=new String[a.length()];for(int i=0;i<a.length();i++){JSONObject o=a.optJSONObject(i);names[i]=o.optString("po")+" · "+o.optString("customer_name");}
        new AlertDialog.Builder(this).setTitle(tr("Select order","Seleccione el pedido")).setItems(names,(d,w)->{selectedOrder=a.optJSONObject(w);shipmentSkuLines=new JSONArray();call(map("action","shipment_set_order","shipment_id",shipmentId,"order_id",selectedOrder.optString("id"),"po",selectedOrder.optString("po"),"customer_name",selectedOrder.optString("customer_name")),j->{step=Step.SHIP_SCAN;render();checkPoAfterScan(false);});}).setNegativeButton(tr("Cancel","Cancelar"),null).show();
    }

    interface Success{void run(JSONObject j);}
    void loadCases(JSONObject j){JSONArray a=j.optJSONArray("cases");if(a==null)return;scannedCases.clear();for(int i=a.length()-1;i>=0;i--){JSONObject x=a.optJSONObject(i);if(x!=null){String s=x.optString("case_serial");if(!s.isEmpty())scannedCases.add(s);}}}
    Map<String,String> map(String...v){Map<String,String>m=new LinkedHashMap<>();for(int i=0;i+1<v.length;i+=2)m.put(v[i],v[i+1]);return m;}
    void call(Map<String,String> data,Success success){ callUrl(BuildConfig.API_URL,data,success); }
    void callScanOrQueue(Map<String,String> data,String type,String parent,String code,Success success){
        if(busy)return;busy=true;io.execute(()->{try{JSONObject j=request(data);runOnUiThread(()->{busy=false;if(j.optInt("ok")==1)success.run(j);else{String msg=localizeError(j.optString("err",tr("Operation failed","Operación fallida")));String low=msg.toLowerCase();if(low.contains("already scanned")||low.contains("ya fue escaneada")||low.contains("ya escaneada")){scanErrorMessage=tr("CASE ALREADY SCANNED","CAJA YA ESCANEADA");tone.startTone(ToneGenerator.TONE_CDMA_ABBR_ALERT,350);render();}else error(msg);}});}
        catch(Exception e){boolean added=queue.add(type,parent,code);runOnUiThread(()->{busy=false;setOnlineState(false);if(!added&&type.equals("CASE")){showDuplicateCase();return;}if(added){if(type.equals("CASE")){caseCount++;if(!scannedCases.contains(code))scannedCases.add(code);}else palletCount++;ok(tr("Saved offline: ","Guardado sin conexión: ")+code);}render();});}});
    }
    void callUrl(String url,Map<String,String> data,Success success){
        if(busy)return;busy=true; io.execute(()->{try{JSONObject j=requestUrl(url,data);runOnUiThread(()->{busy=false;if(j.optInt("ok")==1)success.run(j);else error(localizeError(j.optString("err",tr("Operation failed","Operación fallida"))));});}catch(Exception e){runOnUiThread(()->{busy=false;checkNetwork();error(tr("Connection error","Error de conexión")+": "+e.getMessage());});}});
    }
    JSONObject request(Map<String,String> data)throws Exception{
        return requestUrl(BuildConfig.API_URL,data);
    }
    JSONObject requestUrl(String url,Map<String,String> data)throws Exception{
        StringBuilder body=new StringBuilder();for(Map.Entry<String,String>e:data.entrySet()){if(body.length()>0)body.append('&');body.append(URLEncoder.encode(e.getKey(),"UTF-8")).append('=').append(URLEncoder.encode(e.getValue(),"UTF-8"));}
        HttpURLConnection c=(HttpURLConnection)new URL(url).openConnection();c.setConnectTimeout(3000);c.setReadTimeout(7000);c.setRequestMethod("POST");c.setDoOutput(true);c.setRequestProperty("X-App-Token",BuildConfig.APP_TOKEN);c.setRequestProperty("Content-Type","application/x-www-form-urlencoded; charset=UTF-8");try(OutputStream o=c.getOutputStream()){o.write(body.toString().getBytes(StandardCharsets.UTF_8));}
        InputStream in=c.getResponseCode()<400?c.getInputStream():c.getErrorStream();String s=read(in);return new JSONObject(s);
    }
    String read(InputStream in)throws IOException{ByteArrayOutputStream o=new ByteArrayOutputStream();byte[]b=new byte[4096];int n;while((n=in.read(b))>0)o.write(b,0,n);return o.toString("UTF-8");}
    String localizeError(String e){if(!spanish)return e;String l=e.toLowerCase();if(l.contains("already belongs"))return "La caja ya pertenece a otro pallet";if(l.contains("already scanned"))return "La caja ya fue escaneada";if(l.contains("not found"))return "Código no encontrado";if(l.contains("already in this shipment"))return "El pallet ya está en este envío";if(l.contains("not partial"))return "El pallet escaneado no es parcial";return e;}

    void ok(String s){tone.startTone(ToneGenerator.TONE_PROP_ACK,140);Toast.makeText(this,"✓ "+s,Toast.LENGTH_SHORT).show();}
    void showDuplicateCase(){scanErrorMessage=tr("CASE ALREADY SCANNED","CAJA YA ESCANEADA");tone.startTone(ToneGenerator.TONE_CDMA_ABBR_ALERT,180);healthHandler.postDelayed(()->tone.startTone(ToneGenerator.TONE_CDMA_ABBR_ALERT,280),260);render();}
    void error(String s){tone.startTone(ToneGenerator.TONE_CDMA_ABBR_ALERT,350);new AlertDialog.Builder(this).setTitle(tr("Attention","Atención")).setMessage(s).setPositiveButton("OK",null).show();}

    void registerReceivers(){
        scannerReceiver=new BroadcastReceiver(){public void onReceive(Context c,Intent i){String code=i.getStringExtra("com.symbol.datawedge.data_string");if(code==null)code=i.getStringExtra("com.motorolasolutions.emdk.datawedge.data_string");onScan(code);}};
        IntentFilter sf=new IntentFilter();sf.addAction("com.smproduce.PALLETS_SHIPPING.SCAN");sf.addAction("com.symbol.datawedge.api.RESULT_ACTION");registerReceiver(scannerReceiver,sf,Build.VERSION.SDK_INT>=33?Context.RECEIVER_EXPORTED:0);
        networkReceiver=new BroadcastReceiver(){public void onReceive(Context c,Intent i){checkNetwork();}};registerReceiver(networkReceiver,new IntentFilter(ConnectivityManager.CONNECTIVITY_ACTION));
    }
    @Override public boolean dispatchKeyEvent(KeyEvent event){
        if(event.getAction()==KeyEvent.ACTION_DOWN){
            long now=System.currentTimeMillis();if(now-lastKeyAt>250)keyScanBuffer.setLength(0);lastKeyAt=now;
            int key=event.getKeyCode();
            if(key==KeyEvent.KEYCODE_ENTER||key==KeyEvent.KEYCODE_TAB){String code=keyScanBuffer.toString().trim();keyScanBuffer.setLength(0);if(!code.isEmpty()){onScan(code);return true;}}
            int unicode=event.getUnicodeChar();if(unicode>0&&!Character.isISOControl((char)unicode)){keyScanBuffer.append((char)unicode);return true;}
        }
        return super.dispatchKeyEvent(event);
    }
    void configureDataWedge(){
        final String profile="SMProduce_PalletsShipping";
        Bundle app=new Bundle();app.putString("PACKAGE_NAME",getPackageName());app.putStringArray("ACTIVITY_LIST",new String[]{"*"});
        Bundle barcodeParams=new Bundle();barcodeParams.putString("scanner_input_enabled","true");barcodeParams.putString("scanner_selection","auto");
        Bundle barcode=new Bundle();barcode.putString("PLUGIN_NAME","BARCODE");barcode.putString("RESET_CONFIG","false");barcode.putBundle("PARAM_LIST",barcodeParams);
        Bundle base=new Bundle();base.putString("PROFILE_NAME",profile);base.putString("PROFILE_ENABLED","true");base.putString("CONFIG_MODE","CREATE_IF_NOT_EXIST");base.putParcelableArray("APP_LIST",new Bundle[]{app});base.putBundle("PLUGIN_CONFIG",barcode);
        Intent set=new Intent("com.symbol.datawedge.api.ACTION");set.putExtra("com.symbol.datawedge.api.SET_CONFIG",base);sendBroadcast(set);
        Bundle association=new Bundle();association.putString("PROFILE_NAME",profile);association.putString("PROFILE_ENABLED","true");association.putString("CONFIG_MODE","UPDATE");association.putParcelableArray("APP_LIST",new Bundle[]{app});association.putBundle("PLUGIN_CONFIG",barcode);
        Intent associate=new Intent("com.symbol.datawedge.api.ACTION");associate.putExtra("com.symbol.datawedge.api.SET_CONFIG",association);sendBroadcast(associate);
        Bundle params=new Bundle();params.putString("intent_output_enabled","true");params.putString("intent_action","com.smproduce.PALLETS_SHIPPING.SCAN");params.putString("intent_delivery","2");
        Bundle output=new Bundle();output.putString("PLUGIN_NAME","INTENT");output.putString("RESET_CONFIG","true");output.putBundle("PARAM_LIST",params);
        Bundle outCfg=new Bundle();outCfg.putString("PROFILE_NAME",profile);outCfg.putString("PROFILE_ENABLED","true");outCfg.putString("CONFIG_MODE","UPDATE");outCfg.putBundle("PLUGIN_CONFIG",output);
        Intent setOut=new Intent("com.symbol.datawedge.api.ACTION");setOut.putExtra("com.symbol.datawedge.api.SET_CONFIG",outCfg);sendBroadcast(setOut);
        Bundle keyParams=new Bundle();keyParams.putString("keystroke_output_enabled","false");Bundle keyOut=new Bundle();keyOut.putString("PLUGIN_NAME","KEYSTROKE");keyOut.putString("RESET_CONFIG","true");keyOut.putBundle("PARAM_LIST",keyParams);Bundle keyCfg=new Bundle();keyCfg.putString("PROFILE_NAME",profile);keyCfg.putString("PROFILE_ENABLED","true");keyCfg.putString("CONFIG_MODE","UPDATE");keyCfg.putBundle("PLUGIN_CONFIG",keyOut);Intent setKey=new Intent("com.symbol.datawedge.api.ACTION");setKey.putExtra("com.symbol.datawedge.api.SET_CONFIG",keyCfg);sendBroadcast(setKey);
    }
    void setOnlineState(boolean value){online=value;network.setText(online?tr("● ONLINE","● EN LÍNEA"):tr("● OFFLINE","● SIN CONEXIÓN"));network.setTextColor(online?Color.rgb(102,187,106):Color.rgb(255,143,0));}
    void checkNetwork(){ConnectivityManager cm=(ConnectivityManager)getSystemService(CONNECTIVITY_SERVICE);Network n=cm.getActiveNetwork();NetworkCapabilities cap=n==null?null:cm.getNetworkCapabilities(n);boolean internet=cap!=null&&cap.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);if(!internet)setOnlineState(false);else probeServer();}
    void probeServer(){if(probing||io.isShutdown())return;ConnectivityManager cm=(ConnectivityManager)getSystemService(CONNECTIVITY_SERVICE);Network n=cm.getActiveNetwork();NetworkCapabilities cap=n==null?null:cm.getNetworkCapabilities(n);if(cap==null||!cap.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)){setOnlineState(false);return;}probing=true;io.execute(()->{boolean reachable=false;try{JSONObject j=request(map("action","ping"));reachable=j.optInt("ok")==1;}catch(Exception ignored){}boolean ok=reachable;runOnUiThread(()->{boolean was=online;probing=false;setOnlineState(ok);if(ok&&!was)syncQueue();});});}
    void syncQueue(){io.execute(()->{List<QueueDb.Item>items=queue.all();int done=0;for(QueueDb.Item x:items){try{
            if(x.type.equals("CREATE_PALLET")){JSONObject j=request(map("action","pallet_new"));if(j.optInt("ok")!=1)break;String real=j.optString("pallet_id");queue.replaceParent(x.parent,real);queue.remove(x.id);if(palletId.equals(x.parent))palletId=real;done++;continue;}
            Map<String,String>m=x.type.equals("CASE")?map("action","pallet_scan_case","pallet_id",x.parent,"case_serial",x.code):map("action","shipment_scan_pallet","shipment_id",x.parent,"pallet_id",x.code);JSONObject j=request(m);if(j.optInt("ok")==1||j.optString("err").toLowerCase().contains("already")){queue.remove(x.id);done++;}else break;}catch(Exception e){break;}}int d=done;if(d>0)runOnUiThread(()->{Toast.makeText(this,tr("Synchronized ","Sincronizados ")+d,Toast.LENGTH_LONG).show();refreshCurrent();});});}
    void refreshCurrent(){if(!online)return;if(!palletId.isEmpty())call(map("action","pallet_status","pallet_id",palletId),j->{caseCount=j.optInt("cases_count");loadCases(j);render();});else if(!shipmentId.isEmpty())call(map("action","shipment_resume","shipment_id",shipmentId),j->{palletCount=j.optInt("pallet_count");shipmentCases=j.optInt("cases_count");render();if(selectedOrder!=null)checkPoAfterScan();});}

    static class QueueDb extends SQLiteOpenHelper{
        static class Item{long id;String type,parent,code;Item(long i,String t,String p,String c){id=i;type=t;parent=p;code=c;}}
        QueueDb(Context c){super(c,"pallets_shipping_offline.db",null,1);}public void onCreate(SQLiteDatabase d){d.execSQL("CREATE TABLE queue(id INTEGER PRIMARY KEY AUTOINCREMENT,type TEXT,parent TEXT,code TEXT,created INTEGER,UNIQUE(type,parent,code))");}public void onUpgrade(SQLiteDatabase d,int a,int b){}
        boolean add(String t,String p,String c){ContentValues v=new ContentValues();v.put("type",t);v.put("parent",p);v.put("code",c);v.put("created",System.currentTimeMillis());return getWritableDatabase().insertWithOnConflict("queue",null,v,SQLiteDatabase.CONFLICT_IGNORE)!=-1;}
        boolean exists(String t,String p,String code){try(Cursor c=getReadableDatabase().rawQuery("SELECT 1 FROM queue WHERE type=? AND parent=? AND code=? LIMIT 1",new String[]{t,p,code})){return c.moveToFirst();}}
        List<Item> all(){ArrayList<Item>l=new ArrayList<>();try(Cursor c=getReadableDatabase().rawQuery("SELECT id,type,parent,code FROM queue ORDER BY id",null)){while(c.moveToNext())l.add(new Item(c.getLong(0),c.getString(1),c.getString(2),c.getString(3)));}return l;}
        void remove(long id){getWritableDatabase().delete("queue","id=?",new String[]{String.valueOf(id)});}int countFor(String p){try(Cursor c=getReadableDatabase().rawQuery("SELECT COUNT(*) FROM queue WHERE parent=?",new String[]{p})){return c.moveToFirst()?c.getInt(0):0;}}
        void replaceParent(String oldParent,String newParent){ContentValues v=new ContentValues();v.put("parent",newParent);getWritableDatabase().update("queue",v,"parent=?",new String[]{oldParent});}
        boolean removeLast(String t,String p){try(Cursor c=getReadableDatabase().rawQuery("SELECT id FROM queue WHERE type=? AND parent=? ORDER BY id DESC LIMIT 1",new String[]{t,p})){if(!c.moveToFirst())return false;remove(c.getLong(0));return true;}}
    }
}
