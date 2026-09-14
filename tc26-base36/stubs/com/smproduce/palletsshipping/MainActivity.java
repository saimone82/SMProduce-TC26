package com.smproduce.palletsshipping;
import android.app.Activity;
import android.widget.*;
import java.util.*;
import org.json.*;
/** Compile-time ABI only. Never included in the output DEX/APK. */
public class MainActivity extends Activity {
    enum Step { HOME, PALLET_MODE, PALLET_RESUME, PALLET_SCAN, PALLET_FINISH, PALLET_EDIT_ID, PALLET_EDIT_CASES, SHIP_MODE, SHIP_RESUME, SHIP_ORDER, SHIP_SCAN, SHIP_FINISH, DONE }
    interface Success { void run(JSONObject j); }
    boolean online, busy, spanish;
    String palletId, palletEditPassword;
    int caseCount;
    Step step;
    ArrayList<String> scannedCases;
    LinearLayout root, body, nav;
    Button back, next, lang;
    TextView network, counter, progress;
    void buildShell() {}
    void render() {}
    void checkNetwork() {}
    void setOnlineState(boolean b) {}
    void syncQueue() {}
    void onScan(String s) {}
    void goNext() {}
    void callUrl(String u,Map<String,String> p,Success s) {}
    void call(Map<String,String> p,Success s) {}
    void callScanOrQueue(Map<String,String> p,String t,String parent,String serial,Success s) {}
    JSONObject requestUrl(String u,Map<String,String> p) throws Exception {return null;}
    Map<String,String> map(String... s) {return null;}
    void requestPalletEditPassword() {}
    void openPalletForEdit(String s) {}
    void editPalletCase(String s) {}
    void palletEditScreen() {}
    void loadCases(JSONObject j) {}
    void resetHome() {}
    String tr(String e,String s) {return e;}
    int dp(int x) {return x;}
    TextView tv(String t,int s,int c) {return null;}
    Button button(String t,int c) {return null;}
    void heading(String a,String b) {}
    void addSpace(int n) {}
    void error(String s) {}
    void ok(String s) {}
    void done(String s) {}
}
