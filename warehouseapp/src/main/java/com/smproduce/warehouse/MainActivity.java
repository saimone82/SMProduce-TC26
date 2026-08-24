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
    static final String DATA="com.symbol.datawedge.data_string";
    static final String PREFS="warehouse_offline";
    static final String QKEY="queue";

    LinearLayout home;
    WebView web;
    TextView title,status;
    Button back;
    boolean inWeb=false;
    String currentMode="";
    StringBuilder keyBuf=new StringBuilder();
    long lastKeyAt=0,lastScanAt=0;
    String lastScan="";

    String pendingScan="";
    int pendingAttempts=0;
    final Handler scanHandler=new Handler(Looper.getMainLooper());

    final BroadcastReceiver rx=new BroadcastReceiver(){
        @Override public void onReceive(Context c,Intent i){
            if(!BuildConfig.DW_ACTION.equals(i.getAction())) return;
            String s=i.getStringExtra(DATA);
            if(s!=null&&!s.trim().isEmpty()) acceptScan(s.trim(),"intent");
        }
    };

    final BroadcastReceiver netRx=new BroadcastReceiver(){
        @Override public void onReceive(Context c,Intent i){updateNetStatus();if(isOnline())syncOfflineQueue();}
    };

    @Override public void onCreate(Bundle b){
        super.onCreate(b);
        buildUi();
        setupDataWedge();
        IntentFilter f=new IntentFilter(BuildConfig.DW_ACTION);
        if(Build.VERSION.SDK_INT>=33)registerReceiver(rx,f,Context.RECEIVER_EXPORTED);else registerReceiver(rx,f);
        registerReceiver(netRx,new IntentFilter(ConnectivityManager.CONNECTIVITY_ACTION));
        updateNetStatus();
    }

    @Override protected void onResume(){super.onResume();setupDataWedge();updateNetStatus();if(isOnline())syncOfflineQueue();}

    @Override protected void onDestroy(){
        scanHandler.removeCallbacksAndMessages(null);
        try{unregisterReceiver(rx);}catch(Exception ignored){}
        try{unregisterReceiver(netRx);}catch(Exception ignored){}
        if(web!=null)web.destroy();
        super.onDestroy();
    }

    @Override public boolean dispatchKeyEvent(KeyEvent e){
        if(e.getAction()!=KeyEvent.ACTION_DOWN)return super.dispatchKeyEvent(e);
        int k=e.getKeyCode();long now=System.currentTimeMillis();
        if(now-lastKeyAt>500)keyBuf.setLength(0);lastKeyAt=now;
        if(k==KeyEvent.KEYCODE_ENTER||k==KeyEvent.KEYCODE_NUMPAD_ENTER){
            String s=keyBuf.toString().trim();keyBuf.setLength(0);
            if(!s.isEmpty()){acceptScan(s,"keystroke");return true;}
        }
        int u=e.getUnicodeChar();
        if(u>=32&&u<=126){keyBuf.append((char)u);return true;}
        return super.dispatchKeyEvent(e);
    }

    void buildUi(){
        LinearLayout root=new LinearLayout(this);root.setOrientation(LinearLayout.VERTICAL);root.setBackgroundColor(Color.rgb(6,17,31));
        LinearLayout bar=new LinearLayout(this);bar.setGravity(Gravity.CENTER_VERTICAL);bar.setPadding(12,8,12,8);bar.setBackgroundColor(Color.rgb(13,29,48));
        back=new Button(this);back.setText("‹ HOME");back.setVisibility(View.GONE);back.setOnClickListener(v->showHome());bar.addView(back,new LinearLayout.LayoutParams(-2,-2));
        title=new TextView(this);title.setText("WAREHOUSE");title.setTextColor(Color.WHITE);title.setTextSize(20);title.setTypeface(null,1);title.setGravity(Gravity.CENTER);bar.addView(title,new LinearLayout.LayoutParams(0,-2,1));
        status=new TextView(this);status.setTextSize(11);status.setPadding(8,0,0,0);bar.addView(status,new LinearLayout.LayoutParams(-2,-2));
        root.addView(bar,new LinearLayout.LayoutParams(-1,-2));

        FrameLayout body=new FrameLayout(this);
        home=new LinearLayout(this);home.setOrientation(LinearLayout.VERTICAL);home.setGravity(Gravity.CENTER);home.setPadding(28,28,28,28);
        TextView brand=new TextView(this);brand.setText("SM PRODUCE");brand.setTextColor(Color.WHITE);brand.setTextSize(28);brand.setTypeface(null,1);brand.setGravity(Gravity.CENTER);home.addView(brand);
        TextView sub=new TextView(this);sub.setText("WAREHOUSE");sub.setTextColor(Color.rgb(143,169,193));sub.setTextSize(17);sub.setGravity(Gravity.CENTER);home.addView(sub);

        Button p=bigButton("PALLETS",Color.rgb(25,118,210));
        p.setOnClickListener(v->openPage("Pallets","pallet","/pages/tc26_pallet.php?token="+BuildConfig.TC26_TOKEN));
        LinearLayout.LayoutParams pp=new LinearLayout.LayoutParams(-1,150);pp.topMargin=45;home.addView(p,pp);

        Button s=bigButton("SHIPPING",Color.rgb(46,125,50));
        s.setOnClickListener(v->openPage("Shipping","shipping","/pages/tc26_shipping.php?token="+BuildConfig.TC26_TOKEN));
        LinearLayout.LayoutParams sp=new LinearLayout.LayoutParams(-1,150);sp.topMargin=24;home.addView(s,sp);

        TextView hint=new TextView(this);hint.setText("TC26 scanner ready\nOffline scans are saved locally and synchronized when connection returns");hint.setTextColor(Color.rgb(143,169,193));hint.setTextSize(14);hint.setGravity(Gravity.CENTER);LinearLayout.LayoutParams hp=new LinearLayout.LayoutParams(-1,-2);hp.topMargin=38;home.addView(hint,hp);
        body.addView(home,new FrameLayout.LayoutParams(-1,-1));

        web=new WebView(this);web.setVisibility(View.GONE);web.setBackgroundColor(Color.rgb(11,22,34));web.setFocusable(true);web.setFocusableInTouchMode(true);
        WebSettings ws=web.getSettings();ws.setJavaScriptEnabled(true);ws.setDomStorageEnabled(true);ws.setDatabaseEnabled(true);ws.setCacheMode(WebSettings.LOAD_DEFAULT);ws.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);ws.setUserAgentString(ws.getUserAgentString()+" SMProduceWarehouse/1.3.5");
        CookieManager.getInstance().setAcceptCookie(true);CookieManager.getInstance().setAcceptThirdPartyCookies(web,true);
        web.setWebViewClient(new WebViewClient(){
            @Override public void onPageFinished(WebView v,String url){
                super.onPageFinished(v,url);updateNetStatus();
                if(!pendingScan.isEmpty())retryPendingScan();
                if(isOnline())syncOfflineQueue();
            }
            @Override public void onReceivedError(WebView v,WebResourceRequest r,WebResourceError e){
                if(r.isForMainFrame())Toast.makeText(MainActivity.this,"OFFLINE - scans will be saved locally",Toast.LENGTH_LONG).show();
            }
        });
        body.addView(web,new FrameLayout.LayoutParams(-1,-1));root.addView(body,new LinearLayout.LayoutParams(-1,0,1));setContentView(root);
    }

    Button bigButton(String text,int color){Button b=new Button(this);b.setText(text);b.setTextColor(Color.WHITE);b.setTextSize(22);b.setTypeface(null,1);b.setGravity(Gravity.CENTER);b.setBackgroundColor(color);return b;}

    void openPage(String name,String mode,String path){
        currentMode=mode;inWeb=true;pendingScan="";pendingAttempts=0;
        home.setVisibility(View.GONE);web.setVisibility(View.VISIBLE);back.setVisibility(View.VISIBLE);title.setText(name.toUpperCase());web.loadUrl(BuildConfig.BASE_URL+path);web.requestFocus();
    }

    void showHome(){
        inWeb=false;currentMode="";pendingScan="";pendingAttempts=0;scanHandler.removeCallbacksAndMessages(null);
        web.stopLoading();web.setVisibility(View.GONE);home.setVisibility(View.VISIBLE);back.setVisibility(View.GONE);title.setText("WAREHOUSE");
    }

    @Override public void onBackPressed(){if(inWeb){if(web.canGoBack())web.goBack();else showHome();}else super.onBackPressed();}

    void acceptScan(String code,String source){
        code=code==null?"":code.trim();if(code.isEmpty())return;
        long now=System.currentTimeMillis();
        if(code.equals(lastScan)&&now-lastScanAt<800)return;
        lastScan=code;lastScanAt=now;
        Toast.makeText(this,"SCAN: "+code,Toast.LENGTH_SHORT).show();
        deliverScan(code);
    }

    void deliverScan(String code){
        if(!inWeb||currentMode.isEmpty()){Toast.makeText(this,"Open Pallets or Shipping",Toast.LENGTH_SHORT).show();return;}
        if(!isOnline()){queueWithContext(code);return;}
        submitOnlineScan(code);
    }

    String activeIdJavascript(){
        if("pallet".equals(currentMode)){
            return "(function(){var e=document.getElementById('palletId')||document.getElementById('pallet_id')||document.querySelector('[name=\\\"pallet_id\\\"]');if(e&&e.value)return String(e.value);if(typeof _pid!=='undefined'&&_pid)return String(_pid);if(typeof palletId!=='undefined'&&palletId)return String(palletId);return '';})()";
        }
        return "(function(){var e=document.getElementById('shipmentId')||document.getElementById('shipment_id')||document.querySelector('[name=\\\"shipment_id\\\"]');if(e&&e.value)return String(e.value);if(typeof _sid!=='undefined'&&_sid)return String(_sid);if(typeof shipmentId!=='undefined'&&shipmentId)return String(shipmentId);return '';})()";
    }

    void submitOnlineScan(String code){
        web.evaluateJavascript(activeIdJavascript(),val->{
            String id=cleanJs(val);
            if(id.isEmpty()){
                pendingScan=code;pendingAttempts=0;tryDeliverPending();
                return;
            }
            final String endpoint="pallet".equals(currentMode)?"/pages/api/tc26_pallet_api.php":"/pages/api/tc26_shipping_api.php";
            final String body="pallet".equals(currentMode)
                    ? "action=scan&pallet_id="+jsEncode(id)+"&case_serial="+jsEncode(code)+"&token="+jsEncode(BuildConfig.TC26_TOKEN)
                    : "action=scan&shipment_id="+jsEncode(id)+"&pallet_id="+jsEncode(code)+"&token="+jsEncode(BuildConfig.TC26_TOKEN);
            String js="(async function(){try{const p=new URLSearchParams('"+body+"');const r=await fetch('"+endpoint+"',{method:'POST',body:p,credentials:'include'});const t=await r.text();try{const j=JSON.parse(t);return j.ok?'OK:'+String(j.message||j.msg||'Added'):'ERR:'+String(j.error||j.err||j.message||'server');}catch(e){return 'ERR:JSON '+t.substring(0,100);}}catch(e){return 'ERR:'+String(e);}})()";
            web.evaluateJavascript(js,res->{
                String rr=cleanJs(res);
                if(rr.startsWith("OK:")){
                    Toast.makeText(this,"ADDED: "+code,Toast.LENGTH_SHORT).show();
                    web.reload();
                }else{
                    Toast.makeText(this,"SCAN ERROR: "+rr,Toast.LENGTH_LONG).show();
                }
            });
        });
    }

    void retryPendingScan(){if(pendingScan.isEmpty())return;scanHandler.removeCallbacksAndMessages(null);scanHandler.postDelayed(this::tryDeliverPending,150);}

    void tryDeliverPending(){
        if(pendingScan.isEmpty()||!inWeb)return;
        final String code=pendingScan;
        final String q=JSONObject.quote(code);
        String js="(function(){try{"+
                "if(typeof window.onDataWedgeScan==='function'){window.onDataWedgeScan("+q+",'tc26');return 'OK_CALLBACK';}"+
                "var fns=['scanCase','handleScan','processScan','addCase'];for(var i=0;i<fns.length;i++){if(typeof window[fns[i]]==='function'){window[fns[i]]("+q+");return 'OK_FN:'+fns[i];}}"+
                "var sel=['#caseSerial','#case_serial','#scanInput','#barcode','[name=\\\"case_serial\\\"]','[name=\\\"serial\\\"]','[name=\\\"barcode\\\"]'];var a=null;for(var x=0;x<sel.length&&!a;x++)a=document.querySelector(sel[x]);"+
                "if(!a){var all=document.querySelectorAll('input[type=\\\"text\\\"],input:not([type])');for(var y=0;y<all.length;y++){var z=((all[y].id||'')+' '+(all[y].name||'')+' '+(all[y].placeholder||'')).toLowerCase();if(z.indexOf('scan')>=0||z.indexOf('barcode')>=0||z.indexOf('serial')>=0||z.indexOf('case')>=0){a=all[y];break;}}}"+
                "if(a){a.focus();a.value="+q+";a.dispatchEvent(new Event('input',{bubbles:true}));a.dispatchEvent(new Event('change',{bubbles:true}));a.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',code:'Enter',keyCode:13,which:13,bubbles:true}));a.dispatchEvent(new KeyboardEvent('keyup',{key:'Enter',code:'Enter',keyCode:13,which:13,bubbles:true}));if(a.form&&typeof a.form.requestSubmit==='function')a.form.requestSubmit();return 'OK_INPUT';}"+
                "return document.readyState==='complete'?'NO_HANDLER':'LOADING';"+
                "}catch(e){return 'ERR:'+String(e);}})()";
        web.evaluateJavascript(js,res->{
            String r=cleanJs(res);
            if(r.startsWith("OK_")){
                pendingScan="";pendingAttempts=0;
                Toast.makeText(this,"ACQUIRED: "+code,Toast.LENGTH_SHORT).show();
                return;
            }
            pendingAttempts++;
            if(pendingAttempts<=20){scanHandler.postDelayed(this::tryDeliverPending,250);}else{
                String save=pendingScan;pendingScan="";pendingAttempts=0;
                if(!save.isEmpty()){
                    Toast.makeText(this,"No scanner handler on page - saving scan",Toast.LENGTH_LONG).show();
                    queueWithContext(save);
                }
            }
        });
    }

    void queueWithContext(String code){
        web.evaluateJavascript(activeIdJavascript(),val->{
            String id=cleanJs(val);
            if(id.isEmpty()){Toast.makeText(this,"No active "+currentMode+" ID - scan not saved",Toast.LENGTH_LONG).show();return;}
            enqueue(currentMode,id,code);Toast.makeText(this,"SAVED: "+code+" ("+queueCount()+" pending)",Toast.LENGTH_LONG).show();updateNetStatus();
            if(isOnline())syncOfflineQueue();
        });
    }

    String cleanJs(String v){if(v==null||"null".equals(v))return "";v=v.trim();if(v.startsWith("\"")&&v.endsWith("\""))v=v.substring(1,v.length()-1);return v.replace("\\\"","\"").replace("\\\\","\\");}

    void enqueue(String type,String id,String code){try{JSONArray a=getQueue();JSONObject o=new JSONObject();o.put("type",type);o.put("id",id);o.put("code",code);o.put("ts",System.currentTimeMillis());a.put(o);getSharedPreferences(PREFS,MODE_PRIVATE).edit().putString(QKEY,a.toString()).apply();}catch(Exception ignored){}}
    JSONArray getQueue(){try{return new JSONArray(getSharedPreferences(PREFS,MODE_PRIVATE).getString(QKEY,"[]"));}catch(Exception e){return new JSONArray();}}
    int queueCount(){return getQueue().length();}

    void syncOfflineQueue(){if(!isOnline()||web==null)return;JSONArray a=getQueue();if(a.length()==0){updateNetStatus();return;}try{syncItem(a,0);}catch(Exception ignored){}}

    void syncItem(JSONArray a,int idx){
        if(idx>=a.length()){getSharedPreferences(PREFS,MODE_PRIVATE).edit().putString(QKEY,"[]").apply();Toast.makeText(this,"Offline scans synchronized",Toast.LENGTH_LONG).show();updateNetStatus();return;}
        try{
            JSONObject o=a.getJSONObject(idx);String type=o.getString("type"),id=o.getString("id"),code=o.getString("code");String endpoint,body;
            if("pallet".equals(type)){endpoint="/pages/api/tc26_pallet_api.php";body="action=scan&pallet_id="+jsEncode(id)+"&case_serial="+jsEncode(code)+"&token="+jsEncode(BuildConfig.TC26_TOKEN);}else{endpoint="/pages/api/tc26_shipping_api.php";body="action=scan&shipment_id="+jsEncode(id)+"&pallet_id="+jsEncode(code)+"&token="+jsEncode(BuildConfig.TC26_TOKEN);}
            String js="(async function(){try{const p=new URLSearchParams('"+body+"');const r=await fetch('"+endpoint+"',{method:'POST',body:p,credentials:'include'});const t=await r.text();try{const j=JSON.parse(t);return j.ok?'OK':'ERR:'+String(j.error||j.err||j.message||'server');}catch(e){return 'ERR:json';}}catch(e){return 'ERR:network';}})()";
            web.evaluateJavascript(js,res->{String rr=cleanJs(res);if("OK".equals(rr)){JSONArray n=new JSONArray();for(int x=idx+1;x<a.length();x++)try{n.put(a.getJSONObject(x));}catch(Exception ignored){}getSharedPreferences(PREFS,MODE_PRIVATE).edit().putString(QKEY,n.toString()).apply();updateNetStatus();syncOfflineQueue();web.reload();}else Toast.makeText(this,"Sync paused: "+rr,Toast.LENGTH_LONG).show();});
        }catch(Exception ignored){}
    }

    String jsEncode(String s){try{return java.net.URLEncoder.encode(s,"UTF-8");}catch(Exception e){return s;}}

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

    void dw(Bundle cfg){Intent i=new Intent("com.symbol.datawedge.api.ACTION");i.setPackage("com.symbol.datawedge");i.putExtra("com.symbol.datawedge.api.SET_CONFIG",cfg);sendBroadcast(i);}
    Bundle base(String mode){Bundle p=new Bundle();p.putString("PROFILE_NAME",PROFILE);p.putString("PROFILE_ENABLED","true");p.putString("CONFIG_MODE",mode);return p;}

    void setupDataWedge(){try{
        dw(base("CREATE_IF_NOT_EXIST"));
        Bundle assoc=base("UPDATE"),a=new Bundle();a.putString("PACKAGE_NAME",getPackageName());a.putStringArray("ACTIVITY_LIST",new String[]{"*"});assoc.putParcelableArray("APP_LIST",new Bundle[]{a});dw(assoc);
        Bundle bar=base("UPDATE"),b=new Bundle(),bp=new Bundle();b.putString("PLUGIN_NAME","BARCODE");b.putString("RESET_CONFIG","true");bp.putString("scanner_selection","auto");bp.putString("scanner_input_enabled","true");b.putBundle("PARAM_LIST",bp);bar.putBundle("PLUGIN_CONFIG",b);dw(bar);
        Bundle out=base("UPDATE"),o=new Bundle(),op=new Bundle();o.putString("PLUGIN_NAME","INTENT");o.putString("RESET_CONFIG","true");op.putString("intent_output_enabled","true");op.putString("intent_action",BuildConfig.DW_ACTION);op.putString("intent_category","android.intent.category.DEFAULT");op.putInt("intent_delivery",2);o.putBundle("PARAM_LIST",op);out.putBundle("PLUGIN_CONFIG",o);dw(out);
        Bundle key=base("UPDATE"),k=new Bundle(),kp=new Bundle();k.putString("PLUGIN_NAME","KEYSTROKE");k.putString("RESET_CONFIG","true");kp.putString("keystroke_output_enabled","false");k.putBundle("PARAM_LIST",kp);key.putBundle("PLUGIN_CONFIG",k);dw(key);
    }catch(Exception e){Toast.makeText(this,"DataWedge setup error: "+e.getMessage(),Toast.LENGTH_LONG).show();}}
}
