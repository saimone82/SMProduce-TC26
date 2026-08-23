package com.smproduce.shipping;

import android.app.Activity;
import android.content.*;
import android.os.*;
import android.webkit.*;
import org.json.JSONObject;

public class MainActivity extends Activity {
    private static final String PROFILE = "SM_PRODUCE_SHIPPING";
    private static final String DATA = "com.symbol.datawedge.data_string";
    private WebView web;

    private final BroadcastReceiver rx = new BroadcastReceiver() {
        @Override public void onReceive(Context context, Intent intent) {
            if (!BuildConfig.DW_ACTION.equals(intent.getAction())) return;
            String code = intent.getStringExtra(DATA);
            if (code == null || code.trim().isEmpty()) return;
            final String js = "if(typeof onDataWedgeScan==='function'){onDataWedgeScan(" + JSONObject.quote(code.trim()) + ");}else{window.__lastDataWedgeScan=" + JSONObject.quote(code.trim()) + ";}";
            web.post(() -> web.evaluateJavascript(js, null));
        }
    };

    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        web = new WebView(this);
        web.setBackgroundColor(0xff06111f);
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        s.setUserAgentString(s.getUserAgentString() + " SMProduceTC26-Shipping/1.0");
        web.setWebViewClient(new WebViewClient() {
            @Override public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                view.evaluateJavascript("if(window.__lastDataWedgeScan&&typeof onDataWedgeScan==='function'){var x=window.__lastDataWedgeScan;window.__lastDataWedgeScan=null;onDataWedgeScan(x);}", null);
            }
        });
        web.setWebChromeClient(new WebChromeClient());
        setContentView(web);
        setupDataWedge();
        registerReceiver(rx, new IntentFilter(BuildConfig.DW_ACTION), Context.RECEIVER_EXPORTED);
        if (state == null) web.loadUrl(BuildConfig.PAGE_URL); else web.restoreState(state);
    }

    @Override protected void onResume() {
        super.onResume();
        setupDataWedge();
        if (web != null) web.onResume();
    }

    @Override protected void onPause() {
        if (web != null) web.onPause();
        super.onPause();
    }

    @Override protected void onSaveInstanceState(Bundle outState) {
        if (web != null) web.saveState(outState);
        super.onSaveInstanceState(outState);
    }

    @Override public void onBackPressed() {
        if (web != null && web.canGoBack()) web.goBack(); else super.onBackPressed();
    }

    @Override protected void onDestroy() {
        try { unregisterReceiver(rx); } catch (Exception ignored) {}
        if (web != null) web.destroy();
        super.onDestroy();
    }

    private void sendSetConfig(Bundle profile) {
        Intent i = new Intent("com.symbol.datawedge.api.ACTION");
        i.setPackage("com.symbol.datawedge");
        i.putExtra("com.symbol.datawedge.api.SET_CONFIG", profile);
        sendBroadcast(i);
    }

    private Bundle baseProfile(String mode) {
        Bundle p = new Bundle();
        p.putString("PROFILE_NAME", PROFILE);
        p.putString("PROFILE_ENABLED", "true");
        p.putString("CONFIG_MODE", mode);
        return p;
    }

    private void setupDataWedge() {
        sendSetConfig(baseProfile("CREATE_IF_NOT_EXIST"));

        Bundle assoc = baseProfile("UPDATE");
        Bundle app = new Bundle();
        app.putString("PACKAGE_NAME", getPackageName());
        app.putStringArray("ACTIVITY_LIST", new String[]{"*"});
        assoc.putParcelableArray("APP_LIST", new Bundle[]{app});
        sendSetConfig(assoc);

        Bundle barcode = baseProfile("UPDATE");
        Bundle b = new Bundle();
        b.putString("PLUGIN_NAME", "BARCODE");
        b.putString("RESET_CONFIG", "true");
        Bundle bp = new Bundle();
        bp.putString("scanner_selection", "auto");
        bp.putString("scanner_input_enabled", "true");
        b.putBundle("PARAM_LIST", bp);
        barcode.putBundle("PLUGIN_CONFIG", b);
        sendSetConfig(barcode);

        Bundle intentCfg = baseProfile("UPDATE");
        Bundle o = new Bundle();
        o.putString("PLUGIN_NAME", "INTENT");
        o.putString("RESET_CONFIG", "true");
        Bundle op = new Bundle();
        op.putString("intent_output_enabled", "true");
        op.putString("intent_action", BuildConfig.DW_ACTION);
        op.putString("intent_category", "android.intent.category.DEFAULT");
        op.putInt("intent_delivery", 2);
        o.putBundle("PARAM_LIST", op);
        intentCfg.putBundle("PLUGIN_CONFIG", o);
        sendSetConfig(intentCfg);

        Bundle keyCfg = baseProfile("UPDATE");
        Bundle k = new Bundle();
        k.putString("PLUGIN_NAME", "KEYSTROKE");
        Bundle kp = new Bundle();
        kp.putString("keystroke_output_enabled", "false");
        k.putBundle("PARAM_LIST", kp);
        keyCfg.putBundle("PLUGIN_CONFIG", k);
        sendSetConfig(keyCfg);
    }
}
