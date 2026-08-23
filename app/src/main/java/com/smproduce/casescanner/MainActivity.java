package com.smproduce.casescanner;

import android.app.*;
import android.content.*;
import android.graphics.Color;
import android.media.*;
import android.os.*;
import android.provider.Settings;
import android.view.*;
import android.widget.*;
import org.json.JSONObject;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.util.*;
import java.util.concurrent.*;

public class MainActivity extends Activity {
    static final String PROFILE = "SM_PRODUCE_CASE_SCANNER";
    static final String DW_API_ACTION = "com.symbol.datawedge.api.ACTION";
    static final String DW_SET_CONFIG = "com.symbol.datawedge.api.SET_CONFIG";
    static final String DATA = "com.symbol.datawedge.data_string";

    final Handler ui = new Handler(Looper.getMainLooper());
    final ExecutorService net = Executors.newSingleThreadExecutor();
    LinearLayout root;
    TextView state, code, details, hint;
    volatile boolean busy = false;
    String last = "";
    long lastAt = 0;

    final BroadcastReceiver rx = new BroadcastReceiver() {
        @Override public void onReceive(Context c, Intent i) {
            if (!BuildConfig.DW_ACTION.equals(i.getAction())) return;
            String s = i.getStringExtra(DATA);
            if (s != null && !s.trim().isEmpty()) scan(s);
        }
    };

    @Override public void onCreate(Bundle b) {
        super.onCreate(b);
        buildUi();
        registerScanReceiver();
        ui.postDelayed(this::setupDW, 500);
        ready();
    }

    void registerScanReceiver() {
        IntentFilter f = new IntentFilter();
        f.addAction(BuildConfig.DW_ACTION);
        f.addCategory(Intent.CATEGORY_DEFAULT);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(rx, f, Context.RECEIVER_EXPORTED);
        else registerReceiver(rx, f);
    }

    @Override protected void onResume() {
        super.onResume();
        ui.postDelayed(this::setupDW, 300);
    }

    @Override protected void onDestroy() {
        try { unregisterReceiver(rx); } catch (Exception ignored) {}
        net.shutdownNow();
        super.onDestroy();
    }

    TextView tv(String s, int z, boolean bold) {
        TextView v = new TextView(this);
        v.setText(s); v.setTextSize(z); v.setTextColor(Color.WHITE); v.setGravity(Gravity.CENTER);
        if (bold) v.setTypeface(null, 1);
        return v;
    }

    void buildUi() {
        root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setGravity(Gravity.CENTER_HORIZONTAL);
        root.setPadding(28,48,28,28);
        root.setBackgroundColor(Color.rgb(6,17,31));
        root.addView(tv("SM PRODUCE LTD",24,true), new LinearLayout.LayoutParams(-1,-2));
        root.addView(tv("CASE SCANNER",16,true), new LinearLayout.LayoutParams(-1,-2));
        state = tv("READY TO SCAN",34,true);
        LinearLayout.LayoutParams p = new LinearLayout.LayoutParams(-1,-2); p.topMargin=90;
        root.addView(state,p);
        code=tv("",30,true); root.addView(code,new LinearLayout.LayoutParams(-1,-2));
        details=tv("",19,false); root.addView(details,new LinearLayout.LayoutParams(-1,-2));
        View sp=new View(this); root.addView(sp,new LinearLayout.LayoutParams(1,0,1));
        hint=tv("Use the TC26 scan trigger",16,false); root.addView(hint,new LinearLayout.LayoutParams(-1,-2));
        setContentView(root);
    }

    void sendDW(Bundle config) {
        Intent i = new Intent(DW_API_ACTION);
        i.setPackage("com.symbol.datawedge");
        i.putExtra(DW_SET_CONFIG, config);
        sendBroadcast(i);
    }

    void setupDW() {
        // 1) Create/enable profile and associate it to THIS installed package (debug/release safe)
        Bundle base = new Bundle();
        base.putString("PROFILE_NAME", PROFILE);
        base.putString("PROFILE_ENABLED", "true");
        base.putString("CONFIG_MODE", "CREATE_IF_NOT_EXIST");
        Bundle app = new Bundle();
        app.putString("PACKAGE_NAME", getPackageName());
        app.putStringArray("ACTIVITY_LIST", new String[]{"*"});
        base.putParcelableArray("APP_LIST", new Bundle[]{app});
        sendDW(base);

        // 2) Scanner input ON
        Bundle barcode = new Bundle();
        barcode.putString("PROFILE_NAME", PROFILE);
        barcode.putString("PROFILE_ENABLED", "true");
        barcode.putString("CONFIG_MODE", "UPDATE");
        Bundle bcPlugin = new Bundle();
        bcPlugin.putString("PLUGIN_NAME", "BARCODE");
        bcPlugin.putString("RESET_CONFIG", "true");
        Bundle bcParams = new Bundle();
        bcParams.putString("scanner_selection", "auto");
        bcParams.putString("scanner_input_enabled", "true");
        bcPlugin.putBundle("PARAM_LIST", bcParams);
        barcode.putBundle("PLUGIN_CONFIG", bcPlugin);
        sendDW(barcode);

        // 3) Intent output ON, broadcast delivery
        Bundle intentCfg = new Bundle();
        intentCfg.putString("PROFILE_NAME", PROFILE);
        intentCfg.putString("PROFILE_ENABLED", "true");
        intentCfg.putString("CONFIG_MODE", "UPDATE");
        Bundle intPlugin = new Bundle();
        intPlugin.putString("PLUGIN_NAME", "INTENT");
        intPlugin.putString("RESET_CONFIG", "true");
        Bundle intParams = new Bundle();
        intParams.putString("intent_output_enabled", "true");
        intParams.putString("intent_action", BuildConfig.DW_ACTION);
        intParams.putString("intent_category", Intent.CATEGORY_DEFAULT);
        intParams.putString("intent_delivery", "2");
        intPlugin.putBundle("PARAM_LIST", intParams);
        intentCfg.putBundle("PLUGIN_CONFIG", intPlugin);
        sendDW(intentCfg);

        // 4) Keystroke output OFF so scans go only to this app receiver
        Bundle keyCfg = new Bundle();
        keyCfg.putString("PROFILE_NAME", PROFILE);
        keyCfg.putString("PROFILE_ENABLED", "true");
        keyCfg.putString("CONFIG_MODE", "UPDATE");
        Bundle keyPlugin = new Bundle();
        keyPlugin.putString("PLUGIN_NAME", "KEYSTROKE");
        Bundle keyParams = new Bundle();
        keyParams.putString("keystroke_output_enabled", "false");
        keyPlugin.putBundle("PARAM_LIST", keyParams);
        keyCfg.putBundle("PLUGIN_CONFIG", keyPlugin);
        sendDW(keyCfg);
    }

