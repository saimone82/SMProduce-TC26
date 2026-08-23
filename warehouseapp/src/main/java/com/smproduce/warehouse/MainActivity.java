package com.smproduce.warehouse;

import android.app.*;
import android.content.*;
import android.graphics.Color;
import android.media.*;
import android.os.*;
import android.view.*;
import android.webkit.*;
import android.widget.*;
import org.json.JSONObject;

public class MainActivity extends Activity {
    static final String PROFILE="SM_PRODUCE_WAREHOUSE";
    static final String DATA="com.symbol.datawedge.data_string";
    final Handler ui=new Handler(Looper.getMainLooper());
    LinearLayout home;
    WebView web;
    TextView title;
    Button back;
    boolean inWeb=false;

    final BroadcastReceiver rx=new BroadcastReceiver(){
        @Override public void onReceive(Context c, Intent i){
            if(!BuildConfig.DW_ACTION.equals(i.getAction())) return;
            String s=i.getStringExtra(DATA);
            if(s==null || s.trim().isEmpty()) return;
            deliverScan(s.trim());
        }
    };

    @Override public void onCreate(Bundle b){
        super.onCreate(b);
        buildUi();
        setupDataWedge();
        IntentFilter f=new IntentFilter(BuildConfig.DW_ACTION);
        if(Build.VERSION.SDK_INT>=33) registerReceiver(rx,f,Context.RECEIVER_EXPORTED); else registerReceiver(rx,f);
    }

    @Override protected void onResume(){ super.onResume(); setupDataWedge(); }
    @Override protected void onDestroy(){ try{unregisterReceiver(rx);}catch(Exception ignored){} if(web!=null)web.destroy(); super.onDestroy(); }

    void buildUi(){
        LinearLayout root=new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL); root.setBackgroundColor(Color.rgb(6,17,31));
        LinearLayout bar=new LinearLayout(this); bar.setGravity(Gravity.CENTER_VERTICAL); bar.setPadding(12,8,12,8); bar.setBackgroundColor(Color.rgb(13,29,48));
        back=new Button(this); back.setText("‹ HOME"); back.setVisibility(View.GONE); back.setOnClickListener(v->showHome()); bar.addView(back,new LinearLayout.LayoutParams(-2,-2));
        title=new TextView(this); title.setText("SM PRODUCE WAREHOUSE"); title.setTextColor(Color.WHITE); title.setTextSize(20); title.setTypeface(null,1); title.setGravity(Gravity.CENTER); bar.addView(title,new LinearLayout.LayoutParams(0,-2,1));
        root.addView(bar,new LinearLayout.LayoutParams(-1,-2));

        FrameLayout body=new FrameLayout(this);
        home=new LinearLayout(this); home.setOrientation(LinearLayout.VERTICAL); home.setGravity(Gravity.CENTER); home.setPadding(28,28,28,28);
        TextView sub=new TextView(this); sub.setText("TC26 PALLET & SHIPPING"); sub.setTextColor(Color.rgb(143,169,193)); sub.setTextSize(16); sub.setGravity(Gravity.CENTER); home.addView(sub,new LinearLayout.LayoutParams(-1,-2));
        Button p=bigButton("PALLETIZATION\nCreate / Scan / Close Pallet", Color.rgb(25,118,210)); p.setOnClickListener(v->openPage("Palletization","/pages/tc26_pallet.php"));
        LinearLayout.LayoutParams bp=new LinearLayout.LayoutParams(-1,150); bp.topMargin=50; home.addView(p,bp);
        Button s=bigButton("SHIPPING\nScan Pallets / PO / BOL", Color.rgb(46,125,50)); s.setOnClickListener(v->openPage("Shipping","/pages/tc26_shipping.php"));
        LinearLayout.LayoutParams bs=new LinearLayout.LayoutParams(-1,150); bs.topMargin=24; home.addView(s,bs);
        TextView hint=new TextView(this); hint.setText("Use the TC26 hardware scan trigger inside Palletization or Shipping"); hint.setTextColor(Color.rgb(143,169,193)); hint.setTextSize(14); hint.setGravity(Gravity.CENTER); LinearLayout.LayoutParams hp=new LinearLayout.LayoutParams(-1,-2); hp.topMargin=42; home.addView(hint,hp);
        body.addView(home,new FrameLayout.LayoutParams(-1,-1));

