package com.smproduce.palletsshipping;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.AlertDialog;
import android.content.BroadcastReceiver;
import android.content.DialogInterface;
import android.content.DialogInterface;
import android.content.Intent;
import android.content.IntentFilter;
import android.graphics.Color;
import android.media.ToneGenerator;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.os.Build;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.provider.Settings;
import android.text.TextUtils;
import android.view.KeyEvent;
import android.view.View;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.Space;
import android.widget.TextView;
import android.widget.Toast;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Iterator;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

public class MainActivity extends Activity {
    Button back;
    LinearLayout body;
    TextView counter;
    TextView detail;
    Button lang;
    EditText manual;
    LinearLayout nav;
    TextView network;
    BroadcastReceiver networkReceiver;
    Button next;
    TextView progress;
    MainActivity$QueueDb queue;
    LinearLayout root;
    BroadcastReceiver scannerReceiver;
    JSONObject selectedOrder;
    TextView subtitle;
    TextView title;
    MainActivity$Step step = MainActivity$Step.HOME;
    boolean spanish = false;
    boolean online = false;
    boolean busy = false;
    String palletId = "";
    String palletVariety = "";
    String palletSize = "";
    String palletPackaging = "";
    String pendingMismatchSerial = "";
    JSONObject pendingMismatchResponse = null;
    String shipmentId = "";
    int caseCount = 0;
    int palletCount = 0;
    int shipmentCases = 0;
    JSONArray selectedOrders = new JSONArray();
    boolean multiPo = false;
    boolean shipmentMismatch = false;
    String shipmentCompareMessage = "";
    String scanErrorMessage = "";
    JSONArray shipmentSkuLines = new JSONArray();
    boolean casesExpanded = false;
    boolean palletEditRemove = false;
    String palletEditPassword = "";
    String ownershipOverridePassword = "";
    final ArrayList<String> scannedCases = new ArrayList<>();
    final ExecutorService io = Executors.newSingleThreadExecutor();
    final ToneGenerator tone = new ToneGenerator(5, 90);
    final StringBuilder keyScanBuffer = new StringBuilder();
    long lastKeyAt = 0;
    String lastScanCode = "";
    long lastScanAt = 0;
    boolean probing = false;
    final Handler healthHandler = new Handler(Looper.getMainLooper());
    final Runnable healthRunnable = new MainActivity$1(this);

    static void lambda$showOrders$53(boolean[] zArr, DialogInterface dialogInterface, int i, boolean z) {
        zArr[i] = z;
    }

    void addSpace(int i) {
        this.body.addView(new Space(this), new LinearLayout.LayoutParams(1, dp(i)));
    }

    boolean applyComparison(JSONObject jSONObject, boolean z) {
        JSONArray jSONArrayOptJSONArray = jSONObject.optJSONArray("sku_lines");
        this.shipmentSkuLines = jSONArrayOptJSONArray == null ? new JSONArray() : jSONArrayOptJSONArray;
        int i = 0;
        int iOptInt = jSONObject.optInt("po_qty", 0);
        int iOptInt2 = jSONObject.optInt("ship_qty", 0);
        JSONObject jSONObjectOptJSONObject = jSONObject.optJSONObject("po_varieties");
        JSONObject jSONObjectOptJSONObject2 = jSONObject.optJSONObject("ship_varieties");
        boolean z2 = (iOptInt2 > iOptInt && iOptInt > 0) || jSONObject.optInt("unallocated_cases", 0) > 0;
        ArrayList arrayList = new ArrayList();
        if (jSONObject.optInt("unallocated_cases", 0) > 0) {
            arrayList.add(tr("Unallocated cases: ", "Cajas no asignadas: ") + jSONObject.optInt("unallocated_cases"));
        }
        if (z2) {
            arrayList.add(tr("Too many cases: ", "Demasiadas cajas: ") + iOptInt2 + " / " + iOptInt);
        }
        for (int i2 = 0; i2 < this.shipmentSkuLines.length(); i2++) {
            JSONObject jSONObjectOptJSONObject3 = this.shipmentSkuLines.optJSONObject(i2);
            if (jSONObjectOptJSONObject3 != null && (jSONObjectOptJSONObject3.optBoolean("over", false) || jSONObjectOptJSONObject3.optBoolean("extra", false))) {
                z2 = true;
                arrayList.add("SKU " + jSONObjectOptJSONObject3.optString("sku") + ": " + jSONObjectOptJSONObject3.optInt("loaded") + " / " + jSONObjectOptJSONObject3.optInt("required"));
            }
        }
        if (jSONObjectOptJSONObject2 != null) {
            Iterator<String> itKeys = jSONObjectOptJSONObject2.keys();
            while (itKeys.hasNext()) {
                String next = itKeys.next();
                int iOptInt3 = jSONObjectOptJSONObject2.optInt(next);
                int iOptInt4 = jSONObjectOptJSONObject == null ? i : jSONObjectOptJSONObject.optInt(next, i);
                if (iOptInt4 == 0 || iOptInt3 > iOptInt4) {
                    z2 = true;
                    arrayList.add(next + ": " + iOptInt3 + " / " + iOptInt4);
                }
                i = 0;
            }
        }
        int iMax = Math.max(0, iOptInt - iOptInt2);
        if (arrayList.isEmpty()) {
            this.shipmentCompareMessage = iMax > 0 ? tr("PO compatible · Remaining cases: ", "PO compatible · Cajas restantes: ") + iMax : tr("PO matches the shipment", "El PO coincide con el envío");
        } else {
            this.shipmentCompareMessage = tr("Discrepancy: ", "Diferencia: ") + TextUtils.join("; ", arrayList);
        }
        this.shipmentMismatch = z2 || !(z || jSONObject.optBoolean("all_ok", false));
        if (!z && !jSONObject.optBoolean("all_ok", false) && arrayList.isEmpty()) {
            this.shipmentCompareMessage = tr("Order incomplete · Remaining cases: ", "Pedido incompleto · Cajas restantes: ") + iMax;
        }
        return z2;
    }

    void buildShell() {
        this.root = new LinearLayout(this);
        this.root.setOrientation(1);
        this.root.setBackgroundColor(Color.rgb(11, 22, 34));
        LinearLayout linearLayout = new LinearLayout(this);
        linearLayout.setGravity(16);
        linearLayout.setPadding(dp(18), dp(12), dp(12), dp(12));
        linearLayout.setBackgroundColor(Color.rgb(19, 32, 51));
        LinearLayout linearLayout2 = new LinearLayout(this);
        linearLayout2.setOrientation(1);
        TextView textViewTv = tv("Pallets / Shipping", 20, -1);
        textViewTv.setTypeface(null, 1);
        linearLayout2.addView(textViewTv);
        this.network = tv("", 12, -3355444);
        linearLayout2.addView(this.network);
        linearLayout.addView(linearLayout2, new LinearLayout.LayoutParams(0, -2, 1.0f));
        this.lang = button("EN", Color.rgb(25, 118, 210));
        this.lang.setOnClickListener(new MainActivity$$ExternalSyntheticLambda27(this));
        linearLayout.addView(this.lang, new LinearLayout.LayoutParams(dp(64), dp(46)));
        this.root.addView(linearLayout);
        this.progress = tv("", 12, Color.rgb(110, 140, 170));
        this.progress.setPadding(dp(18), dp(12), dp(18), 0);
        this.root.addView(this.progress);
        this.body = new LinearLayout(this);
        this.body.setOrientation(1);
        this.body.setGravity(1);
        this.body.setPadding(dp(18), dp(16), dp(18), dp(12));
        ScrollView scrollView = new ScrollView(this);
        scrollView.setFillViewport(true);
        scrollView.addView(this.body, new FrameLayout.LayoutParams(-1, -2));
        this.root.addView(scrollView, new LinearLayout.LayoutParams(-1, 0, 1.0f));
        this.nav = new LinearLayout(this);
        this.nav.setPadding(dp(14), dp(10), dp(14), dp(14));
        this.nav.setGravity(17);
        this.back = button("Back", Color.rgb(52, 65, 85));
        this.next = button("Next", Color.rgb(25, 118, 210));
        this.back.setOnClickListener(new MainActivity$$ExternalSyntheticLambda28(this));
        this.next.setOnClickListener(new MainActivity$$ExternalSyntheticLambda29(this));
        this.nav.addView(this.back, new LinearLayout.LayoutParams(0, dp(56), 1.0f));
        this.nav.addView(new Space(this), new LinearLayout.LayoutParams(dp(12), 1));
        this.nav.addView(this.next, new LinearLayout.LayoutParams(0, dp(56), 1.0f));
        this.root.addView(this.nav);
        setContentView(this.root);
    }

    Button button(String str, int i) {
        Button button = new Button(this);
        button.setText(str);
        button.setTextColor(-1);
        button.setTextSize(16.0f);
        button.setAllCaps(false);
        button.setBackgroundColor(i);
        return button;
    }

    void call(Map<String, String> map, MainActivity$Success mainActivity$Success) {
        callUrl(BuildConfig.API_URL, map, mainActivity$Success);
    }

    void callScanOrQueue(Map<String, String> map, String str, String str2, String str3, MainActivity$Success mainActivity$Success) {
        if (this.busy) {
            return;
        }
        this.busy = true;
        this.io.execute(new MainActivity$$ExternalSyntheticLambda35(this, map, mainActivity$Success, str, str2, str3));
    }

    void callUrl(String str, Map<String, String> map, MainActivity$Success mainActivity$Success) {
        if (this.busy) {
            return;
        }
        this.busy = true;
        this.io.execute(new MainActivity$$ExternalSyntheticLambda55(this, str, map, mainActivity$Success));
    }

    String caseField(JSONObject jSONObject, String str) {
        if (jSONObject != null) {
            String strTrim = jSONObject.optString(str, "").trim();
            if (!strTrim.isEmpty()) {
                return strTrim;
            }
            String strTrim2 = jSONObject.optString(str + "_name", "").trim();
            if (!strTrim2.isEmpty()) {
                return strTrim2;
            }
            if ("packaging".equals(str)) {
                String strTrim3 = jSONObject.optString("pack", "").trim();
                if (!strTrim3.isEmpty()) {
                    return strTrim3;
                }
            }
            JSONObject jSONObjectOptJSONObject = jSONObject.optJSONObject("case");
            if (jSONObjectOptJSONObject != null && jSONObjectOptJSONObject != jSONObject) {
                String strCaseField = caseField(jSONObjectOptJSONObject, str);
                if (!strCaseField.isEmpty()) {
                    return strCaseField;
                }
            }
            JSONObject jSONObjectOptJSONObject2 = jSONObject.optJSONObject("details");
            if (jSONObjectOptJSONObject2 != null && jSONObjectOptJSONObject2 != jSONObject) {
                return caseField(jSONObjectOptJSONObject2, str);
            }
        }
        return "";
    }

    boolean caseProductMismatch(String str, JSONObject jSONObject) {
        JSONObject jSONObjectOptJSONObject;
        if (jSONObject.optBoolean("sku_mismatch", false)) {
            this.pendingMismatchSerial = str;
            this.pendingMismatchResponse = jSONObject;
            return true;
        }
        rememberPalletProduct(jSONObject);
        JSONArray jSONArrayOptJSONArray = jSONObject.optJSONArray("cases");
        jSONObjectOptJSONObject = jSONObject.optJSONObject("case");
        if (jSONArrayOptJSONArray != null) {
            for (int i = 0; i < jSONArrayOptJSONArray.length(); i++) {
                JSONObject candidate = jSONArrayOptJSONArray.optJSONObject(i);
                if (candidate != null && str.equalsIgnoreCase(candidate.optString("case_serial"))) {
                    jSONObjectOptJSONObject = candidate;
                    break;
                }
            }
        }
        if (jSONObjectOptJSONObject == null) {
            jSONObjectOptJSONObject = jSONObject;
        }
        String strCaseField = caseField(jSONObjectOptJSONObject, "sku");
        if (strCaseField.isEmpty()) {
            strCaseField = caseField(jSONObject, "sku");
        }
        String str2 = this.palletVariety;
        if (str2.isEmpty()) {
            if (!strCaseField.isEmpty()) {
                this.palletVariety = strCaseField;
            }
        } else if (!strCaseField.isEmpty() && !str2.equalsIgnoreCase(strCaseField)) {
            this.pendingMismatchSerial = str;
            this.pendingMismatchResponse = jSONObject;
            return true;
        }
        return false;
    }

