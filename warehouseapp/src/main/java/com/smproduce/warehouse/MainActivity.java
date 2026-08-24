package com.smproduce.warehouse;

import android.app.*;
import android.content.*;
import android.graphics.Color;
import android.net.*;
import android.os.*;
import android.view.*;
import android.webkit.*;
import android.widget.*;
import org.json.*;

public class MainActivity extends Activity {
    static final String PROFILE="SM_PRODUCE_WAREHOUSE";
    static final String DW_API_ACTION="com.symbol.datawedge.api.ACTION";
    static final String DW_SET_CONFIG="com.symbol.datawedge.api.SET_CONFIG";
    static final String DATA="com.symbol.datawedge.data_string";
    static final String PREFS="warehouse_offline";
    static final String QKEY="queue";

    LinearLayout home;
    WebView web;
    TextView title,status;
    Button back;
    boolean inWeb=false;
    String currentMode="";
    long lastScanAt=0;
    String lastScan="";
    String pendingScan="";
    int pendingAttempts=0;
    final Handler ui=new Handler(Looper.getMainLooper());

    final BroadcastReceiver rx=new BroadcastReceiver(){
        @Override public void onReceive(Context c,Intent i){
            if(!BuildConfig.DW_ACTION.equals(i.getAction())) return;
            String s=i.getStringExtra(DATA);
            if(s!=null&&!s.trim().isEmpty()) acceptScan(s.trim());
        }
    };

    final BroadcastReceiver netRx=new BroadcastReceiver(){
        @Override public void onReceive(Context c,Intent i){
            updateNetStatus();
            if(isOnline()) syncOfflineQueue();
        }
    };

    @Override public void onCreate(Bundle b){
        super.onCreate(b);
        buildUi();
        registerScanReceiver();
        registerReceiver(netRx,new IntentFilter(ConnectivityManager.CONNECTIVITY_ACTION));
        ui.postDelayed(this::setupDataWedge,500);
        updateNetStatus();
    }

    void registerScanReceiver(){
        IntentFilter f=new IntentFilter();
        f.addAction(BuildConfig.DW_ACTION);
        f.addCategory(Intent.CATEGORY_DEFAULT);
        if(Build.VERSION.SDK_INT>=33) registerReceiver(rx,f,Context.RECEIVER_EXPORTED);
        else registerReceiver(rx,f);
    }

    @Override protected void onResume(){
        super.onResume();
        ui.postDelayed(this::setupDataWedge,300);
        updateNetStatus();
        if(isOnline()) syncOfflineQueue();
    }

    @Override protected void onDestroy(){
        ui.removeCallbacksAndMessages(null);
        try{unregisterReceiver(rx);}catch(Exception ignored){}
        try{unregisterReceiver(netRx);}catch(Exception ignored){}
        if(web!=null) web.destroy();
        super.onDestroy();
    }

