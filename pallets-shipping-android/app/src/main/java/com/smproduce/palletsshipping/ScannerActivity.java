package com.smproduce.palletsshipping;

import android.app.*;
import android.content.*;
import android.graphics.Color;
import android.os.*;
import android.text.InputType;
import android.view.*;
import android.widget.*;
import org.json.*;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;
import java.util.*;
import java.util.concurrent.*;

/** Full TC26/DataWedge read-only scanner: CASE→PALLET and PALLET→CASES. */
public final class ScannerActivity extends Activity {
    private static final String ACTION="com.smproduce.TC26_SCANNER.SCAN";
    private enum Mode { CASE, PALLET }
    private boolean spanish=false, busy=false, foreground=false;
    private Mode mode=Mode.CASE;
    private String password="";
    private LinearLayout root,body,historyBox;
    private TextView status,title;
    private EditText input;
    private Button caseMode,palletMode,lang,clear;
    private final ArrayList<String> caseHistory=new ArrayList<>(), palletHistory=new ArrayList<>();
    private final ExecutorService io=Executors.newSingleThreadExecutor();
    private BroadcastReceiver receiver;

    private String tr(String en,String es){return spanish?es:en;}
    private int dp(int n){return Math.round(n*getResources().getDisplayMetrics().density);}
    private TextView tv(String s,int size,int color){TextView v=new TextView(this);v.setText(s);v.setTextSize(size);v.setTextColor(color);v.setPadding(0,dp(4),0,dp(4));return v;}
    private Button btn(String s,int color){Button b=new Button(this);b.setText(s);b.setTextColor(Color.WHITE);b.setTextSize(14);b.setAllCaps(false);b.setBackgroundColor(color);return b;}