        web=new WebView(this); web.setVisibility(View.GONE); web.setBackgroundColor(Color.rgb(11,22,34));
        WebSettings ws=web.getSettings(); ws.setJavaScriptEnabled(true); ws.setDomStorageEnabled(true); ws.setDatabaseEnabled(true); ws.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW); ws.setUserAgentString(ws.getUserAgentString()+" SMProduceTC26Warehouse/1.0");
        CookieManager.getInstance().setAcceptCookie(true); CookieManager.getInstance().setAcceptThirdPartyCookies(web,true);
        web.setWebViewClient(new WebViewClient(){
            @Override public void onPageFinished(WebView v,String url){ super.onPageFinished(v,url); }
            @Override public void onReceivedError(WebView v, WebResourceRequest r, WebResourceError e){ if(r.isForMainFrame()) toast("Connection error"); }
        });
        body.addView(web,new FrameLayout.LayoutParams(-1,-1)); root.addView(body,new LinearLayout.LayoutParams(-1,0,1)); setContentView(root);
    }

    Button bigButton(String text,int color){ Button b=new Button(this); b.setText(text); b.setTextColor(Color.WHITE); b.setTextSize(20); b.setTypeface(null,1); b.setGravity(Gravity.CENTER); b.setBackgroundColor(color); return b; }

    void openPage(String name,String path){
        inWeb=true; home.setVisibility(View.GONE); web.setVisibility(View.VISIBLE); back.setVisibility(View.VISIBLE); title.setText(name.toUpperCase());
        String url=BuildConfig.BASE_URL+path+"?token="+UriEncode(BuildConfig.TC26_TOKEN);
        web.loadUrl(url);
    }
    String UriEncode(String s){ try{return java.net.URLEncoder.encode(s,"UTF-8");}catch(Exception e){return s;} }

    void showHome(){ inWeb=false; web.stopLoading(); web.setVisibility(View.GONE); home.setVisibility(View.VISIBLE); back.setVisibility(View.GONE); title.setText("SM PRODUCE WAREHOUSE"); }

    @Override public void onBackPressed(){ if(inWeb){ showHome(); } else super.onBackPressed(); }

    void deliverScan(String code){
        if(!inWeb || web.getVisibility()!=View.VISIBLE){ tone(false); toast("Open Palletization or Shipping first"); return; }
        final String q=JSONObject.quote(code);
        web.evaluateJavascript("(function(){if(typeof onDataWedgeScan==='function'){onDataWedgeScan("+q+",'tc26');return 'OK';}return 'NO_CALLBACK';})()", v->{
            if(v!=null && v.contains("NO_CALLBACK")){ tone(false); toast("Page not ready for scanner"); }
        });
    }

    void toast(String m){ Toast.makeText(this,m,Toast.LENGTH_SHORT).show(); }
    void tone(boolean ok){ try{ ToneGenerator t=new ToneGenerator(AudioManager.STREAM_NOTIFICATION,80); t.startTone(ok?ToneGenerator.TONE_PROP_BEEP:ToneGenerator.TONE_PROP_NACK,150); ui.postDelayed(t::release,300);}catch(Exception ignored){} }

    void dw(Bundle cfg){ Intent i=new Intent("com.symbol.datawedge.api.ACTION"); i.setPackage("com.symbol.datawedge"); i.putExtra("com.symbol.datawedge.api.SET_CONFIG",cfg); sendBroadcast(i); }
    Bundle baseCfg(String mode){ Bundle p=new Bundle(); p.putString("PROFILE_NAME",PROFILE); p.putString("PROFILE_ENABLED","true"); p.putString("CONFIG_MODE",mode); return p; }

    void setupDataWedge(){
        try{
            Bundle create=baseCfg("CREATE_IF_NOT_EXIST"); dw(create);

            Bundle assoc=baseCfg("UPDATE"); Bundle a=new Bundle(); a.putString("PACKAGE_NAME",getPackageName()); a.putStringArray("ACTIVITY_LIST",new String[]{"*"}); assoc.putParcelableArray("APP_LIST",new Bundle[]{a}); dw(assoc);

            Bundle bar=baseCfg("UPDATE"); Bundle b=new Bundle(); b.putString("PLUGIN_NAME","BARCODE"); b.putString("RESET_CONFIG","true"); Bundle bp=new Bundle(); bp.putString("scanner_selection","auto"); bp.putString("scanner_input_enabled","true"); b.putBundle("PARAM_LIST",bp); bar.putBundle("PLUGIN_CONFIG",b); dw(bar);

            Bundle intent=baseCfg("UPDATE"); Bundle o=new Bundle(); o.putString("PLUGIN_NAME","INTENT"); o.putString("RESET_CONFIG","true"); Bundle op=new Bundle(); op.putString("intent_output_enabled","true"); op.putString("intent_action",BuildConfig.DW_ACTION); op.putString("intent_category","android.intent.category.DEFAULT"); op.putInt("intent_delivery",2); o.putBundle("PARAM_LIST",op); intent.putBundle("PLUGIN_CONFIG",o); dw(intent);

            Bundle key=baseCfg("UPDATE"); Bundle k=new Bundle(); k.putString("PLUGIN_NAME","KEYSTROKE"); Bundle kp=new Bundle(); kp.putString("keystroke_output_enabled","false"); k.putBundle("PARAM_LIST",kp); key.putBundle("PLUGIN_CONFIG",k); dw(key);
        }catch(Exception e){ toast("DataWedge setup error: "+e.getMessage()); }
    }
}