    void checkNetwork() {
        ConnectivityManager connectivityManager = (ConnectivityManager) getSystemService("connectivity");
        Network activeNetwork = connectivityManager.getActiveNetwork();
        NetworkCapabilities networkCapabilities = activeNetwork == null ? null : connectivityManager.getNetworkCapabilities(activeNetwork);
        boolean z = networkCapabilities != null && networkCapabilities.hasCapability(12);
        if (z) {
            setOnlineState(z);
        } else {
            setOnlineState(false);
        }
    }

    void checkPoAfterScan() {
        checkPoAfterScan(true);
    }

    void checkPoAfterScan(boolean z) {
        if (this.multiPo) {
            call(map("action", "shipment_multi_status", "shipment_id", this.shipmentId), new MainActivity$$ExternalSyntheticLambda7(this, z));
        } else {
            callUrl(BuildConfig.SHIPPING_API_URL, map("action", "compare", "shipment_id", this.shipmentId, "order_id", this.selectedOrder.optString("id")), new MainActivity$$ExternalSyntheticLambda8(this, z));
        }
    }

    Button choice(String str, String str2, View.OnClickListener view$OnClickListener) {
        Button button = button(tr(str, str2), Color.rgb(25, 118, 210));
        button.setOnClickListener(view$OnClickListener);
        this.body.addView(button, new LinearLayout.LayoutParams(-1, dp(64)));
        addSpace(14);
        return button;
    }

    void clearPendingMismatch() {
        this.pendingMismatchSerial = "";
        this.pendingMismatchResponse = null;
    }

    void closeShipment() {
        if (!this.online) {
            error(tr("Wait for synchronization before closing", "Espere la sincronización antes de cerrar"));
            return;
        }
        this.queue.countFor(this.shipmentId);
        if (this.palletCount <= 0) {
            error(tr("Scan at least one pallet", "Escanee al menos un pallet"));
            return;
        }
        if (this.selectedOrder == null) {
            performShipmentClose();
        } else if (this.multiPo) {
            call(map("action", "shipment_multi_status", "shipment_id", this.shipmentId), new MainActivity$$ExternalSyntheticLambda49(this));
        } else {
            callUrl(BuildConfig.SHIPPING_API_URL, map("action", "compare", "shipment_id", this.shipmentId, "order_id", this.selectedOrder.optString("id")), new MainActivity$$ExternalSyntheticLambda50(this));
        }
    }

    void configureDataWedge() {
        Bundle bundle = new Bundle();
        bundle.putString("PACKAGE_NAME", getPackageName());
        bundle.putStringArray("ACTIVITY_LIST", new String[]{"*"});
        Bundle bundle2 = new Bundle();
        bundle2.putString("scanner_input_enabled", "true");
        bundle2.putString("scanner_selection", "auto");
        Bundle bundle3 = new Bundle();
        bundle3.putString("PLUGIN_NAME", "BARCODE");
        bundle3.putString("RESET_CONFIG", "false");
        bundle3.putBundle("PARAM_LIST", bundle2);
        Bundle bundle4 = new Bundle();
        bundle4.putString("PROFILE_NAME", "SMProduce_PalletsShipping");
        bundle4.putString("PROFILE_ENABLED", "true");
        bundle4.putString("CONFIG_MODE", "CREATE_IF_NOT_EXIST");
        bundle4.putParcelableArray("APP_LIST", new Bundle[]{bundle});
        bundle4.putBundle("PLUGIN_CONFIG", bundle3);
        Intent intent = new Intent("com.symbol.datawedge.api.ACTION");
        intent.putExtra("com.symbol.datawedge.api.SET_CONFIG", bundle4);
        sendBroadcast(intent);
        Bundle bundle5 = new Bundle();
        bundle5.putString("PROFILE_NAME", "SMProduce_PalletsShipping");
        bundle5.putString("PROFILE_ENABLED", "true");
        bundle5.putString("CONFIG_MODE", "UPDATE");
        bundle5.putParcelableArray("APP_LIST", new Bundle[]{bundle});
        bundle5.putBundle("PLUGIN_CONFIG", bundle3);
        Intent intent2 = new Intent("com.symbol.datawedge.api.ACTION");
        intent2.putExtra("com.symbol.datawedge.api.SET_CONFIG", bundle5);
        sendBroadcast(intent2);
        Bundle bundle6 = new Bundle();
        bundle6.putString("intent_output_enabled", "true");
        bundle6.putString("intent_action", "com.smproduce.PALLETS_SHIPPING.SCAN");
        bundle6.putString("intent_delivery", "2");
        Bundle bundle7 = new Bundle();
        bundle7.putString("PLUGIN_NAME", "INTENT");
        bundle7.putString("RESET_CONFIG", "true");
        bundle7.putBundle("PARAM_LIST", bundle6);
        Bundle bundle8 = new Bundle();
        bundle8.putString("PROFILE_NAME", "SMProduce_PalletsShipping");
        bundle8.putString("PROFILE_ENABLED", "true");
        bundle8.putString("CONFIG_MODE", "UPDATE");
        bundle8.putBundle("PLUGIN_CONFIG", bundle7);
        Intent intent3 = new Intent("com.symbol.datawedge.api.ACTION");
        intent3.putExtra("com.symbol.datawedge.api.SET_CONFIG", bundle8);
        sendBroadcast(intent3);
        Bundle bundle9 = new Bundle();
        bundle9.putString("keystroke_output_enabled", "false");
        Bundle bundle10 = new Bundle();
        bundle10.putString("PLUGIN_NAME", "KEYSTROKE");
        bundle10.putString("RESET_CONFIG", "true");
        bundle10.putBundle("PARAM_LIST", bundle9);
        Bundle bundle11 = new Bundle();
        bundle11.putString("PROFILE_NAME", "SMProduce_PalletsShipping");
        bundle11.putString("PROFILE_ENABLED", "true");
        bundle11.putString("CONFIG_MODE", "UPDATE");
        bundle11.putBundle("PLUGIN_CONFIG", bundle10);
        Intent intent4 = new Intent("com.symbol.datawedge.api.ACTION");
        intent4.putExtra("com.symbol.datawedge.api.SET_CONFIG", bundle11);
        sendBroadcast(intent4);
    }

    void deleteEmptyPallet() {
        if (this.online) {
            call(map("action", "pallet_delete", "pallet_id", this.palletId), new MainActivity$EmptyDeleteSuccess(this));
        } else {
            error(tr("Internet connection required. Empty pallet was not deleted.", "Se requiere conexión a Internet. El pallet vacío no fue eliminado."));
        }
    }

    @Override
    public boolean dispatchKeyEvent(KeyEvent keyEvent) {
        if (keyEvent.getAction() == 0) {
            long jCurrentTimeMillis = System.currentTimeMillis();
            if (jCurrentTimeMillis - this.lastKeyAt > 250) {
                this.keyScanBuffer.setLength(0);
            }
            this.lastKeyAt = jCurrentTimeMillis;
            int keyCode = keyEvent.getKeyCode();
            if (keyCode == 66 || keyCode == 61) {
                String strTrim = this.keyScanBuffer.toString().trim();
                this.keyScanBuffer.setLength(0);
                if (!strTrim.isEmpty()) {
                    onScan(strTrim);
                    return true;
                }
            }
            int unicodeChar = keyEvent.getUnicodeChar();
            if (unicodeChar > 0 && !Character.isISOControl((char) unicodeChar)) {
                this.keyScanBuffer.append((char) unicodeChar);
                return true;
            }
        }
        return super.dispatchKeyEvent(keyEvent);
    }

    void done(String str) {
        this.step = MainActivity$Step.DONE;
        render();
        this.subtitle.setText(str);
    }

    int dp(int i) {
        return Math.round(i * getResources().getDisplayMetrics().density);
    }

    void editPalletCase(String str) {
        if (!this.online) {
            error(tr("Editing requires a connection", "La modificación requiere conexión"));
        } else if (this.palletEditRemove) {
            call(map("action", "pallet_remove_case", "pallet_id", this.palletId, "case_serial", str), new MainActivity$$ExternalSyntheticLambda21(this, str));
        } else {
            call(map("action", "pallet_scan_case", "pallet_id", this.palletId, "case_serial", str), new MainActivity$$ExternalSyntheticLambda23(this, str));
        }
    }

    void error(String str) {
        this.tone.startTone(97, 350);
        new AlertDialog.Builder(this).setTitle(tr("Attention", "Atención")).setMessage(str).setPositiveButton("OK", (DialogInterface.OnClickListener) null).show();
    }

    void finishEditedPallet(boolean z) {
        if (!this.online) {
            error(tr("Connect before closing the pallet", "Conéctese antes de cerrar el pallet"));
            return;
        }
        String[] strArr = new String[6];
        strArr[0] = "action";
        strArr[1] = "pallet_close";
        strArr[2] = "pallet_id";
        strArr[3] = this.palletId;
        strArr[4] = "print_label";
        strArr[5] = z ? "1" : "0";
        call(map(strArr), new MainActivity$$ExternalSyntheticLambda22(this, z));
    }

    void finishPallet(boolean z) {
        if (!this.online) {
            error(tr("Wait for synchronization before finishing", "Espere la sincronización antes de finalizar"));
            return;
        }
        this.queue.countFor(this.palletId);
        String[] strArr = new String[4];
        strArr[0] = "action";
        strArr[1] = z ? "pallet_close" : "pallet_partial";
        strArr[2] = "pallet_id";
        strArr[3] = this.palletId;
        call(map(strArr), new MainActivity$$ExternalSyntheticLambda39(this, z));
    }

    void goBack() {
        if (this.step == MainActivity$Step.PALLET_MODE || this.step == MainActivity$Step.SHIP_MODE) {
            this.step = MainActivity$Step.HOME;
        } else if (this.step == MainActivity$Step.PALLET_RESUME) {
            this.step = MainActivity$Step.PALLET_MODE;
        } else if (this.step == MainActivity$Step.PALLET_EDIT_ID) {
            this.palletEditPassword = "";
            this.step = MainActivity$Step.PALLET_MODE;
        } else {
            if (this.step == MainActivity$Step.PALLET_EDIT_CASES) {
                error(tr("Save and close the pallet before leaving", "Guarde y cierre el pallet antes de salir"));
                return;
            }
            if (this.step == MainActivity$Step.PALLET_SCAN) {
                this.step = MainActivity$Step.PALLET_MODE;
            } else if (this.step == MainActivity$Step.PALLET_FINISH) {
                this.step = MainActivity$Step.PALLET_SCAN;
            } else if (this.step == MainActivity$Step.SHIP_RESUME) {
                this.step = MainActivity$Step.SHIP_MODE;
            } else if (this.step == MainActivity$Step.SHIP_ORDER) {
                this.shipmentId = "";
                this.selectedOrder = null;
                this.step = MainActivity$Step.HOME;
            } else if (this.step == MainActivity$Step.SHIP_SCAN) {
                this.step = MainActivity$Step.SHIP_ORDER;
            } else if (this.step == MainActivity$Step.SHIP_FINISH) {
                this.step = MainActivity$Step.SHIP_SCAN;
            }
        }
        render();
    }

