package com.smproduce.palletsshipping;

import android.app.AlertDialog;
import android.content.*;
import android.graphics.Color;
import android.net.*;
import android.view.*;
import android.widget.*;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.util.*;
import org.json.*;

/** Extends the REAL, unchanged 2.0.36 TC26 activity; not the 2.0.5/48 MainActivity. */
public class Recovery36Activity extends TC26Activity {
    static final String VERSION = "2.0.49-base36";
    static final String MODIFY = "https://smproduceprod.uk/api/tc26_modify_pallet.php";
    private int editMode = 0; // 0 lookup, 1 add, 2 remove
    private boolean editDirty;
    private String modifyPin = "", loadedStatus = "";

    private boolean networkAvailable() {
        ConnectivityManager cm=(ConnectivityManager)getSystemService(CONNECTIVITY_SERVICE);
        Network n=cm==null?null:cm.getActiveNetwork();
        NetworkCapabilities cap=n==null?null:cm.getNetworkCapabilities(n);
        // Exactly the readiness criterion used by original 36. No ping dependency.
        return cap!=null && cap.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
    }
    private boolean requireNetwork() {
        boolean ready=networkAvailable();
        if(online!=ready)setOnlineState(ready);
        if(!ready)error(tr("OFFLINE — APP LOCKED. No operation sent or queued.",
                "SIN CONEXIÓN — APP BLOQUEADA. No se envió ni encoló ninguna operación."));
        return ready;
    }
    @Override Map<String,String> map(String... values) {
        Map<String,String> p=super.map(values); // Keeps original device/operator metadata.
        p.put("app_version",VERSION);return p;
    }
    @Override void syncQueue() { /* Deliberately never replay old offline mutations. */ }
    @Override void onScan(String value) {if(requireNetwork())super.onScan(value);}
    @Override void goNext() {if(requireNetwork())super.goNext();}
    @Override void requestScanner() {if(requireNetwork())super.requestScanner();}
    @Override void callUrl(String url,Map<String,String> p,Success s) {
        if(requireNetwork())super.callUrl(url,p,s);
    }
    @Override void callScanOrQueue(Map<String,String> p,String type,String parent,String serial,Success s) {
        if(requireNetwork())super.callScanOrQueue(p,type,parent,serial,s);
    }
    @Override JSONObject requestUrl(String url,Map<String,String> p) throws Exception {
        // Last boundary check also covers requests issued by original Complete/BOL dialogs.
        if(!networkAvailable())return failure("OFFLINE — operation blocked before sending.");
        HttpURLConnection c=null;
        try {
            c=(HttpURLConnection)new URL(url).openConnection();
            c.setConnectTimeout(9000); // Original 36 transport timeouts and authentication.
            String action=p.get("action");
            c.setReadTimeout("multi_bol".equals(action)||"shipment_close".equals(action)?90000:20000);
            c.setRequestMethod("POST");c.setDoOutput(true);
            c.setRequestProperty("X-App-Token",TOKEN);
            c.setRequestProperty("Content-Type","application/x-www-form-urlencoded; charset=UTF-8");
            StringBuilder body=new StringBuilder();
            for(Map.Entry<String,String> e:p.entrySet()) {
                if(body.length()>0)body.append('&');
                body.append(URLEncoder.encode(e.getKey(),"UTF-8")).append('=')
                    .append(URLEncoder.encode(e.getValue(),"UTF-8"));
            }
            try(OutputStream out=c.getOutputStream()){out.write(body.toString().getBytes(StandardCharsets.UTF_8));}
            int status=c.getResponseCode();
            InputStream input=status<400?c.getInputStream():c.getErrorStream();
            String text="";
            if(input!=null)try(InputStream in=input;ByteArrayOutputStream bytes=new ByteArrayOutputStream()) {
                byte[] buffer=new byte[4096];int n;
                while((n=in.read(buffer))!=-1)bytes.write(buffer,0,n);
                text=bytes.toString("UTF-8").trim();
            }
            if(status<200||status>=300)return failure("SERVER HTTP "+status+" — "+new URL(url).getPath()
                    +". Operation not confirmed; check its status before retrying.");
            if(text.startsWith("\uFEFF"))text=text.substring(1);
            try{return new JSONObject(text);}
            catch(JSONException e){return failure("SERVER RESPONSE INVALID — "+new URL(url).getPath()
                    +". Operation not confirmed; check its status before retrying.");}
        }catch(IOException e) {
            return failure("CONNECTION ERROR ("+e.getClass().getSimpleName()+"). "
                    +"Operation not confirmed. Check the pallet/shipment before retrying. Nothing queued offline.");
        }finally{if(c!=null)c.disconnect();}
    }
    private JSONObject failure(String message)throws JSONException {
        return new JSONObject().put("ok",0).put("err",message);
    }
    @Override void buildShell() {
        super.buildShell(); // Retains original scanner, icons, foreground and print handling.
        // Place navigation above the scroll area, as requested for the TC26.
        if(nav.getParent()==root){root.removeView(nav);root.addView(nav,2);}
        network.setOnClickListener(v->new AlertDialog.Builder(this).setTitle(VERSION)
                .setMessage("Base: original TC26 2.0.36\nAPI: "+API
                    +"\nOffline operations: BLOCKED\nHTTP errors are shown separately.")
                .setPositiveButton("OK",null).show());
    }
    @Override void render() {
        super.render();
        if(!online) {
            // Original TC26 render can append pending BOL actions after the lock screen.
            body.removeAllViews();nav.setVisibility(View.GONE);
            heading(tr("OFFLINE — APP LOCKED","SIN CONEXIÓN — APP BLOQUEADA"),
                    tr("Reconnect to continue. No operation is saved or queued offline.",
                       "Conéctese para continuar. Nada se guarda ni se encola sin conexión."));
        }
        if(editPalletIcon!=null)editPalletIcon.setEnabled(online);
    }
    private void modify(Map<String,String> p,Success s){callUrl(MODIFY,p,s);}
    @Override void requestPalletEditPassword() {
        if(!requireNetwork())return;
        if(!canOpenPalletEdit()){error(tr("Finish the current operation first.","Termine primero la operación actual."));return;}
        showPassword("Modify Pallet","",tr("Continue","Continuar"),true,(pin,d)->{
            modify(map("action","verify_password","password",pin),j->{
                modifyPin=pin;palletEditPassword=pin;editMode=0;editDirty=false;
                d.dismiss();palletId="";scannedCases.clear();caseCount=0;step=Step.PALLET_EDIT_ID;render();
            });
        },null);
    }
    @Override void openPalletForEdit(String id) {
        modify(map("action","edit_open","pallet_id",id,"password",modifyPin),j->{
            palletId=j.optString("pallet_id");caseCount=j.optInt("cases_count");
            loadedStatus=j.optString("status","OPEN");loadCases(j);
            editMode=0;editDirty=false;step=Step.PALLET_EDIT_CASES;render();
        });
    }
    @Override void editPalletCase(String code) {
        if(!requireNetwork())return;
        if(editMode==0) {
            modify(map("action","lookup_case","case_serial",code,"password",modifyPin),j->{
                String target=j.optString("pallet_id");
                if(target.isEmpty()){error(tr("Case has no pallet","La caja no tiene pallet"));return;}
                if(target.equals(palletId)){ok(code);return;}
                if(editDirty){error(tr("Save before switching pallets","Guarde antes de cambiar de pallet"));return;}
                openPalletForEdit(target);
            });return;
        }
        modify(map("action","edit_case","pallet_id",palletId,"case_serial",code,
                "mode",editMode==2?"REMOVE":"ADD","password",modifyPin),j->{
            caseCount=j.optInt("cases_count");loadedStatus=j.optString("status");
            loadCases(j);editDirty=true;ok(code);render();
        });
    }
    private void addAction(LinearLayout row,String label,int color,Runnable action) {
        Button b=button(label,color);b.setTextSize(11);b.setOnClickListener(v->action.run());
        LinearLayout.LayoutParams lp=new LinearLayout.LayoutParams(0,dp(52),1);lp.setMargins(dp(2),0,dp(2),0);row.addView(b,lp);
    }
    @Override void palletEditScreen() {
        heading("Modify Pallet",palletId+" · "+loadedStatus);
        counter=tv(caseCount+" "+tr("CASES","CAJAS"),34,Color.WHITE);body.addView(counter);
        body.addView(tv(editMode==0?"LOOKUP — scan a case to find its pallet":editMode==1?"ADD — scan cases":"REMOVE — scan cases",15,Color.LTGRAY));
        LinearLayout modes=new LinearLayout(this);
        addAction(modes,"LOOKUP",0xff2549a0,()->{editMode=0;render();});
        addAction(modes,"+ ADD",0xff166534,()->{editMode=1;render();});
        addAction(modes,"− REMOVE",0xff991b1b,()->{editMode=2;render();});body.addView(modes);addSpace(10);
        LinearLayout states=new LinearLayout(this);
        addAction(states,"OPEN",0xff2549a0,()->saveChoice("OPEN"));
        addAction(states,"PARTIAL",0xffb45309,()->saveChoice("PARTIAL"));
        addAction(states,"CLOSED",0xff166534,()->saveChoice("COMPLETE"));
        addAction(states,"DELETE",0xff991b1b,this::deletePrompt);body.addView(states);addSpace(10);
        Button another=button("SELECT ANOTHER PALLET",0xff344155);
        another.setOnClickListener(v->{if(editDirty){error("Save the current pallet first");return;}
            palletId="";scannedCases.clear();step=Step.PALLET_EDIT_ID;render();});body.addView(another);addSpace(10);
        body.addView(tv(tr("CASES ON PALLET","CAJAS EN EL PALLET"),15,Color.LTGRAY));
        for(String serial:scannedCases)body.addView(tv(serial,15,Color.WHITE));
    }
    private void saveChoice(String status) {
        if(!requireNetwork())return;
        new AlertDialog.Builder(this).setTitle("Save pallet: "+status)
            .setItems(new String[]{"SAVE ONLY","PRINT LABEL"},(d,w)->{
                if(w==0)save(status,0);else choosePrinter(status);
            }).setNegativeButton(tr("Cancel","Cancelar"),null).show();
    }
    private void choosePrinter(String status) {
        modify(map("action","printers"),j->{
            JSONArray a=j.optJSONArray("printers");List<Integer> ids=new ArrayList<>();List<String> names=new ArrayList<>();
            int last=getSharedPreferences("tc26_modify_pallet",MODE_PRIVATE).getInt("last_printer_id",0),selected=-1;
            if(a!=null)for(int i=0;i<a.length();i++){
                JSONObject p=a.optJSONObject(i);if(p==null||p.optInt("id")<=0)continue;
                if(p.optInt("id")==last)selected=ids.size();ids.add(p.optInt("id"));names.add(p.optString("name"));
            }
            if(ids.isEmpty()){error("No active printers configured in the webapp");return;}
            AlertDialog d=new AlertDialog.Builder(this).setTitle("Choose label printer")
                .setSingleChoiceItems(names.toArray(new String[0]),selected,null)
                .setNegativeButton(tr("Cancel","Cancelar"),null).setPositiveButton("PRINT",null).create();
            d.setOnShowListener(x->d.getButton(-1).setOnClickListener(v->{
                int pos=d.getListView().getCheckedItemPosition();if(pos<0)return;int printer=ids.get(pos);
                getSharedPreferences("tc26_modify_pallet",MODE_PRIVATE).edit().putInt("last_printer_id",printer).apply();
                d.dismiss();save(status,printer);
            }));d.show();
        });
    }
    private void save(String status,int printer) {
        Success saved=j->{editDirty=false;if(printer>0)modify(map("action","print_label","pallet_id",palletId,
                "status",status,"printer_id",String.valueOf(printer),"password",modifyPin),x->done("Pallet "+palletId+" · label sent"));
            else done("Pallet "+palletId+" · "+status+" · saved without printing");};
        if("COMPLETE".equals(status)) {
            // Original 36 Complete interceptor retains customer/SKU quantity and PIN rules.
            call(map("action","pallet_save_no_print","pallet_id",palletId,"password",modifyPin,"print_label","0"),saved);
        }else modify(map("action","set_status","pallet_id",palletId,"status",status,"print_label","0","password",modifyPin),saved);
    }
    private void deletePrompt() {
        if(!requireNetwork())return;
        new AlertDialog.Builder(this).setTitle("DELETE pallet "+palletId)
            .setMessage(caseCount+" cases will be released; casecodes will not be deleted.")
            .setNegativeButton(tr("Cancel","Cancelar"),null).setPositiveButton("DELETE",(d,w)->
                showPassword("DELETE protected","Enter the additional protected PIN","DELETE",true,(pin,dialog)->{
                    modify(map("action","delete_pallet","pallet_id",palletId,"password",pin),j->{
                        dialog.dismiss();editDirty=false;done("Pallet deleted. Cases released.");
                    });
                },null)).show();
    }
    @Override void resetHome(){editMode=0;editDirty=false;modifyPin="";loadedStatus="";super.resetHome();}
}
