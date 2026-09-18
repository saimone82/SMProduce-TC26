package com.smproduce.veggie.shipping;

import android.app.Activity;
import android.os.Bundle;
import android.webkit.CookieManager;
import android.webkit.WebChromeClient;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.graphics.Color;
import android.view.View;
import android.view.WindowManager;
import android.widget.FrameLayout;
import android.widget.ProgressBar;
import android.widget.Toast;

import org.json.JSONObject;

public class MainActivity extends Activity {
    private static final String APP_URL = "https://smproduceprod.uk/cherry/veggie/tc26_shipping.php";
    private static final String DW_ACTION = "com.smproduce.VEGGIE_TC26.SCAN";
    private WebView webView;
    private ProgressBar progress;

    private final BroadcastReceiver scanReceiver = new BroadcastReceiver() {
        @Override public void onReceive(Context context, Intent intent) {
            if (!DW_ACTION.equals(intent.getAction())) return;
            String data = intent.getStringExtra("com.symbol.datawedge.data_string");
            if (data == null || data.trim().isEmpty()) return;
            final String code = data.trim().toUpperCase();
            if (webView != null) {
                String js = "window.veggieTc26Scan && window.veggieTc26Scan(" + JSONObject.quote(code) + ");";
                webView.evaluateJavascript(js, null);
            }
        }
    };

    @Override protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.WHITE);

        webView = new WebView(this);
        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        progress.setMax(100);

        root.addView(webView, new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT,
            FrameLayout.LayoutParams.MATCH_PARENT
        ));

        FrameLayout.LayoutParams pp = new FrameLayout.LayoutParams(
            FrameLayout.LayoutParams.MATCH_PARENT, 6
        );
        root.addView(progress, pp);
        setContentView(root);

        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        s.setBuiltInZoomControls(false);
        s.setDisplayZoomControls(false);
        s.setSupportZoom(false);
        s.setUserAgentString(s.getUserAgentString() + " SMProduce-Veggie-TC26/1.0");

        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, false);

        webView.setWebViewClient(new WebViewClient() {
            @Override public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                progress.setVisibility(View.GONE);
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override public void onProgressChanged(WebView view, int newProgress) {
                progress.setProgress(newProgress);
                progress.setVisibility(newProgress >= 100 ? View.GONE : View.VISIBLE);
            }
        });

        configureDataWedge();
        webView.loadUrl(APP_URL);
    }

    @Override protected void onResume() {
        super.onResume();
        IntentFilter filter = new IntentFilter(DW_ACTION);
        filter.addCategory(Intent.CATEGORY_DEFAULT);
        if (android.os.Build.VERSION.SDK_INT >= 33) {
            registerReceiver(scanReceiver, filter, Context.RECEIVER_EXPORTED);
        } else {
            registerReceiver(scanReceiver, filter);
        }
        configureDataWedge();
    }

    @Override protected void onPause() {
        try { unregisterReceiver(scanReceiver); } catch (Exception ignored) {}
        super.onPause();
    }

    @Override public void onBackPressed() {
        if (webView != null && webView.canGoBack()) webView.goBack();
        else super.onBackPressed();
    }

    private void configureDataWedge() {
        try {
            final String profile = "SMProduce_Veggie_TC26";

            Bundle app = new Bundle();
            app.putString("PACKAGE_NAME", getPackageName());
            app.putStringArray("ACTIVITY_LIST", new String[]{"*"});

            Bundle base = new Bundle();
            base.putString("PROFILE_NAME", profile);
            base.putString("PROFILE_ENABLED", "true");
            base.putString("CONFIG_MODE", "CREATE_IF_NOT_EXIST");
            base.putParcelableArray("APP_LIST", new Bundle[]{app});
            sendDataWedge(base);

            Bundle barcodeParams = new Bundle();
            barcodeParams.putString("scanner_input_enabled", "true");
            barcodeParams.putString("scanner_selection", "auto");
            barcodeParams.putString("decoder_code128", "true");

            Bundle barcode = new Bundle();
            barcode.putString("PLUGIN_NAME", "BARCODE");
            barcode.putString("RESET_CONFIG", "true");
            barcode.putBundle("PARAM_LIST", barcodeParams);

            Bundle barcodeCfg = new Bundle();
            barcodeCfg.putString("PROFILE_NAME", profile);
            barcodeCfg.putString("PROFILE_ENABLED", "true");
            barcodeCfg.putString("CONFIG_MODE", "UPDATE");
            barcodeCfg.putBundle("PLUGIN_CONFIG", barcode);
            sendDataWedge(barcodeCfg);

            Bundle intentParams = new Bundle();
            intentParams.putString("intent_output_enabled", "true");
            intentParams.putString("intent_action", DW_ACTION);
            intentParams.putString("intent_category", Intent.CATEGORY_DEFAULT);
            intentParams.putString("intent_delivery", "2");

            Bundle intentPlugin = new Bundle();
            intentPlugin.putString("PLUGIN_NAME", "INTENT");
            intentPlugin.putString("RESET_CONFIG", "true");
            intentPlugin.putBundle("PARAM_LIST", intentParams);

            Bundle intentCfg = new Bundle();
            intentCfg.putString("PROFILE_NAME", profile);
            intentCfg.putString("PROFILE_ENABLED", "true");
            intentCfg.putString("CONFIG_MODE", "UPDATE");
            intentCfg.putBundle("PLUGIN_CONFIG", intentPlugin);
            sendDataWedge(intentCfg);

            Bundle keyParams = new Bundle();
            keyParams.putString("keystroke_output_enabled", "false");
            Bundle keyPlugin = new Bundle();
            keyPlugin.putString("PLUGIN_NAME", "KEYSTROKE");
            keyPlugin.putString("RESET_CONFIG", "true");
            keyPlugin.putBundle("PARAM_LIST", keyParams);

            Bundle keyCfg = new Bundle();
            keyCfg.putString("PROFILE_NAME", profile);
            keyCfg.putString("PROFILE_ENABLED", "true");
            keyCfg.putString("CONFIG_MODE", "UPDATE");
            keyCfg.putBundle("PLUGIN_CONFIG", keyPlugin);
            sendDataWedge(keyCfg);
        } catch (Exception e) {
            Toast.makeText(this, "DataWedge profile could not be configured automatically.", Toast.LENGTH_LONG).show();
        }
    }

    private void sendDataWedge(Bundle config) {
        Intent i = new Intent("com.symbol.datawedge.api.ACTION");
        i.setPackage("com.symbol.datawedge");
        i.putExtra("com.symbol.datawedge.api.SET_CONFIG", config);
        sendBroadcast(i);
    }
}