    void goNext() {
        if (this.step == MainActivity$Step.PALLET_RESUME) {
            String strText = text();
            if (strText.isEmpty()) {
                error(tr("Scan the pallet label", "Escanee la etiqueta"));
                return;
            } else {
                resumePallet(strText);
                return;
            }
        }
        if (this.step == MainActivity$Step.PALLET_EDIT_ID) {
            String strText2 = text();
            if (strText2.isEmpty()) {
                error(tr("Scan the pallet label", "Escanee la etiqueta"));
                return;
            } else {
                openPalletForEdit(strText2);
                return;
            }
        }
        if (this.step == MainActivity$Step.PALLET_SCAN) {
            if (this.caseCount == 0) {
                deleteEmptyPallet();
                return;
            } else {
                this.step = MainActivity$Step.PALLET_FINISH;
                render();
                return;
            }
        }
        if (this.step != MainActivity$Step.SHIP_RESUME) {
            if (this.step == MainActivity$Step.SHIP_SCAN) {
                this.step = MainActivity$Step.SHIP_FINISH;
                render();
                return;
            }
            return;
        }
        String strText3 = text();
        if (strText3.isEmpty()) {
            error(tr("Scan the shipment label", "Escanee la etiqueta del envío"));
        } else {
            resumeShipment(strText3);
        }
    }

    void heading(String str, String str2) {
        this.title = tv(str, 27, -1);
        this.title.setGravity(17);
        this.title.setTypeface(null, 1);
        this.body.addView(this.title, new LinearLayout.LayoutParams(-1, -2));
        this.subtitle = tv(str2, 16, Color.rgb(150, 170, 190));
        this.subtitle.setGravity(17);
        this.body.addView(this.subtitle, new LinearLayout.LayoutParams(-1, -2));
        addSpace(28);
    }

    void m0lambda$buildShell$0$comsmproducepalletsshippingMainActivity(View view) {
        this.spanish = !this.spanish;
        this.lang.setText(this.spanish ? "ES" : "EN");
        render();
    }

    void m1lambda$buildShell$1$comsmproducepalletsshippingMainActivity(View view) {
        goBack();
    }

    void m2lambda$buildShell$2$comsmproducepalletsshippingMainActivity(View view) {
        goNext();
    }

    void m3xfa91627b(JSONObject jSONObject, MainActivity$Success mainActivity$Success) {
        this.busy = false;
        if (jSONObject.optInt("ok") == 1) {
            mainActivity$Success.run(jSONObject);
            return;
        }
        String strLocalizeError = localizeError(jSONObject.optString("err", tr("Operation failed", "Operación fallida")));
        String lowerCase = strLocalizeError.toLowerCase();
        if (lowerCase.contains("already scanned") || lowerCase.contains("ya fue escaneada") || lowerCase.contains("ya escaneada")) {
            this.scanErrorMessage = tr("CASE ALREADY SCANNED", "CAJA YA ESCANEADA");
            this.tone.startTone(97, 350);
            render();
        } else if (lowerCase.startsWith("pallet rejected:")) {
            requestOwnershipOverride(strLocalizeError);
        } else {
            error(strLocalizeError);
        }
    }

    void m4xbd7dcbda(boolean z, String str, String str2) {
        this.busy = false;
        setOnlineState(false);
        error(tr("No Internet connection. Operation cancelled; nothing was saved.", "Sin conexión a Internet. Operación cancelada; no se guardó nada."));
        render();
    }

    void m5x806a3539(Map map, MainActivity$Success mainActivity$Success, String str, String str2, String str3) {
        try {
            runOnUiThread(new MainActivity$$ExternalSyntheticLambda32(this, request(map), mainActivity$Success));
        } catch (Exception e) {
            runOnUiThread(new MainActivity$$ExternalSyntheticLambda34(this, false, str, str3));
        }
    }

    void m6lambda$callUrl$60$comsmproducepalletsshippingMainActivity(JSONObject jSONObject, MainActivity$Success mainActivity$Success) {
        this.busy = false;
        if (jSONObject.optInt("ok") == 1) {
            mainActivity$Success.run(jSONObject);
        } else {
            error(localizeError(jSONObject.optString("err", tr("Operation failed", "Operación fallida"))));
        }
    }

    void m7lambda$callUrl$61$comsmproducepalletsshippingMainActivity(Exception exc) {
        this.busy = false;
        checkNetwork();
        error(tr("Connection error", "Error de conexión") + ": " + exc.getMessage());
    }

    void m8lambda$callUrl$62$comsmproducepalletsshippingMainActivity(String str, Map map, MainActivity$Success mainActivity$Success) {
        try {
            runOnUiThread(new MainActivity$$ExternalSyntheticLambda51(this, requestUrl(str, map), mainActivity$Success));
        } catch (Exception e) {
            runOnUiThread(new MainActivity$$ExternalSyntheticLambda52(this, e));
        }
    }

    void m9xd6bf37b1(DialogInterface dialogInterface, int i) {
        removeLast(false);
    }

    void m10x971045db(boolean z, JSONObject jSONObject) {
        boolean zApplyComparison = applyComparison(jSONObject, true);
        render();
        if (zApplyComparison && z) {
            new AlertDialog.Builder(this).setTitle(tr("Pallet does not match the selected POs", "El pallet no coincide con los PO seleccionados")).setMessage(this.shipmentCompareMessage).setPositiveButton(tr("Remove Last Pallet", "Eliminar último pallet"), new MainActivity$$ExternalSyntheticLambda0(this)).setNegativeButton(tr("Keep and Review", "Mantener y revisar"), (DialogInterface.OnClickListener) null).show();
        }
    }

    void m11x59fcaf3a(DialogInterface dialogInterface, int i) {
        removeLast(false);
    }

    void m12x1ce91899(boolean z, JSONObject jSONObject) {
        boolean zApplyComparison = applyComparison(jSONObject, true);
        render();
        if (zApplyComparison && z) {
            new AlertDialog.Builder(this).setTitle(tr("Pallet does not match the PO", "El pallet no coincide con el PO")).setMessage(this.shipmentCompareMessage).setPositiveButton(tr("Remove Last Pallet", "Eliminar último pallet"), new MainActivity$$ExternalSyntheticLambda6(this)).setNegativeButton(tr("Keep and Review", "Mantener y revisar"), (DialogInterface.OnClickListener) null).show();
        }
    }

    void m13xb83cd697(JSONObject jSONObject) {
        applyComparison(jSONObject, false);
        if (jSONObject.optBoolean("all_ok", false)) {
            performShipmentClose();
        } else {
            requestMismatchOverride();
        }
    }

    void m14x7b293ff6(JSONObject jSONObject) {
        applyComparison(jSONObject, false);
        if (jSONObject.optBoolean("all_ok", false)) {
            performShipmentClose();
        } else {
            requestMismatchOverride();
        }
    }

    void m15x3b018b9(String str, JSONObject jSONObject) {
        this.caseCount = jSONObject.optInt("cases_count");
        loadCases(jSONObject);
        clearPendingMismatch();
        if (this.caseCount == 0) {
            this.palletVariety = "";
            this.palletSize = "";
            this.palletPackaging = "";
        }
        this.scanErrorMessage = "";
        ok(tr("Case removed: ", "Caja eliminada: ") + str);
        render();
    }

    void m16xc69c8218(String str, JSONObject jSONObject) {
        this.caseCount = jSONObject.optInt("cases_count");
        loadCases(jSONObject);
        boolean zCaseProductMismatch = caseProductMismatch(str, jSONObject);
        render();
        if (zCaseProductMismatch) {
            showCaseProductMismatch(str, jSONObject);
        } else {
            ok(tr("Case added: ", "Caja añadida: ") + str);
        }
    }

    void m17x26de0239(boolean z, JSONObject jSONObject) {
        String str;
        String str2;
        if (!z) {
            str = "Pallet updated and closed without printing";
            str2 = "Pallet actualizado y cerrado sin imprimir";
        } else if (jSONObject.optInt("label_printed", 0) != 1) {
            error(tr("Pallet saved, but the label was not sent. Check the printer selected in Pallets Manage.", "Pallet guardado, pero la etiqueta no fue enviada. Compruebe la impresora seleccionada en Pallets Manage."));
            return;
        } else {
            str = "Pallet updated, closed and sent to print";
            str2 = "Pallet actualizado, cerrado y enviado a imprimir";
        }
        done(tr(str, str2));
    }

    void m18xab809433(boolean z, JSONObject jSONObject) {
        String str;
        String str2;
        if (jSONObject.optInt("label_printed", 0) != 1) {
            error(tr("Pallet saved, but the label was not sent. Check the printer selected in Pallets Manage.", "Pallet guardado, pero la etiqueta no fue enviada. Compruebe la impresora seleccionada en Pallets Manage."));
            return;
        }
        this.palletId = "";
        this.palletVariety = "";
        this.palletSize = "";
        this.palletPackaging = "";
        if (z) {
            str = "Pallet closed as complete and sent to print";
            str2 = "Pallet cerrado como completo y enviado a imprimir";
        } else {
            str = "Pallet closed as partial and sent to print";
            str2 = "Pallet cerrado como parcial y enviado a imprimir";
        }
        done(tr(str, str2));
    }

    void m19x538f0094(JSONObject jSONObject) {
        showOpenShipments(jSONObject.optJSONArray("shipments"));
    }

    void m20lambda$newPallet$22$comsmproducepalletsshippingMainActivity(JSONObject jSONObject) {
        this.palletId = jSONObject.optString("pallet_id");
        this.caseCount = jSONObject.optInt("cases_count");
        this.palletVariety = "";
        this.palletSize = "";
        this.palletPackaging = "";
        this.scannedCases.clear();
        loadCases(jSONObject);
        rememberPalletProduct(jSONObject);
        this.casesExpanded = false;
        this.scanErrorMessage = "";
        this.step = MainActivity$Step.PALLET_SCAN;
        render();
    }

    void m21lambda$newShipment$30$comsmproducepalletsshippingMainActivity(JSONObject jSONObject) {
        this.shipmentId = jSONObject.optString("shipment_id");
        this.step = MainActivity$Step.SHIP_ORDER;
        render();
        searchOrders("");
    }

    void m22xec3b6c6f(JSONObject jSONObject) {
        this.palletEditPassword = "";
        this.palletId = jSONObject.optString("pallet_id");
        this.caseCount = jSONObject.optInt("cases_count");
        this.palletVariety = "";
        this.palletSize = "";
        this.palletPackaging = "";
        loadCases(jSONObject);
        rememberPalletProduct(jSONObject);
        this.palletEditRemove = false;
        this.step = MainActivity$Step.PALLET_EDIT_CASES;
        render();
    }

    void m23lambda$orderScreen$20$comsmproducepalletsshippingMainActivity(View view) {
        searchOrders(this.manual.getText().toString());
    }

    void m24lambda$orderScreen$21$comsmproducepalletsshippingMainActivity(View view) {
        requestSkipPassword();
    }

    void m25xe238d976(View view) {
        this.palletEditRemove = false;
        render();
    }

    void m26xa52542d5(View view) {
        this.palletEditRemove = true;
        render();
    }

    void m27x6811ac34(View view) {
        finishEditedPallet(true);
    }

    void m28xcda25b25(boolean z, JSONObject jSONObject) {
        int iOptInt = jSONObject.optInt("bol_count", this.selectedOrders.length());
        if (z) {
            done(tr("Shipment closed and BOLs printed, but the POs could not all be set to SHIPPED.", "Envío cerrado y BOL impresos, pero no todos los PO pudieron marcarse como SHIPPED."));
        } else {
            done(tr("Shipment closed. " + iOptInt + " BOLs printed in 3 copies each.", "Envío cerrado. " + iOptInt + " BOL impresos en 3 copias cada uno."));
        }
    }

    void m29x908ec484(boolean z, JSONObject jSONObject) {
        if (z) {
            done(tr("Shipment closed and printed, but the PO could not be set to SHIPPED. Check the order in the webapp.", "Envío cerrado e impreso, pero el PO no pudo marcarse como SHIPPED. Compruebe el pedido en la webapp."));
        } else {
            done(tr("Shipment closed, PO set to SHIPPED. Label and BOL sent to print", "Envío cerrado, PO marcado como SHIPPED. Etiqueta y BOL enviados a imprimir"));
        }
    }

