package com.smproduce.dumping;

import android.app.*;
import android.os.*;
import android.graphics.Color;
import android.graphics.Typeface;
import android.media.AudioManager;
import android.media.ToneGenerator;
import android.content.*;
import android.view.*;
import android.view.inputmethod.EditorInfo;
import android.widget.*;
import org.json.JSONObject;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.util.*;

public class MainActivity extends Activity {
    private static final String PROFILE = "SM_PRODUCE_DUMPING";
    private static final String DW_API_ACTION = "com.symbol.datawedge.api.ACTION";
    private static final String DW_SET_CONFIG = "com.symbol.datawedge.api.SET_CONFIG";
    private static final String DW_DATA = "com.symbol.datawedge.data_string";
    private LinearLayout root, history;
    private EditText scan;
    private TextView status, subtitle, total;
    private Button language, settings;
    private boolean spanish;
    private int dumpedCount = 0;
    private final ToneGenerator tone = new ToneGenerator(AudioManager.STREAM_NOTIFICATION, 90);
    private android.content.SharedPreferences prefs;
    private final BroadcastReceiver scanReceiver = new BroadcastReceiver() {
        @Override public void onReceive(Context context, Intent intent) {
            if (!BuildConfig.DW_ACTION.equals(intent.getAction())) return;
            String value = intent.getStringExtra(DW_DATA);
            if (value == null || value.trim().isEmpty() || !scan.isEnabled()) return;
            scan.setText(value.trim());
            submit();
        }
    };

    @Override public void onCreate(Bundle b) {
        super.onCreate(b);
        prefs = getSharedPreferences("dumping", MODE_PRIVATE);
        spanish = "es".equals(prefs.getString("language", "en"));
        buildUi();
        registerDataWedgeReceiver();
        new Handler(Looper.getMainLooper()).postDelayed(this::setupDataWedge, 500);
        if (prefs.getString("server", "").isEmpty()) showSettings(); else focusScanner();
    }

    @Override protected void onResume() {
        super.onResume();
        new Handler(Looper.getMainLooper()).postDelayed(this::setupDataWedge, 300);
    }

    @Override protected void onDestroy() {
        try { unregisterReceiver(scanReceiver); } catch (Exception ignored) {}
        tone.release();
        super.onDestroy();
    }

    private void registerDataWedgeReceiver() {
        IntentFilter filter = new IntentFilter();
        filter.addAction(BuildConfig.DW_ACTION);
        filter.addCategory(Intent.CATEGORY_DEFAULT);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(scanReceiver, filter, Context.RECEIVER_EXPORTED);
        else registerReceiver(scanReceiver, filter);
    }

    private void sendDataWedgeConfig(Bundle config) {
        Intent intent = new Intent(DW_API_ACTION);
        intent.setPackage("com.symbol.datawedge");
        intent.putExtra(DW_SET_CONFIG, config);
        sendBroadcast(intent);
    }

    private Bundle dataWedgeProfile(String mode) {
        Bundle profile = new Bundle();
        profile.putString("PROFILE_NAME", PROFILE);
        profile.putString("PROFILE_ENABLED", "true");
        profile.putString("CONFIG_MODE", mode);
        return profile;
    }

