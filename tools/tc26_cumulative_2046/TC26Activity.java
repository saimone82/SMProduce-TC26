package com.smproduce.palletsshipping;
import android.app.Activity;
import android.graphics.drawable.Drawable;
import android.widget.*;
public class TC26Activity extends Activity {
    LinearLayout root,body,nav; Button lang,back,next; TextView network,subtitle,progress,counter,detail;
    ImageButton editPalletIcon; boolean spanish,online,busy; String shipmentId="";
    static class ScanIcon extends Drawable { public void draw(android.graphics.Canvas c){} public void setAlpha(int a){} public void setColorFilter(android.graphics.ColorFilter f){} public int getOpacity(){return -3;} }
    static class EditPalletIcon extends Drawable { public void draw(android.graphics.Canvas c){} public void setAlpha(int a){} public void setColorFilter(android.graphics.ColorFilter f){} public int getOpacity(){return -3;} }
    void buildShell(){} void scanPallet(String s){} void requestScanner(){} void requestPalletEditPassword(){}
    int dp(int n){return n;} String tr(String a,String b){return a;} TextView tv(String s,int z,int c){return null;} void error(String s){}
}
