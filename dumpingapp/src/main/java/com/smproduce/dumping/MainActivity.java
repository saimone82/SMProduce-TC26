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
    private LinearLayout root, history;
    private EditText scan;
    private TextView status, subtitle, total;
    private Button language, settings;
    private boolean italian;
    private int dumpedCount = 0;
    private final ToneGenerator tone = new ToneGenerator(AudioManager.STREAM_NOTIFICATION, 90);
    private android.content.SharedPreferences prefs;

    @Override public void onCreate(Bundle b) {
        super.onCreate(b);
        prefs = getSharedPreferences("dumping", MODE_PRIVATE);
        italian = "it".equals(prefs.getString("language", "en"));
        buildUi();
        if (prefs.getString("server", "").isEmpty()) showSettings(); else focusScanner();
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
        language = new Button(this); language.setOnClickListener(v -> { italian=!italian; prefs.edit().putString("language",italian?"it":"en").apply(); buildUi(); focusScanner(); });
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
        language.setText(italian?"EN":"IT");
        subtitle.setText(italian?"Scansiona un bin per segnarlo come rovesciato":"Scan a bin to mark it as dumped");
        status.setText(italian?"PRONTO ALLA SCANSIONE":"READY TO SCAN");
        total.setText((italian?"Rovesciati in questa sessione: ":"Dumped this session: ")+dumpedCount);
        TextView h=findViewById(1001); if(h!=null) h.setText(italian?"Ultime scansioni":"Recent scans");
    }

    private void submit(){
        String code=scan.getText().toString().trim().toUpperCase(Locale.ROOT);
        if(code.isEmpty()){ focusScanner(); return; }
        scan.setEnabled(false); status.setText(italian?"CONTROLLO…":"CHECKING…"); status.setTextColor(Color.rgb(146,64,14));
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
        } catch(Exception e){ runOnUiThread(() -> result(false,code,italian?"Connessione al server non riuscita":"Server connection failed",null)); }
    }

    private void result(boolean ok,String barcode,String message,JSONObject data){
        scan.setEnabled(true); scan.setText("");
        if(ok){
            dumpedCount++; tone.startTone(ToneGenerator.TONE_PROP_ACK,180); status.setText("✓ "+barcode+"  "+(italian?"ROVESCIATO":"DUMPED")); status.setTextColor(Color.rgb(21,128,61));
            String detail=barcode;
            if(data!=null){ String grower=data.optString("grower",""); String variety=data.optString("variety",""); String lot=data.optString("lot",""); detail += "\n"+grower+(variety.isEmpty()?"":" · "+variety)+(lot.isEmpty()?"":" · Lot "+lot); }
            TextView row=text("✓ "+detail,17,Color.rgb(21,128,61)); history.addView(row,0);
        } else { tone.startTone(ToneGenerator.TONE_PROP_NACK,350); status.setText("✕ "+message); status.setTextColor(Color.rgb(185,28,28)); TextView row=text("✕ "+barcode+" — "+message,16,Color.rgb(185,28,28)); history.addView(row,0); }
        total.setText((italian?"Rovesciati in questa sessione: ":"Dumped this session: ")+dumpedCount); focusScanner();
    }

    private void focusScanner(){ scan.requestFocus(); scan.setSelection(scan.length()); }

    private void showSettings(){
        LinearLayout box=new LinearLayout(this); box.setPadding(30,5,30,5); box.setOrientation(LinearLayout.VERTICAL);
        EditText server=new EditText(this); server.setHint("http://192.168.1.10/smproduce/"); server.setText(prefs.getString("server","")); box.addView(server);
        EditText key=new EditText(this); key.setHint("API key"); key.setText(prefs.getString("key","SM-DUMPING-2026")); box.addView(key);
        new AlertDialog.Builder(this).setTitle(italian?"Impostazioni server":"Server settings").setView(box)
            .setPositiveButton(italian?"Salva":"Save",(d,w)->{ String s=server.getText().toString().trim(); if(!s.isEmpty()&&!s.startsWith("http"))s="http://"+s; prefs.edit().putString("server",s).putString("key",key.getText().toString()).apply(); focusScanner(); })
            .setNegativeButton(italian?"Annulla":"Cancel",null).show();
    }
}
