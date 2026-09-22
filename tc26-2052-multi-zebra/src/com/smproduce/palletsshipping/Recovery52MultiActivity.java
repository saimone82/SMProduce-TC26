package com.smproduce.palletsshipping;

import android.app.AlertDialog;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.provider.Settings;
import android.text.InputType;
import android.widget.EditText;
import android.widget.Toast;
import java.util.Map;
import java.util.UUID;
import org.json.JSONObject;

/**
 * Thin overlay on the real 2.0.52 launcher.  It does not replace any of the
 * original scanner, pallet, BOL, recovery or Modify Pallet code.
 */
public class Recovery52MultiActivity extends Recovery36Activity {
    private static final String API = "https://smproduceprod.uk/api/pallets_shipping_app.php";
    private static final String PREFS = "smproduce_tc26_multi_zebra";
    private static final String DEVICE_KEY = "device_id";
    private static final String APP_VERSION = "2.0.53-multi-zebra";

    private String deviceId = "";
    private String lastNotice = "";
    private boolean noticeVisible;

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        deviceId = prefs.getString(DEVICE_KEY, "");
        if (deviceId == null || deviceId.length() < 8) {
            String androidId = Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID);
            if (androidId == null || androidId.length() < 8) androidId = UUID.randomUUID().toString();
            deviceId = "tc26-" + androidId.replaceAll("[^A-Za-z0-9._:-]", "");
            prefs.edit().putString(DEVICE_KEY, deviceId).apply();
        }
    }

    @Override Map<String,String> map(String... values) {
        Map<String,String> request = super.map(values);
        request.put("client_device_id", deviceId);
        request.put("app_version", APP_VERSION);
        return request;
    }

    @Override void callUrl(String url, Map<String,String> request, Success callback) {
        final String action = request.get("action");
        final String shipmentId = request.get("shipment_id");
        super.callUrl(url, request, json -> {
            callback.run(json);
            if ("shipment_resume".equals(action) || "shipment_set_order".equals(action)
                    || "shipment_scan_pallet".equals(action)) {
                showCollaborationNotice(json, shipmentId);
            }
        });
    }

    private void showCollaborationNotice(JSONObject json, String shipmentId) {
        final String notice = json.optString("collaboration_notice", "").trim();
        if (notice.isEmpty() || noticeVisible || notice.equals(lastNotice)) return;
        lastNotice = notice;
        noticeVisible = true;
        new AlertDialog.Builder(this)
                .setTitle("Shipment already active")
                .setMessage(notice + "\n\nTake Over blocks shipment operations on other Zebras only. Palletizing remains available.")
                .setPositiveButton("CONTINUE", null)
                .setNeutralButton("TAKE OVER", (dialog, which) -> requestTakeOver(shipmentId))
                .setOnDismissListener(dialog -> noticeVisible = false)
                .show();
    }

    private void requestTakeOver(String shipmentId) {
        if (shipmentId == null || shipmentId.trim().isEmpty()) return;
        final EditText password = new EditText(this);
        password.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        password.setHint("Password");
        int pad = (int)(20 * getResources().getDisplayMetrics().density);
        password.setPadding(pad, 0, pad, 0);
        new AlertDialog.Builder(this)
                .setTitle("Take Over Shipment")
                .setMessage("This blocks shipment operations on the other Zebras. It does not block palletizing.")
                .setView(password)
                .setNegativeButton("CANCEL", null)
                .setPositiveButton("TAKE OVER", (dialog, which) -> {
                    Map<String,String> request = map(
                            "action", "shipment_takeover",
                            "shipment_id", shipmentId,
                            "takeover_password", password.getText().toString());
                    callUrl(API, request, json -> {
                        if (json.optInt("ok") == 1) {
                            Toast.makeText(this, "Shipment taken over on this Zebra", Toast.LENGTH_LONG).show();
                        } else {
                            error(json.optString("err", "Shipment Take Over was not accepted"));
                        }
                    });
                })
                .show();
    }
}
