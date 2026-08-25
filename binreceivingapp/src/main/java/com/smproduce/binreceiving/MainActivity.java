package com.smproduce.binreceiving;

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
import java.text.SimpleDateFormat;
import java.util.*;

public class MainActivity extends Activity {
    final int BG=Color.rgb(7,17,31), PANEL=Color.rgb(13,29,48), BLUE=Color.rgb(25,118,210), GREEN=Color.rgb(22,163,74), RED=Color.rgb(185,28,28);
    LinearLayout root,body,bar; TextView step,status; Button home,lang;
    boolean es=false; String mode="",grower="",type="",variety="",lot=""; int qty=0;
    ArrayList<String> growers=new ArrayList<>(),types=new ArrayList<>(),varieties=new ArrayList<>();
    final Handler ui=new Handler(Looper.getMainLooper());
    String apiUrl="";
    final String[] API_PATHS=new String[]{"/pages/api/bin_receiving_api.php","/api/bin_receiving_api.php","/bin_receiving_api.php"};

    @Override public void onCreate(Bundle b){super.onCreate(b);getWindow().setFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN,WindowManager.LayoutParams.FLAG_FULLSCREEN);es=getSharedPreferences("bins",MODE_PRIVATE).getBoolean("es",false);apiUrl=BuildConfig.BASE_URL+API_PATHS[0];shell();loadPresets();}

    void shell(){
        root=new LinearLayout(this);root.setOrientation(LinearLayout.VERTICAL);root.setBackgroundColor(BG);
        bar=new LinearLayout(this);bar.setGravity(Gravity.CENTER_VERTICAL);bar.setPadding(dp(16),dp(8),dp(16),dp(8));bar.setBackgroundColor(PANEL);
        home=button("HOME",17);home.setVisibility(View.INVISIBLE);home.setOnClickListener(v->showMode());bar.addView(home,new LinearLayout.LayoutParams(dp(140),dp(58)));
        TextView title=text("BINS RECEIVING",26);title.setTypeface(null,1);bar.addView(title,new LinearLayout.LayoutParams(0,dp(58),1));
        lang=button(es?"ES":"EN",17);lang.setOnClickListener(v->{es=!es;getSharedPreferences("bins",MODE_PRIVATE).edit().putBoolean("es",es).apply();lang.setText(es?"ES":"EN");showMode();});bar.addView(lang,new LinearLayout.LayoutParams(dp(100),dp(58)));
        root.addView(bar,new LinearLayout.LayoutParams(-1,-2));
        step=text("",15);step.setTextColor(Color.rgb(148,163,184));root.addView(step,new LinearLayout.LayoutParams(-1,dp(38)));
        body=new LinearLayout(this);body.setOrientation(LinearLayout.VERTICAL);body.setGravity(Gravity.CENTER);body.setPadding(dp(38),dp(18),dp(38),dp(20));root.addView(body,new LinearLayout.LayoutParams(-1,0,1));
        status=text("",13);status.setTextColor(Color.rgb(203,213,225));root.addView(status,new LinearLayout.LayoutParams(-1,dp(34)));setContentView(root);
    }

    void loadPresets(){status.setText(t("Loading presets…","Cargando listas…"));new Thread(()->{try{JSONObject j=reqPresets();growers=list(j.optJSONArray("growers"));types=list(j.optJSONArray("binTypes"));varieties=list(j.optJSONArray("varieties"));ui.post(()->{status.setText(t("Connected to SM Produce","Conectado a SM Produce"));showMode();});}catch(Exception e){ui.post(()->errorLoad(e.getMessage()));}}).start();}
    void errorLoad(String m){clear();home.setVisibility(View.INVISIBLE);step.setText("SERVER");question(t("CANNOT LOAD PRESETS","NO SE PUEDEN CARGAR LAS LISTAS"));TextView x=text(cleanError(m),20);x.setTextColor(Color.rgb(254,202,202));x.setPadding(dp(25),0,dp(25),dp(18));body.addView(x);big(t("RETRY","REINTENTAR"),BLUE,v->loadPresets());}
    String cleanError(String m){if(m==null)m="";String low=m.toLowerCase(Locale.US);if(low.contains("404")||low.contains("not found")||low.contains("doctype html"))return t("Server API not found. Upload bin_receiving_api.php to the server folder /pages/api/ then press RETRY.","API del servidor no encontrada. Cargue bin_receiving_api.php en /pages/api/ y presione REINTENTAR.");if(low.contains("unauthorized")||low.contains("401"))return t("Unauthorized tablet token. Check APP_TOKEN in the APK and PHP API.","Token no autorizado. Revise APP_TOKEN en el APK y en el API PHP.");return m;}

    void reset(){mode="";grower="";type="";variety="";lot="";qty=0;}
    void showMode(){reset();clear();home.setVisibility(View.INVISIBLE);step.setText(t("START","INICIO"));question(t("WHAT ARE YOU RECEIVING?","¿QUÉ ESTÁS RECIBIENDO?"));big(t("EMPTY BINS","BINS VACÍOS"),Color.rgb(14,116,144),v->{mode="empty";showGrower();});big(t("FULL BINS","BINS LLENOS"),GREEN,v->{mode="full";showGrower();});}
    void showGrower(){clear();home.setVisibility(View.VISIBLE);step.setText(mode.equals("empty")?t("EMPTY BINS — STEP 1","BINS VACÍOS — PASO 1"):t("FULL BINS — STEP 1","BINS LLENOS — PASO 1"));question(t("WHO IS THE GROWER?","¿QUIÉN ES EL GROWER?"));options(growers,s->{grower=s;showType();});}
    void showType(){clear();step.setText(mode.equals("empty")?t("EMPTY BINS — STEP 2","BINS VACÍOS — PASO 2"):t("FULL BINS — STEP 2","BINS LLENOS — PASO 2"));question(t("WHAT TYPE OF BINS?","¿QUÉ TIPO DE BINS?"));options(types,s->{type=s;if(mode.equals("empty"))showQty();else showVariety();});}
    void showVariety(){clear();step.setText(t("FULL BINS — STEP 3","BINS LLENOS — PASO 3"));question(t("WHAT VARIETY?","¿QUÉ VARIEDAD?"));options(varieties,s->{variety=s;showLot();});}
    void showLot(){clear();step.setText(t("FULL BINS — STEP 4","BINS LLENOS — PASO 4"));question(t("WHAT LOT?","¿QUÉ LOTE?"));EditText e=input(false);e.setHint(t("Enter lot","Ingrese lote"));body.addView(e,new LinearLayout.LayoutParams(dp(700),dp(92)));big(t("NEXT","SIGUIENTE"),BLUE,v->{lot=e.getText().toString().trim();showQty();});}
    void showQty(){clear();int n=mode.equals("empty")?3:5;step.setText((mode.equals("empty")?t("EMPTY BINS — STEP ","BINS VACÍOS — PASO "):t("FULL BINS — STEP ","BINS LLENOS — PASO "))+n);question(t("HOW MANY BINS?","¿CUÁNTOS BINS?"));EditText e=input(true);e.setHint("0");body.addView(e,new LinearLayout.LayoutParams(dp(420),dp(100)));LinearLayout q=new LinearLayout(this);q.setGravity(Gravity.CENTER);q.setPadding(0,dp(12),0,0);for(int n0:new int[]{5,10,20,25,50}){Button b=button(String.valueOf(n0),18);b.setOnClickListener(v->e.setText(((Button)v).getText().toString()));q.addView(b,new LinearLayout.LayoutParams(dp(115),dp(62)));}body.addView(q,new LinearLayout.LayoutParams(-1,dp(80)));big(t("NEXT","SIGUIENTE"),BLUE,v->{try{qty=Integer.parseInt(e.getText().toString().trim());}catch(Exception ex){qty=0;}if(qty<=0){Toast.makeText(this,t("Enter a quantity","Ingrese una cantidad"),Toast.LENGTH_SHORT).show();return;}showConfirm();});}
    void showConfirm(){clear();step.setText(t("CONFIRM","CONFIRMAR"));question(t("CLOSE RECORD AND PRINT?","¿CERRAR REGISTRO E IMPRIMIR?"));String s=t("Grower","Grower")+": "+grower+"\n"+t("Bin type","Tipo de bin")+": "+type+"\n";if(mode.equals("full"))s+=t("Variety","Variedad")+": "+variety+"\n"+t("Lot","Lote")+": "+(lot.isEmpty()?"—":lot)+"\n";s+=t("Quantity","Cantidad")+": "+qty;TextView x=text(s,23);x.setPadding(dp(20),dp(10),dp(20),dp(16));body.addView(x);big(t("YES — SAVE & PRINT","SÍ — GUARDAR E IMPRIMIR"),GREEN,v->save());big(t("BACK","ATRÁS"),Color.rgb(71,85,105),v->showQty());}

    void save(){disable(body);status.setText(t("Saving and printing…","Guardando e imprimiendo…"));LinkedHashMap<String,String> p=new LinkedHashMap<>();p.put("grower",grower);p.put("type",type);p.put("quantity",String.valueOf(qty));p.put("date",new SimpleDateFormat("yyyy-MM-dd",Locale.US).format(new Date()));if(mode.equals("full")){p.put("variety",variety);p.put("lot",lot);}String a=mode.equals("empty")?"save_empty":"save_full";new Thread(()->{try{JSONObject j=req(a,p);boolean ok=j.optBoolean("ok",false);String m=j.optString("msg",j.optString("err",j.optString("error","")));ui.post(()->{if(ok)success(m);else failure(m);});}catch(Exception e){ui.post(()->failure(cleanError(e.getMessage())));}}).start();}
    void success(String m){clear();step.setText(t("DONE","LISTO"));question(t("RECORD SAVED","REGISTRO GUARDADO"));TextView x=text(m,19);x.setTextColor(Color.rgb(134,239,172));body.addView(x);big(t("NEW RECEIVING","NUEVA RECEPCIÓN"),GREEN,v->showMode());status.setText(t("Connected to SM Produce","Conectado a SM Produce"));}
    void failure(String m){clear();step.setText("ERROR");question(t("RECORD NOT SAVED","REGISTRO NO GUARDADO"));TextView x=text(m,19);x.setTextColor(Color.rgb(254,202,202));x.setPadding(dp(25),0,dp(25),dp(18));body.addView(x);big(t("TRY AGAIN","REINTENTAR"),RED,v->showConfirm());big(t("HOME","INICIO"),Color.rgb(71,85,105),v->showMode());}

    JSONObject reqPresets()throws Exception{Exception last=null;for(String p:API_PATHS){String u=BuildConfig.BASE_URL+p;try{JSONObject j=call(u,"presets",new LinkedHashMap<>());apiUrl=u;return j;}catch(Exception e){last=e;}}throw last==null?new IOException("Cannot connect to server API"):last;}
    JSONObject req(String action,Map<String,String> data)throws Exception{return call(apiUrl,action,data);}
    JSONObject call(String base,String action,Map<String,String> data)throws Exception{URL u=new URL(base+"?api_action="+URLEncoder.encode(action,"UTF-8"));HttpURLConnection c=(HttpURLConnection)u.openConnection();c.setConnectTimeout(10000);c.setReadTimeout(60000);c.setRequestProperty("X-App-Token",BuildConfig.APP_TOKEN);c.setRequestProperty("X-Requested-With","XMLHttpRequest");if(!data.isEmpty()){c.setRequestMethod("POST");c.setDoOutput(true);StringBuilder b=new StringBuilder();for(Map.Entry<String,String> e:data.entrySet()){if(b.length()>0)b.append('&');b.append(URLEncoder.encode(e.getKey(),"UTF-8")).append('=').append(URLEncoder.encode(e.getValue(),"UTF-8"));}byte[] z=b.toString().getBytes(StandardCharsets.UTF_8);c.setRequestProperty("Content-Type","application/x-www-form-urlencoded; charset=UTF-8");c.setFixedLengthStreamingMode(z.length);try(OutputStream o=c.getOutputStream()){o.write(z);}}int code=c.getResponseCode();InputStream in=code>=400?c.getErrorStream():c.getInputStream();String raw=read(in);c.disconnect();if(raw.trim().isEmpty())throw new IOException("Empty server response (HTTP "+code+")");try{JSONObject j=new JSONObject(raw);if(!j.optBoolean("ok",true)&&code>=400)throw new IOException(j.optString("error",j.optString("err","HTTP "+code)));return j;}catch(JSONException e){throw new IOException("HTTP "+code+" invalid JSON: "+raw.substring(0,Math.min(180,raw.length())));}}
    String read(InputStream in)throws Exception{if(in==null)return"";BufferedReader r=new BufferedReader(new InputStreamReader(in,StandardCharsets.UTF_8));StringBuilder b=new StringBuilder();String l;while((l=r.readLine())!=null)b.append(l);return b.toString();}
    ArrayList<String> list(JSONArray a){ArrayList<String>x=new ArrayList<>();if(a!=null)for(int i=0;i<a.length();i++){String s=a.optString(i,"").trim();if(!s.isEmpty())x.add(s);}return x;}

    interface Pick{void go(String s);}void options(ArrayList<String> a,Pick p){ScrollView sv=new ScrollView(this);LinearLayout list=new LinearLayout(this);list.setOrientation(LinearLayout.VERTICAL);list.setGravity(Gravity.CENTER);sv.addView(list);body.addView(sv,new LinearLayout.LayoutParams(-1,0,1));for(String s:a){Button b=button(s,22);b.setBackgroundColor(PANEL);b.setOnClickListener(v->p.go(s));LinearLayout.LayoutParams lp=new LinearLayout.LayoutParams(-1,dp(78));lp.setMargins(0,dp(5),0,dp(5));list.addView(b,lp);}if(a.isEmpty())list.addView(text(t("No presets available","No hay presets disponibles"),20));}
    void question(String s){TextView q=text(s,34);q.setTypeface(null,1);q.setPadding(0,0,0,dp(18));body.addView(q);}
    void big(String s,int color,View.OnClickListener l){Button b=button(s,22);b.setBackgroundColor(color);b.setOnClickListener(l);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(dp(760),dp(86));p.setMargins(0,dp(10),0,0);body.addView(b,p);}
    Button button(String s,int size){Button b=new Button(this);b.setText(s);b.setTextSize(size);b.setTextColor(Color.WHITE);b.setAllCaps(false);b.setBackgroundColor(Color.rgb(30,41,59));return b;}
    TextView text(String s,int size){TextView v=new TextView(this);v.setText(s);v.setTextSize(size);v.setTextColor(Color.WHITE);v.setGravity(Gravity.CENTER);return v;}
    EditText input(boolean numeric){EditText e=new EditText(this);e.setTextColor(Color.WHITE);e.setHintTextColor(Color.rgb(148,163,184));e.setTextSize(numeric?42:30);e.setGravity(Gravity.CENTER);e.setSingleLine(true);e.setBackgroundColor(PANEL);if(numeric)e.setInputType(InputType.TYPE_CLASS_NUMBER);return e;}
    void clear(){body.removeAllViews();}void disable(ViewGroup g){for(int i=0;i<g.getChildCount();i++){View v=g.getChildAt(i);v.setEnabled(false);if(v instanceof ViewGroup)disable((ViewGroup)v);}}String t(String en,String es0){return es?es0:en;}int dp(int n){return(int)(n*getResources().getDisplayMetrics().density+.5f);}
}