    private void setupDataWedge() {
        try {
            Bundle create = dataWedgeProfile("CREATE_IF_NOT_EXIST");
            Bundle app = new Bundle();
            app.putString("PACKAGE_NAME", getPackageName());
            app.putStringArray("ACTIVITY_LIST", new String[]{"*"});
            create.putParcelableArray("APP_LIST", new Bundle[]{app});
            sendDataWedgeConfig(create);

            Bundle barcode = dataWedgeProfile("UPDATE");
            Bundle barcodePlugin = new Bundle();
            barcodePlugin.putString("PLUGIN_NAME", "BARCODE");
            barcodePlugin.putString("RESET_CONFIG", "true");
            Bundle barcodeParams = new Bundle();
            barcodeParams.putString("scanner_selection", "auto");
            barcodeParams.putString("scanner_input_enabled", "true");
            barcodePlugin.putBundle("PARAM_LIST", barcodeParams);
            barcode.putBundle("PLUGIN_CONFIG", barcodePlugin);
            sendDataWedgeConfig(barcode);

            Bundle output = dataWedgeProfile("UPDATE");
            Bundle intentPlugin = new Bundle();
            intentPlugin.putString("PLUGIN_NAME", "INTENT");
            intentPlugin.putString("RESET_CONFIG", "true");
            Bundle intentParams = new Bundle();
            intentParams.putString("intent_output_enabled", "true");
            intentParams.putString("intent_action", BuildConfig.DW_ACTION);
            intentParams.putString("intent_category", Intent.CATEGORY_DEFAULT);
            intentParams.putString("intent_delivery", "2");
            intentPlugin.putBundle("PARAM_LIST", intentParams);
            output.putBundle("PLUGIN_CONFIG", intentPlugin);
            sendDataWedgeConfig(output);

            Bundle keystroke = dataWedgeProfile("UPDATE");
            Bundle keyPlugin = new Bundle();
            keyPlugin.putString("PLUGIN_NAME", "KEYSTROKE");
            Bundle keyParams = new Bundle();
            keyParams.putString("keystroke_output_enabled", "false");
            keyPlugin.putBundle("PARAM_LIST", keyParams);
            keystroke.putBundle("PLUGIN_CONFIG", keyPlugin);
            sendDataWedgeConfig(keystroke);
        } catch (Exception e) {
            Toast.makeText(this, spanish ? "Error al configurar DataWedge" : "DataWedge setup error", Toast.LENGTH_LONG).show();
        }
    }

    private TextView text(String value, int size, int color) {
        TextView v = new TextView(this); v.setText(value); v.setTextSize(size); v.setTextColor(color);
        v.setPadding(12,10,12,10); return v;
    }

    private void buildUi() {
        ScrollView scroll = new ScrollView(this);
        root = new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL); root.setPadding(24,24,24,24); root.setBackgroundColor(Color.rgb(249,250,251));
        scroll.addView(root); setContentView(scroll);

        LinearLayout bar = new LinearLayout(this); bar.setGravity(Gravity.CENTER_VERTICAL);
        TextView title = text("DUMPING", 28, Color.rgb(17,24,39)); title.setTypeface(null, Typeface.BOLD);
        bar.addView(title, new LinearLayout.LayoutParams(0,-2,1));
        language = new Button(this); language.setOnClickListener(v -> { spanish=!spanish; prefs.edit().putString("language",spanish?"es":"en").apply(); buildUi(); focusScanner(); });
        settings = new Button(this); settings.setText("⚙"); settings.setOnClickListener(v -> showSettings());
        bar.addView(language); bar.addView(settings); root.addView(bar);

