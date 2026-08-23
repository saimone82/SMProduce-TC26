package com.smproduce.warehouse;

import android.app.*;
import android.content.*;
import android.graphics.Color;
import android.os.*;
import android.view.*;
import android.webkit.*;
import android.widget.*;
import org.json.JSONObject;

public class MainActivity extends Activity {
    static final String PROFILE="SM_PRODUCE_WAREHOUSE";
    static final String DATA="com.symbol.datawedge.data_string";
    LinearLayout home;
    WebView web;
    TextView title;
    Button back;
    boolean inWeb=false;

    final BroadcastReceiver rx=new BroadcastReceiver(){
        @Override public void onReceive(Context c, Intent i){
            if(!BuildConfig.DW_ACTION.equals(i.getAction())) return;
            String s=i.getStringExtra(DATA);
            if(s!=null && !s.trim().isEmpty()) deliverScan(s.trim());
        }
    };

    @Override public void onCreate(Bundle b){
        super.onCreate(b); buildUi(); setupDataWedge();
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
        TextView sub=new TextView(this); sub.setText("PALLETIZATION & SHIPPING"); sub.setTextColor(Color.rgb(143,169,193)); sub.setTextSize(17); sub.setGravity(Gravity.CENTER); home.addView(sub);
        Button p=bigButton("PALLETIZATION\nCreate • Scan Cases • Close Pallet",Color.rgb(25,118,210)); p.setOnClickListener(v->openPage("Palletization","/pages/tc26_pallet.php")); LinearLayout.LayoutParams pp=new LinearLayout.LayoutParams(-1,160); pp.topMargin=45; home.addView(p,pp);
        Button s=bigButton("SHIPPING\nScan Pallets • PO • Close • BOL",Color.rgb(46,125,50)); s.setOnClickListener(v->openPage("Shipping","/pages/tc26_shipping.php")); LinearLayout.LayoutParams sp=new LinearLayout.LayoutParams(-1,160); sp.topMargin=24; home.addView(s,sp);
        TextView hint=new TextView(this); hint.setText("Hardware scanner enabled automatically"); hint.setTextColor(Color.rgb(143,169,193)); hint.setTextSize(14); hint.setGravity(Gravity.CENTER); LinearLayout.LayoutParams hp=new LinearLayout.LayoutParams(-1,-2); hp.topMargin=38; home.addView(hint,hp);
        body.addView(home,new FrameLayout.LayoutParams(-1,-1));
        web=new WebView(this); web.setVisibility(View.GONE); web.setBackgroundColor(Color.rgb(11,22,34));
        WebSettings ws=web.getSettings(); ws.setJavaScriptEnabled(true); ws.setDomStorageEnabled(true); ws.setDatabaseEnabled(true); ws.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW); ws.setUserAgentString(ws.getUserAgentString()+" SMProduceTC26Warehouse/1.1");
        CookieManager.getInstance().setAcceptCookie(true); CookieManager.getInstance().setAcceptThirdPartyCookies(web,true);
        web.setWebViewClient(new WebViewClient(){ @Override public void onReceivedError(WebView v,WebResourceRequest r,WebResourceError e){ if(r.isForMainFrame()) Toast.makeText(MainActivity.this,"Connection error",Toast.LENGTH_SHORT).show(); }});
        body.addView(web,new FrameLayout.LayoutParams(-1,-1)); root.addView(body,new LinearLayout.LayoutParams(-1,0,1)); setContentView(root);
    }
    Button bigButton(String text,int color){ Button b=new Button(this); b.setText(text); b.setTextColor(Color.WHITE); b.setTextSize(19); b.setTypeface(null,1); b.setGravity(Gravity.CENTER); b.setBackgroundColor(color); return b; }
    void openPage(String name,String path){ inWeb=true; home.setVisibility(View.GONE); web.setVisibility(View.VISIBLE); back.setVisibility(View.VISIBLE); title.setText(name.toUpperCase()); web.loadUrl(BuildConfig.BASE_URL+path); }
    void showHome(){ inWeb=false; web.stopLoading(); web.setVisibility(View.GONE); home.setVisibility(View.VISIBLE); back.setVisibility(View.GONE); title.setText("SM PRODUCE WAREHOUSE"); }
    @Override public void onBackPressed(){ if(inWeb){ if(web.canGoBack()) web.goBack(); else showHome(); } else super.onBackPressed(); }
    void deliverScan(String code){
        if(!inWeb || web.getVisibility()!=View.VISIBLE){ Toast.makeText(this,"Open Palletization or Shipping",Toast.LENGTH_SHORT).show(); return; }
        String q=JSONObject.quote(code);
        web.evaluateJavascript("(function(){if(typeof onDataWedgeScan==='function'){onDataWedgeScan("+q+",'tc26');return 'OK';}return 'NO_CALLBACK';})()",v->{ if(v!=null&&v.contains("NO_CALLBACK")) Toast.makeText(this,"Scanner page not ready",Toast.LENGTH_SHORT).show(); });
    }
    void dw(Bundle cfg){ Intent i=new Intent("com.symbol.datawedge.api.ACTION"); i.setPackage("com.symbol.datawedge"); i.putExtra("com.symbol.datawedge.api.SET_CONFIG",cfg); sendBroadcast(i); }
    Bundle base(String mode){ Bundle p=new Bundle(); p.putString("PROFILE_NAME",PROFILE); p.putString("PROFILE_ENABLED","true"); p.putString("CONFIG_MODE",mode); return p; }
    void setupDataWedge(){ try{
        dw(base("CREATE_IF_NOT_EXIST"));
        Bundle assoc=base("UPDATE"),a=new Bundle(); a.putString("PACKAGE_NAME",getPackageName()); a.putStringArray("ACTIVITY_LIST",new String[]{"*"}); assoc.putParcelableArray("APP_LIST",new Bundle[]{a}); dw(assoc);
        Bundle bar=base("UPDATE"),b=new Bundle(),bp=new Bundle(); b.putString("PLUGIN_NAME","BARCODE"); b.putString("RESET_CONFIG","true"); bp.putString("scanner_selection","auto"); bp.putString("scanner_input_enabled","true"); b.putBundle("PARAM_LIST",bp); bar.putBundle("PLUGIN_CONFIG",b); dw(bar);
        Bundle out=base("UPDATE"),o=new Bundle(),op=new Bundle(); o.putString("PLUGIN_NAME","INTENT"); o.putString("RESET_CONFIG","true"); op.putString("intent_output_enabled","true"); op.putString("intent_action",BuildConfig.DW_ACTION); op.putString("intent_category","android.intent.category.DEFAULT"); op.putInt("intent_delivery",2); o.putBundle("PARAM_LIST",op); out.putBundle("PLUGIN_CONFIG",o); dw(out);
        Bundle key=base("UPDATE"),k=new Bundle(),kp=new Bundle(); k.putString("PLUGIN_NAME","KEYSTROKE"); kp.putString("keystroke_output_enabled","false"); k.putBundle("PARAM_LIST",kp); key.putBundle("PLUGIN_CONFIG",k); dw(key);
    }catch(Exception e){ Toast.makeText(this,"DataWedge setup error",Toast.LENGTH_SHORT).show(); }}
}
