package com.smproduce.palletsshipping;

import android.app.AlertDialog;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.*;
import android.graphics.drawable.Drawable;
import android.text.InputType;
import android.view.*;
import android.widget.*;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/** TC26-only cumulative UI: Palletizing + Shipping + full Modify Pallet + full Scanner. */
public class Tc26MainActivity extends MainActivity {
    private static final int EDIT_LOOKUP=0, EDIT_ADD=1, EDIT_REMOVE=2;
    private static final int SCANNER_REQUEST=2460;
    private int editMode=EDIT_LOOKUP;
    private boolean editDirty=false;
    private String modifyPassword="";
    private String loadedPalletStatus="";

    private SharedPreferences modifyPrefs(){ return getSharedPreferences("tc26_modify_pallet",MODE_PRIVATE); }

    @Override Map<String,String> map(String... values){
        Map<String,String> p=super.map(values); p.put("app_version","2.0.46-tc26"); return p;
    }

    @Override void buildShell(){
        root=new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL); root.setBackgroundColor(Color.rgb(11,22,34));
        LinearLayout header=new LinearLayout(this); header.setOrientation(LinearLayout.VERTICAL); header.setPadding(dp(10),dp(7),dp(8),dp(5)); header.setBackgroundColor(Color.rgb(19,32,51));

        LinearLayout top=new LinearLayout(this); top.setGravity(Gravity.CENTER_VERTICAL);
        network=tv("",11,Color.LTGRAY); network.setGravity(Gravity.START|Gravity.CENTER_VERTICAL); network.setPadding(0,0,0,0);
        top.addView(network,new LinearLayout.LayoutParams(dp(86),dp(46)));
        TextView app=tv("Pallet Shipping",19,Color.WHITE); app.setTypeface(null,1); app.setGravity(Gravity.CENTER); app.setSingleLine(true); app.setPadding(0,0,0,0);
        top.addView(app,new LinearLayout.LayoutParams(0,dp(46),1));

        ImageButton scanner=new ImageButton(this); scanner.setImageDrawable(new ScannerIcon()); scanner.setBackgroundColor(Color.TRANSPARENT); scanner.setPadding(dp(8),dp(8),dp(8),dp(8)); scanner.setContentDescription(tr("Scanner","Escáner")); scanner.setOnClickListener(v->requestScanner());
        top.addView(scanner,new LinearLayout.LayoutParams(dp(44),dp(44)));
        ImageButton modify=new ImageButton(this); modify.setImageDrawable(new ModifyIcon()); modify.setBackgroundColor(Color.TRANSPARENT); modify.setPadding(dp(7),dp(7),dp(7),dp(7)); modify.setContentDescription(tr("Modify Existing Pallet","Modificar pallet existente")); modify.setOnClickListener(v->requestPalletEditPassword());
        top.addView(modify,new LinearLayout.LayoutParams(dp(44),dp(44)));
        header.addView(top,new LinearLayout.LayoutParams(-1,dp(46)));

        LinearLayout utility=new LinearLayout(this); utility.setGravity(Gravity.CENTER_VERTICAL);
        back=button(tr("Back","Atrás"),Color.rgb(52,65,85)); back.setTextSize(13); back.setOnClickListener(v->goBack()); utility.addView(back,new LinearLayout.LayoutParams(dp(92),dp(34)));
        Space gap=new Space(this); utility.addView(gap,new LinearLayout.LayoutParams(0,1,1));
        lang=button("EN",Color.rgb(25,118,210)); lang.setTextSize(12); lang.setOnClickListener(v->{spanish=!spanish;lang.setText(spanish?"ES":"EN");render();}); utility.addView(lang,new LinearLayout.LayoutParams(dp(54),dp(34)));
        header.addView(utility,new LinearLayout.LayoutParams(-1,dp(36)));
        root.addView(header);