    void m30x537b2de3(boolean z, JSONObject jSONObject) {
        callUrl(BuildConfig.SHIPPING_API_URL, map("action", "queue_bol_print", "shipment_id", this.shipmentId), new MainActivity$$ExternalSyntheticLambda68(this, z));
    }

    void m31x16679742(JSONObject jSONObject) {
        boolean z = jSONObject.optInt("order_expected", 0) == 1 && jSONObject.optInt("order_closed", 0) != 1;
        if (jSONObject.optInt("po_count", 0) > 0 || this.selectedOrders.length() > 0) {
            callUrl(BuildConfig.SHIPPING_API_URL, map("action", "multi_bol", "shipment_id", this.shipmentId), new MainActivity$$ExternalSyntheticLambda41(this, z));
        } else {
            callUrl(BuildConfig.SHIPPING_API_URL, map("action", "bol", "shipment_id", this.shipmentId), new MainActivity$$ExternalSyntheticLambda42(this, z));
        }
    }

    void m32lambda$probeServer$64$comsmproducepalletsshippingMainActivity(boolean z) {
        boolean z2 = this.online;
        this.probing = false;
        setOnlineState(z);
        if (!z || z2) {
            return;
        }
        syncQueue();
    }

    void m33lambda$probeServer$65$comsmproducepalletsshippingMainActivity() {
        boolean z = false;
        try {
            z = request(map("action", "ping")).optInt("ok") == 1;
        } catch (Exception e) {
        }
        runOnUiThread(new MainActivity$$ExternalSyntheticLambda47(this, z));
    }

    void m34xc981ea0(JSONObject jSONObject) {
        this.caseCount = jSONObject.optInt("cases_count");
        loadCases(jSONObject);
        render();
    }

    void m35xcf8487ff(JSONObject jSONObject) {
        this.palletCount = jSONObject.optInt("pallet_count");
        this.shipmentCases = jSONObject.optInt("cases_count");
        render();
        if (this.selectedOrder != null) {
            checkPoAfterScan();
        }
    }

    void m36lambda$removeLast$36$comsmproducepalletsshippingMainActivity(boolean z, JSONObject jSONObject) {
        if (z) {
            this.caseCount = jSONObject.optInt("cases_count");
            if (!this.scannedCases.isEmpty()) {
                this.scannedCases.remove(this.scannedCases.size() - 1);
            }
            if (this.caseCount == 0) {
                this.palletVariety = "";
                this.palletSize = "";
                this.palletPackaging = "";
                clearPendingMismatch();
            }
            this.scanErrorMessage = "";
        } else {
            this.palletCount = jSONObject.optInt("pallet_count");
            this.shipmentCases = jSONObject.optInt("cases_count");
            this.shipmentMismatch = false;
            this.shipmentCompareMessage = "";
        }
        render();
        if (z || this.selectedOrder == null) {
            return;
        }
        checkPoAfterScan();
    }

    void m37lambda$render$10$comsmproducepalletsshippingMainActivity(View view) {
        newShipment();
    }

    void m38lambda$render$11$comsmproducepalletsshippingMainActivity(View view) {
        loadOpenShipments();
    }

    void m39lambda$render$12$comsmproducepalletsshippingMainActivity(View view) {
        done(tr("Shipment saved", "Envío guardado"));
    }

    void m40lambda$render$13$comsmproducepalletsshippingMainActivity(View view) {
        closeShipment();
    }

    void m41lambda$render$14$comsmproducepalletsshippingMainActivity(View view) {
        resetHome();
    }

    void m42lambda$render$3$comsmproducepalletsshippingMainActivity(View view) {
        this.step = MainActivity$Step.PALLET_MODE;
        render();
    }

    void m43lambda$render$4$comsmproducepalletsshippingMainActivity(View view) {
        this.step = MainActivity$Step.SHIP_MODE;
        render();
    }

    void m44lambda$render$5$comsmproducepalletsshippingMainActivity(View view) {
        newPallet();
    }

    void m45lambda$render$6$comsmproducepalletsshippingMainActivity(View view) {
        this.step = MainActivity$Step.PALLET_RESUME;
        render();
    }

    void m46lambda$render$7$comsmproducepalletsshippingMainActivity(View view) {
        requestPalletEditPassword();
    }

    void m47lambda$render$8$comsmproducepalletsshippingMainActivity(View view) {
        finishPallet(true);
    }

    void m48lambda$render$9$comsmproducepalletsshippingMainActivity(View view) {
        finishPallet(false);
    }

    void m49x7851b870(JSONObject jSONObject) {
        performShipmentClose();
    }

    void m50x3b3e21cf(EditText editText, DialogInterface dialogInterface, int i) {
        call(map("action", "verify_skip_po_password", "password", editText.getText().toString()), new MainActivity$$ExternalSyntheticLambda5(this));
    }

    void m51x92f53a36(EditText editText, DialogInterface dialogInterface, int i) {
        this.ownershipOverridePassword = editText.getText().toString();
        scanPallet(this.lastScanCode);
        this.ownershipOverridePassword = "";
    }

    void m52x9c070a5c(String str, JSONObject jSONObject) {
        this.palletEditPassword = str;
        this.step = MainActivity$Step.PALLET_EDIT_ID;
        render();
    }

    void m53x5ef373bb(EditText editText, DialogInterface dialogInterface, int i) {
        String string = editText.getText().toString();
        call(map("action", "verify_skip_po_password", "password", string), new MainActivity$$ExternalSyntheticLambda4(this, string));
    }

    void m54x2be37032(EditText editText, DialogInterface dialogInterface, int i) {
        verifySkipPassword(editText.getText().toString());
    }

    void m55xbba9aed0(JSONObject jSONObject) {
        this.palletId = jSONObject.optString("pallet_id");
        this.caseCount = jSONObject.optInt("cases_count");
        this.palletVariety = "";
        this.palletSize = "";
        this.palletPackaging = "";
        loadCases(jSONObject);
        rememberPalletProduct(jSONObject);
        this.casesExpanded = false;
        this.scanErrorMessage = "";
        this.step = MainActivity$Step.PALLET_SCAN;
        render();
    }

    void m56x27c7b88d(JSONObject jSONObject, JSONObject jSONObject2) {
        this.shipmentId = jSONObject2.optString("shipment_id");
        this.palletCount = jSONObject2.optInt("pallet_count");
        this.shipmentCases = jSONObject2.optInt("cases_count");
        JSONObject jSONObjectOptJSONObject = jSONObject2.optJSONObject("shipment");
        if (jSONObject != null) {
            jSONObjectOptJSONObject = jSONObject;
        }
        JSONArray jSONArrayOptJSONArray = jSONObject2.optJSONArray("shipment_orders");
        this.selectedOrders = jSONArrayOptJSONArray == null ? new JSONArray() : jSONArrayOptJSONArray;
        this.multiPo = this.selectedOrders.length() > 1;
        if (this.selectedOrders.length() > 0) {
            this.selectedOrder = this.selectedOrders.optJSONObject(0);
        } else if (jSONObjectOptJSONObject == null || jSONObjectOptJSONObject.optInt("order_id", 0) <= 0) {
            this.selectedOrder = null;
        } else {
            this.selectedOrder = new JSONObject();
            try {
                this.selectedOrder.put("id", jSONObjectOptJSONObject.optInt("order_id"));
                this.selectedOrder.put("po", jSONObjectOptJSONObject.optString("po"));
                this.selectedOrder.put("customer_name", jSONObjectOptJSONObject.optString("customer_name"));
            } catch (JSONException e) {
            }
        }
        this.shipmentSkuLines = new JSONArray();
        this.shipmentMismatch = false;
        this.shipmentCompareMessage = "";
        this.step = MainActivity$Step.SHIP_SCAN;
        render();
        if (this.selectedOrder != null) {
            checkPoAfterScan(false);
        }
    }

    void m57lambda$scanCase$34$comsmproducepalletsshippingMainActivity(String str, JSONObject jSONObject) {
        this.caseCount = jSONObject.optInt("cases_count", this.caseCount + 1);
        loadCases(jSONObject);
        boolean zCaseProductMismatch = caseProductMismatch(str, jSONObject);
        render();
        if (zCaseProductMismatch) {
            showCaseProductMismatch(str, jSONObject);
        } else {
            ok(str);
        }
    }

    void m58lambda$scanPallet$35$comsmproducepalletsshippingMainActivity(String str, JSONObject jSONObject) {
        this.palletCount = jSONObject.optInt("pallet_count", this.palletCount + 1);
        this.shipmentCases = jSONObject.optInt("cases_count", this.shipmentCases);
        JSONObject jSONObjectOptJSONObject = jSONObject.optJSONObject("multi");
        if (jSONObjectOptJSONObject != null) {
            applyComparison(jSONObjectOptJSONObject, true);
        }
        ok(str);
        render();
        if (this.selectedOrder == null || jSONObjectOptJSONObject != null) {
            return;
        }
        checkPoAfterScan();
    }

    void m59lambda$scanScreen$18$comsmproducepalletsshippingMainActivity(boolean z, View view) {
        removeLast(z);
    }

    void m60lambda$scanScreen$19$comsmproducepalletsshippingMainActivity(View view) {
        this.casesExpanded = !this.casesExpanded;
        render();
    }

    void m61x8fb70db1(JSONObject jSONObject) {
        showOrders(jSONObject.optJSONArray("orders"));
    }

    void m62x4cf9b3eb() {
        this.tone.startTone(97, 280);
    }

    void m63x5669d97c(JSONArray jSONArray, DialogInterface dialogInterface, int i) {
        JSONObject jSONObjectOptJSONObject = jSONArray.optJSONObject(i);
        if (jSONObjectOptJSONObject != null) {
            resumeShipment(jSONObjectOptJSONObject.optString("shipment_id"), jSONObjectOptJSONObject);
        }
    }

    void m64lambda$showOrders$54$comsmproducepalletsshippingMainActivity(JSONObject jSONObject) {
        this.step = MainActivity$Step.SHIP_SCAN;
        JSONObject jSONObjectOptJSONObject = jSONObject.optJSONObject("multi");
        if (jSONObjectOptJSONObject != null) {
            applyComparison(jSONObjectOptJSONObject, false);
        }
        render();
    }

    void m65lambda$showOrders$55$comsmproducepalletsshippingMainActivity(JSONArray jSONArray, boolean[] zArr, AlertDialog alertDialog, View view) {
        JSONObject jSONObjectOptJSONObject;
        ArrayList arrayList = new ArrayList();
        this.selectedOrders = new JSONArray();
        for (int i = 0; i < jSONArray.length(); i++) {
            if (zArr[i] && (jSONObjectOptJSONObject = jSONArray.optJSONObject(i)) != null) {
                arrayList.add(jSONObjectOptJSONObject.optString("id"));
                this.selectedOrders.put(jSONObjectOptJSONObject);
            }
        }
        if (arrayList.isEmpty()) {
            Toast.makeText(this, tr("Select at least one PO", "Seleccione al menos un PO"), 0).show();
            return;
        }
        this.selectedOrder = this.selectedOrders.optJSONObject(0);
        this.multiPo = this.selectedOrders.length() > 1;
        this.shipmentSkuLines = new JSONArray();
        alertDialog.dismiss();
        call(map("action", "shipment_set_orders", "shipment_id", this.shipmentId, "order_ids", TextUtils.join(",", arrayList)), new MainActivity$$ExternalSyntheticLambda16(this));
    }

    void m66lambda$showOrders$56$comsmproducepalletsshippingMainActivity(AlertDialog alertDialog, JSONArray jSONArray, boolean[] zArr, DialogInterface dialogInterface) {
        alertDialog.getButton(-1).setOnClickListener(new MainActivity$$ExternalSyntheticLambda66(this, jSONArray, zArr, alertDialog));
    }

    void m67lambda$syncQueue$66$comsmproducepalletsshippingMainActivity(int i) {
        Toast.makeText(this, tr("Synchronized ", "Sincronizados ") + i, 1).show();
        refreshCurrent();
    }

