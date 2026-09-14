package com.smproduce.palletsshipping;
import android.app.*;
import android.content.*;
import android.view.*;
import android.widget.*;
import org.json.*;
import java.util.*;

/** Intercepts only Complete/save-close calls; the original success flow is retained. */
public final class PalletCompleteDialog {
    private final MainActivity host;private final String url,pid;private final Map<String,String> params;private final MainActivity.Success success;
    private boolean finished;private LargePasswordDialog password;
    private interface Reply{void done(JSONObject value,String error);}
    private PalletCompleteDialog(MainActivity h,String u,Map<String,String> p,MainActivity.Success s){host=h;url=u;params=new HashMap<String,String>(p);pid=p.get("pallet_id");success=s;}
    public static boolean intercept(MainActivity h,String url,Map<String,String> p,MainActivity.Success s){
        String action=p.get("action");if(!"pallet_close".equals(action)&&!"pallet_save_no_print".equals(action))return false;
        if(h.busy)return true;h.busy=true;new PalletCompleteDialog(h,url,p,s).load();return true;
    }
    private String tr(String en,String es){return host.tr(en,es);}
    private void finish(){if(finished)return;finished=true;if(password!=null)password.dismiss();host.busy=false;if(!host.isFinishing())host.render();}
    private void request(final Map<String,String> data,final Reply reply){
        final ProgressDialog progress=new ProgressDialog(host);progress.setMessage(tr("Checking pallet…","Comprobando pallet…"));progress.setCancelable(false);progress.show();
        host.io.execute(new Runnable(){public void run(){JSONObject value=null;String error=null;try{value=host.requestUrl(url,data);}catch(Exception e){error=tr("Unable to confirm the server response. Check the pallet status before retrying.","No se pudo confirmar la respuesta. Revise el estado del pallet antes de reintentar.");}
            final JSONObject result=value;final String problem=error;host.runOnUiThread(new Runnable(){public void run(){progress.dismiss();if(finished||host.isFinishing())return;reply.done(result,problem);}});
        }});
    }
    private void load(){
        Map<String,String> data=host.map("action","pallet_complete_context","pallet_id",pid);
        if(params.containsKey("complete_client_id"))data.put("complete_client_id",params.get("complete_client_id"));
        request(data,new Reply(){public void done(JSONObject j,String error){if(error!=null){failure(error);return;}if(j.optInt("ok")!=1){failure(j.optString("err"));return;}review(j);}});
    }
    private void review(final JSONObject context){
        if(context.optBoolean("needs_customer")){chooseCustomer(context.optJSONArray("customers"));return;}
        params.put("complete_client_id",context.optString("client_id","0"));params.put("complete_source_token",context.optString("source_token"));
        if(!context.optBoolean("override_required")){send("");return;}
        JSONArray checks=context.optJSONArray("mismatches");StringBuilder message=new StringBuilder("Pallet "+pid+"\n"+context.optString("customer_name")+"\n");
        if(checks!=null)for(int i=0;i<checks.length();i++){JSONObject c=checks.optJSONObject(i);if(c!=null)message.append("SKU ").append(c.optInt("sku_id")).append(": ").append(c.optInt("actual")).append(tr(" cases; required "," cajas; requeridas ")).append(c.optInt("required")).append("\n");}
        message.append(tr("\nEnter the override password to close Complete, or cancel to correct the pallet.","\nIntroduzca la contraseña de anulación para cerrar Completo, o cancele para corregir el pallet."));
        final AlertDialog warning=new AlertDialog.Builder(host).setTitle(tr("Case count mismatch","Cantidad de cajas incorrecta")).setMessage(message.toString())
            .setNegativeButton(tr("Back to pallet","Volver al pallet"),new DialogInterface.OnClickListener(){public void onClick(DialogInterface d,int w){finish();}})
            .setPositiveButton(tr("Override with password","Anular con contraseña"),new DialogInterface.OnClickListener(){public void onClick(DialogInterface d,int w){showPassword(context);}}).create();warning.setCancelable(false);warning.show();
    }
    private void showPassword(final JSONObject context){
        String summary="Pallet "+pid+" · "+context.optInt("total_cases")+tr(" cases"," cajas");
        password=new LargePasswordDialog(host,host.spanish,tr("Complete override","Anular cierre completo"),summary,tr("Close Complete","Cerrar Completo"),true,
            new LargePasswordDialog.Submit(){public void run(String value,LargePasswordDialog dialog){dialog.dismiss();send(value);}},new Runnable(){public void run(){finish();}},new Runnable(){public void run(){password=null;}});
        password.show();
    }
    private void chooseCustomer(final JSONArray customers){
        if(customers==null||customers.length()==0){failure(tr("Configure customers in the webapp first.","Configure los clientes en la webapp primero."));return;}
        LinearLayout body=new LinearLayout(host);body.setOrientation(1);body.setPadding(host.dp(18),host.dp(8),host.dp(18),host.dp(8));
        TextView info=new TextView(host);info.setText(tr("This pallet has no assigned customer. Select its customer before checking the Complete rule.","Este pallet no tiene cliente asignado. Selecciónelo para comprobar la regla de cierre completo."));info.setTextSize(17);body.addView(info);
        final Spinner list=new Spinner(host);ArrayList<String> names=new ArrayList<String>();names.add(tr("Choose customer","Seleccione cliente"));for(int i=0;i<customers.length();i++)names.add(customers.optJSONObject(i).optString("client_name"));
        ArrayAdapter<String> adapter=new ArrayAdapter<String>(host,android.R.layout.simple_spinner_item,names);adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);list.setAdapter(adapter);body.addView(list,new LinearLayout.LayoutParams(-1,host.dp(56)));
        final AlertDialog d=new AlertDialog.Builder(host).setTitle(tr("Pallet customer","Cliente del pallet")).setView(body).setNegativeButton(tr("Cancel","Cancelar"),new DialogInterface.OnClickListener(){public void onClick(DialogInterface x,int w){finish();}}).setPositiveButton(tr("Continue","Continuar"),null).create();d.setCancelable(false);d.show();
        d.getButton(-1).setOnClickListener(new View.OnClickListener(){public void onClick(View v){int index=list.getSelectedItemPosition()-1;if(index<0){Toast.makeText(host,tr("Choose a customer","Seleccione un cliente"),Toast.LENGTH_SHORT).show();return;}params.put("complete_client_id",customers.optJSONObject(index).optString("id"));d.dismiss();load();}});
    }
    private void send(String value){
        final Map<String,String> data=new HashMap<String,String>(params);if(value.length()>0)data.put("complete_password",value);
        request(data,new Reply(){public void done(JSONObject j,String error){data.remove("complete_password");if(error!=null){failure(error);return;}
            if(j.optInt("ok")!=1){final JSONObject context=j.optJSONObject("complete_context");if(context!=null){new AlertDialog.Builder(host).setTitle(tr("Pallet still open","El pallet sigue abierto")).setMessage(j.optString("err")).setPositiveButton(tr("Review again","Revisar de nuevo"),new DialogInterface.OnClickListener(){public void onClick(DialogInterface d,int w){load();}}).setNegativeButton(tr("Cancel","Cancelar"),new DialogInterface.OnClickListener(){public void onClick(DialogInterface d,int w){finish();}}).setCancelable(false).show();}else failure(j.optString("err"));return;}
            finished=true;host.busy=false;success.run(j);
        }});
    }
    private void failure(String message){new AlertDialog.Builder(host).setTitle(tr("Pallet Complete","Cerrar pallet")).setMessage(message).setPositiveButton(tr("Reload","Recargar"),new DialogInterface.OnClickListener(){public void onClick(DialogInterface d,int w){load();}}).setNegativeButton(tr("Back","Atrás"),new DialogInterface.OnClickListener(){public void onClick(DialogInterface d,int w){finish();}}).setCancelable(false).show();}
}