    void buildUi(){
        LinearLayout root=new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(Color.rgb(6,17,31));

        LinearLayout bar=new LinearLayout(this);
        bar.setGravity(Gravity.CENTER_VERTICAL);
        bar.setPadding(12,8,12,8);
        bar.setBackgroundColor(Color.rgb(13,29,48));

        back=new Button(this);
        back.setText("‹ HOME");
        back.setVisibility(View.GONE);
        back.setOnClickListener(v->showHome());
        bar.addView(back,new LinearLayout.LayoutParams(-2,-2));

        title=new TextView(this);
        title.setText("WAREHOUSE");
        title.setTextColor(Color.WHITE);
        title.setTextSize(20);
        title.setTypeface(null,1);
        title.setGravity(Gravity.CENTER);
        bar.addView(title,new LinearLayout.LayoutParams(0,-2,1));

        status=new TextView(this);
        status.setTextSize(11);
        status.setPadding(8,0,0,0);
        bar.addView(status,new LinearLayout.LayoutParams(-2,-2));
        root.addView(bar,new LinearLayout.LayoutParams(-1,-2));

        FrameLayout body=new FrameLayout(this);
        home=new LinearLayout(this);
        home.setOrientation(LinearLayout.VERTICAL);
        home.setGravity(Gravity.CENTER);
        home.setPadding(28,28,28,28);

        TextView brand=new TextView(this);
        brand.setText("SM PRODUCE");
        brand.setTextColor(Color.WHITE);
        brand.setTextSize(28);
        brand.setTypeface(null,1);
        brand.setGravity(Gravity.CENTER);
        home.addView(brand);

        TextView sub=new TextView(this);
        sub.setText("WAREHOUSE");
        sub.setTextColor(Color.rgb(143,169,193));
        sub.setTextSize(17);
        sub.setGravity(Gravity.CENTER);
        home.addView(sub);

        Button p=bigButton("PALLETS",Color.rgb(25,118,210));
        p.setOnClickListener(v->openPage("Pallets","pallet","/pages/tc26_pallet.php?token="+BuildConfig.TC26_TOKEN));
        LinearLayout.LayoutParams pp=new LinearLayout.LayoutParams(-1,150);
        pp.topMargin=45;
        home.addView(p,pp);

        Button s=bigButton("SHIPPING",Color.rgb(46,125,50));
        s.setOnClickListener(v->openPage("Shipping","shipping","/pages/tc26_shipping.php?token="+BuildConfig.TC26_TOKEN));
        LinearLayout.LayoutParams sp=new LinearLayout.LayoutParams(-1,150);
        sp.topMargin=24;
        home.addView(s,sp);

        TextView hint=new TextView(this);
        hint.setText("TC26 scanner ready\nOffline scans are saved locally and synchronized when connection returns");
        hint.setTextColor(Color.rgb(143,169,193));
        hint.setTextSize(14);
        hint.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams hp=new LinearLayout.LayoutParams(-1,-2);
        hp.topMargin=38;
        home.addView(hint,hp);
        body.addView(home,new FrameLayout.LayoutParams(-1,-1));

        web=new WebView(this);
        web.setVisibility(View.GONE);
        web.setBackgroundColor(Color.rgb(11,22,34));
        WebSettings ws=web.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        ws.setDatabaseEnabled(true);
        ws.setCacheMode(WebSettings.LOAD_DEFAULT);
        ws.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        ws.setUserAgentString(ws.getUserAgentString()+" SMProduceWarehouse/1.3.8");
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web,true);
        web.setWebViewClient(new WebViewClient(){
            @Override public void onPageFinished(WebView v,String url){
                super.onPageFinished(v,url);
                updateNetStatus();
                if(!pendingScan.isEmpty()) deliverToPage(pendingScan);
                if(isOnline()) syncOfflineQueue();
            }
            @Override public void onReceivedError(WebView v,WebResourceRequest r,WebResourceError e){
                if(r.isForMainFrame()) Toast.makeText(MainActivity.this,"OFFLINE - scans will be saved locally",Toast.LENGTH_LONG).show();
            }
        });
        body.addView(web,new FrameLayout.LayoutParams(-1,-1));
        root.addView(body,new LinearLayout.LayoutParams(-1,0,1));
        setContentView(root);
    }

    Button bigButton(String text,int color){
        Button b=new Button(this);
        b.setText(text);
        b.setTextColor(Color.WHITE);
        b.setTextSize(22);
        b.setTypeface(null,1);
        b.setGravity(Gravity.CENTER);
        b.setBackgroundColor(color);
        return b;
    }

    void openPage(String name,String mode,String path){
        currentMode=mode;
        inWeb=true;
        pendingScan="";
        pendingAttempts=0;
        home.setVisibility(View.GONE);
        web.setVisibility(View.VISIBLE);
        back.setVisibility(View.VISIBLE);
        title.setText(name.toUpperCase());
        web.loadUrl(BuildConfig.BASE_URL+path);
    }

    void showHome(){
        inWeb=false;
        currentMode="";
        pendingScan="";
        pendingAttempts=0;
        ui.removeCallbacksAndMessages(null);
        web.stopLoading();
        web.setVisibility(View.GONE);
        home.setVisibility(View.VISIBLE);
        back.setVisibility(View.GONE);
        title.setText("WAREHOUSE");
        ui.postDelayed(this::setupDataWedge,300);
    }

    @Override public void onBackPressed(){
        if(inWeb){
            if(web.canGoBack()) web.goBack(); else showHome();
        }else super.onBackPressed();
    }

    void acceptScan(String code){
        code=code==null?"":code.trim();
        if(code.isEmpty()) return;
        long now=System.currentTimeMillis();
        if(code.equals(lastScan) && now-lastScanAt<1200) return;
        lastScan=code;
        lastScanAt=now;
        Toast.makeText(this,"SCAN: "+code,Toast.LENGTH_SHORT).show();

        if(!inWeb || currentMode.isEmpty()){
            Toast.makeText(this,"Open Pallets or Shipping",Toast.LENGTH_SHORT).show();
            return;
        }
        if(!isOnline()){
            queueWithContext(code);
            return;
        }
        pendingScan=code;
        pendingAttempts=0;
        deliverToPage(code);
    }

    void deliverToPage(String code){
        if(!inWeb || code==null || code.isEmpty()) return;
        final String q=JSONObject.quote(code);
        String js="(function(){try{"+
                "if(typeof window.onDataWedgeScan==='function'){window.onDataWedgeScan("+q+",'tc26');return 'OK';}"+
                "return document.readyState==='complete'?'NO_CALLBACK':'LOADING';"+
                "}catch(e){return 'ERR:'+String(e);}})()";
        web.evaluateJavascript(js,res->{
            String r=cleanJs(res);
            if("OK".equals(r)){
                pendingScan="";
                pendingAttempts=0;
                return;
            }
            pendingAttempts++;
            if(pendingAttempts<=20){
                ui.postDelayed(()->deliverToPage(code),250);
            }else{
                String save=pendingScan;
                pendingScan="";
                pendingAttempts=0;
                if(!save.isEmpty()){
                    Toast.makeText(this,"Pallet page not ready - scan saved",Toast.LENGTH_LONG).show();
                    queueWithContext(save);
                }
            }
        });
    }

    String activeIdJavascript(){
        if("pallet".equals(currentMode)){
            return "(function(){var e=document.getElementById('palletId');return e&&e.value?String(e.value):'';})()";
        }
        return "(function(){if(typeof _sid!=='undefined'&&_sid)return String(_sid);var e=document.getElementById('shipmentId');return e&&e.value?String(e.value):'';})()";
    }

    void queueWithContext(String code){
        web.evaluateJavascript(activeIdJavascript(),val->{
            String id=cleanJs(val);
            if(id.isEmpty()){
                Toast.makeText(this,"No active "+currentMode+" ID - scan not saved",Toast.LENGTH_LONG).show();
                return;
            }
            enqueue(currentMode,id,code);
            Toast.makeText(this,"SAVED: "+code+" ("+queueCount()+" pending)",Toast.LENGTH_LONG).show();
            updateNetStatus();
        });
    }

    String cleanJs(String v){
        if(v==null || "null".equals(v)) return "";
        v=v.trim();
        if(v.startsWith("\"")&&v.endsWith("\"")) v=v.substring(1,v.length()-1);
        return v.replace("\\\"","\"").replace("\\\\","\\");
    }

    void enqueue(String type,String id,String code){
        try{
            JSONArray a=getQueue();
            JSONObject o=new JSONObject();
            o.put("type",type);o.put("id",id);o.put("code",code);o.put("ts",System.currentTimeMillis());
            a.put(o);
            getSharedPreferences(PREFS,MODE_PRIVATE).edit().putString(QKEY,a.toString()).apply();
        }catch(Exception ignored){}
    }

    JSONArray getQueue(){
        try{return new JSONArray(getSharedPreferences(PREFS,MODE_PRIVATE).getString(QKEY,"[]"));}
        catch(Exception e){return new JSONArray();}
    }

    int queueCount(){return getQueue().length();}

    void syncOfflineQueue(){
        if(!isOnline()||web==null)return;
        JSONArray a=getQueue();
        if(a.length()==0){updateNetStatus();return;}
        try{syncFirst(a);}catch(Exception ignored){}
    }

    void syncFirst(JSONArray a){
        if(a.length()==0)return;
        try{
            JSONObject o=a.getJSONObject(0);
            String type=o.getString("type"),id=o.getString("id"),code=o.getString("code");
            String endpoint,body;
            if("pallet".equals(type)){
                endpoint="/pages/api/tc26_pallet_api.php";
                body="action=scan&pallet_id="+jsEncode(id)+"&case_serial="+jsEncode(code);
            }else{
                endpoint="/pages/api/tc26_shipping_api.php";
                body="action=scan&shipment_id="+jsEncode(id)+"&pallet_id="+jsEncode(code);
            }
            String js="(async function(){try{const p=new URLSearchParams('"+body+"');const r=await fetch('"+endpoint+"',{method:'POST',body:p,credentials:'include'});const t=await r.text();try{const j=JSON.parse(t);return j.ok?'OK':'ERR:'+String(j.err||j.error||j.message||'server');}catch(e){return 'ERR:json';}}catch(e){return 'ERR:network';}})()";
            web.evaluateJavascript(js,res->{
                String rr=cleanJs(res);
                if("OK".equals(rr)){
                    JSONArray n=new JSONArray();
                    for(int i=1;i<a.length();i++)try{n.put(a.getJSONObject(i));}catch(Exception ignored){}
                    getSharedPreferences(PREFS,MODE_PRIVATE).edit().putString(QKEY,n.toString()).apply();
                    updateNetStatus();
                    if(n.length()>0) syncOfflineQueue(); else web.reload();
                }else{
                    Toast.makeText(this,"Sync paused: "+rr,Toast.LENGTH_LONG).show();
                }
            });
        }catch(Exception ignored){}
    }

    String jsEncode(String s){
        try{return java.net.URLEncoder.encode(s,"UTF-8");}
        catch(Exception e){return s;}
    }

    boolean isOnline(){
        try{
            ConnectivityManager cm=(ConnectivityManager)getSystemService(CONNECTIVITY_SERVICE);
            if(Build.VERSION.SDK_INT>=23){
                Network n=cm.getActiveNetwork();
                if(n==null)return false;
                NetworkCapabilities c=cm.getNetworkCapabilities(n);
                if(c==null)return false;
                return c.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) ||
                       c.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) ||
                       c.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) ||
                       c.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET);
            }
            NetworkInfo ni=cm.getActiveNetworkInfo();
            return ni!=null&&ni.isConnected();
        }catch(Exception e){return false;}
    }

    void updateNetStatus(){
        if(status==null)return;
        int q=queueCount();
        if(isOnline()){
            status.setText(q>0?"ONLINE • "+q+" pending":"ONLINE");
            status.setTextColor(Color.rgb(100,220,120));
        }else{
            status.setText(q>0?"OFFLINE • "+q+" saved":"OFFLINE");
            status.setTextColor(Color.rgb(255,170,70));
        }
    }

    void sendDW(Bundle config){
        Intent i=new Intent(DW_API_ACTION);
        i.setPackage("com.symbol.datawedge");
        i.putExtra(DW_SET_CONFIG,config);
        sendBroadcast(i);
    }

    void setupDataWedge(){
        try{
            Bundle base=new Bundle();
            base.putString("PROFILE_NAME",PROFILE);
            base.putString("PROFILE_ENABLED","true");
            base.putString("CONFIG_MODE","CREATE_IF_NOT_EXIST");
            Bundle app=new Bundle();
            app.putString("PACKAGE_NAME",getPackageName());
            app.putStringArray("ACTIVITY_LIST",new String[]{"*"});
            base.putParcelableArray("APP_LIST",new Bundle[]{app});
            sendDW(base);

            Bundle barcode=new Bundle();
            barcode.putString("PROFILE_NAME",PROFILE);
            barcode.putString("PROFILE_ENABLED","true");
            barcode.putString("CONFIG_MODE","UPDATE");
            Bundle bcPlugin=new Bundle();
            bcPlugin.putString("PLUGIN_NAME","BARCODE");
            bcPlugin.putString("RESET_CONFIG","true");
            Bundle bcParams=new Bundle();
            bcParams.putString("scanner_selection","auto");
            bcParams.putString("scanner_input_enabled","true");
            bcPlugin.putBundle("PARAM_LIST",bcParams);
            barcode.putBundle("PLUGIN_CONFIG",bcPlugin);
            sendDW(barcode);

            Bundle intentCfg=new Bundle();
            intentCfg.putString("PROFILE_NAME",PROFILE);
            intentCfg.putString("PROFILE_ENABLED","true");
            intentCfg.putString("CONFIG_MODE","UPDATE");
            Bundle intPlugin=new Bundle();
            intPlugin.putString("PLUGIN_NAME","INTENT");
            intPlugin.putString("RESET_CONFIG","true");
            Bundle intParams=new Bundle();
            intParams.putString("intent_output_enabled","true");
            intParams.putString("intent_action",BuildConfig.DW_ACTION);
            intParams.putString("intent_category",Intent.CATEGORY_DEFAULT);
            intParams.putString("intent_delivery","2");
            intPlugin.putBundle("PARAM_LIST",intParams);
            intentCfg.putBundle("PLUGIN_CONFIG",intPlugin);
            sendDW(intentCfg);

            Bundle keyCfg=new Bundle();
            keyCfg.putString("PROFILE_NAME",PROFILE);
            keyCfg.putString("PROFILE_ENABLED","true");
            keyCfg.putString("CONFIG_MODE","UPDATE");
            Bundle keyPlugin=new Bundle();
            keyPlugin.putString("PLUGIN_NAME","KEYSTROKE");
            Bundle keyParams=new Bundle();
            keyParams.putString("keystroke_output_enabled","false");
            keyPlugin.putBundle("PARAM_LIST",keyParams);
            keyCfg.putBundle("PLUGIN_CONFIG",keyPlugin);
            sendDW(keyCfg);
        }catch(Exception e){
            Toast.makeText(this,"DataWedge setup error: "+e.getMessage(),Toast.LENGTH_LONG).show();
        }
    }
}