        progress=tv("",12,Color.rgb(110,140,170)); progress.setPadding(dp(18),dp(10),dp(18),0); root.addView(progress);
        nav=new LinearLayout(this); nav.setPadding(dp(14),dp(8),dp(14),dp(12)); nav.setGravity(Gravity.CENTER);
        next=button("Next",Color.rgb(25,118,210)); next.setOnClickListener(v->goNext()); nav.addView(next,new LinearLayout.LayoutParams(-1,dp(56))); root.addView(nav);
        body=new LinearLayout(this); body.setOrientation(LinearLayout.VERTICAL); body.setGravity(Gravity.CENTER_HORIZONTAL); body.setPadding(dp(18),dp(12),dp(18),dp(12));
        ScrollView scroll=new ScrollView(this); scroll.setFillViewport(true); scroll.addView(body,new ScrollView.LayoutParams(-1,-2)); root.addView(scroll,new LinearLayout.LayoutParams(-1,0,1));
        setContentView(root);
    }

    static final class ScannerIcon extends Drawable {
        final Paint p=new Paint(Paint.ANTI_ALIAS_FLAG);
        ScannerIcon(){p.setStyle(Paint.Style.STROKE);p.setStrokeCap(Paint.Cap.ROUND);p.setStrokeJoin(Paint.Join.ROUND);}
        @Override public void draw(Canvas c){Rect b=getBounds();c.save();c.translate(b.left,b.top);c.scale(b.width()/32f,b.height()/32f);p.setColor(Color.WHITE);p.setStrokeWidth(2.0f);
            c.drawLine(3,9,3,4,p);c.drawLine(3,4,8,4,p);c.drawLine(24,4,29,4,p);c.drawLine(29,4,29,9,p);c.drawLine(3,23,3,28,p);c.drawLine(3,28,8,28,p);c.drawLine(24,28,29,28,p);c.drawLine(29,23,29,28,p);
            int[] xs={8,11,14,18,21,24};for(int x:xs)c.drawLine(x,9,x,23,p);c.restore();}
        @Override public void setAlpha(int a){p.setAlpha(a);} @Override public void setColorFilter(ColorFilter f){p.setColorFilter(f);} @Override public int getOpacity(){return PixelFormat.TRANSLUCENT;}
    }
    static final class ModifyIcon extends Drawable {
        final Paint p=new Paint(Paint.ANTI_ALIAS_FLAG);
        ModifyIcon(){p.setStyle(Paint.Style.STROKE);p.setStrokeCap(Paint.Cap.ROUND);p.setStrokeJoin(Paint.Join.ROUND);}
        @Override public void draw(Canvas c){Rect b=getBounds();c.save();c.translate(b.left,b.top);c.scale(b.width()/32f,b.height()/32f);p.setColor(Color.WHITE);p.setStrokeWidth(2.0f);
            RectF pallet=new RectF(3,13,19,25);c.drawRoundRect(pallet,2,2,p);c.drawLine(3,19,19,19,p);c.drawLine(8,13,8,25,p);c.drawLine(14,13,14,25,p);c.drawLine(2,27,21,27,p);
            c.save();c.rotate(-42,24,9);RectF pencil=new RectF(22,3,26,15);c.drawRoundRect(pencil,1,1,p);c.drawLine(22,12,26,12,p);c.drawLine(22,15,24,18,p);c.drawLine(24,18,26,15,p);c.restore();c.restore();}
        @Override public void setAlpha(int a){p.setAlpha(a);} @Override public void setColorFilter(ColorFilter f){p.setColorFilter(f);} @Override public int getOpacity(){return PixelFormat.TRANSLUCENT;}
    }

    private boolean safeToolState(){return !busy&&(step==Step.HOME||step==Step.PALLET_MODE||step==Step.SHIP_MODE||step==Step.DONE);}

    void requestScanner(){
        if(!online){error(tr("Connect before opening Scanner","Conéctese antes de abrir el Escáner"));return;}
        if(!safeToolState()){error(tr("Finish the current operation or return to Home first.","Termine la operación actual o vuelva al inicio primero."));return;}
        final EditText input=new EditText(this); input.setInputType(InputType.TYPE_CLASS_NUMBER|InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        final AlertDialog d=new AlertDialog.Builder(this).setTitle(tr("Scanner password","Contraseña del escáner")).setMessage(tr("Enter the protected PIN","Introduzca el PIN protegido")).setView(input).setNegativeButton(tr("Cancel","Cancelar"),null).setPositiveButton(tr("Open","Abrir"),null).create();
        d.setOnShowListener(x->d.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v->{String pin=input.getText().toString().trim();if(pin.isEmpty())return;call(map("action","scanner_unlock","password",pin),j->{d.dismiss();Intent i=new Intent(this,ScannerActivity.class);i.putExtra("scanner_password",pin);startActivityForResult(i,SCANNER_REQUEST);});})); d.show();
    }

    @Override void requestPalletEditPassword(){
        if(!online){error(tr("Connect before modifying a pallet","Conéctese antes de modificar un pallet"));return;}
        if(!safeToolState()){error(tr("Finish the current operation or return to Home first.","Termine la operación actual o vuelva al inicio primero."));return;}
        final EditText input=new EditText(this); input.setInputType(InputType.TYPE_CLASS_NUMBER|InputType.TYPE_NUMBER_VARIATION_PASSWORD);
        final AlertDialog d=new AlertDialog.Builder(this).setTitle(tr("Modify Pallet","Modificar pallet")).setMessage(tr("Enter the protected PIN","Introduzca el PIN protegido")).setView(input).setNegativeButton(tr("Cancel","Cancelar"),null).setPositiveButton(tr("Continue","Continuar"),null).create();
        d.setOnShowListener(x->d.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v->{String pin=input.getText().toString().trim();if(pin.isEmpty())return;callUrl(BuildConfig.MODIFY_API_URL,map("action","verify_password","password",pin),j->{modifyPassword=pin;palletEditPassword=pin;editMode=EDIT_LOOKUP;editDirty=false;d.dismiss();step=Step.PALLET_EDIT_ID;render();});}));d.show();
    }

    @Override void openPalletForEdit(String id){
        callUrl(BuildConfig.MODIFY_API_URL,map("action","edit_open","pallet_id",id,"password",modifyPassword),j->{palletId=j.optString("pallet_id");caseCount=j.optInt("cases_count");loadedPalletStatus=j.optString("status","OPEN").toUpperCase();loadCases(j);editMode=EDIT_LOOKUP;editDirty=false;step=Step.PALLET_EDIT_CASES;render();});
    }

    @Override void palletEditScreen(){
        heading(tr("Modify pallet","Modificar pallet"),palletId);
        counter=tv(caseCount+" "+tr("CASES","CAJAS"),34,Color.rgb(102,187,106));counter.setGravity(Gravity.CENTER);counter.setTypeface(null,1);body.addView(counter);
        String mt;int mc;if(editMode==EDIT_ADD){mt=tr("ADD MODE — scan cases to add","MODO AÑADIR — escanee cajas para añadir");mc=Color.rgb(134,239,172);}else if(editMode==EDIT_REMOVE){mt=tr("REMOVE MODE — scan cases to remove","MODO ELIMINAR — escanee cajas para eliminar");mc=Color.rgb(248,113,113);}else{mt=tr("LOOKUP MODE — scan a case to switch to its pallet","MODO BÚSQUEDA — escanee una caja para cambiar a su pallet");mc=Color.rgb(147,197,253);}TextView mode=tv(mt,15,mc);mode.setGravity(Gravity.CENTER);body.addView(mode);
        TextView state=tv(tr("Current status: ","Estado actual: ")+loadedPalletStatus,14,Color.LTGRAY);state.setGravity(Gravity.CENTER);body.addView(state);addSpace(8);addCameraButtons();
        LinearLayout modes=new LinearLayout(this);modes.setOrientation(LinearLayout.HORIZONTAL);Button lookup=button(tr("LOOKUP","BUSCAR"),editMode==EDIT_LOOKUP?Color.rgb(30,64,175):Color.rgb(52,65,85));Button add=button(tr("+ ADD","+ AÑADIR"),editMode==EDIT_ADD?Color.rgb(22,101,52):Color.rgb(52,65,85));Button rem=button(tr("− REMOVE","− QUITAR"),editMode==EDIT_REMOVE?Color.rgb(185,28,28):Color.rgb(52,65,85));lookup.setOnClickListener(v->{editMode=EDIT_LOOKUP;render();});add.setOnClickListener(v->{editMode=EDIT_ADD;render();});rem.setOnClickListener(v->{editMode=EDIT_REMOVE;render();});modes.addView(lookup,new LinearLayout.LayoutParams(0,dp(52),1));modes.addView(add,new LinearLayout.LayoutParams(0,dp(52),1));modes.addView(rem,new LinearLayout.LayoutParams(0,dp(52),1));body.addView(modes);
        TextView lt=tv(tr("CASES ON PALLET","CAJAS EN EL PALLET"),13,Color.LTGRAY);lt.setTypeface(null,1);body.addView(lt);LinearLayout list=new LinearLayout(this);list.setOrientation(LinearLayout.VERTICAL);list.setPadding(dp(14),dp(8),dp(14),dp(8));list.setBackgroundColor(Color.rgb(19,32,51));if(scannedCases.isEmpty())list.addView(tv(tr("No cases on this pallet","No hay cajas en este pallet"),14,Color.LTGRAY));else for(int i=scannedCases.size()-1;i>=0;i--)list.addView(tv("• "+scannedCases.get(i),15,Color.WHITE));body.addView(list);addSpace(10);
        LinearLayout status=new LinearLayout(this);status.setOrientation(LinearLayout.HORIZONTAL);Button open=button("OPEN",Color.rgb(37,99,235));Button partial=button("PARTIAL",Color.rgb(180,83,9));Button complete=button("COMPLETE",Color.rgb(22,101,52));Button del=button("DELETE",Color.rgb(185,28,28));for(Button b:new Button[]{open,partial,complete,del})b.setTextSize(11);open.setOnClickListener(v->saveOpen());partial.setOnClickListener(v->showSaveChoice("PARTIAL"));complete.setOnClickListener(v->showSaveChoice("COMPLETE"));del.setOnClickListener(v->showDeletePallet());status.addView(open,new LinearLayout.LayoutParams(0,dp(52),1));status.addView(partial,new LinearLayout.LayoutParams(0,dp(52),1));status.addView(complete,new LinearLayout.LayoutParams(0,dp(52),1));status.addView(del,new LinearLayout.LayoutParams(0,dp(52),1));body.addView(status);
        addSpace(10);Button another=button(tr("SELECT ANOTHER PALLET","SELECCIONAR OTRO PALLET"),Color.rgb(52,65,85));another.setOnClickListener(v->{if(editDirty){error(tr("Save the current pallet before switching","Guarde el pallet actual antes de cambiar"));return;}palletId="";caseCount=0;scannedCases.clear();step=Step.PALLET_EDIT_ID;render();});body.addView(another,new LinearLayout.LayoutParams(-1,dp(54)));
    }

    @Override void editPalletCase(String code){
        if(!online){error(tr("Editing requires a connection","La modificación requiere conexión"));return;}if(editMode==EDIT_LOOKUP){lookupCaseAndSwitch(code);return;}String mode=editMode==EDIT_REMOVE?"REMOVE":"ADD";callUrl(BuildConfig.MODIFY_API_URL,map("action","edit_case","pallet_id",palletId,"case_serial",code,"mode",mode,"password",modifyPassword),j->{caseCount=j.optInt("cases_count");loadedPalletStatus=j.optString("status","OPEN").toUpperCase();loadCases(j);editDirty=true;ok((editMode==EDIT_REMOVE?tr("Case removed: ","Caja eliminada: "):tr("Case added: ","Caja añadida: "))+code);render();});
    }
    private void lookupCaseAndSwitch(String code){callUrl(BuildConfig.MODIFY_API_URL,map("action","lookup_case","case_serial",code,"password",modifyPassword),j->{String target=j.optString("pallet_id","");if(target.isEmpty()){error(tr("This case is not assigned to a pallet","Esta caja no está asignada a un pallet"));return;}if(target.equalsIgnoreCase(palletId)){Toast.makeText(this,tr("Case is on the current pallet","La caja está en el pallet actual")+" "+palletId,Toast.LENGTH_SHORT).show();return;}if(editDirty){error(tr("Save the current pallet before switching","Guarde el pallet actual antes de cambiar"));return;}openPalletForEdit(target);});}

    private void showSaveChoice(String status){String[] choices={tr("SAVE ONLY","SOLO GUARDAR"),tr("PRINT LABEL","IMPRIMIR ETIQUETA")};new AlertDialog.Builder(this).setTitle(tr("Save pallet as ","Guardar pallet como ")+status).setItems(choices,(d,w)->{if(w==0)saveStatus(status,false,0);else choosePrinter(status);}).setNegativeButton(tr("Cancel","Cancelar"),null).show();}
    private void choosePrinter(String status){callUrl(BuildConfig.MODIFY_API_URL,map("action","printers"),j->{JSONArray a=j.optJSONArray("printers");if(a==null||a.length()==0){error(tr("No active pallet label printers are configured in the webapp","No hay impresoras de etiquetas activas configuradas en la webapp"));return;}List<String> names=new ArrayList<>();List<Integer> ids=new ArrayList<>();int remembered=modifyPrefs().getInt("last_printer_id",0),checked=-1;for(int i=0;i<a.length();i++){JSONObject p=a.optJSONObject(i);if(p==null)continue;int id=p.optInt("id");ids.add(id);names.add(p.optString("name","Printer "+id));if(id==remembered)checked=ids.size()-1;}AlertDialog d=new AlertDialog.Builder(this).setTitle(tr("Choose printer","Seleccione impresora")).setSingleChoiceItems(names.toArray(new String[0]),checked,null).setNegativeButton(tr("Cancel","Cancelar"),null).setPositiveButton(tr("PRINT","IMPRIMIR"),null).create();d.setOnShowListener(x->d.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v->{int pos=d.getListView().getCheckedItemPosition();if(pos<0||pos>=ids.size()){Toast.makeText(this,tr("Choose a printer","Seleccione una impresora"),Toast.LENGTH_SHORT).show();return;}int id=ids.get(pos);modifyPrefs().edit().putInt("last_printer_id",id).apply();d.dismiss();saveStatus(status,true,id);}));d.show();});}

    private void saveOpen(){callUrl(BuildConfig.MODIFY_API_URL,map("action","set_status","pallet_id",palletId,"status","OPEN","print_label","0","password",modifyPassword),j->done("Pallet "+palletId+" → OPEN"));}
    private void saveStatus(String status,boolean print,int printerId){
        if("COMPLETE".equals(status)){saveComplete(print,printerId);return;}
        callUrl(BuildConfig.MODIFY_API_URL,map("action","set_status","pallet_id",palletId,"status",status,"print_label","0","password",modifyPassword),j->{if(print)printExisting(status,printerId);else done("Pallet "+palletId+" → "+status+tr(" · saved without printing"," · guardado sin imprimir"));});
    }
    private void saveComplete(boolean print,int printerId){
        Map<String,String> params=map("action","pallet_save_no_print","pallet_id",palletId,"print_label","0");
        Success success=j->{if(print)printExisting("COMPLETE",printerId);else done("Pallet "+palletId+" → COMPLETE"+tr(" · saved without printing"," · guardado sin imprimir"));};
        if(!PalletCompleteDialog.intercept(this,BuildConfig.API_URL,params,success))call(params,success);
    }
    private void printExisting(String status,int printerId){callUrl(BuildConfig.MODIFY_API_URL,map("action","print_label","pallet_id",palletId,"status",status,"printer_id",String.valueOf(printerId),"password",modifyPassword),j->done("Pallet "+palletId+" → "+status+tr(" · label sent"," · etiqueta enviada")));}

    private void showDeletePallet(){new AlertDialog.Builder(this).setTitle(tr("DELETE pallet","ELIMINAR pallet")).setMessage(tr("Delete pallet ","Eliminar pallet ")+palletId+"?\n\n"+caseCount+" "+tr("cases will be released and can be palletized again. Casecodes are not deleted.","cajas quedarán libres y podrán paletizarse de nuevo. Los casecodes no se eliminan.")).setNegativeButton(tr("Cancel","Cancelar"),null).setPositiveButton("DELETE",(d,w)->askDeletePassword()).show();}
    private void askDeletePassword(){EditText input=new EditText(this);input.setInputType(InputType.TYPE_CLASS_NUMBER|InputType.TYPE_NUMBER_VARIATION_PASSWORD);AlertDialog d=new AlertDialog.Builder(this).setTitle(tr("DELETE protected","ELIMINACIÓN protegida")).setMessage(tr("Enter the protected PIN to delete ","Introduzca el PIN protegido para eliminar ")+palletId).setView(input).setNegativeButton(tr("Cancel","Cancelar"),null).setPositiveButton("DELETE",null).create();d.setOnShowListener(x->d.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v->{String pin=input.getText().toString().trim();if(pin.isEmpty())return;callUrl(BuildConfig.MODIFY_API_URL,map("action","delete_pallet","pallet_id",palletId,"password",pin),j->{d.dismiss();done(tr("Pallet deleted. Cases are available again.","Pallet eliminado. Las cajas están disponibles de nuevo."));});}));d.show();}

    @Override protected void onActivityResult(int request,int result,Intent data){super.onActivityResult(request,result,data);if(request==SCANNER_REQUEST)render();}
    @Override void resetHome(){editMode=EDIT_LOOKUP;editDirty=false;modifyPassword="";loadedPalletStatus="";super.resetHome();}
}