    void m68lambda$syncQueue$67$comsmproducepalletsshippingMainActivity() {
        int i = 0;
        for (MainActivity$QueueDb$Item mainActivity$QueueDb$Item : this.queue.all()) {
            try {
                if (!mainActivity$QueueDb$Item.type.equals("CREATE_PALLET")) {
                    JSONObject jSONObjectRequest = request(mainActivity$QueueDb$Item.type.equals("CASE") ? map("action", "pallet_scan_case", "pallet_id", mainActivity$QueueDb$Item.parent, "case_serial", mainActivity$QueueDb$Item.code) : map("action", "shipment_scan_pallet", "shipment_id", mainActivity$QueueDb$Item.parent, "pallet_id", mainActivity$QueueDb$Item.code));
                    if (jSONObjectRequest.optInt("ok") != 1 && !jSONObjectRequest.optString("err").toLowerCase().contains("already")) {
                        break;
                    }
                    this.queue.remove(mainActivity$QueueDb$Item.id);
                    i++;
                } else {
                    JSONObject jSONObjectRequest2 = request(map("action", "pallet_new"));
                    if (jSONObjectRequest2.optInt("ok") != 1) {
                        break;
                    }
                    String strOptString = jSONObjectRequest2.optString("pallet_id");
                    this.queue.replaceParent(mainActivity$QueueDb$Item.parent, strOptString);
                    this.queue.remove(mainActivity$QueueDb$Item.id);
                    if (this.palletId.equals(mainActivity$QueueDb$Item.parent)) {
                        this.palletId = strOptString;
                    }
                    i++;
                }
            } catch (Exception e) {
            }
        }
        int i2 = i;
        if (i2 > 0) {
            runOnUiThread(new MainActivity$$ExternalSyntheticLambda26(this, i2));
        }
    }

    void m69x91bc29d5(JSONObject jSONObject) {
        this.selectedOrder = null;
        this.step = MainActivity$Step.SHIP_SCAN;
        render();
    }

    void loadCases(JSONObject jSONObject) {
        JSONArray jSONArrayOptJSONArray = jSONObject.optJSONArray("cases");
        if (jSONArrayOptJSONArray == null) {
            return;
        }
        this.scannedCases.clear();
        for (int length = jSONArrayOptJSONArray.length() - 1; length >= 0; length--) {
            JSONObject jSONObjectOptJSONObject = jSONArrayOptJSONArray.optJSONObject(length);
            if (jSONObjectOptJSONObject != null) {
                String strOptString = jSONObjectOptJSONObject.optString("case_serial");
                if (!strOptString.isEmpty()) {
                    this.scannedCases.add(strOptString);
                }
            }
        }
    }

    void loadOpenShipments() {
        if (this.online) {
            call(map("action", "shipment_open_list"), new MainActivity$$ExternalSyntheticLambda2(this));
        } else {
            error(tr("Connect to load saved shipments", "Conéctese para cargar los envíos guardados"));
        }
    }

    String localizeError(String str) {
        if (!this.spanish) {
            return str;
        }
        String lowerCase = str.toLowerCase();
        if (lowerCase.contains("already belongs")) {
            return "La caja ya pertenece a otro pallet";
        }
        if (lowerCase.contains("already scanned")) {
            return "La caja ya fue escaneada";
        }
        if (lowerCase.contains("not found")) {
            return "Código no encontrado";
        }
        if (lowerCase.contains("already in this shipment")) {
            return "El pallet ya está en este envío";
        }
        return lowerCase.contains("not partial") ? "El pallet escaneado no es parcial" : str;
    }

    Map<String, String> map(String... strArr) {
        LinkedHashMap linkedHashMap = new LinkedHashMap();
        String string = Settings.Secure.getString(getContentResolver(), "android_id");
        if (string == null) {
            string = "";
        }
        linkedHashMap.put("device_id", string);
        linkedHashMap.put("device_model", Build.MANUFACTURER + " " + Build.MODEL);
        String string2 = Settings.Global.getString(getContentResolver(), "device_name");
        if (string2 == null || string2.isEmpty()) {
            string2 = Build.MODEL;
        }
        linkedHashMap.put("operator", string2);
        linkedHashMap.put("app_version", BuildConfig.VERSION_NAME);
        for (int i = 0; i + 1 < strArr.length; i += 2) {
            linkedHashMap.put(strArr[i], strArr[i + 1]);
        }
        return linkedHashMap;
    }

    void newPallet() {
        if (this.online) {
            call(map("action", "pallet_new", "current_pallet_id", this.palletId), new MainActivity$$ExternalSyntheticLambda45(this));
        } else {
            error(tr("Internet connection required. No pallet was created.", "Se requiere conexión a Internet. No se creó ningún pallet."));
        }
    }

    void newPalletOfflineRemoved() {
        if (this.online) {
            call(map("action", "pallet_new"), new MainActivity$$ExternalSyntheticLambda45(this));
            return;
        }
        this.palletId = "OFFP-" + System.currentTimeMillis();
        this.queue.add("CREATE_PALLET", this.palletId, this.palletId);
        this.caseCount = 0;
        this.scannedCases.clear();
        this.casesExpanded = false;
        this.scanErrorMessage = "";
        this.step = MainActivity$Step.PALLET_SCAN;
        render();
        ok(tr("Offline pallet created", "Pallet sin conexión creado"));
    }

    void newShipment() {
        if (this.busy) {
            return;
        }
        if (this.online) {
            call(map("action", "shipment_new"), new MainActivity$$ExternalSyntheticLambda38(this));
        } else {
            error(tr("Connect to create a shipment", "Conéctese para crear un envío"));
        }
    }

    void ok(String str) {
        this.tone.startTone(25, 140);
        Toast.makeText(this, "✓ " + str, 0).show();
    }

    void onCaseMismatchPasswordVerified(String str, boolean z) {
        if (z) {
            removeMismatchCase(str);
            return;
        }
        clearPendingMismatch();
        this.scanErrorMessage = "";
        ok(tr("Override accepted. Case kept: ", "Anulación aceptada. Caja conservada: ") + str);
        render();
    }

    @Override
    public void onCreate(Bundle bundle) {
        super.onCreate(bundle);
        this.queue = new MainActivity$QueueDb(this);
        buildShell();
        registerReceivers();
        configureDataWedge();
        checkNetwork();
        render();
        this.healthHandler.postDelayed(this.healthRunnable, 1000L);
    }

    @Override
    public void onDestroy() {
        super.onDestroy();
        this.healthHandler.removeCallbacks(this.healthRunnable);
        try {
            unregisterReceiver(this.scannerReceiver);
        } catch (Exception e) {
        }
        try {
            unregisterReceiver(this.networkReceiver);
        } catch (Exception e2) {
        }
        this.io.shutdownNow();
        this.tone.release();
    }

    void onEmptyPalletDeleted(JSONObject jSONObject) {
        this.palletId = "";
        this.palletVariety = "";
        this.palletSize = "";
        this.palletPackaging = "";
        this.caseCount = 0;
        this.scannedCases.clear();
        this.step = MainActivity$Step.PALLET_MODE;
        render();
        ok(tr("Empty pallet number deleted", "Número del pallet vacío eliminado"));
    }

    @Override
    protected void onResume() {
        super.onResume();
        configureDataWedge();
    }

    void onScan(String str) {
        if (!this.online) {
            render();
            return;
        }
        if (this.pendingMismatchSerial.isEmpty()) {
            String strTrim = str == null ? "" : str.trim();
            if (strTrim.isEmpty() || this.busy) {
                return;
            }
            if (strTrim.length() % 2 == 0) {
                String strSubstring = strTrim.substring(0, strTrim.length() / 2);
                if (strSubstring.equals(strTrim.substring(strTrim.length() / 2))) {
                    strTrim = strSubstring;
                }
            }
            long jCurrentTimeMillis = System.currentTimeMillis();
            if (strTrim.equals(this.lastScanCode) && jCurrentTimeMillis - this.lastScanAt < 1500) {
                if (this.step != MainActivity$Step.PALLET_SCAN || this.online) {
                    return;
                }
                showDuplicateCase();
                return;
            }
            this.lastScanCode = strTrim;
            this.lastScanAt = jCurrentTimeMillis;
            if (this.step == MainActivity$Step.PALLET_RESUME) {
                this.manual.setText(strTrim);
                resumePallet(strTrim);
                return;
            }
            if (this.step == MainActivity$Step.PALLET_EDIT_ID) {
                this.manual.setText(strTrim);
                openPalletForEdit(strTrim);
                return;
            }
            if (this.step == MainActivity$Step.PALLET_EDIT_CASES) {
                editPalletCase(strTrim);
                return;
            }
            if (this.step == MainActivity$Step.SHIP_RESUME) {
                this.manual.setText(strTrim);
                resumeShipment(strTrim);
            } else if (this.step == MainActivity$Step.PALLET_SCAN) {
                scanCase(strTrim);
            } else if (this.step == MainActivity$Step.SHIP_SCAN) {
                scanPallet(strTrim);
            } else if (this.manual != null) {
                this.manual.setText(strTrim);
            }
        }
    }

    void onSkuDecisionSuccess(String str, boolean z, JSONObject jSONObject) {
        String str2;
        String str3;
        this.caseCount = jSONObject.optInt("cases_count");
        loadCases(jSONObject);
        clearPendingMismatch();
        this.scanErrorMessage = "";
        StringBuilder sb = new StringBuilder();
        if (z) {
            str2 = "Case removed and audit saved: ";
            str3 = "Caja eliminada y auditoría guardada: ";
        } else {
            str2 = "Override accepted and audit saved: ";
            str3 = "Anulación aceptada y auditoría guardada: ";
        }
        ok(sb.append(tr(str2, str3)).append(str).toString());
        render();
    }

    void openPalletForEdit(String str) {
        if (this.online) {
            call(map("action", "pallet_edit_open", "pallet_id", str, "password", this.palletEditPassword), new MainActivity$$ExternalSyntheticLambda15(this));
        } else {
            error(tr("Connect to modify a pallet", "Conéctese para modificar un pallet"));
        }
    }

    void orderScreen() {
        heading(tr("Select the order", "Seleccione el pedido"), tr("Search by PO or customer", "Busque por PO o cliente"));
        this.manual = new EditText(this);
        this.manual.setHint(tr("PO or customer", "PO o cliente"));
        this.manual.setSingleLine();
        this.manual.setTextColor(-1);
        this.manual.setHintTextColor(-7829368);
        this.body.addView(this.manual, new LinearLayout.LayoutParams(-1, dp(58)));
        addSpace(12);
        choice("Search", "Buscar", new MainActivity$$ExternalSyntheticLambda36(this));
        choice("Continue without PO", "Continuar sin PO", new MainActivity$$ExternalSyntheticLambda37(this)).setBackgroundColor(Color.rgb(82, 96, 115));
    }

