package com.smproduce.palletsshipping;

import android.app.Activity;
import android.app.Dialog;
import android.graphics.Color;
import android.graphics.Rect;
import android.graphics.Typeface;
import android.graphics.drawable.ColorDrawable;
import android.graphics.drawable.GradientDrawable;
import android.view.*;
import android.widget.*;

/** Four-digit PIN pad: three wide keys per row, no alphabet or symbol pages. */
final class LargePasswordDialog {
    interface Submit { void run(String password, LargePasswordDialog dialog); }
    private static final int INK=Color.rgb(20,33,52),BLUE=Color.rgb(29,78,216),BG=Color.rgb(244,247,251);
    private final Activity activity;
    private final boolean spanish;
    final Dialog dialog;
    final LinearLayout content,keyboard;
    final TextView masked;
    final HorizontalScrollView maskScroll;
    final LinearLayout inputRow;
    private final PasswordBuffer buffer=new PasswordBuffer();
    private final Submit submit;
    private final Button accept;
    private final Runnable scrollEnd;
    private boolean dismissed;
    LargePasswordDialog(Activity a,boolean es,String title,String message,String confirm,boolean cancelable,
                        Submit callback,Runnable cancelled,Runnable onDismiss){
        activity=a;spanish=es;submit=callback;dialog=new Dialog(a);dialog.requestWindowFeature(Window.FEATURE_NO_TITLE);
        dialog.setCancelable(cancelable);dialog.setCanceledOnTouchOutside(false);
        dialog.setOnCancelListener(d->{if(cancelled!=null)cancelled.run();});
        Rect visible=new Rect();a.getWindow().getDecorView().getWindowVisibleDisplayFrame(visible);
        int screenHeight=visible.height()>0?visible.height():a.getResources().getDisplayMetrics().heightPixels;
        int availableDp=(int)(screenHeight/a.getResources().getDisplayMetrics().density);
        int keyHeight=Math.max(44,Math.min(68,(availableDp-256)/4));
        content=new LinearLayout(a);content.setOrientation(1);content.setPadding(dp(8),dp(10),dp(8),dp(10));content.setBackground(shape(BG,12));
        TextView heading=text(title,20);heading.setTypeface(null,Typeface.BOLD);heading.setMaxLines(2);content.addView(heading,block(-2));
        if(message!=null&&!message.isEmpty()){
            ScrollView info=new ScrollView(a);TextView note=text(message,15);note.setPadding(0,dp(5),0,dp(5));info.addView(note);
            content.addView(info,block(50));
        }
        inputRow=new LinearLayout(a);inputRow.setGravity(Gravity.CENTER_VERTICAL);inputRow.setBackground(shape(Color.WHITE,7));
        maskScroll=new HorizontalScrollView(a);maskScroll.setHorizontalScrollBarEnabled(false);maskScroll.setFillViewport(true);
        masked=text("",28);masked.setSingleLine(true);masked.setGravity(Gravity.CENTER_VERTICAL);masked.setPadding(dp(10),0,dp(10),0);
        masked.setTextIsSelectable(false);maskScroll.addView(masked,new FrameLayout.LayoutParams(-2,-1));
        inputRow.addView(maskScroll,new LinearLayout.LayoutParams(0,dp(54),1));
        Button clear=key("×",()->{buffer.clear();refresh();});clear.setContentDescription(tr("Clear PIN","Borrar PIN"));inputRow.addView(clear,new LinearLayout.LayoutParams(dp(50),dp(50)));
        LinearLayout.LayoutParams field=block(56);field.topMargin=dp(8);field.bottomMargin=dp(8);content.addView(inputRow,field);
        scrollEnd=()->maskScroll.fullScroll(View.FOCUS_RIGHT);
        keyboard=new LinearLayout(a);keyboard.setOrientation(1);
        for(int row=0;row<4;row++){
            LinearLayout line=new LinearLayout(a);
            for(int col=0;col<3;col++){
                int index=row*3+col;
                Button button;
                if(index==9){button=key(tr("Clear","Borrar"),()->{buffer.clear();refresh();});button.setTextSize(17);}
                else if(index==11){
                    button=key("⌫",()->{buffer.erase();refresh();});
                    button.setContentDescription(tr("Delete last digit","Borrar último dígito"));
                    button.setOnLongClickListener(v->{buffer.clear();refresh();return true;});
                }else{
                    final String digit=index==10?"0":Integer.toString(index+1);
                    button=key(digit,()->typeDigit(digit));button.setTextSize(30);
                }
                line.addView(button,cell(keyHeight));
            }keyboard.addView(line);
        }content.addView(keyboard,block(-2));
        LinearLayout actions=new LinearLayout(a);
        Button cancel=key(tr("Cancel","Cancelar"),()->{dismiss();if(cancelled!=null)cancelled.run();});cancel.setTextSize(17);
        accept=key(confirm,this::submit);accept.setTextSize(17);accept.setTextColor(Color.WHITE);accept.setBackground(shape(BLUE,7));
        actions.addView(cancel,cell(50));actions.addView(accept,cell(50));LinearLayout.LayoutParams actionLp=block(-2);actionLp.topMargin=dp(6);content.addView(actions,actionLp);
        dialog.setContentView(content);Window window=dialog.getWindow();window.setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
        window.setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_STATE_ALWAYS_HIDDEN);window.addFlags(WindowManager.LayoutParams.FLAG_ALT_FOCUSABLE_IM);
        dialog.setOnDismissListener(d->{dismissed=true;buffer.clear();masked.setText("");maskScroll.removeCallbacks(scrollEnd);if(onDismiss!=null)onDismiss.run();});
        dialog.setOnKeyListener((d,code,event)->{
            if(code==KeyEvent.KEYCODE_BACK)return false;
            if(event.getAction()!=KeyEvent.ACTION_DOWN)return code==KeyEvent.KEYCODE_DEL||code==KeyEvent.KEYCODE_ENTER||event.getUnicodeChar()>0;
            if(code==KeyEvent.KEYCODE_DEL){buffer.erase();refresh();return true;}
            if(code==KeyEvent.KEYCODE_ENTER||code==KeyEvent.KEYCODE_NUMPAD_ENTER){if(event.getRepeatCount()==0)submit();return true;}
            int ch=event.getUnicodeChar();if(ch>='0'&&ch<='9'){typeDigit(Character.toString((char)ch));return true;}return ch>0;
        });
        refresh();
    }
    void show(){dialog.show();int width=activity.getResources().getDisplayMetrics().widthPixels-dp(8);dialog.getWindow().setLayout(Math.min(width,dp(520)),WindowManager.LayoutParams.WRAP_CONTENT);}
    void dismiss(){dialog.dismiss();}
    boolean isShowing(){return !dismissed&&dialog.isShowing();}
    private void submit(){if(isShowing()&&buffer.count()==4)submit.run(buffer.password(),this);}
    private void typeDigit(String digit){if(buffer.count()<4&&digit.matches("[0-9]")){buffer.append(digit);refresh();}}
    private void refresh(){
        masked.setText(buffer.masked()+"│");
        masked.setContentDescription(tr("PIN digits: ","Dígitos del PIN: ")+buffer.count()+" / 4");
        accept.setEnabled(buffer.count()==4);accept.setAlpha(buffer.count()==4?1f:.45f);
        if(scrollEnd!=null){maskScroll.removeCallbacks(scrollEnd);maskScroll.post(scrollEnd);}
    }
    private Button key(String value,Runnable action){
        Button b=new Button(activity);b.setText(value);b.setTextSize(24);b.setTextColor(INK);b.setAllCaps(false);b.setPadding(0,0,0,0);b.setMinWidth(0);b.setMinimumWidth(0);b.setMinHeight(0);b.setMinimumHeight(0);b.setBackground(shape(Color.WHITE,6));b.setOnClickListener(v->action.run());return b;
    }
    private TextView text(String value,int size){TextView t=new TextView(activity);t.setText(value);t.setTextSize(size);t.setTextColor(INK);return t;}
    private GradientDrawable shape(int color,int radius){GradientDrawable d=new GradientDrawable();d.setColor(color);d.setCornerRadius(dp(radius));d.setStroke(dp(1),Color.rgb(202,212,225));return d;}
    private LinearLayout.LayoutParams cell(int h){LinearLayout.LayoutParams p=new LinearLayout.LayoutParams(0,dp(h),1);p.setMargins(dp(1),dp(2),dp(1),dp(2));return p;}
    private LinearLayout.LayoutParams block(int h){return new LinearLayout.LayoutParams(-1,h<0?h:dp(h));}
    private int dp(int n){return Math.round(n*activity.getResources().getDisplayMetrics().density);}
    private String tr(String en,String es){return spanish?es:en;}
}
