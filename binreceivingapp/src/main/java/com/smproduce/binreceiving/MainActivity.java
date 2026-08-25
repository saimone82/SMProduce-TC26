package com.smproduce.binreceiving;

import android.app.*;
import android.content.*;
import android.graphics.Color;
import android.net.*;
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
    final int BG=Color.rgb(7,17,31), PANEL=Color.rgb(13,29,48), BLUE=Color.rgb(25,118,210), GREEN=Color.rgb(22,163,74), RED=Color.rgb(185,28,28), GRAY=Color.rgb(71,85,105);
    static final String PREFS="bins", CACHE_GROWERS="cache_growers", CACHE_TYPES="cache_types", CACHE_VARIETIES="cache_varieties", QUEUE_KEY="offline_queue";

    LinearLayout root,body,bar;
    TextView step,status;
    Button back,home,lang;
    boolean es=false;
    String mode="",grower="",type="",variety="",lot="",currentScreen="home";
    int qty=0;
    ArrayList<String> growers=new ArrayList<>(),types=new ArrayList<>(),varieties=new ArrayList<>();
    final Handler ui=new Handler(Looper.getMainLooper());
    SharedPreferences prefs;
    ConnectivityManager cm;
    ConnectivityManager.NetworkCallback netCallback;
    volatile boolean refreshing=false, syncing=false;
    String apiUrl="";
    final String[] API_PATHS=new String[]{"/pages/api/bin_receiving_api.php","/api/bin_receiving_api.php","/bin_receiving_api.php"};

    @Override public void onCreate(Bundle b){
        super.onCreate(b);
        getWindow().setFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN,WindowManager.LayoutParams.FLAG_FULLSCREEN);
        prefs=getSharedPreferences(PREFS,MODE_PRIVATE);
        es=prefs.getBoolean("es",false);
        apiUrl=BuildConfig.BASE_URL+API_PATHS[0];
        shell();
        loadCachedPresets();
        showMode();
        registerNetworkWatcher();
        refreshPresetsAsync();
        syncQueueAsync();
    }

    @Override protected void onResume(){
        super.onResume();
        updateStatus();
        if(isOnline()){refreshPresetsAsync();syncQueueAsync();}
    }

    @Override protected void onDestroy(){
        try{if(cm!=null&&netCallback!=null)cm.unregisterNetworkCallback(netCallback);}catch(Exception ignored){}
        super.onDestroy();
    }

    @Override public void onBackPressed(){
        if("home".equals(currentScreen)){super.onBackPressed();return;}
        goBack();
    }

    void shell(){
        root=new LinearLayout(this);root.setOrientation(LinearLayout.VERTICAL);root.setBackgroundColor(BG);
        bar=new LinearLayout(this);bar.setGravity(Gravity.CENTER_VERTICAL);bar.setPadding(dp(10),dp(7),dp(10),dp(7));bar.setBackgroundColor(PANEL);

        back=button("‹ BACK",16);back.setVisibility(View.INVISIBLE);back.setOnClickListener(v->goBack());
        bar.addView(back,new LinearLayout.LayoutParams(dp(120),dp(58)));

        home=button("HOME",16);home.setVisibility(View.INVISIBLE);home.setOnClickListener(v->showMode());
        LinearLayout.LayoutParams hp=new LinearLayout.LayoutParams(dp(115),dp(58));hp.setMargins(dp(6),0,0,0);bar.addView(home,hp);

        TextView title=text("BINS RECEIVING",25);title.setTypeface(null,1);bar.addView(title,new LinearLayout.LayoutParams(0,dp(58),1));

        lang=button(es?"ES":"EN",16);lang.setOnClickListener(v->{es=!es;prefs.edit().putBoolean("es",es).apply();lang.setText(es?"ES":"EN");redrawCurrent();});
        bar.addView(lang,new LinearLayout.LayoutParams(dp(90),dp(58)));
        root.addView(bar,new LinearLayout.LayoutParams(-1,-2));

        step=text("",15);step.setTextColor(Color.rgb(148,163,184));root.addView(step,new LinearLayout.LayoutParams(-1,dp(38)));
        body=new LinearLayout(this);body.setOrientation(LinearLayout.VERTICAL);body.setGravity(Gravity.CENTER);body.setPadding(dp(38),dp(16),dp(38),dp(18));root.addView(body,new LinearLayout.LayoutParams(-1,0,1));
        status=text("",13);status.setTextColor(Color.rgb(203,213,225));root.addView(status,new LinearLayout.LayoutParams(-1,dp(34)));
        setContentView(root);
    }

    void setNav(boolean canBack){
        back.setVisibility(canBack?View.VISIBLE:View.INVISIBLE);
        home.setVisibility(canBack?View.VISIBLE:View.INVISIBLE);
    }

    void registerNetworkWatcher(){
        try{
            cm=(ConnectivityManager)getSystemService(CONNECTIVITY_SERVICE);
            netCallback=new ConnectivityManager.NetworkCallback(){
                @Override public void onAvailable(Network network){ui.post(()->{updateStatus();refreshPresetsAsync();syncQueueAsync();});}
                @Override public void onLost(Network network){ui.post(()->updateStatus());}
            };
            cm.registerDefaultNetworkCallback(netCallback);
        }catch(Exception ignored){}
    }

    boolean isOnline(){
        try{
            if(cm==null)cm=(ConnectivityManager)getSystemService(CONNECTIVITY_SERVICE);
            Network n=cm.getActiveNetwork();if(n==null)return false;
            NetworkCapabilities c=cm.getNetworkCapabilities(n);
            return c!=null&&c.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
        }catch(Exception e){return false;}
    }

    void updateStatus(){
        int pending=pendingCount();
        String p=pending>0?(" • "+pending+" "+t("PENDING","PENDIENTE")):"";
        status.setText((isOnline()?t("ONLINE","EN LÍNEA"):t("OFFLINE MODE","MODO SIN CONEXIÓN"))+p);
    }

    void loadCachedPresets(){
        growers=listFromString(prefs.getString(CACHE_GROWERS,"[]"));
        types=listFromString(prefs.getString(CACHE_TYPES,"[]"));
        varieties=listFromString(prefs.getString(CACHE_VARIETIES,"[]"));
        updateStatus();
    }

    ArrayList<String> listFromString(String raw){try{return list(new JSONArray(raw));}catch(Exception e){return new ArrayList<>();}}

    void refreshPresetsAsync(){
        if(refreshing||!isOnline())return;
        refreshing=true;
        new Thread(()->{
            try{
                JSONObject j=reqPresets();
                JSONArray ga=j.optJSONArray("growers"),ta=j.optJSONArray("binTypes"),va=j.optJSONArray("varieties");
                ArrayList<String> ng=list(ga),nt=list(ta),nv=list(va);
                prefs.edit().putString(CACHE_GROWERS,ga==null?"[]":ga.toString()).putString(CACHE_TYPES,ta==null?"[]":ta.toString()).putString(CACHE_VARIETIES,va==null?"[]":va.toString()).apply();
                ui.post(()->{
                    boolean wasEmpty=growers.isEmpty()||types.isEmpty()||varieties.isEmpty();
                    growers=ng;types=nt;varieties=nv;updateStatus();
                    if(wasEmpty&&(currentScreen.equals("grower")||currentScreen.equals("type")||currentScreen.equals("variety")))redrawCurrent();
                });
            }catch(Exception e){ui.post(this::updateStatus);}finally{refreshing=false;}
        }).start();
    }

    void reset(){mode="";grower="";type="";variety="";lot="";qty=0;}

    void showMode(){
        reset();currentScreen="home";clear();setNav(false);step.setText(t("START","INICIO"));
        question(t("WHAT ARE YOU RECEIVING?","¿QUÉ ESTÁS RECIBIENDO?"));
        big(t("EMPTY BINS","BINS VACÍOS"),Color.rgb(14,116,144),v->{mode="empty";showGrower();});
        big(t("FULL BINS","BINS LLENOS"),GREEN,v->{mode="full";showGrower();});
        updateStatus();
    }

    void showGrower(){
        currentScreen="grower";clear();setNav(true);
        step.setText(mode.equals("empty")?t("EMPTY BINS — STEP 1","BINS VACÍOS — PASO 1"):t("FULL BINS — STEP 1","BINS LLENOS — PASO 1"));
        question(t("WHO IS THE GROWER?","¿QUIÉN ES EL GROWER?"));
        options(growers,grower,s->{grower=s;showType();});
    }

    void showType(){
        currentScreen="type";clear();setNav(true);
        step.setText(mode.equals("empty")?t("EMPTY BINS — STEP 2","BINS VACÍOS — PASO 2"):t("FULL BINS — STEP 2","BINS LLENOS — PASO 2"));
        question(t("WHAT TYPE OF BINS?","¿QUÉ TIPO DE BINS?"));
        options(types,type,s->{type=s;if(mode.equals("empty"))showQty();else showVariety();});
    }

    void showVariety(){
        currentScreen="variety";clear();setNav(true);step.setText(t("FULL BINS — STEP 3","BINS LLENOS — PASO 3"));
        question(t("WHAT VARIETY?","¿QUÉ VARIEDAD?"));
        options(varieties,variety,s->{variety=s;showLot();});
    }

    void showLot(){
        currentScreen="lot";clear();setNav(true);step.setText(t("FULL BINS — STEP 4","BINS LLENOS — PASO 4"));
        question(t("WHAT LOT? (OPTIONAL)","¿QUÉ LOTE? (OPCIONAL)"));
        EditText e=input(false);e.setHint(t("Leave empty to skip","Dejar vacío para omitir"));e.setText(lot);
        body.addView(e,new LinearLayout.LayoutParams(dp(700),dp(92)));
        big(t("NEXT","SIGUIENTE"),BLUE,v->{lot=e.getText().toString().trim();showQty();});
        big(t("BACK","ATRÁS"),GRAY,v->{lot=e.getText().toString().trim();showVariety();});
    }

    void showQty(){
        currentScreen="qty";clear();setNav(true);int n=mode.equals("empty")?3:5;
        step.setText((mode.equals("empty")?t("EMPTY BINS — STEP ","BINS VACÍOS — PASO "):t("FULL BINS — STEP ","BINS LLENOS — PASO "))+n);
        question(t("HOW MANY BINS?","¿CUÁNTOS BINS?"));
        EditText e=input(true);e.setHint("0");if(qty>0)e.setText(String.valueOf(qty));
        body.addView(e,new LinearLayout.LayoutParams(dp(420),dp(100)));
        LinearLayout q=new LinearLayout(this);q.setGravity(Gravity.CENTER);q.setPadding(0,dp(10),0,0);
        for(int n0:new int[]{5,10,20,25,50}){Button b=button(String.valueOf(n0),18);b.setOnClickListener(v->e.setText(((Button)v).getText().toString()));q.addView(b,new LinearLayout.LayoutParams(dp(115),dp(62)));}
        body.addView(q,new LinearLayout.LayoutParams(-1,dp(78)));
        big(t("NEXT","SIGUIENTE"),BLUE,v->{if(!readQty(e))return;showConfirm();});
        big(t("BACK","ATRÁS"),GRAY,v->{readQtyQuiet(e);if(mode.equals("empty"))showType();else showLot();});
    }

    boolean readQty(EditText e){
        readQtyQuiet(e);if(qty<=0){Toast.makeText(this,t("Enter a quantity","Ingrese una cantidad"),Toast.LENGTH_SHORT).show();return false;}return true;
    }
    void readQtyQuiet(EditText e){try{String s=e.getText().toString().trim();qty=s.isEmpty()?0:Integer.parseInt(s);}catch(Exception ex){qty=0;}}

    void showConfirm(){
        currentScreen="confirm";clear();setNav(true);step.setText(t("CONFIRM","CONFIRMAR"));question(t("CLOSE RECORD?","¿CERRAR REGISTRO?"));
        String s=t("Grower","Grower")+": "+grower+"\n"+t("Bin type","Tipo de bin")+": "+type+"\n";
        if(mode.equals("full"))s+=t("Variety","Variedad")+": "+variety+"\n"+t("Lot","Lote")+": "+(lot.isEmpty()?"—":lot)+"\n";
        s+=t("Quantity","Cantidad")+": "+qty;
        TextView x=text(s,23);x.setPadding(dp(20),dp(8),dp(20),dp(12));body.addView(x);
        big(t("SAVE & PRINT","GUARDAR E IMPRIMIR"),GREEN,v->saveFast(true));
        big(t("SAVE ONLY","SOLO GUARDAR"),BLUE,v->saveFast(false));
        big(t("BACK","ATRÁS"),GRAY,v->showQty());
    }

    LinkedHashMap<String,String> currentPayload(boolean wantPrint){
        LinkedHashMap<String,String> p=new LinkedHashMap<>();
        p.put("grower",grower);p.put("type",type);p.put("quantity",String.valueOf(qty));p.put("date",new SimpleDateFormat("yyyy-MM-dd",Locale.US).format(new Date()));p.put("print",wantPrint?"1":"0");
        if(mode.equals("full")){p.put("variety",variety);p.put("lot",lot);}return p;
    }

    void saveFast(boolean wantPrint){
        String action=mode.equals("empty")?"save_empty":"save_full";
        LinkedHashMap<String,String> payload=currentPayload(wantPrint);
        enqueue(action,payload,wantPrint);
        showQueued(wantPrint);
        if(isOnline())syncQueueAsync();
    }

    void showQueued(boolean wantPrint){
        currentScreen="done";clear();setNav(false);step.setText(t("SAVED","GUARDADO"));
        question(t("RECORD SAVED","REGISTRO GUARDADO"));
        String msg;
        if(isOnline()) msg=wantPrint?t("Saved immediately. Sending and printing in background.","Guardado inmediatamente. Enviando e imprimiendo en segundo plano."):t("Saved immediately. Sending to the server in background.","Guardado inmediatamente. Enviando al servidor en segundo plano.");
        else msg=wantPrint?t("Saved on the tablet. It will sync and print automatically when internet returns.","Guardado en la tableta. Se sincronizará e imprimirá cuando vuelva internet."):t("Saved on the tablet. It will sync automatically when internet returns.","Guardado en la tableta. Se sincronizará cuando vuelva internet.");
        TextView x=text(msg,20);x.setTextColor(isOnline()?Color.rgb(134,239,172):Color.rgb(253,224,71));x.setPadding(dp(30),0,dp(30),dp(14));body.addView(x);
        big(t("NEW RECEIVING","NUEVA RECEPCIÓN"),GREEN,v->showMode());updateStatus();
    }

    synchronized void enqueue(String action,Map<String,String> data,boolean wantPrint){
        try{
            JSONArray q=getQueue();JSONObject item=new JSONObject();String id=UUID.randomUUID().toString();item.put("id",id);item.put("action",action);item.put("print",wantPrint);item.put("createdAt",System.currentTimeMillis());
            JSONObject d=new JSONObject();for(Map.Entry<String,String> e:data.entrySet())d.put(e.getKey(),e.getValue());d.put("client_request_id",id);item.put("data",d);q.put(item);saveQueue(q);
        }catch(Exception ignored){}
    }

    synchronized JSONArray getQueue(){try{return new JSONArray(prefs.getString(QUEUE_KEY,"[]"));}catch(Exception e){return new JSONArray();}}
    synchronized void saveQueue(JSONArray q){prefs.edit().putString(QUEUE_KEY,q.toString()).apply();}
    int pendingCount(){return getQueue().length();}

    void syncQueueAsync(){
        if(syncing||!isOnline()||pendingCount()==0)return;
        syncing=true;
        new Thread(()->{
            try{
                while(isOnline()){
                    JSONObject item;
                    synchronized(this){JSONArray q=getQueue();if(q.length()==0)break;item=q.optJSONObject(0);if(item==null){removeFirst();continue;}}
                    try{
                        LinkedHashMap<String,String> data=map(item.optJSONObject("data"));
                        data.put("print",item.optBoolean("print",true)?"1":"0");
                        JSONObject r=req(item.optString("action"),data);
                        if(r.optBoolean("ok",false)){removeFirst();ui.post(this::updateStatus);}else break;
                    }catch(Exception e){break;}
                }
            }finally{syncing=false;ui.post(this::updateStatus);}
        }).start();
    }

    synchronized void removeFirst(){
        JSONArray q=getQueue(),n=new JSONArray();for(int i=1;i<q.length();i++)n.put(q.opt(i));saveQueue(n);
    }

    LinkedHashMap<String,String> map(JSONObject o){
        LinkedHashMap<String,String> m=new LinkedHashMap<>();if(o==null)return m;Iterator<String> it=o.keys();while(it.hasNext()){String k=it.next();m.put(k,o.optString(k,""));}return m;
    }

    void goBack(){
        switch(currentScreen){
            case "grower": showMode(); break;
            case "type": showGrower(); break;
            case "variety": showType(); break;
            case "lot": showVariety(); break;
            case "qty": if(mode.equals("empty"))showType();else showLot(); break;
            case "confirm": showQty(); break;
            default: showMode(); break;
        }
    }

    void redrawCurrent(){
        switch(currentScreen){
            case "grower":showGrower();break;
            case "type":showType();break;
            case "variety":showVariety();break;
            case "lot":showLot();break;
            case "qty":showQty();break;
            case "confirm":showConfirm();break;
            default:showMode();break;
        }
    }

    JSONObject reqPresets()throws Exception{
        Exception last=null;for(String p:API_PATHS){String u=BuildConfig.BASE_URL+p;try{JSONObject j=call(u,"presets",new LinkedHashMap<>());apiUrl=u;return j;}catch(Exception e){last=e;}}
        throw last==null?new IOException("Cannot connect to server API"):last;
    }
    JSONObject req(String action,Map<String,String> data)throws Exception{return call(apiUrl,action,data);}

    JSONObject call(String base,String action,Map<String,String> data)throws Exception{
        URL u=new URL(base+"?api_action="+URLEncoder.encode(action,"UTF-8"));HttpURLConnection c=(HttpURLConnection)u.openConnection();
        c.setConnectTimeout(5000);c.setReadTimeout(60000);c.setRequestProperty("X-App-Token",BuildConfig.APP_TOKEN);c.setRequestProperty("X-Requested-With","XMLHttpRequest");
        if(!data.isEmpty()){
            c.setRequestMethod("POST");c.setDoOutput(true);StringBuilder b=new StringBuilder();
            for(Map.Entry<String,String> e:data.entrySet()){if(b.length()>0)b.append('&');b.append(URLEncoder.encode(e.getKey(),"UTF-8")).append('=').append(URLEncoder.encode(e.getValue(),"UTF-8"));}
            byte[] z=b.toString().getBytes(StandardCharsets.UTF_8);c.setRequestProperty("Content-Type","application/x-www-form-urlencoded; charset=UTF-8");c.setFixedLengthStreamingMode(z.length);try(OutputStream o=c.getOutputStream()){o.write(z);}
        }
        int code=c.getResponseCode();InputStream in=code>=400?c.getErrorStream():c.getInputStream();String raw=read(in);c.disconnect();
        if(raw.trim().isEmpty())throw new IOException("Empty server response (HTTP "+code+")");
        try{return new JSONObject(raw);}catch(Exception e){throw new IOException("HTTP "+code+" invalid JSON");}
    }

    String read(InputStream in)throws Exception{if(in==null)return"";BufferedReader r=new BufferedReader(new InputStreamReader(in,StandardCharsets.UTF_8));StringBuilder b=new StringBuilder();String l;while((l=r.readLine())!=null)b.append(l);return b.toString();}
    ArrayList<String> list(JSONArray a){ArrayList<String>x=new ArrayList<>();if(a!=null)for(int i=0;i<a.length();i++){String s=a.optString(i,"").trim();if(!s.isEmpty())x.add(s);}return x;}

    interface Pick{void go(String s);}
    void options(ArrayList<String> a,String selected,Pick p){
        ScrollView sv=new ScrollView(this);LinearLayout list=new LinearLayout(this);list.setOrientation(LinearLayout.VERTICAL);list.setGravity(Gravity.CENTER);sv.addView(list);body.addView(sv,new LinearLayout.LayoutParams(-1,0,1));
        for(String s:a){Button b=button(s.equals(selected)?"✓  "+s:s,22);b.setBackgroundColor(s.equals(selected)?BLUE:PANEL);b.setOnClickListener(v->p.go(s));LinearLayout.LayoutParams lp=new LinearLayout.LayoutParams(-1,dp(78));lp.setMargins(0,dp(5),0,dp(5));list.addView(b,lp);}
        if(a.isEmpty()){
            TextView x=text(t("No cached presets yet. Connect to internet once, then try again.","Todavía no hay listas guardadas. Conéctese a internet una vez e intente de nuevo."),20);x.setPadding(dp(30),dp(20),dp(30),dp(20));list.addView(x);
            Button r=button(t("REFRESH","ACTUALIZAR"),18);r.setOnClickListener(v->{refreshPresetsAsync();Toast.makeText(this,t("Refreshing…","Actualizando…"),Toast.LENGTH_SHORT).show();});list.addView(r,new LinearLayout.LayoutParams(-1,dp(70)));
        }
    }

    void question(String s){TextView q=text(s,33);q.setTypeface(null,1);q.setPadding(0,0,0,dp(16));body.addView(q);}
    void big(String s,int color,View.OnClickListener l){Button b=button(s,21);b.setBackgroundColor(color);b.setOnClickListener(l);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(dp(760),dp(82));p.setMargins(0,dp(8),0,0);body.addView(b,p);}
    Button button(String s,int size){Button b=new Button(this);b.setText(s);b.setTextSize(size);b.setTextColor(Color.WHITE);b.setAllCaps(false);b.setBackgroundColor(Color.rgb(30,41,59));return b;}
    TextView text(String s,int size){TextView v=new TextView(this);v.setText(s);v.setTextSize(size);v.setTextColor(Color.WHITE);v.setGravity(Gravity.CENTER);return v;}
    EditText input(boolean numeric){EditText e=new EditText(this);e.setTextColor(Color.WHITE);e.setHintTextColor(Color.rgb(148,163,184));e.setTextSize(numeric?42:30);e.setGravity(Gravity.CENTER);e.setSingleLine(true);e.setBackgroundColor(PANEL);if(numeric)e.setInputType(InputType.TYPE_CLASS_NUMBER);return e;}
    void clear(){body.removeAllViews();}
    String t(String en,String es0){return es?es0:en;}
    int dp(int n){return(int)(n*getResources().getDisplayMetrics().density+.5f);}
}