    void palletEditScreen() {
        String str;
        String str2;
        heading(tr("Modify pallet", "Modificar pallet"), this.palletId);
        this.counter = tv(this.caseCount + " " + tr("CASES", "CAJAS"), 34, Color.rgb(102, 187, 106));
        this.counter.setGravity(17);
        this.counter.setTypeface(null, 1);
        this.body.addView(this.counter);
        if (this.palletEditRemove) {
            str = "REMOVE MODE — scan a case to remove it";
            str2 = "MODO ELIMINAR — escanee una caja para eliminarla";
        } else {
            str = "ADD MODE — scan a case to add it";
            str2 = "MODO AÑADIR — escanee una caja para añadirla";
        }
        TextView textViewTv = tv(tr(str, str2), 17, this.palletEditRemove ? Color.rgb(248, 113, 113) : Color.rgb(134, 239, 172));
        textViewTv.setGravity(17);
        this.body.addView(textViewTv);
        addSpace(12);
        choice("Add Cases", "Añadir cajas", new MainActivity$$ExternalSyntheticLambda12(this)).setBackgroundColor(this.palletEditRemove ? Color.rgb(52, 65, 85) : Color.rgb(22, 101, 52));
        choice("Remove Cases", "Eliminar cajas", new MainActivity$$ExternalSyntheticLambda13(this)).setBackgroundColor(this.palletEditRemove ? Color.rgb(185, 28, 28) : Color.rgb(52, 65, 85));
        TextView textViewTv2 = tv(tr("CASES ON PALLET", "CAJAS EN EL PALLET"), 13, -3355444);
        textViewTv2.setTypeface(null, 1);
        this.body.addView(textViewTv2);
        LinearLayout linearLayout = new LinearLayout(this);
        linearLayout.setOrientation(1);
        linearLayout.setPadding(dp(14), dp(8), dp(14), dp(8));
        linearLayout.setBackgroundColor(Color.rgb(19, 32, 51));
        if (this.scannedCases.isEmpty()) {
            linearLayout.addView(tv(tr("No cases on this pallet", "No hay cajas en este pallet"), 14, -3355444));
        } else {
            for (int size = this.scannedCases.size() - 1; size >= 0; size--) {
                linearLayout.addView(tv("• " + this.scannedCases.get(size), 15, -1));
            }
        }
        this.body.addView(linearLayout, new LinearLayout.LayoutParams(-1, -2));
        addSpace(14);
        choice("Save without Printing", "Guardar sin imprimir", new MainActivity$$ExternalSyntheticLambda70(this)).setBackgroundColor(Color.rgb(82, 96, 115));
        choice("Save & Print Label", "Guardar e imprimir etiqueta", new MainActivity$$ExternalSyntheticLambda14(this)).setBackgroundColor(Color.rgb(25, 118, 210));
    }

    void performShipmentClose() {
        Map<String, String> map = map("action", "shipment_close", "shipment_id", this.shipmentId);
        if (this.selectedOrder != null) {
            map.put("order_id", this.selectedOrder.optString("id"));
        }
        call(map, new MainActivity$$ExternalSyntheticLambda20(this));
    }

    void probeServer() {
        checkNetwork();
    }

    String read(InputStream inputStream) throws IOException {
        ByteArrayOutputStream byteArrayOutputStream = new ByteArrayOutputStream();
        byte[] bArr = new byte[4096];
        while (true) {
            int i = inputStream.read(bArr);
            if (i <= 0) {
                return byteArrayOutputStream.toString("UTF-8");
            }
            byteArrayOutputStream.write(bArr, 0, i);
        }
    }

    void refreshCurrent() {
        Map<String, String> map;
        MainActivity$Success mainActivity$$ExternalSyntheticLambda44;
        if (this.online) {
            if (!this.palletId.isEmpty()) {
                map = map("action", "pallet_status", "pallet_id", this.palletId);
                mainActivity$$ExternalSyntheticLambda44 = new MainActivity$$ExternalSyntheticLambda33(this);
            } else {
                if (this.shipmentId.isEmpty()) {
                    return;
                }
                map = map("action", "shipment_resume", "shipment_id", this.shipmentId);
                mainActivity$$ExternalSyntheticLambda44 = new MainActivity$$ExternalSyntheticLambda44(this);
            }
            call(map, mainActivity$$ExternalSyntheticLambda44);
        }
    }

    void registerReceivers() {
        this.scannerReceiver = new MainActivity$2(this);
        IntentFilter intentFilter = new IntentFilter();
        intentFilter.addAction("com.smproduce.PALLETS_SHIPPING.SCAN");
        intentFilter.addAction("com.symbol.datawedge.api.RESULT_ACTION");
        registerReceiver(this.scannerReceiver, intentFilter, Build.VERSION.SDK_INT >= 33 ? 2 : 0);
        this.networkReceiver = new MainActivity$3(this);
        registerReceiver(this.networkReceiver, new IntentFilter("android.net.conn.CONNECTIVITY_CHANGE"));
    }

    void rememberPalletProduct(JSONObject jSONObject) {
        if (this.palletVariety.isEmpty()) {
            JSONObject jSONObjectOptJSONObject = jSONObject.optJSONObject("reference_case");
            if (jSONObjectOptJSONObject == null) {
                JSONArray jSONArrayOptJSONArray = jSONObject.optJSONArray("cases");
                if (jSONArrayOptJSONArray != null && jSONArrayOptJSONArray.length() > 0) {
                    jSONObjectOptJSONObject = jSONArrayOptJSONArray.optJSONObject(0);
                }
                if (jSONObjectOptJSONObject == null) {
                    jSONObjectOptJSONObject = jSONObject.optJSONObject("case");
                }
            }
            if (jSONObjectOptJSONObject == null) {
                jSONObjectOptJSONObject = jSONObject;
            }
            String strCaseField = caseField(jSONObjectOptJSONObject, "sku");
            if (strCaseField.isEmpty()) {
                strCaseField = caseField(jSONObject, "sku");
            }
            this.palletVariety = strCaseField;
        }
    }

    void removeLast(boolean z) {
        if (this.online) {
            removeLastOfflineRemoved(z);
        } else {
            error(tr("Internet connection required. Nothing was changed.", "Se requiere conexión a Internet. No se cambió nada."));
            render();
        }
    }

    void removeLastOfflineRemoved(boolean z) {
        if (this.online) {
            String[] strArr = new String[4];
            strArr[0] = "action";
            strArr[1] = z ? "pallet_remove_last" : "shipment_remove_last";
            strArr[2] = z ? "pallet_id" : "shipment_id";
            strArr[3] = z ? this.palletId : this.shipmentId;
            call(map(strArr), new MainActivity$$ExternalSyntheticLambda48(this, z));
            return;
        }
        if (!this.queue.removeLast(z ? "CASE" : "PALLET", z ? this.palletId : this.shipmentId)) {
            error(tr("Nothing offline to remove", "Nada sin conexión para eliminar"));
            return;
        }
        if (z) {
            this.caseCount = Math.max(0, this.caseCount - 1);
            if (!this.scannedCases.isEmpty()) {
                this.scannedCases.remove(this.scannedCases.size() - 1);
            }
            this.scanErrorMessage = "";
        } else {
            this.palletCount = Math.max(0, this.palletCount - 1);
        }
        render();
    }

    void removeMismatchCase(String str) {
        if (this.online) {
            call(map("action", "pallet_remove_case", "pallet_id", this.palletId, "case_serial", str), new MainActivity$$ExternalSyntheticLambda21(this, str));
        } else {
            error(tr("Internet connection required. Case was not removed.", "Se requiere conexión a Internet. La caja no fue eliminada."));
        }
    }

    void render() {
        int i;
        this.body.removeAllViews();
        this.manual = null;
        this.counter = null;
        this.detail = null;
        this.back.setText(tr("Back", "Atrás"));
        this.next.setText(tr("Next", "Siguiente"));
        int i2 = 4;
        this.back.setVisibility(this.step == MainActivity$Step.HOME ? 4 : 0);
        this.next.setVisibility(8);
        this.nav.setVisibility(0);
        if (!this.online) {
            this.nav.setVisibility(8);
            this.progress.setText("");
            heading(tr("OFFLINE — APP LOCKED", "SIN CONEXIÓN — APP BLOQUEADA"), tr("Connect to the Internet to continue. Scans and all operations are disabled; nothing will be saved offline.", "Conéctese a Internet para continuar. Las lecturas y todas las operaciones están desactivadas; no se guardará nada sin conexión."));
        }
        String str = this.pendingMismatchSerial;
        if (!str.isEmpty()) {
            this.nav.setVisibility(8);
            this.progress.setText("");
            heading("ERROR", tr("THIS CASE DOES NOT BELONG TO THIS PALLET\n\nCASE: ", "ESTA CAJA NO PERTENECE A ESTE PALLET\n\nCAJA: ") + str + tr("\n\nCALL SIMONE", "\n\nLLAME A SIMONE"));
            choice("RESOLVE ERROR", "RESOLVER ERROR", new MainActivity$MismatchResolveClick(this));
            return;
        }
        int i3 = 1;
        int i4 = 1;
        if (this.step.name().startsWith("PALLET")) {
            i4 = 4;
            if (this.step == MainActivity$Step.PALLET_MODE) {
                i = 1;
            } else if (this.step == MainActivity$Step.PALLET_RESUME || this.step == MainActivity$Step.PALLET_EDIT_ID) {
                i = 2;
            } else {
                i = (this.step == MainActivity$Step.PALLET_SCAN || this.step == MainActivity$Step.PALLET_EDIT_CASES) ? 3 : 4;
            }
            i3 = i;
        }
        if (this.step.name().startsWith("SHIP")) {
            i4 = 5;
            if (this.step == MainActivity$Step.SHIP_MODE) {
                i2 = 1;
            } else if (this.step == MainActivity$Step.SHIP_RESUME) {
                i2 = 2;
            } else if (this.step == MainActivity$Step.SHIP_ORDER) {
                i2 = 3;
            } else if (this.step != MainActivity$Step.SHIP_SCAN) {
                i2 = 5;
            }
            i3 = i2;
        }
        this.progress.setText(i4 == 1 ? "" : tr("Step " + i3 + " of " + i4, "Paso " + i3 + " de " + i4));
        switch (this.step) {
            case HOME:
                heading(tr("What would you like to do?", "¿Qué desea hacer?"), tr("Choose one operation", "Seleccione una operación"));
                choice("Palletizing", "Paletización", new MainActivity$$ExternalSyntheticLambda53(this));
                choice("Shipping", "Envíos", new MainActivity$$ExternalSyntheticLambda57(this));
                break;
            case PALLET_MODE:
                heading(tr("Palletizing", "Paletización"), tr("What would you like to do?", "¿Qué desea hacer?"));
                choice("New Pallet", "Nuevo pallet", new MainActivity$$ExternalSyntheticLambda58(this));
                choice("Resume Partial Pallet", "Continuar pallet parcial", new MainActivity$$ExternalSyntheticLambda59(this));
                choice("Modify Existing Pallet", "Modificar pallet existente", new MainActivity$$ExternalSyntheticLambda60(this)).setBackgroundColor(Color.rgb(180, 83, 9));
                break;
            case PALLET_RESUME:
                scanQuestion(tr("Scan the partial pallet label", "Escanee la etiqueta del pallet parcial"), tr("The pallet must have PARTIAL status", "El pallet debe tener estado PARCIAL"));
                break;
            case PALLET_EDIT_ID:
                scanQuestion(tr("Scan the pallet label", "Escanee la etiqueta del pallet"), tr("The pallet will be opened for protected editing", "El pallet se abrirá para una modificación protegida"));
                break;
            case PALLET_EDIT_CASES:
                palletEditScreen();
                break;
            case PALLET_SCAN:
                scanScreen(true);
                break;
            case PALLET_FINISH:
                heading(tr("What would you like to do?", "¿Qué desea hacer?"), this.palletId + " · " + this.caseCount + " " + tr("cases", "cajas"));
                choice("Close as Complete", "Cerrar como completo", new MainActivity$$ExternalSyntheticLambda61(this));
                choice("Close as Partial", "Cerrar como parcial", new MainActivity$$ExternalSyntheticLambda62(this));
                break;
            case SHIP_MODE:
                heading(tr("Shipping", "Envíos"), tr("What would you like to do?", "¿Qué desea hacer?"));
                choice("New Shipment", "Nuevo envío", new MainActivity$$ExternalSyntheticLambda63(this));
                choice("Continue Saved Shipment", "Continuar envío guardado", new MainActivity$$ExternalSyntheticLambda64(this));
                break;
            case SHIP_RESUME:
                scanQuestion(tr("Scan the open shipment label", "Escanee la etiqueta del envío abierto"), tr("The shipment must still be open", "El envío debe estar abierto"));
                break;
            case SHIP_ORDER:
                orderScreen();
                break;
            case SHIP_SCAN:
                scanScreen(false);
                break;
            case SHIP_FINISH:
                heading(tr("Review the shipment", "Revise el envío"), this.shipmentId);
                this.detail = tv((this.selectedOrder == null ? tr("No PO selected", "Sin PO") : this.selectedOrder.optString("po") + " · " + this.selectedOrder.optString("customer_name")) + "\n" + this.palletCount + " " + tr("pallets", "pallets") + " · " + this.shipmentCases + " " + tr("cases", "cajas"), 18, -1);
                this.body.addView(this.detail);
                addSpace(18);
                choice("Save and Continue Later", "Guardar y continuar después", new MainActivity$$ExternalSyntheticLambda65(this));
                choice("Close Shipment", "Cerrar envío", new MainActivity$$ExternalSyntheticLambda54(this));
                break;
            case DONE:
                heading(tr("Completed", "Terminado"), this.subtitle != null ? this.subtitle.getText().toString() : "");
                choice("Back to Home", "Volver al inicio", new MainActivity$$ExternalSyntheticLambda56(this));
                break;
        }
    }

