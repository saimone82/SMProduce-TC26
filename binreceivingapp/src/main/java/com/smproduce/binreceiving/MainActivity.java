package com.smproduce.binreceiving;

import android.app.*;
import android.content.*;
import android.graphics.Color;
import android.net.*;
import android.os.*;
import android.text.InputFilter;
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
    final int BG=Color.rgb(7,17,31), PANEL=Color.rgb(13,29,48), BLUE=Color.rgb(25,118,210), GREEN=Color.rgb(22,163,74), RED=Color.rgb(185,28,28), GRAY=Color.rgb(71,85,105), ORANGE=Color.rgb(217,119,6);
    static final String PREFS="bins", CACHE_GROWERS="cache_growers", CACHE_TYPES="cache_types", CACHE_VARIETIES="cache_varieties", QUEUE_KEY="offline_queue";
    static final String EDIT_PASSWORD="2424";
    LinearLayout root,body,bar; TextView step,status; Button lang,settings;
    boolean es=false; String mode="",grower="",type="",variety="",lot="",currentScreen="home"; int qty=0;
    ArrayList<String> growers=new ArrayList<>(),types=new ArrayList<>(),varieties=new ArrayList<>();
    final Handler ui=new Handler(Looper.getMainLooper());
    SharedPreferences prefs; ConnectivityManager cm; ConnectivityManager.NetworkCallback netCallback;
    volatile boolean refreshing=false, syncing=false; String apiUrl="";
    final String[] API_PATHS=new String[]{"/pages/api/bin_receiving_api.php","/api/bin_receiving_api.php","/bin_receiving_api.php"};

    @Override public void onCreate(Bundle b){
        super.onCreate(b);getWindow().setFlags(WindowManager.LayoutParams.FLAG_FULLSCREEN,WindowManager.LayoutParams.FLAG_FULLSCREEN);
        prefs=getSharedPreferences(PREFS,MODE_PRIVATE);es=prefs.getBoolean("es",false);apiUrl=BuildConfig.BASE_URL+API_PATHS[0];
        shell();loadCachedPresets();showMode();registerNetworkWatcher();refreshPresetsAsync();syncQueueAsync();
    }
    @Override protected void onResume(){super.onResume();updateStatus();if(isOnline()){refreshPresetsAsync();syncQueueAsync();}}
    @Override protected void onDestroy(){try{if(cm!=null&&netCallback!=null)cm.unregisterNetworkCallback(netCallback);}catch(Exception ignored){}super.onDestroy();}
    @Override public void onBackPressed(){if("home".equals(currentScreen)){super.onBackPressed();return;}goBack();}

    void shell(){
        boolean compact=compactUi();
        root=new LinearLayout(this);root.setOrientation(LinearLayout.VERTICAL);root.setBackgroundColor(BG);
        bar=new LinearLayout(this);bar.setGravity(Gravity.CENTER_VERTICAL);bar.setPadding(0,dp(compact?4:7),dp(compact?4:10),dp(compact?4:7));bar.setBackgroundColor(PANEL);
        status=text("",compact?10:13);status.setGravity(Gravity.LEFT|Gravity.CENTER_VERTICAL);status.setPadding(dp(compact?5:10),0,0,0);bar.addView(status,new LinearLayout.LayoutParams(dp(compact?82:118),dp(compact?46:58)));
        TextView title=text("BINS RECEIVING",compact?18:25);title.setTypeface(null,1);bar.addView(title,new LinearLayout.LayoutParams(0,dp(compact?46:58),1));
        lang=button(es?"ES":"EN",compact?13:16);lang.setPadding(0,0,0,0);lang.setOnClickListener(v->{es=!es;prefs.edit().putBoolean("es",es).apply();lang.setText(es?"ES":"EN");redrawCurrent();});bar.addView(lang,new LinearLayout.LayoutParams(dp(compact?56:90),dp(compact?46:58)));
        root.addView(bar,new LinearLayout.LayoutParams(-1,-2));
        FrameLayout utilityRow=new FrameLayout(this);utilityRow.setBackgroundColor(BG);
        step=text("",compact?12:15);step.setTextColor(Color.rgb(148,163,184));utilityRow.addView(step,new FrameLayout.LayoutParams(-1,dp(compact?42:56)));
        settings=button("⚙",compact?23:28);settings.setContentDescription(t("Record editor","Editor de registros"));settings.setPadding(0,0,0,0);settings.setOnClickListener(v->showEditMenu());FrameLayout.LayoutParams sp=new FrameLayout.LayoutParams(dp(compact?48:60),dp(compact?40:54),Gravity.END|Gravity.TOP);sp.setMargins(0,dp(2),0,0);utilityRow.addView(settings,sp);
        root.addView(utilityRow,new LinearLayout.LayoutParams(-1,dp(compact?42:56)));
        body=new LinearLayout(this);body.setOrientation(LinearLayout.VERTICAL);body.setGravity(Gravity.TOP|Gravity.CENTER_HORIZONTAL);body.setPadding(dp(compact?12:38),dp(compact?10:28),dp(compact?12:38),dp(compact?10:18));root.addView(body,new LinearLayout.LayoutParams(-1,0,1));
        setContentView(root);
    }
    void setNav(boolean canBack){
        if(!canBack)return;
        LinearLayout nav=new LinearLayout(this);nav.setGravity(Gravity.CENTER);nav.setOrientation(LinearLayout.HORIZONTAL);
        Button back=button(t("‹ BACK","‹ ATRÁS"),compactUi()?16:19);back.setBackgroundColor(GRAY);back.setOnClickListener(v->goBack());
        Button home=button("HOME",compactUi()?16:19);home.setBackgroundColor(BLUE);home.setOnClickListener(v->showMode());
        LinearLayout.LayoutParams bp=new LinearLayout.LayoutParams(0,dp(compactUi()?52:66),1);bp.setMargins(0,0,dp(6),0);nav.addView(back,bp);
        LinearLayout.LayoutParams hp=new LinearLayout.LayoutParams(0,dp(compactUi()?52:66),1);hp.setMargins(dp(6),0,0,0);nav.addView(home,hp);
        LinearLayout.LayoutParams np=new LinearLayout.LayoutParams(-1,dp(compactUi()?60:76));np.setMargins(0,0,0,dp(compactUi()?4:8));body.addView(nav,np);
    }
    void registerNetworkWatcher(){try{cm=(ConnectivityManager)getSystemService(CONNECTIVITY_SERVICE);netCallback=new ConnectivityManager.NetworkCallback(){@Override public void onAvailable(Network n){ui.post(()->{updateStatus();refreshPresetsAsync();syncQueueAsync();});}@Override public void onLost(Network n){ui.post(()->updateStatus());}};cm.registerDefaultNetworkCallback(netCallback);}catch(Exception ignored){}}
    boolean isOnline(){try{if(cm==null)cm=(ConnectivityManager)getSystemService(CONNECTIVITY_SERVICE);Network n=cm.getActiveNetwork();if(n==null)return false;NetworkCapabilities c=cm.getNetworkCapabilities(n);return c!=null&&c.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);}catch(Exception e){return false;}}
    void updateStatus(){int pending=pendingCount();boolean online=isOnline();String p=pending>0?("\n"+pending+" "+t("PENDING","PENDIENTE")):"";status.setText((online?"● "+t("ONLINE","EN LÍNEA"):"● "+t("OFFLINE","SIN RED"))+p);status.setTextColor(online?Color.rgb(74,222,128):Color.rgb(253,224,71));}

    void loadCachedPresets(){growers=listFromString(prefs.getString(CACHE_GROWERS,"[]"));types=listFromString(prefs.getString(CACHE_TYPES,"[]"));varieties=listFromString(prefs.getString(CACHE_VARIETIES,"[]"));updateStatus();}
    ArrayList<String> listFromString(String raw){try{return list(new JSONArray(raw));}catch(Exception e){return new ArrayList<>();}}
    void saveGrowerCache(){prefs.edit().putString(CACHE_GROWERS,new JSONArray(growers).toString()).apply();}
    void refreshPresetsAsync(){if(refreshing||!isOnline())return;refreshing=true;new Thread(()->{try{JSONObject j=reqPresets();JSONArray ga=j.optJSONArray("growers"),ta=j.optJSONArray("binTypes"),va=j.optJSONArray("varieties");ArrayList<String> ng=list(ga),nt=list(ta),nv=list(va);prefs.edit().putString(CACHE_GROWERS,ga==null?"[]":ga.toString()).putString(CACHE_TYPES,ta==null?"[]":ta.toString()).putString(CACHE_VARIETIES,va==null?"[]":va.toString()).apply();ui.post(()->{growers=ng;types=nt;varieties=nv;updateStatus();if(currentScreen.equals("grower")||currentScreen.equals("type")||currentScreen.equals("variety"))redrawCurrent();});}catch(Exception e){ui.post(this::updateStatus);}finally{refreshing=false;}}).start();}

    void reset(){mode="";grower="";type="";variety="";lot="";qty=0;}
    void showMode(){reset();currentScreen="home";clear();setNav(false);step.setText(t("START","INICIO"));question(t("WHAT ARE YOU RECEIVING?","¿QUÉ ESTÁS RECIBIENDO?"));homeBig(t("EMPTY BINS","BINS VACÍOS"),Color.rgb(14,116,144),v->{mode="empty";showGrower();});homeBig(t("FULL BINS","BINS LLENOS"),GREEN,v->{mode="full";showGrower();});updateStatus();}
    void showGrower(){currentScreen="grower";clear();setNav(true);step.setText(mode.equals("empty")?t("EMPTY BINS — STEP 1","BINS VACÍOS — PASO 1"):t("FULL BINS — STEP 1","BINS LLENOS — PASO 1"));question(t("WHO IS THE GROWER?","¿QUIÉN ES EL GROWER?"));Button add=button(t("+ ADD NEW GROWER","+ AGREGAR NUEVO GROWER"),compactUi()?16:20);add.setBackgroundColor(ORANGE);add.setOnClickListener(v->showAddGrower());LinearLayout.LayoutParams ap=new LinearLayout.LayoutParams(-1,dp(compactUi()?58:72));ap.setMargins(0,0,0,dp(compactUi()?6:10));body.addView(add,ap);options(growers,grower,s->{grower=s;showType();});}
    void showAddGrower(){currentScreen="add_grower";clear();setNav(true);step.setText(t("NEW GROWER","NUEVO GROWER"));question(t("ENTER GROWER NAME","INGRESE NOMBRE DEL GROWER"));EditText e=input(false);e.setHint(t("Grower name","Nombre del grower"));body.addView(e,new LinearLayout.LayoutParams(-1,dp(compactUi()?68:92)));big(t("SAVE GROWER","GUARDAR GROWER"),GREEN,v->{String name=e.getText().toString().trim();if(name.isEmpty()){Toast.makeText(this,t("Enter a grower name","Ingrese un nombre"),Toast.LENGTH_SHORT).show();return;}addGrowerLocalAndQueue(name);});}
    void addGrowerLocalAndQueue(String name){String found=null;for(String g:growers)if(g.equalsIgnoreCase(name)){found=g;break;}if(found==null){growers.add(name);Collections.sort(growers,String.CASE_INSENSITIVE_ORDER);saveGrowerCache();found=name;LinkedHashMap<String,String> p=new LinkedHashMap<>();p.put("name",name);enqueue("add_grower",p,"none");if(isOnline())syncQueueAsync();}grower=found;Toast.makeText(this,t("Grower selected","Grower seleccionado"),Toast.LENGTH_SHORT).show();showType();}
    void showType(){currentScreen="type";clear();setNav(true);step.setText(mode.equals("empty")?t("EMPTY BINS — STEP 2","BINS VACÍOS — PASO 2"):t("FULL BINS — STEP 2","BINS LLENOS — PASO 2"));question(t("WHAT TYPE OF BINS?","¿QUÉ TIPO DE BINS?"));options(types,type,s->{type=s;if(mode.equals("empty"))showQty();else showVariety();});}
    void showVariety(){currentScreen="variety";clear();setNav(true);step.setText(t("FULL BINS — STEP 3","BINS LLENOS — PASO 3"));question(t("WHAT VARIETY?","¿QUÉ VARIEDAD?"));options(varieties,variety,s->{variety=s;showLot();});}
    void showLot(){currentScreen="lot";clear();setNav(true);step.setText(t("FULL BINS — STEP 4","BINS LLENOS — PASO 4"));question(t("WHAT LOT? (OPTIONAL)","¿QUÉ LOTE? (OPCIONAL)"));EditText e=input(false);e.setHint(t("Leave empty to skip","Dejar vacío para omitir"));e.setText(lot);body.addView(e,new LinearLayout.LayoutParams(-1,dp(compactUi()?68:92)));big(t("NEXT","SIGUIENTE"),BLUE,v->{lot=e.getText().toString().trim();showQty();});}
    void showQty(){currentScreen="qty";clear();setNav(true);int n=mode.equals("empty")?3:5;step.setText((mode.equals("empty")?t("EMPTY BINS — STEP ","BINS VACÍOS — PASO "):t("FULL BINS — STEP ","BINS LLENOS — PASO "))+n);question(t("HOW MANY BINS?","¿CUÁNTOS BINS?"));EditText e=input(true);e.setHint("0");if(qty>0)e.setText(String.valueOf(qty));body.addView(e,new LinearLayout.LayoutParams(compactUi()?-1:dp(420),dp(compactUi()?72:100)));LinearLayout q=new LinearLayout(this);q.setGravity(Gravity.CENTER);q.setPadding(0,dp(compactUi()?6:10),0,0);for(int n0:new int[]{5,10,20,25,50}){Button b=button(String.valueOf(n0),compactUi()?15:18);b.setOnClickListener(v->e.setText(((Button)v).getText().toString()));LinearLayout.LayoutParams qp=new LinearLayout.LayoutParams(0,dp(compactUi()?48:62),1);qp.setMargins(dp(2),0,dp(2),0);q.addView(b,qp);}body.addView(q,new LinearLayout.LayoutParams(-1,dp(compactUi()?60:78)));big(t("NEXT","SIGUIENTE"),BLUE,v->{if(!readQty(e))return;showConfirm();});}
    boolean readQty(EditText e){readQtyQuiet(e);if(qty<=0){Toast.makeText(this,t("Enter a quantity","Ingrese una cantidad"),Toast.LENGTH_SHORT).show();return false;}return true;}
    void readQtyQuiet(EditText e){try{String s=e.getText().toString().trim();qty=s.isEmpty()?0:Integer.parseInt(s);}catch(Exception ex){qty=0;}}
    void showConfirm(){currentScreen="confirm";clear();setNav(true);step.setText(t("CONFIRM","CONFIRMAR"));question(t("CLOSE RECORD?","¿CERRAR REGISTRO?"));String s=t("Grower","Grower")+": "+grower+"\n"+t("Bin type","Tipo de bin")+": "+type+"\n";if(mode.equals("full"))s+=t("Variety","Variedad")+": "+variety+"\n"+t("Lot","Lote")+": "+(lot.isEmpty()?"—":lot)+"\n";s+=t("Quantity","Cantidad")+": "+qty;TextView x=text(s,compactUi()?18:23);x.setPadding(dp(20),dp(compactUi()?3:8),dp(20),dp(compactUi()?5:12));body.addView(x);if(mode.equals("full")){big(t("SAVE & PRINT LABELS","GUARDAR E IMPRIMIR ETIQUETAS"),GREEN,v->saveFast("labels"));big(t("SAVE, PRINT REPORT & LABELS","GUARDAR, IMPRIMIR REPORTE Y ETIQUETAS"),BLUE,v->saveFast("report_labels"));}else{big(t("SAVE & PRINT REPORT","GUARDAR E IMPRIMIR REPORTE"),GREEN,v->saveFast("report"));}}

    LinkedHashMap<String,String> currentPayload(String printMode){LinkedHashMap<String,String> p=new LinkedHashMap<>();p.put("grower",grower);p.put("type",type);p.put("quantity",String.valueOf(qty));p.put("date",new SimpleDateFormat("yyyy-MM-dd",Locale.US).format(new Date()));p.put("print_mode",printMode);if(mode.equals("full")){p.put("variety",variety);p.put("lot",lot);}return p;}
    void saveFast(String printMode){String action=mode.equals("empty")?"save_empty":"save_full";enqueue(action,currentPayload(printMode),printMode);showQueued(printMode);if(isOnline())syncQueueAsync();}
    void showQueued(String printMode){currentScreen="done";clear();setNav(false);step.setText(t("SAVED","GUARDADO"));question(t("RECORD SAVED","REGISTRO GUARDADO"));String job="labels".equals(printMode)?t("labels","etiquetas"):"report".equals(printMode)?t("report","reporte"):t("report and labels","reporte y etiquetas");String msg=isOnline()?t("Saved immediately. Sending and printing ","Guardado inmediatamente. Enviando e imprimiendo ")+job+t(" in background."," en segundo plano."):t("Saved on this device. It will sync and print ","Guardado en este dispositivo. Se sincronizará e imprimirá ")+job+t(" when internet returns."," cuando vuelva internet.");TextView x=text(msg,compactUi()?17:20);x.setTextColor(isOnline()?Color.rgb(134,239,172):Color.rgb(253,224,71));x.setPadding(dp(compactUi()?12:30),0,dp(compactUi()?12:30),dp(compactUi()?7:14));body.addView(x);big(t("NEW RECEIVING","NUEVA RECEPCIÓN"),GREEN,v->showMode());updateStatus();}

    void showEditMenu(){
        LinearLayout menu=new LinearLayout(this);menu.setOrientation(LinearLayout.VERTICAL);menu.setPadding(dp(22),dp(10),dp(22),dp(12));
        Button fullList=button(t("EDIT FULL BINS","EDITAR BINS LLENOS"),compactUi()?16:20);fullList.setBackgroundColor(GREEN);menu.addView(fullList,new LinearLayout.LayoutParams(dp(compactUi()?280:420),dp(compactUi()?60:78)));
        Button emptyList=button(t("EDIT EMPTY BINS","EDITAR BINS VACÍOS"),compactUi()?16:20);emptyList.setBackgroundColor(Color.rgb(14,116,144));LinearLayout.LayoutParams ep=new LinearLayout.LayoutParams(dp(compactUi()?280:420),dp(compactUi()?60:78));ep.setMargins(0,dp(12),0,0);menu.addView(emptyList,ep);
        AlertDialog dlg=new AlertDialog.Builder(this).setTitle(t("RECORD EDITOR","EDITOR DE REGISTROS")).setView(menu).setNegativeButton(t("CANCEL","CANCELAR"),null).create();
        fullList.setOnClickListener(v->{dlg.dismiss();askListPassword("full");});emptyList.setOnClickListener(v->{dlg.dismiss();askListPassword("empty");});dlg.show();
    }
    void askListPassword(String kind){
        if(!isOnline()){Toast.makeText(this,t("Internet is required to edit records","Se requiere internet para editar registros"),Toast.LENGTH_LONG).show();return;}
        EditText e=input(true);e.setInputType(InputType.TYPE_CLASS_NUMBER|InputType.TYPE_NUMBER_VARIATION_PASSWORD);e.setFilters(new InputFilter[]{new InputFilter.LengthFilter(4)});e.setHint(t("4-digit PIN","PIN de 4 dígitos"));
        new AlertDialog.Builder(this).setTitle(t("Protected records","Registros protegidos")).setView(e)
            .setNegativeButton(t("CANCEL","CANCELAR"),null).setPositiveButton("OK",(d,w)->{
                String password=e.getText().toString();if(!EDIT_PASSWORD.equals(password)){Toast.makeText(this,t("Wrong password","Contraseña incorrecta"),Toast.LENGTH_SHORT).show();return;}loadRecords(kind,password);
            }).show();
    }
    void loadRecords(String kind,String password){
        currentScreen="record_list";clear();setNav(true);step.setText(kind.equals("full")?t("FULL BINS LIST","LISTA BINS LLENOS"):t("EMPTY BINS LIST","LISTA BINS VACÍOS"));question(t("Loading records…","Cargando registros…"));
        new Thread(()->{try{LinkedHashMap<String,String> p=new LinkedHashMap<>();p.put("kind",kind);p.put("edit_password",password);JSONObject r=req("list_records",p);if(!r.optBoolean("ok",false))throw new IOException(r.optString("error","Cannot load records"));JSONArray a=r.optJSONArray("records");ui.post(()->showRecordList(kind,password,a==null?new JSONArray():a));}catch(Exception ex){ui.post(()->{Toast.makeText(this,ex.getMessage(),Toast.LENGTH_LONG).show();showMode();});}}).start();
    }
    void showRecordList(String kind,String password,JSONArray records){
        currentScreen="record_list";clear();setNav(true);question(kind.equals("full")?t("FULL BINS — TAP TO EDIT","BINS LLENOS — TOQUE PARA EDITAR"):t("EMPTY BINS — TAP TO EDIT","BINS VACÍOS — TOQUE PARA EDITAR"));
        ScrollView sv=new ScrollView(this);LinearLayout list=new LinearLayout(this);list.setOrientation(LinearLayout.VERTICAL);sv.addView(list);body.addView(sv,new LinearLayout.LayoutParams(-1,0,1));
        for(int i=0;i<records.length();i++){JSONObject r=records.optJSONObject(i);if(r==null)continue;String line="#"+r.optString("id")+"  "+r.optString("date")+"  •  "+r.optString("grower")+"  •  "+r.optString("type")+(kind.equals("full")?"  •  "+r.optString("variety")+"  •  "+r.optString("lot"):"")+"  •  "+r.optString("quantity")+" bins";Button b=button(line,16);b.setGravity(Gravity.LEFT|Gravity.CENTER_VERTICAL);b.setOnClickListener(v->editRecord(kind,password,r));LinearLayout.LayoutParams lp=new LinearLayout.LayoutParams(-1,dp(62));lp.setMargins(0,dp(3),0,dp(3));list.addView(b,lp);}
        if(records.length()==0)list.addView(text(t("No records found","No se encontraron registros"),20));
    }
    void editRecord(String kind,String password,JSONObject r){
        LinearLayout form=new LinearLayout(this);form.setOrientation(LinearLayout.VERTICAL);form.setPadding(dp(22),dp(12),dp(22),dp(18));form.setBackgroundColor(Color.rgb(10,25,43));
        form.addView(fieldLabel(t("Grower preset","Preset Grower")));Spinner eg=presetSpinner(growers,r.optString("grower"));form.addView(eg,new LinearLayout.LayoutParams(-1,dp(58)));
        form.addView(fieldLabel(t("Bin type preset","Preset tipo bin")));Spinner et=presetSpinner(types,r.optString("type"));form.addView(et,new LinearLayout.LayoutParams(-1,dp(58)));
        Spinner ev=presetSpinner(varieties,r.optString("variety"));EditText el=editField(t("Lot","Lote"),r.optString("lot"),false);if(kind.equals("full")){form.addView(fieldLabel(t("Variety preset","Preset Variedad")));form.addView(ev,new LinearLayout.LayoutParams(-1,dp(58)));form.addView(el);}
        form.addView(fieldLabel(t("Receiving date","Fecha de recepción")));Button ed=button(r.optString("date"),18);ed.setBackgroundColor(BLUE);ed.setOnClickListener(v->pickDate(ed));form.addView(ed,new LinearLayout.LayoutParams(-1,dp(58)));
        EditText eq=editField(t("Quantity","Cantidad"),r.optString("quantity"),true);form.addView(eq);ScrollView wrap=new ScrollView(this);wrap.addView(form);
        AlertDialog dlg=new AlertDialog.Builder(this).setTitle(t("Edit record #","Editar registro #")+r.optString("id")).setView(wrap).setNegativeButton(t("CANCEL","CANCELAR"),null).setPositiveButton(t("SAVE","GUARDAR"),null).create();
        dlg.setOnShowListener(x->dlg.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v->{String gs=String.valueOf(eg.getSelectedItem()).trim(),ts=String.valueOf(et.getSelectedItem()).trim(),vs=String.valueOf(ev.getSelectedItem()).trim(),ls=el.getText().toString().trim(),ds=ed.getText().toString().trim(),qs=eq.getText().toString().trim();if(gs.isEmpty()||ts.isEmpty()||ds.isEmpty()||qs.isEmpty()||(kind.equals("full")&&vs.isEmpty())){Toast.makeText(this,t("Complete all required fields","Complete todos los campos requeridos"),Toast.LENGTH_SHORT).show();return;}dlg.dismiss();updateRecord(kind,password,r.optString("id"),gs,ts,vs,ls,ds,qs);}));dlg.show();
    }
    TextView fieldLabel(String s){TextView v=text(s,15);v.setGravity(Gravity.LEFT|Gravity.CENTER_VERTICAL);v.setTextColor(Color.rgb(191,219,254));v.setTypeface(null,1);v.setPadding(dp(4),dp(7),0,0);v.setLayoutParams(new LinearLayout.LayoutParams(-1,dp(36)));return v;}
    Spinner presetSpinner(ArrayList<String> source,String selected){
        ArrayList<String> values=new ArrayList<>(source);if(!selected.isEmpty()&&!values.contains(selected))values.add(0,selected);
        ArrayAdapter<String> a=new ArrayAdapter<String>(this,android.R.layout.simple_spinner_item,values){
            TextView styled(String value,boolean dropdown){TextView v=new TextView(MainActivity.this);v.setText(value);v.setTextSize(20);v.setGravity(Gravity.LEFT|Gravity.CENTER_VERTICAL);v.setPadding(dp(16),0,dp(12),0);v.setTextColor(dropdown?Color.rgb(15,23,42):Color.WHITE);v.setBackgroundColor(dropdown?Color.WHITE:Color.rgb(30,64,105));return v;}
            @Override public View getView(int position,View convertView,ViewGroup parent){return styled(getItem(position),false);}
            @Override public View getDropDownView(int position,View convertView,ViewGroup parent){TextView v=styled(getItem(position),true);v.setMinHeight(dp(58));return v;}
        };
        Spinner s=new Spinner(this);s.setPopupBackgroundDrawable(new android.graphics.drawable.ColorDrawable(Color.WHITE));s.setAdapter(a);int pos=values.indexOf(selected);if(pos>=0)s.setSelection(pos);return s;
    }
    void pickDate(Button target){Calendar c=Calendar.getInstance();try{String[] p=target.getText().toString().split("-");c.set(Integer.parseInt(p[0]),Integer.parseInt(p[1])-1,Integer.parseInt(p[2]));}catch(Exception ignored){}new DatePickerDialog(this,(v,y,m,d)->target.setText(String.format(Locale.US,"%04d-%02d-%02d",y,m+1,d)),c.get(Calendar.YEAR),c.get(Calendar.MONTH),c.get(Calendar.DAY_OF_MONTH)).show();}
    EditText editField(String hint,String value,boolean numeric){EditText e=input(numeric);e.setHint(hint);e.setText(value);e.setTextColor(Color.WHITE);e.setHintTextColor(Color.rgb(148,163,184));e.setBackgroundColor(Color.rgb(30,64,105));e.setTextSize(20);e.setGravity(Gravity.LEFT|Gravity.CENTER_VERTICAL);e.setPadding(dp(16),0,dp(12),0);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,dp(58));p.setMargins(0,dp(3),0,dp(5));e.setLayoutParams(p);return e;}
    void updateRecord(String kind,String password,String id,String gs,String ts,String vs,String ls,String ds,String qs){
        clear();question(t("Saving changes…","Guardando cambios…"));new Thread(()->{try{LinkedHashMap<String,String> p=new LinkedHashMap<>();p.put("kind",kind);p.put("id",id);p.put("grower",gs);p.put("type",ts);p.put("variety",vs);p.put("lot",ls);p.put("date",ds);p.put("quantity",qs);p.put("edit_password",password);JSONObject result=req("update_record",p);if(!result.optBoolean("ok",false))throw new IOException(result.optString("error","Update failed"));ui.post(()->{Toast.makeText(this,t("Record updated","Registro actualizado"),Toast.LENGTH_SHORT).show();loadRecords(kind,password);});}catch(Exception ex){ui.post(()->{Toast.makeText(this,ex.getMessage(),Toast.LENGTH_LONG).show();loadRecords(kind,password);});}}).start();
    }

    synchronized void enqueue(String action,Map<String,String> data,String printMode){try{JSONArray q=getQueue();JSONObject item=new JSONObject();String id=UUID.randomUUID().toString();item.put("id",id);item.put("action",action);item.put("print_mode",printMode);item.put("createdAt",System.currentTimeMillis());JSONObject d=new JSONObject();for(Map.Entry<String,String> e:data.entrySet())d.put(e.getKey(),e.getValue());d.put("client_request_id",id);item.put("data",d);q.put(item);saveQueue(q);}catch(Exception ignored){}}
    synchronized JSONArray getQueue(){try{return new JSONArray(prefs.getString(QUEUE_KEY,"[]"));}catch(Exception e){return new JSONArray();}}
    synchronized void saveQueue(JSONArray q){prefs.edit().putString(QUEUE_KEY,q.toString()).apply();}
    synchronized void removeFirst(){try{JSONArray q=getQueue(),n=new JSONArray();for(int i=1;i<q.length();i++)n.put(q.get(i));saveQueue(n);}catch(Exception ignored){}}
    int pendingCount(){return getQueue().length();}
    void syncQueueAsync(){if(syncing||!isOnline()||pendingCount()==0)return;syncing=true;new Thread(()->{try{while(isOnline()){JSONObject item;synchronized(this){JSONArray q=getQueue();if(q.length()==0)break;item=q.optJSONObject(0);if(item==null){removeFirst();continue;}}try{LinkedHashMap<String,String> data=map(item.optJSONObject("data"));if(!"add_grower".equals(item.optString("action"))){String printMode=item.optString("print_mode","");if(printMode.isEmpty())printMode=item.optBoolean("print",true)?"report_labels":"none";data.put("print_mode",printMode);}JSONObject r=req(item.optString("action"),data);if(r.optBoolean("ok",false)){removeFirst();ui.post(this::updateStatus);}else break;}catch(Exception e){break;}}}finally{syncing=false;ui.post(()->{updateStatus();if(isOnline()&&pendingCount()>0)ui.postDelayed(this::syncQueueAsync,2500);});}}).start();}
    LinkedHashMap<String,String> map(JSONObject o){LinkedHashMap<String,String> m=new LinkedHashMap<>();if(o!=null){Iterator<String> it=o.keys();while(it.hasNext()){String k=it.next();m.put(k,o.optString(k,""));}}return m;}

    JSONObject reqPresets()throws Exception{Exception last=null;for(String p:API_PATHS){String u=BuildConfig.BASE_URL+p;try{JSONObject j=call(u,"presets",new LinkedHashMap<>());apiUrl=u;return j;}catch(Exception e){last=e;}}throw last==null?new IOException("Cannot connect to server API"):last;}
    JSONObject req(String action,Map<String,String> data)throws Exception{return call(apiUrl,action,data);}
    JSONObject call(String base,String action,Map<String,String> data)throws Exception{URL u=new URL(base+"?api_action="+URLEncoder.encode(action,"UTF-8"));HttpURLConnection c=(HttpURLConnection)u.openConnection();c.setConnectTimeout(5000);c.setReadTimeout(60000);c.setRequestProperty("X-App-Token",BuildConfig.APP_TOKEN);c.setRequestProperty("X-Requested-With","XMLHttpRequest");if(!data.isEmpty()){c.setRequestMethod("POST");c.setDoOutput(true);StringBuilder b=new StringBuilder();for(Map.Entry<String,String> e:data.entrySet()){if(b.length()>0)b.append('&');b.append(URLEncoder.encode(e.getKey(),"UTF-8")).append('=').append(URLEncoder.encode(e.getValue(),"UTF-8"));}byte[] z=b.toString().getBytes(StandardCharsets.UTF_8);c.setRequestProperty("Content-Type","application/x-www-form-urlencoded; charset=UTF-8");c.setFixedLengthStreamingMode(z.length);try(OutputStream o=c.getOutputStream()){o.write(z);}}int code=c.getResponseCode();InputStream in=code>=400?c.getErrorStream():c.getInputStream();String raw=read(in);c.disconnect();if(raw.trim().isEmpty())throw new IOException("Empty server response (HTTP "+code+")");try{return new JSONObject(raw);}catch(Exception e){throw new IOException("HTTP "+code+" invalid server response");}}
    String read(InputStream in)throws Exception{if(in==null)return"";BufferedReader r=new BufferedReader(new InputStreamReader(in,StandardCharsets.UTF_8));StringBuilder b=new StringBuilder();String l;while((l=r.readLine())!=null)b.append(l);return b.toString();}
    ArrayList<String> list(JSONArray a){ArrayList<String>x=new ArrayList<>();if(a!=null)for(int i=0;i<a.length();i++){String s=a.optString(i,"").trim();if(!s.isEmpty())x.add(s);}return x;}

    void goBack(){switch(currentScreen){case "grower":showMode();break;case "add_grower":showGrower();break;case "type":showGrower();break;case "variety":showType();break;case "lot":showVariety();break;case "qty":if(mode.equals("empty"))showType();else showLot();break;case "confirm":showQty();break;case "record_list":showMode();break;default:showMode();}}
    void redrawCurrent(){switch(currentScreen){case "grower":showGrower();break;case "add_grower":showAddGrower();break;case "type":showType();break;case "variety":showVariety();break;case "lot":showLot();break;case "qty":showQty();break;case "confirm":showConfirm();break;default:showMode();}}
    interface Pick{void go(String s);}void options(ArrayList<String> a,String selected,Pick p){ScrollView sv=new ScrollView(this);LinearLayout list=new LinearLayout(this);list.setOrientation(LinearLayout.VERTICAL);list.setGravity(Gravity.CENTER);sv.addView(list);body.addView(sv,new LinearLayout.LayoutParams(-1,0,1));for(String s:a){Button b=button((s.equals(selected)?"✓ ":"")+s,compactUi()?17:21);b.setBackgroundColor(s.equals(selected)?Color.rgb(30,64,175):PANEL);b.setOnClickListener(v->p.go(s));LinearLayout.LayoutParams lp=new LinearLayout.LayoutParams(-1,dp(compactUi()?58:72));lp.setMargins(0,dp(compactUi()?2:4),0,dp(compactUi()?2:4));list.addView(b,lp);}if(a.isEmpty())list.addView(text(t("No presets available yet","No hay presets disponibles"),compactUi()?17:20));}
    void question(String s){TextView q=text(s,compactUi()?23:32);q.setTypeface(null,1);q.setPadding(0,0,0,dp(compactUi()?7:16));body.addView(q);}
    void big(String s,int color,View.OnClickListener l){Button b=button(s,compactUi()?16:21);b.setBackgroundColor(color);b.setOnClickListener(l);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,dp(compactUi()?62:82));p.setMargins(0,dp(compactUi()?5:8),0,0);body.addView(b,p);}
    void homeBig(String s,int color,View.OnClickListener l){Button b=button(s,compactUi()?22:27);b.setTypeface(null,1);b.setBackgroundColor(color);b.setOnClickListener(l);LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(-1,dp(compactUi()?76:104));p.setMargins(0,dp(compactUi()?8:12),0,0);body.addView(b,p);}
    Button button(String s,int size){Button b=new Button(this);b.setText(s);b.setTextSize(size);b.setTextColor(Color.WHITE);b.setAllCaps(false);b.setBackgroundColor(Color.rgb(30,41,59));return b;}
    TextView text(String s,int size){TextView v=new TextView(this);v.setText(s);v.setTextSize(size);v.setTextColor(Color.WHITE);v.setGravity(Gravity.CENTER);return v;}
    EditText input(boolean numeric){EditText e=new EditText(this);e.setTextColor(Color.WHITE);e.setHintTextColor(Color.rgb(148,163,184));e.setTextSize(numeric?(compactUi()?34:42):(compactUi()?22:30));e.setGravity(Gravity.CENTER);e.setSingleLine(true);e.setBackgroundColor(PANEL);if(numeric)e.setInputType(InputType.TYPE_CLASS_NUMBER);return e;}
    boolean compactUi(){return getResources().getConfiguration().smallestScreenWidthDp<600;}
    void clear(){body.removeAllViews();}String t(String en,String es0){return es?es0:en;}int dp(int n){return(int)(n*getResources().getDisplayMetrics().density+.5f);}
}