        subtitle = text("",18,Color.DKGRAY); root.addView(subtitle);
        scan = new EditText(this); scan.setTextSize(25); scan.setSingleLine(true); scan.setHint("FBIN-000123"); scan.setImeOptions(EditorInfo.IME_ACTION_DONE); scan.setInputType(1); scan.setSelectAllOnFocus(true);
        scan.setOnEditorActionListener((v,id,event) -> { if(id==EditorInfo.IME_ACTION_DONE || (event!=null&&event.getKeyCode()==KeyEvent.KEYCODE_ENTER)){ submit(); return true;} return false; });
        root.addView(scan, new LinearLayout.LayoutParams(-1,90));
        Button dump = new Button(this); dump.setText("SCAN / DUMP"); dump.setTextSize(19); dump.setOnClickListener(v -> submit()); root.addView(dump,new LinearLayout.LayoutParams(-1,80));
        status = text("",20,Color.DKGRAY); status.setGravity(Gravity.CENTER); status.setTypeface(null,Typeface.BOLD); root.addView(status,new LinearLayout.LayoutParams(-1,90));
        total = text("",18,Color.rgb(21,128,61)); total.setGravity(Gravity.CENTER); root.addView(total);
        TextView h = text("",19,Color.rgb(17,24,39)); h.setId(1001); h.setTypeface(null,Typeface.BOLD); root.addView(h);
        history = new LinearLayout(this); history.setOrientation(LinearLayout.VERTICAL); root.addView(history);
        refreshLabels();
    }

    private void refreshLabels(){
        language.setText(spanish?"EN":"ES");
        subtitle.setText(spanish?"Escanee un bin para marcarlo como volcado":"Scan a bin to mark it as dumped");
        status.setText(spanish?"LISTO PARA ESCANEAR":"READY TO SCAN");
        total.setText((spanish?"Volcados en esta sesión: ":"Dumped this session: ")+dumpedCount);
        TextView h=findViewById(1001); if(h!=null) h.setText(spanish?"Escaneos recientes":"Recent scans");
    }

    private void submit(){
        String code=scan.getText().toString().trim().toUpperCase(Locale.ROOT);
        if(code.isEmpty()){ focusScanner(); return; }
        scan.setEnabled(false); status.setText(spanish?"COMPROBANDO…":"CHECKING…"); status.setTextColor(Color.rgb(146,64,14));
        new Thread(() -> callApi(code)).start();
    }

    private void callApi(String code){
        try {
            String base=prefs.getString("server","").trim();
            if(!base.endsWith("/")) base+="/";
            URL url=new URL(base+"api/dumping_scan.php");
            HttpURLConnection c=(HttpURLConnection)url.openConnection(); c.setRequestMethod("POST"); c.setConnectTimeout(7000); c.setReadTimeout(10000); c.setDoOutput(true);
            c.setRequestProperty("Content-Type","application/x-www-form-urlencoded; charset=UTF-8");
            String body="code="+URLEncoder.encode(code,"UTF-8")+"&api_key="+URLEncoder.encode(prefs.getString("key","SM-DUMPING-2026"),"UTF-8");
            try(OutputStream os=c.getOutputStream()){os.write(body.getBytes(StandardCharsets.UTF_8));}
            InputStream is=c.getResponseCode()<400?c.getInputStream():c.getErrorStream();
            BufferedReader br=new BufferedReader(new InputStreamReader(is,StandardCharsets.UTF_8)); StringBuilder sb=new StringBuilder(); String line; while((line=br.readLine())!=null)sb.append(line);
            JSONObject j=new JSONObject(sb.toString()); boolean ok=j.optBoolean("ok",false);
            runOnUiThread(() -> result(ok,j.optString("barcode",code),j.optString("message",j.optString("error","Error")),j));
        } catch(Exception e){ runOnUiThread(() -> result(false,code,spanish?"No se pudo conectar con el servidor":"Server connection failed",null)); }
    }

    private void result(boolean ok,String barcode,String message,JSONObject data){
        scan.setEnabled(true); scan.setText("");
        if(ok){
            dumpedCount++; tone.startTone(ToneGenerator.TONE_PROP_ACK,180); status.setText("✓ "+barcode+"  "+(spanish?"VOLCADO":"DUMPED")); status.setTextColor(Color.rgb(21,128,61));
            String detail=barcode;
            if(data!=null){ String grower=data.optString("grower",""); String variety=data.optString("variety",""); String lot=data.optString("lot",""); detail += "\n"+grower+(variety.isEmpty()?"":" · "+variety)+(lot.isEmpty()?"":" · Lot "+lot); }
            TextView row=text("✓ "+detail,17,Color.rgb(21,128,61)); history.addView(row,0);
        } else { tone.startTone(ToneGenerator.TONE_PROP_NACK,350); status.setText("✕ "+message); status.setTextColor(Color.rgb(185,28,28)); TextView row=text("✕ "+barcode+" — "+message,16,Color.rgb(185,28,28)); history.addView(row,0); }
        total.setText((spanish?"Volcados en esta sesión: ":"Dumped this session: ")+dumpedCount); focusScanner();
    }

    private void focusScanner(){ scan.requestFocus(); scan.setSelection(scan.length()); }

    private void showSettings(){
        LinearLayout box=new LinearLayout(this); box.setPadding(30,5,30,5); box.setOrientation(LinearLayout.VERTICAL);
        EditText server=new EditText(this); server.setHint("http://192.168.1.10/smproduce/"); server.setText(prefs.getString("server","")); box.addView(server);
        EditText key=new EditText(this); key.setHint("API key"); key.setText(prefs.getString("key","SM-DUMPING-2026")); box.addView(key);
        new AlertDialog.Builder(this).setTitle(spanish?"Configuración del servidor":"Server settings").setView(box)
            .setPositiveButton(spanish?"Guardar":"Save",(d,w)->{ String s=server.getText().toString().trim(); if(!s.isEmpty()&&!s.startsWith("http"))s="http://"+s; prefs.edit().putString("server",s).putString("key",key.getText().toString()).apply(); focusScanner(); })
            .setNegativeButton(spanish?"Cancelar":"Cancel",null).show();
    }
}