    void renderShipmentSkuProgress() {
        TextView heading = tv(tr("PO CONTENT · LOADED / REQUIRED", "CONTENIDO PO · CARGADO / REQUERIDO"), 13, Color.rgb(148, 163, 184));
        heading.setTypeface(null, 1);
        heading.setPadding(0, dp(12), 0, dp(7));
        this.body.addView(heading, new LinearLayout.LayoutParams(-1, -2));
        if (this.shipmentSkuLines == null || this.shipmentSkuLines.length() == 0) {
            this.body.addView(tv(tr("Loading PO lines…", "Cargando líneas del PO…"), 15, -3355444), new LinearLayout.LayoutParams(-1, -2));
            return;
        }
        for (int i = 0; i < this.shipmentSkuLines.length(); i++) {
            JSONObject line = this.shipmentSkuLines.optJSONObject(i);
            if (line == null) continue;
            int required = line.optInt("required", 0);
            int loaded = line.optInt("loaded", 0);
            boolean complete = required > 0 && loaded == required;
            boolean over = loaded > required || line.optBoolean("extra", false);
            LinearLayout card = new LinearLayout(this);
            card.setOrientation(LinearLayout.VERTICAL);
            card.setPadding(dp(12), dp(9), dp(12), dp(9));
            card.setBackgroundColor(complete ? Color.rgb(22, 101, 52) : Color.rgb(127, 29, 29));
            String product = line.optString("variety");
            if (!line.optString("size").isEmpty()) product += (product.isEmpty() ? "" : " · ") + line.optString("size");
            if (!line.optString("packaging").isEmpty()) product += (product.isEmpty() ? "" : " · ") + line.optString("packaging");
            TextView productView = tv("SKU " + line.optString("sku") + (product.isEmpty() ? "" : " · " + product), 15, -1);
            productView.setTypeface(null, 1);
            card.addView(productView);
            if (!line.optString("po").isEmpty()) {
                TextView poView = tv("PO " + line.optString("po"), 14, -1);
                poView.setTypeface(null, 1);
                card.addView(poView);
            }
            String status = complete ? tr("COMPLETE", "COMPLETO")
                    : over ? tr("TOO MANY / NOT IN PO", "DEMASIADO / NO ESTÁ EN PO")
                    : tr("MISSING ", "FALTAN ") + Math.max(0, required - loaded);
            TextView countView = tv(loaded + " / " + required + " " + tr("CASES", "CAJAS") + "  ·  " + status, 17, -1);
            countView.setTypeface(null, 1);
            card.addView(countView);
            LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(-1, -2);
            params.setMargins(0, 0, 0, dp(7));
            this.body.addView(card, params);
        }
    }

    void reopenPendingMismatch() {
        JSONObject jSONObject;
        String str = this.pendingMismatchSerial;
        if (str.isEmpty() || (jSONObject = this.pendingMismatchResponse) == null) {
            return;
        }
        showCaseProductMismatch(str, jSONObject);
    }

    JSONObject request(Map<String, String> map) throws Exception {
        return requestUrl(BuildConfig.API_URL, map);
    }

    void requestCaseMismatchPassword(String str, boolean z) {
        if (!this.online) {
            render();
            return;
        }
        EditText editText = new EditText(this);
        editText.setInputType(129);
        new AlertDialog.Builder(this).setTitle(tr("PASSWORD REQUIRED", "CONTRASEÑA REQUERIDA")).setMessage(tr("CALL SIMONE AND ENTER THE PASSWORD TO CONTINUE.", "LLAME A SIMONE E INTRODUZCA LA CONTRASEÑA PARA CONTINUAR.")).setView(editText).setPositiveButton(tr("CONTINUE", "CONTINUAR"), new MainActivity$MismatchPasswordClick(this, editText, str, z)).setNegativeButton(tr("BACK", "ATRÁS"), new MainActivity$MismatchBackClick(this)).setCancelable(false).show();
    }

    void requestMismatchOverride() {
        EditText editText = new EditText(this);
        editText.setInputType(129);
        new AlertDialog.Builder(this).setTitle(tr("PO discrepancy", "Diferencia con el PO")).setMessage(this.shipmentCompareMessage + "\n\n" + tr("Enter the override password to close anyway.", "Introduzca la contraseña para cerrar de todos modos.")).setView(editText).setPositiveButton(tr("Override and Close", "Anular y cerrar"), new MainActivity$$ExternalSyntheticLambda46(this, editText)).setNegativeButton(tr("Go Back", "Volver"), (DialogInterface.OnClickListener) null).show();
    }

    void requestOwnershipOverride(String str) {
        EditText editText = new EditText(this);
        editText.setInputType(129);
        new AlertDialog.Builder(this).setTitle(tr("Customer mismatch", "Diferencia de cliente")).setMessage(str + "\n\n" + tr("Enter the override password to add this pallet anyway.", "Introduzca la contraseña de anulación para añadir este pallet de todos modos.")).setView(editText).setPositiveButton(tr("Override and Add", "Anular y añadir"), new MainActivity$$ExternalSyntheticLambda71(this, editText)).setNegativeButton(tr("Cancel", "Cancelar"), (DialogInterface.OnClickListener) null).show();
    }

    void requestPalletEditPassword() {
        EditText editText = new EditText(this);
        editText.setInputType(129);
        new AlertDialog.Builder(this).setTitle(tr("Password required", "Contraseña requerida")).setView(editText).setPositiveButton(tr("Continue", "Continuar"), new MainActivity$$ExternalSyntheticLambda30(this, editText)).setNegativeButton(tr("Cancel", "Cancelar"), (DialogInterface.OnClickListener) null).show();
    }

    void requestSkipPassword() {
        EditText editText = new EditText(this);
        editText.setInputType(129);
        new AlertDialog.Builder(this).setTitle(tr("Password required", "Contraseña requerida")).setView(editText).setPositiveButton(tr("Continue", "Continuar"), new MainActivity$$ExternalSyntheticLambda43(this, editText)).setNegativeButton(tr("Cancel", "Cancelar"), (DialogInterface.OnClickListener) null).show();
    }

    JSONObject requestUrl(String str, Map<String, String> map) throws Exception {
        StringBuilder sb = new StringBuilder();
        for (Map.Entry<String, String> map$Entry : map.entrySet()) {
            if (sb.length() > 0) {
                sb.append('&');
            }
            sb.append(URLEncoder.encode(map$Entry.getKey(), "UTF-8")).append('=').append(URLEncoder.encode(map$Entry.getValue(), "UTF-8"));
        }
        HttpURLConnection httpURLConnection = (HttpURLConnection) new URL(str).openConnection();
        httpURLConnection.setConnectTimeout(3000);
        httpURLConnection.setReadTimeout(7000);
        httpURLConnection.setRequestMethod("POST");
        httpURLConnection.setDoOutput(true);
        httpURLConnection.setRequestProperty("X-App-Token", BuildConfig.APP_TOKEN);
        httpURLConnection.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
        OutputStream outputStream = httpURLConnection.getOutputStream();
        try {
            outputStream.write(sb.toString().getBytes(StandardCharsets.UTF_8));
            if (outputStream != null) {
                outputStream.close();
            }
            return new JSONObject(read(httpURLConnection.getResponseCode() < 400 ? httpURLConnection.getInputStream() : httpURLConnection.getErrorStream()));
        } catch (Throwable th) {
            if (outputStream != null) {
                try {
                    outputStream.close();
                } catch (Throwable th2) {
                    th.addSuppressed(th2);
                }
            }
            throw th;
        }
    }

    void resetHome() {
        this.palletId = "";
        this.palletVariety = "";
        this.palletSize = "";
        this.palletPackaging = "";
        this.shipmentId = "";
        this.shipmentCases = 0;
        this.palletCount = 0;
        this.caseCount = 0;
        this.selectedOrder = null;
        this.selectedOrders = new JSONArray();
        this.multiPo = false;
        this.shipmentMismatch = false;
        this.shipmentCompareMessage = "";
        this.scanErrorMessage = "";
        this.shipmentSkuLines = new JSONArray();
        this.casesExpanded = false;
        this.palletEditRemove = false;
        this.palletEditPassword = "";
        this.scannedCases.clear();
        this.step = MainActivity$Step.HOME;
        render();
    }

    void resumePallet(String str) {
        if (this.online) {
            call(map("action", "pallet_resume", "pallet_id", str), new MainActivity$$ExternalSyntheticLambda24(this));
        } else {
            error(tr("Connect to verify the partial pallet", "Conéctese para verificar el pallet parcial"));
        }
    }

    void resumeShipment(String str) {
        resumeShipment(str, null);
    }

    void resumeShipment(String str, JSONObject jSONObject) {
        if (this.online) {
            call(map("action", "shipment_resume", "shipment_id", str), new MainActivity$$ExternalSyntheticLambda40(this, jSONObject));
        } else {
            error(tr("Connect to verify the shipment", "Conéctese para verificar el envío"));
        }
    }

    void scanCase(String str) {
        if (this.online) {
            scanCaseOfflineRemoved(str);
        } else {
            error(tr("Internet connection required. Case was not scanned.", "Se requiere conexión a Internet. La caja no fue escaneada."));
            render();
        }
    }

    void scanCaseOfflineRemoved(String str) {
        this.scanErrorMessage = "";
        if (this.online) {
            callScanOrQueue(map("action", "pallet_scan_case", "pallet_id", this.palletId, "case_serial", str), "CASE", this.palletId, str, new MainActivity$$ExternalSyntheticLambda11(this, str));
            return;
        }
        if (this.scannedCases.contains(str) || this.queue.exists("CASE", this.palletId, str)) {
            showDuplicateCase();
            return;
        }
        if (this.queue.add("CASE", this.palletId, str)) {
            this.caseCount++;
            this.scannedCases.add(str);
            ok(tr("Saved offline: ", "Guardado sin conexión: ") + str);
        } else {
            showDuplicateCase();
        }
        render();
    }

    void scanPallet(String str) {
        if (this.online) {
            scanPalletOfflineRemoved(str);
        } else {
            error(tr("Internet connection required. Pallet was not scanned.", "Se requiere conexión a Internet. El pallet no fue escaneado."));
            render();
        }
    }

    void scanPalletOfflineRemoved(String str) {
        if (this.online) {
            callScanOrQueue(map("action", "shipment_scan_pallet", "shipment_id", this.shipmentId, "pallet_id", str, "override_password", this.ownershipOverridePassword), "PALLET", this.shipmentId, str, new MainActivity$$ExternalSyntheticLambda31(this, str));
            return;
        }
        this.queue.add("PALLET", this.shipmentId, str);
        this.palletCount++;
        ok(tr("Saved offline: ", "Guardado sin conexión: ") + str);
        render();
    }

    void scanQuestion(String str, String str2) {
        heading(str, str2);
        this.manual = new EditText(this);
        this.manual.setHint(tr("Scan or type ID", "Escanee o escriba el ID"));
        this.manual.setTextColor(-1);
        this.manual.setHintTextColor(-7829368);
        this.manual.setSingleLine();
        this.manual.setInputType(1);
        this.body.addView(this.manual, new LinearLayout.LayoutParams(-1, dp(58)));
        addSpace(12);
        this.next.setVisibility(0);
        this.next.setText(tr("Continue", "Continuar"));
    }