    void scan(String raw) {
        String v=raw.trim().toUpperCase(Locale.ROOT);
        long n=System.currentTimeMillis();
        if(v.equals(last)&&n-lastAt<1200)return;
        last=v; lastAt=n;
        if(busy)return;
        if(!v.matches("^U\\d{7}$")) { result(v,"NOT A CASE BARCODE","Expected U + 7 digits",false); return; }
        busy=true;
        state.setText("SENDING..."); code.setText(v); details.setText(""); hint.setText("Checking production database");
        net.execute(()->{
            try {
                String id=Settings.Secure.getString(getContentResolver(),Settings.Secure.ANDROID_ID);
                String body="code="+URLEncoder.encode(v,"UTF-8")+"&device="+URLEncoder.encode("TC26-"+id,"UTF-8");
                HttpURLConnection c=(HttpURLConnection)new URL(BuildConfig.API_URL).openConnection();
                c.setRequestMethod("POST"); c.setConnectTimeout(5000); c.setReadTimeout(7000); c.setDoOutput(true);
                c.setRequestProperty("Content-Type","application/x-www-form-urlencoded; charset=UTF-8");
                c.setRequestProperty("Accept","application/json"); c.setRequestProperty("X-API-KEY",BuildConfig.API_KEY);
                try(OutputStream os=c.getOutputStream()){os.write(body.getBytes(StandardCharsets.UTF_8));}
                int h=c.getResponseCode();
                InputStream in=h<400?c.getInputStream():c.getErrorStream();
                BufferedReader r=new BufferedReader(new InputStreamReader(in,StandardCharsets.UTF_8));
                StringBuilder sb=new StringBuilder(); String l; while((l=r.readLine())!=null)sb.append(l);
                JSONObject j=new JSONObject(sb.toString()); ui.post(()->handle(v,h,j));
            } catch(Exception e) { ui.post(()->result(v,"CONNECTION ERROR",e.getMessage(),false)); }
        });
    }

    void handle(String v,int h,JSONObject j){
        if(h==401){result(v,"UNAUTHORIZED","API key rejected",false);return;}
        if(!j.optBoolean("ok",false)){result(v,"SERVER ERROR",j.optString("error",j.optString("reason","")),false);return;}
        if(j.optBoolean("accepted",false)){
            JSONObject c=j.optJSONObject("case"); String d="";
            if(c!=null){d=line(d,"SKU",c.optString("SKU"));d=line(d,"Grower",c.optString("grower"));d=line(d,"Variety",c.optString("variety"));d=line(d,"Size",c.optString("size"));d=line(d,"Packaging",c.optString("packaging"));}
            result(v,"✓ ACCEPTED",d,true);return;
        }
        if(j.optBoolean("duplicate",false)){result(v,"ALREADY SCANNED","This case is already in production",false);return;}
        if("unknown_case".equals(j.optString("reason"))){result(v,"UNKNOWN CASE","Serial not found in casecodes",false);return;}
        result(v,"NOT ACCEPTED",j.optString("reason","Scan rejected"),false);
    }

    String line(String d,String k,String v){if(v==null||v.trim().isEmpty()||"null".equalsIgnoreCase(v))return d;return d+(d.isEmpty()?"":"\n")+k+": "+v;}

    void result(String v,String title,String msg,boolean ok){
        busy=false; root.setBackgroundColor(ok?Color.rgb(20,116,71):Color.rgb(174,38,38));
        state.setText(title); code.setText(v); details.setText(msg==null?"":msg); hint.setText("Ready for next case");
        try{ToneGenerator t=new ToneGenerator(AudioManager.STREAM_NOTIFICATION,85);t.startTone(ok?ToneGenerator.TONE_PROP_BEEP:ToneGenerator.TONE_PROP_NACK,180);ui.postDelayed(t::release,350);}catch(Exception ignored){}
        ui.postDelayed(this::ready,ok?1400:2200);
    }

    void ready(){busy=false;root.setBackgroundColor(Color.rgb(6,17,31));state.setText("READY TO SCAN");code.setText("");details.setText("");hint.setText("Use the TC26 scan trigger");}
}