    @Override public void onCreate(Bundle state){
        super.onCreate(state);password=getIntent().getStringExtra("scanner_password");if(password==null||password.trim().isEmpty()){finish();return;}build();register();dataWedge();render();
    }
    private void build(){
        root=new LinearLayout(this);root.setOrientation(LinearLayout.VERTICAL);root.setBackgroundColor(Color.rgb(11,22,34));
        LinearLayout header=new LinearLayout(this);header.setGravity(Gravity.CENTER_VERTICAL);header.setPadding(dp(10),dp(8),dp(10),dp(8));header.setBackgroundColor(Color.rgb(19,32,51));
        Button back=btn("‹",Color.TRANSPARENT);back.setTextSize(30);back.setOnClickListener(v->finish());header.addView(back,new LinearLayout.LayoutParams(dp(48),dp(48)));
        title=tv("Scanner",20,Color.WHITE);title.setTypeface(null,1);title.setGravity(Gravity.CENTER);header.addView(title,new LinearLayout.LayoutParams(0,dp(48),1));
        lang=btn("EN",Color.rgb(25,118,210));lang.setOnClickListener(v->{spanish=!spanish;lang.setText(spanish?"ES":"EN");render();});header.addView(lang,new LinearLayout.LayoutParams(dp(56),dp(44)));root.addView(header);
        body=new LinearLayout(this);body.setOrientation(LinearLayout.VERTICAL);body.setPadding(dp(14),dp(12),dp(14),dp(12));
        ScrollView scroll=new ScrollView(this);scroll.addView(body,new ScrollView.LayoutParams(-1,-2));root.addView(scroll,new LinearLayout.LayoutParams(-1,0,1));setContentView(root);
    }
    private void render(){
        body.removeAllViews();
        TextView info=tv(tr("Read-only scanner · database is never modified","Escáner de solo lectura · la base de datos nunca se modifica"),13,Color.rgb(148,163,184));info.setGravity(Gravity.CENTER);body.addView(info);
        LinearLayout modes=new LinearLayout(this);modes.setOrientation(LinearLayout.HORIZONTAL);caseMode=btn(tr("CASE → PALLET","CAJA → PALLET"),mode==Mode.CASE?Color.rgb(25,118,210):Color.rgb(52,65,85));palletMode=btn(tr("PALLET → CASES","PALLET → CAJAS"),mode==Mode.PALLET?Color.rgb(25,118,210):Color.rgb(52,65,85));caseMode.setOnClickListener(v->{mode=Mode.CASE;render();});palletMode.setOnClickListener(v->{mode=Mode.PALLET;render();});modes.addView(caseMode,new LinearLayout.LayoutParams(0,dp(54),1));modes.addView(palletMode,new LinearLayout.LayoutParams(0,dp(54),1));body.addView(modes);
        Space sp=new Space(this);body.addView(sp,new LinearLayout.LayoutParams(1,dp(12)));
        input=new EditText(this);input.setTextColor(Color.WHITE);input.setHintTextColor(Color.GRAY);input.setSingleLine();input.setInputType(InputType.TYPE_CLASS_TEXT);input.setHint(mode==Mode.CASE?tr("Case U...","Caja U..."):tr("Pallet P...","Pallet P..."));body.addView(input,new LinearLayout.LayoutParams(-1,dp(56)));
        Button use=btn(tr("USE","USAR"),Color.rgb(25,118,210));use.setOnClickListener(v->scan(input.getText().toString()));body.addView(use,new LinearLayout.LayoutParams(-1,dp(52)));
        status=tv(busy?tr("Checking…","Comprobando…"):tr("Scanner ready","Escáner listo"),15,busy?Color.rgb(251,191,36):Color.rgb(134,239,172));status.setGravity(Gravity.CENTER);status.setTypeface(null,1);body.addView(status);
        clear=btn(tr("CLEAR","BORRAR"),Color.rgb(185,28,28));clear.setOnClickListener(v->{activeHistory().clear();render();});body.addView(clear,new LinearLayout.LayoutParams(-1,dp(48)));
        TextView h=tv(tr("HISTORY","HISTORIAL")+" ("+activeHistory().size()+")",13,Color.LTGRAY);h.setTypeface(null,1);body.addView(h);
        historyBox=new LinearLayout(this);historyBox.setOrientation(LinearLayout.VERTICAL);historyBox.setPadding(dp(12),dp(8),dp(12),dp(8));historyBox.setBackgroundColor(Color.rgb(19,32,51));
        if(activeHistory().isEmpty())historyBox.addView(tv(tr("No scans yet","Sin lecturas"),14,Color.LTGRAY));else for(int i=activeHistory().size()-1;i>=0;i--){TextView row=tv(activeHistory().get(i),14,Color.WHITE);row.setTextIsSelectable(true);historyBox.addView(row);}
        body.addView(historyBox,new LinearLayout.LayoutParams(-1,-2));
    }
    private ArrayList<String> activeHistory(){return mode==Mode.CASE?caseHistory:palletHistory;}
    private String normalize(String raw){if(raw==null)return"";String s=raw.trim().toUpperCase(Locale.ROOT).replaceAll("[\\x00-\\x20\\x7F]+","");if(s.length()%2==0&&s.length()>0){int h=s.length()/2;if(s.substring(0,h).equals(s.substring(h)))s=s.substring(0,h);}return s;}
    private void scan(String raw){
        if(busy||!foreground)return;String code=normalize(raw);if(code.isEmpty())return;
        if(mode==Mode.CASE&&!code.startsWith("U")){showError(tr("Scan a case code beginning with U","Escanee un código de caja que empiece por U"));return;}
        if(mode==Mode.PALLET&&!code.startsWith("P")){showError(tr("Scan a pallet code beginning with P","Escanee un código de pallet que empiece por P"));return;}
        busy=true;render();final Mode sentMode=mode;final String sentCode=code;io.execute(()->{try{JSONObject j=request(sentMode,sentCode);runOnUiThread(()->{busy=false;handle(sentMode,sentCode,j);});}catch(Exception e){String msg=e.getMessage()==null?"Connection error":e.getMessage();runOnUiThread(()->{busy=false;showError(msg);render();});}});
    }
    private void handle(Mode sentMode,String code,JSONObject j){
        if(sentMode==Mode.CASE){String pid=j.optString("pallet_id","");boolean found=j.optInt("found",pid.isEmpty()?0:1)==1&&!pid.isEmpty();caseHistory.add(code+"  →  "+(found?pid:tr("NOT ON A PALLET","NON SU UN PALLET")));}
        else {boolean found=j.optInt("found",0)==1;StringBuilder s=new StringBuilder();s.append(code);if(!found)s.append("  →  ").append(tr("PALLET NOT FOUND","PALLET NON TROVATO"));else{JSONArray a=j.optJSONArray("cases");int count=j.optInt("case_count",a==null?0:a.length());s.append(" · ").append(count).append(" ").append(tr("cases","cajas"));if(a!=null)for(int i=0;i<a.length();i++)s.append("\n   • ").append(a.optString(i));}palletHistory.add(s.toString());}
        render();
    }
    private void showError(String m){Toast.makeText(this,m,Toast.LENGTH_LONG).show();}
    private JSONObject request(Mode m,String code)throws Exception{
        String action=m==Mode.PALLET?"scanner_pallet_lookup":"scanner_case_lookup";String key=m==Mode.PALLET?"pallet_id":"case_serial";
        String body="action="+URLEncoder.encode(action,"UTF-8")+"&"+key+"="+URLEncoder.encode(code,"UTF-8")+"&password="+URLEncoder.encode(password,"UTF-8")+"&app_version=2.0.46-tc26";
        HttpURLConnection c=(HttpURLConnection)new URL(BuildConfig.API_URL).openConnection();try{c.setConnectTimeout(9000);c.setReadTimeout(20000);c.setRequestMethod("POST");c.setDoOutput(true);c.setRequestProperty("X-App-Token",BuildConfig.APP_TOKEN);c.setRequestProperty("Content-Type","application/x-www-form-urlencoded; charset=UTF-8");try(OutputStream o=c.getOutputStream()){o.write(body.getBytes(StandardCharsets.UTF_8));}InputStream r=c.getResponseCode()<400?c.getInputStream():c.getErrorStream();if(r==null)throw new IOException("Empty server response");try(InputStream in=r;ByteArrayOutputStream out=new ByteArrayOutputStream()){byte[] b=new byte[4096];int n;while((n=in.read(b))!=-1)out.write(b,0,n);JSONObject j=new JSONObject(out.toString("UTF-8"));if(j.optInt("ok")!=1)throw new IOException(j.optString("err","API error"));return j;}}finally{c.disconnect();}
    }
    private void register(){receiver=new BroadcastReceiver(){@Override public void onReceive(Context c,Intent i){String v=i.getStringExtra("com.symbol.datawedge.data_string");if(v==null)v=i.getStringExtra("com.motorolasolutions.emdk.datawedge.data_string");scan(v);}};IntentFilter f=new IntentFilter(ACTION);if(Build.VERSION.SDK_INT>=33)registerReceiver(receiver,f,Context.RECEIVER_NOT_EXPORTED);else registerReceiver(receiver,f);}
    private void dataWedge(){Bundle app=new Bundle();app.putString("PACKAGE_NAME",getPackageName());app.putStringArray("ACTIVITY_LIST",new String[]{getClass().getName()});Bundle barcode=new Bundle();barcode.putString("scanner_input_enabled","true");barcode.putString("scanner_selection","auto");Bundle intent=new Bundle();intent.putString("intent_output_enabled","true");intent.putString("intent_action",ACTION);intent.putString("intent_delivery","2");Bundle keyboard=new Bundle();keyboard.putString("keystroke_output_enabled","false");String[] names={"BARCODE","INTENT","KEYSTROKE"};Bundle[] params={barcode,intent,keyboard};for(int x=0;x<names.length;x++){Bundle plugin=new Bundle();plugin.putString("PLUGIN_NAME",names[x]);plugin.putString("RESET_CONFIG","false");plugin.putBundle("PARAM_LIST",params[x]);Bundle cfg=new Bundle();cfg.putString("PROFILE_NAME","SMProduce_TC26_Scanner_2046");cfg.putString("PROFILE_ENABLED","true");cfg.putString("CONFIG_MODE","CREATE_IF_NOT_EXIST");cfg.putParcelableArray("APP_LIST",new Bundle[]{app});cfg.putBundle("PLUGIN_CONFIG",plugin);sendBroadcast(new Intent("com.symbol.datawedge.api.ACTION").putExtra("com.symbol.datawedge.api.SET_CONFIG",cfg));cfg.putString("CONFIG_MODE","UPDATE");sendBroadcast(new Intent("com.symbol.datawedge.api.ACTION").putExtra("com.symbol.datawedge.api.SET_CONFIG",cfg));}}
    @Override protected void onResume(){super.onResume();foreground=true;dataWedge();}
    @Override protected void onPause(){foreground=false;super.onPause();}
    @Override protected void onDestroy(){foreground=false;password="";try{unregisterReceiver(receiver);}catch(Exception ignored){}io.shutdownNow();super.onDestroy();}
}