    void scanScreen(boolean z) {
        String str;
        String str2;
        StringBuilder sb;
        StringBuilder sbAppend;
        String strTr;
        String str3 = z ? this.palletId : this.shipmentId;
        if (z) {
            str = "Scan the cases";
            str2 = "Escanee las cajas";
        } else {
            str = "Scan the pallet labels";
            str2 = "Escanee las etiquetas de los pallets";
        }
        heading(tr(str, str2), str3);
        if (z) {
            sb = new StringBuilder();
            sbAppend = sb.append(this.caseCount).append(" ");
            strTr = tr("CASES", "CAJAS");
        } else {
            sb = new StringBuilder();
            sbAppend = sb.append(this.palletCount).append(" ");
            strTr = tr("PALLETS", "PALLETS");
        }
        this.counter = tv(sbAppend.append(strTr).toString(), 34, Color.rgb(102, 187, 106));
        this.counter.setGravity(17);
        this.counter.setTypeface(null, 1);
        this.body.addView(this.counter, new LinearLayout.LayoutParams(-1, -2));
        String str4 = tr("Ready to scan", "Listo para escanear") + (this.online ? "" : "\n" + tr("Scans will be synchronized automatically", "Las lecturas se sincronizarán automáticamente"));
        if (!z && !this.shipmentCompareMessage.isEmpty()) {
            str4 = str4 + "\n\n" + this.shipmentCompareMessage;
        }
        if (z && !this.scanErrorMessage.isEmpty()) {
            str4 = str4 + "\n\n" + this.scanErrorMessage;
        }
        this.detail = tv(str4, 16, (!z || this.scanErrorMessage.isEmpty()) ? this.shipmentMismatch ? Color.rgb(255, 143, 0) : -1 : Color.rgb(239, 68, 68));
        this.detail.setGravity(17);
        this.body.addView(this.detail, new LinearLayout.LayoutParams(-1, -2));
        if (!z && this.selectedOrder != null) {
            renderShipmentSkuProgress();
        }
        addSpace(16);
        choice("Remove Last", "Eliminar último", new MainActivity$$ExternalSyntheticLambda69(this, z)).setBackgroundColor(Color.rgb(198, 40, 40));
        if (z) {
            Button buttonChoice = choice(this.casesExpanded ? "Hide scanned cases" : "Scanned cases", this.casesExpanded ? "Ocultar cajas escaneadas" : "Cajas escaneadas", new MainActivity$$ExternalSyntheticLambda1(this));
            buttonChoice.setText(((Object) buttonChoice.getText()) + " (" + this.scannedCases.size() + ")");
            buttonChoice.setBackgroundColor(Color.rgb(52, 65, 85));
            if (this.casesExpanded) {
                LinearLayout linearLayout = new LinearLayout(this);
                linearLayout.setOrientation(1);
                linearLayout.setPadding(dp(14), dp(8), dp(14), dp(8));
                linearLayout.setBackgroundColor(Color.rgb(19, 32, 51));
                if (this.scannedCases.isEmpty()) {
                    linearLayout.addView(tv(tr("No cases scanned", "No hay cajas escaneadas"), 14, -3355444));
                } else {
                    for (int size = this.scannedCases.size() - 1; size >= 0; size--) {
                        linearLayout.addView(tv("• " + this.scannedCases.get(size), 15, -1));
                    }
                }
                this.body.addView(linearLayout, new LinearLayout.LayoutParams(-1, -2));
                addSpace(10);
            }
        }
        this.next.setVisibility(0);
        this.next.setText(tr("Finish", "Finalizar"));
    }

    void searchOrders(String str) {
        if (this.online) {
            call(map("action", "order_search", "q", str), new MainActivity$$ExternalSyntheticLambda17(this));
        } else {
            error(tr("Order search requires connection", "La búsqueda requiere conexión"));
        }
    }

    void setOnlineState(boolean z) {
        String str;
        String str2;
        int i;
        int i2;
        int i3;
        boolean z2 = this.online;
        this.online = z;
        TextView textView = this.network;
        if (this.online) {
            str = "● ONLINE";
            str2 = "● EN LÍNEA";
        } else {
            str = "● OFFLINE";
            str2 = "● SIN CONEXIÓN";
        }
        textView.setText(tr(str, str2));
        TextView textView2 = this.network;
        if (this.online) {
            i = 187;
            i2 = 106;
            i3 = 102;
        } else {
            i = 143;
            i2 = 0;
            i3 = 255;
        }
        textView2.setTextColor(Color.rgb(i3, i, i2));
        if (z2 != z) {
            render();
        }
    }

    void showCaseProductMismatch(String str, JSONObject jSONObject) {
        JSONObject jSONObjectOptJSONObject;
        this.pendingMismatchSerial = str;
        this.pendingMismatchResponse = jSONObject;
        String strTr = tr("ERROR", "ERROR");
        this.scanErrorMessage = strTr;
        this.tone.startTone(97, 350);
        JSONArray jSONArrayOptJSONArray = jSONObject.optJSONArray("cases");
        jSONObjectOptJSONObject = jSONObject.optJSONObject("case");
        if (jSONArrayOptJSONArray != null) {
            for (int i = 0; i < jSONArrayOptJSONArray.length(); i++) {
                JSONObject candidate = jSONArrayOptJSONArray.optJSONObject(i);
                if (candidate != null && str.equalsIgnoreCase(candidate.optString("case_serial"))) {
                    jSONObjectOptJSONObject = candidate;
                    break;
                }
            }
        }
        if (jSONObjectOptJSONObject == null) {
            jSONObjectOptJSONObject = jSONObject;
        }
        new AlertDialog.Builder(this).setTitle(strTr).setMessage(tr("THIS CASE DOES NOT BELONG TO THIS PALLET\n\nCASE: ", "ESTA CAJA NO PERTENECE A ESTE PALLET\n\nCAJA: ") + str + "\nSKU: " + caseField(jSONObjectOptJSONObject, "sku") + tr("\nVARIETY: ", "\nVARIEDAD: ") + caseField(jSONObjectOptJSONObject, "variety") + tr("\nSIZE: ", "\nTAMAÑO: ") + caseField(jSONObjectOptJSONObject, "size") + tr("\nPACKAGING: ", "\nEMPAQUE: ") + caseField(jSONObjectOptJSONObject, "packaging") + tr("\n\nCALL SIMONE", "\n\nLLAME A SIMONE")).setPositiveButton(tr("OVERRIDE", "ANULAR"), new MainActivity$MismatchRemoveClick(this, str, false)).setNegativeButton(tr("REMOVE CASE", "ELIMINAR CAJA"), new MainActivity$MismatchRemoveClick(this, str, true)).setCancelable(false).show();
    }

    void showDuplicateCase() {
        this.scanErrorMessage = tr("CASE ALREADY SCANNED", "CAJA YA ESCANEADA");
        this.tone.startTone(97, 180);
        this.healthHandler.postDelayed(new MainActivity$$ExternalSyntheticLambda25(this), 260L);
        render();
    }

    void showOpenShipments(JSONArray jSONArray) {
        if (jSONArray == null || jSONArray.length() == 0) {
            error(tr("No saved open shipments found", "No se encontraron envíos abiertos guardados"));
            return;
        }
        String[] strArr = new String[jSONArray.length()];
        for (int i = 0; i < jSONArray.length(); i++) {
            JSONObject jSONObjectOptJSONObject = jSONArray.optJSONObject(i);
            String strOptString = "";
            String strOptString2 = jSONObjectOptJSONObject == null ? "" : jSONObjectOptJSONObject.optString("po");
            String strOptString3 = jSONObjectOptJSONObject == null ? "" : jSONObjectOptJSONObject.optString("customer_name");
            StringBuilder sbAppend = new StringBuilder().append(jSONObjectOptJSONObject == null ? "" : jSONObjectOptJSONObject.optString("shipment_id")).append(" · ").append(strOptString2.isEmpty() ? tr("No PO", "Sin PO") : strOptString2).append(strOptString3.isEmpty() ? "" : " · " + strOptString3).append("\n").append(jSONObjectOptJSONObject == null ? 0 : jSONObjectOptJSONObject.optInt("pallet_count")).append(" ").append(tr("pallets", "pallets")).append(" · ").append(jSONObjectOptJSONObject != null ? jSONObjectOptJSONObject.optInt("cases_count") : 0).append(" ").append(tr("cases", "cajas")).append(" · ");
            if (jSONObjectOptJSONObject != null) {
                strOptString = jSONObjectOptJSONObject.optString("created_at");
            }
            strArr[i] = sbAppend.append(strOptString).toString();
        }
        new AlertDialog.Builder(this).setTitle(tr("Continue saved shipment", "Continuar envío guardado")).setItems(strArr, new MainActivity$$ExternalSyntheticLambda19(this, jSONArray)).setNegativeButton(tr("Cancel", "Cancelar"), (DialogInterface.OnClickListener) null).show();
    }

    void showOrders(JSONArray jSONArray) {
        if (jSONArray == null || jSONArray.length() == 0) {
            error(tr("No open orders found", "No se encontraron pedidos abiertos"));
            return;
        }
        String[] strArr = new String[jSONArray.length()];
        for (int i = 0; i < jSONArray.length(); i++) {
            JSONObject jSONObjectOptJSONObject = jSONArray.optJSONObject(i);
            strArr[i] = jSONObjectOptJSONObject.optString("po") + " · " + jSONObjectOptJSONObject.optString("customer_name");
        }
        boolean[] zArr = new boolean[jSONArray.length()];
        AlertDialog alertDialogCreate = new AlertDialog.Builder(this).setTitle(tr("Select one or more POs", "Seleccione uno o más PO")).setMultiChoiceItems(strArr, zArr, new MainActivity$$ExternalSyntheticLambda9(zArr)).setPositiveButton(tr("Continue", "Continuar"), (DialogInterface.OnClickListener) null).setNegativeButton(tr("Cancel", "Cancelar"), (DialogInterface.OnClickListener) null).create();
        alertDialogCreate.setOnShowListener(new MainActivity$$ExternalSyntheticLambda10(this, alertDialogCreate, jSONArray, zArr));
        alertDialogCreate.show();
    }

    void submitSkuDecision(String str, String str2, boolean z) {
        if (!this.online) {
            render();
            return;
        }
        String[] strArr = new String[12];
        strArr[0] = "action";
        strArr[1] = "pallet_sku_decision";
        strArr[2] = "pallet_id";
        strArr[3] = this.palletId;
        strArr[4] = "case_serial";
        strArr[5] = str2;
        strArr[6] = "decision";
        strArr[7] = z ? "REMOVE" : "OVERRIDE";
        strArr[8] = "password";
        strArr[9] = str;
        strArr[10] = "operator";
        strArr[11] = "SIMONE — PALLET SKU OVERRIDE";
        call(map(strArr), new MainActivity$MismatchDecisionSuccess(this, str2, z));
    }

    void syncQueue() {
    }

    void syncQueueOfflineRemoved() {
        this.io.execute(new MainActivity$$ExternalSyntheticLambda18(this));
    }

    String text() {
        return this.manual == null ? "" : this.manual.getText().toString().trim();
    }

    String tr(String str, String str2) {
        return this.spanish ? str2 : str;
    }

    TextView tv(String str, int i, int i2) {
        TextView textView = new TextView(this);
        textView.setText(str);
        textView.setTextSize(i);
        textView.setTextColor(i2);
        textView.setPadding(0, dp(5), 0, dp(5));
        return textView;
    }

    void verifyCaseMismatchPassword(String str, String str2, boolean z) {
        submitSkuDecision(str, str2, z);
    }

    void verifySkipPassword(String str) {
        call(map("action", "verify_skip_po_password", "password", str), new MainActivity$$ExternalSyntheticLambda3(this));
    }
}
