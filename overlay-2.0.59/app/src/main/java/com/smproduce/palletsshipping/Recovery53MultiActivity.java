package com.smproduce.palletsshipping;

import android.app.AlertDialog;
import android.content.DialogInterface;
import android.graphics.Color;
import android.os.Build;
import android.provider.Settings;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.widget.Button;
import android.widget.CheckBox;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.lang.reflect.Field;
import java.lang.reflect.Method;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.Map;

public class Recovery53MultiActivity extends Recovery52MultiActivity {
    private static final String API_URL = "https://smproduceprod.uk/api/pallets_shipping_app.php";
    private static final String APP_TOKEN = "SMPS_2026_8e04f770b72d4ceeb9d9";

    private int dp2(int n) {
        return Math.round(n * getResources().getDisplayMetrics().density);
    }

    private TextView label(String text, int sp, int color) {
        TextView v = new TextView(this);
        v.setText(text);
        v.setTextSize(sp);
        v.setTextColor(color);
        v.setPadding(0, dp2(4), 0, dp2(4));
        return v;
    }

    private Button smallButton(String text) {
        Button b = new Button(this);
        b.setText(text);
        b.setTextColor(Color.WHITE);
        b.setTextSize(18);
        b.setAllCaps(false);
        b.setBackgroundColor(Color.rgb(52, 65, 85));
        return b;
    }

    private String productLabel(JSONObject member) {
        if (member == null) return "";
        String variety = member.optString("variety", "").trim();
        String size = member.optString("size", "").trim();
        String packaging = member.optString("packaging", member.optString("packaging_preset", "")).trim();

        StringBuilder out = new StringBuilder();
        if (!variety.isEmpty()) out.append(variety);
        if (!size.isEmpty()) out.append(out.length() > 0 ? " " : "").append(size);
        if (!packaging.isEmpty()) out.append(out.length() > 0 ? " · " : "").append(packaging);
        return out.toString().trim();
    }

    private String compactOrMembers(JSONArray members) {
        if (members == null || members.length() == 0) return "";

        String commonVariety = "";
        String commonPackaging = "";
        ArrayList<String> sizes = new ArrayList<>();
        ArrayList<String> fallback = new ArrayList<>();

        for (int i = 0; i < members.length(); i++) {
            JSONObject m = members.optJSONObject(i);
            if (m == null) {
                String raw = members.optString(i, "").trim();
                if (!raw.isEmpty()) fallback.add(raw);
                continue;
            }

            String variety = m.optString("variety", "").trim();
            String size = m.optString("size", "").trim();
            String packaging = m.optString("packaging", m.optString("packaging_preset", "")).trim();

            if (i == 0) {
                commonVariety = variety;
                commonPackaging = packaging;
            } else {
                if (!commonVariety.equalsIgnoreCase(variety)) commonVariety = "";
                if (!commonPackaging.equalsIgnoreCase(packaging)) commonPackaging = "";
            }

            if (!size.isEmpty() && !sizes.contains(size)) sizes.add(size);
            String full = productLabel(m);
            if (!full.isEmpty()) fallback.add(full);
        }

        if (!commonVariety.isEmpty() && !sizes.isEmpty()) {
            StringBuilder out = new StringBuilder(commonVariety).append(" ");
            for (int i = 0; i < sizes.size(); i++) {
                if (i > 0) out.append("/");
                out.append(sizes.get(i));
            }
            if (!commonPackaging.isEmpty()) out.append(" · ").append(commonPackaging);
            return out.toString();
        }

        if (!fallback.isEmpty()) {
            StringBuilder out = new StringBuilder();
            for (int i = 0; i < fallback.size(); i++) {
                if (i > 0) out.append(" / ");
                out.append(fallback.get(i));
            }
            return out.toString();
        }
        return "";
    }

    private LinearLayout productRow(String text) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.VERTICAL);
        row.setPadding(dp2(10), dp2(8), dp2(10), dp2(8));
        row.setBackgroundColor(Color.rgb(31, 45, 63));
        TextView t = label(text, 16, Color.WHITE);
        t.setTypeface(null, 1);
        row.addView(t, new LinearLayout.LayoutParams(-1, -2));
        return row;
    }

    private boolean visibleLine(JSONObject line) {
        if (line == null) return false;
        if (line.optInt("quantity", line.optInt("required", 0)) > 0) return true;
        if (!line.optString("variety_display", "").trim().isEmpty()) return true;
        if (!line.optString("size_display", "").trim().isEmpty()) return true;
        if (!line.optString("sku_display", "").trim().isEmpty()) return true;
        JSONArray members = line.optJSONArray("allowed_skus");
        return members != null && members.length() > 0;
    }

    private int visibleLineCount(JSONArray lines) {
        int count = 0;
        if (lines == null) return 0;
        for (int i = 0; i < lines.length(); i++) {
            if (visibleLine(lines.optJSONObject(i))) count++;
        }
        return count;
    }

    private String compactOrLine(JSONObject line) {
        String varietyDisplay = line.optString("variety_display", "").trim();
        String sizeDisplay = line.optString("size_display", "").trim();
        String packagingDisplay = line.optString("packaging_display", "").trim();

        if (!varietyDisplay.isEmpty() && !sizeDisplay.isEmpty()) {
            String sizes = sizeDisplay.replace(" / ", "/").replace(" /", "/").replace("/ ", "/");
            StringBuilder out = new StringBuilder(varietyDisplay).append(" ").append(sizes);
            if (!packagingDisplay.isEmpty()) out.append(" · ").append(packagingDisplay);
            return out.toString();
        }

        JSONArray members = line.optJSONArray("allowed_skus");
        String compact = compactOrMembers(members);
        if (!compact.isEmpty()) return compact;

        String description = line.optString("description_display", "").trim();
        if (!description.isEmpty()) return description.replace(" | ", " ");
        return line.optString("sku_display", "").trim();
    }

    private LinearLayout buildPoDetails(JSONObject order) {
        LinearLayout wrap = new LinearLayout(this);
        wrap.setOrientation(LinearLayout.VERTICAL);
        wrap.setPadding(dp2(8), dp2(10), dp2(8), dp2(4));
        wrap.setBackgroundColor(Color.rgb(15, 30, 45));

        JSONArray lines = order.optJSONArray("lines");
        if (lines == null || lines.length() == 0) {
            wrap.addView(label("No product details available", 15, Color.LTGRAY),
                    new LinearLayout.LayoutParams(-1, -2));
            return wrap;
        }

        for (int i = 0; i < lines.length(); i++) {
            JSONObject line = lines.optJSONObject(i);
            if (line == null || !visibleLine(line)) continue;

            boolean isOr = line.optInt("is_mix", 0) == 1
                    || "OR".equalsIgnoreCase(line.optString("line_type", ""));
            int qty = line.optInt("quantity", line.optInt("required", 0));

            LinearLayout group = new LinearLayout(this);
            group.setOrientation(LinearLayout.VERTICAL);
            group.setPadding(dp2(12), dp2(10), dp2(12), dp2(10));
            group.setBackgroundColor(Color.rgb(24, 50, 66));

            LinearLayout head = new LinearLayout(this);
            head.setGravity(Gravity.CENTER_VERTICAL);

            // No AND/OR badge here. The product blocks themselves separate the order lines.
            TextView q = label(qty + " CASES", 17, Color.WHITE);
            q.setTypeface(null, 1);
            q.setGravity(Gravity.RIGHT | Gravity.CENTER_VERTICAL);
            q.setPadding(dp2(8), 0, 0, 0);
            head.addView(q, new LinearLayout.LayoutParams(0, dp2(40), 1));
            group.addView(head, new LinearLayout.LayoutParams(-1, -2));

            JSONArray members = line.optJSONArray("allowed_skus");
            boolean added = false;

            if (isOr) {
                String compact = compactOrLine(line);
                if (!compact.isEmpty()) {
                    LinearLayout.LayoutParams rp = new LinearLayout.LayoutParams(-1, -2);
                    rp.setMargins(0, dp2(6), 0, 0);
                    group.addView(productRow(compact), rp);
                    added = true;
                }
            } else if (members != null && members.length() > 0) {
                for (int m = 0; m < members.length(); m++) {
                    JSONObject member = members.optJSONObject(m);
                    String txt = member != null ? productLabel(member) : members.optString(m, "").trim();
                    if (txt.isEmpty()) continue;
                    LinearLayout.LayoutParams rp = new LinearLayout.LayoutParams(-1, -2);
                    rp.setMargins(0, dp2(6), 0, 0);
                    group.addView(productRow(txt), rp);
                    added = true;
                }
            }

            if (!added) {
                String display = line.optString("sku_display", "").trim();
                if (display.isEmpty()) display = line.optString("product_name", "").trim();
                if (display.isEmpty()) display = "Product not specified";
                LinearLayout.LayoutParams rp = new LinearLayout.LayoutParams(-1, -2);
                rp.setMargins(0, dp2(6), 0, 0);
                group.addView(productRow(display), rp);
            }

            LinearLayout.LayoutParams gp = new LinearLayout.LayoutParams(-1, -2);
            gp.setMargins(0, 0, 0, dp2(10));
            wrap.addView(group, gp);
        }
        return wrap;
    }

    private Field findField(String name) throws Exception {
        Class<?> c = getClass();
        while (c != null) {
            try {
                Field f = c.getDeclaredField(name);
                f.setAccessible(true);
                return f;
            } catch (NoSuchFieldException ignored) {
                c = c.getSuperclass();
            }
        }
        throw new NoSuchFieldException(name);
    }

    private Method findMethod(String name, int args) throws Exception {
        Class<?> c = getClass();
        while (c != null) {
            for (Method m : c.getDeclaredMethods()) {
                if (m.getName().equals(name) && m.getParameterTypes().length == args) {
                    m.setAccessible(true);
                    return m;
                }
            }
            c = c.getSuperclass();
        }
        throw new NoSuchMethodException(name);
    }

    private Object getInherited(String name) throws Exception {
        return findField(name).get(this);
    }

    private void setInherited(String name, Object value) throws Exception {
        findField(name).set(this, value);
    }

    private void showError(String message) {
        new AlertDialog.Builder(this)
                .setTitle("Attention")
                .setMessage(message)
                .setPositiveButton("OK", null)
                .show();
    }

    private String appVersion() {
        try {
            return getPackageManager().getPackageInfo(getPackageName(), 0).versionName;
        } catch (Exception ignored) {
            return "2.0.59-shipment-owner";
        }
    }

    private JSONObject postSetOrders(String shipmentId, ArrayList<String> ids) throws Exception {
        Map<String, String> data = new LinkedHashMap<>();
        data.put("action", "shipment_set_orders");
        data.put("shipment_id", shipmentId);
        data.put("order_ids", android.text.TextUtils.join(",", ids));

        String deviceId = Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID);
        if (deviceId == null || deviceId.trim().isEmpty()) deviceId = Build.MANUFACTURER + "-" + Build.MODEL;
        data.put("device_id", deviceId);
        data.put("device_model", (Build.MANUFACTURER + " " + Build.MODEL).trim());
        data.put("app_version", appVersion());
        data.put("private_device_view", "1");

        StringBuilder body = new StringBuilder();
        for (Map.Entry<String, String> e : data.entrySet()) {
            if (body.length() > 0) body.append('&');
            body.append(URLEncoder.encode(e.getKey(), "UTF-8"))
                    .append('=')
                    .append(URLEncoder.encode(e.getValue(), "UTF-8"));
        }

        HttpURLConnection c = (HttpURLConnection) new URL(API_URL).openConnection();
        c.setConnectTimeout(5000);
        c.setReadTimeout(10000);
        c.setRequestMethod("POST");
        c.setDoOutput(true);
        c.setRequestProperty("X-App-Token", APP_TOKEN);
        c.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
        try (OutputStream o = c.getOutputStream()) {
            o.write(body.toString().getBytes(StandardCharsets.UTF_8));
        }

        InputStream in = c.getResponseCode() < 400 ? c.getInputStream() : c.getErrorStream();
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        byte[] buf = new byte[4096];
        int n;
        while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
        return new JSONObject(out.toString("UTF-8"));
    }

    private void finishPoSelection(JSONArray source, boolean[] checked, AlertDialog dlg) {
        ArrayList<String> ids = new ArrayList<>();
        JSONArray selected = new JSONArray();
        for (int i = 0; i < source.length(); i++) {
            if (!checked[i]) continue;
            JSONObject o = source.optJSONObject(i);
            if (o == null) continue;
            ids.add(o.optString("id"));
            selected.put(o);
        }
        if (ids.isEmpty()) {
            Toast.makeText(this, "Select at least one PO", Toast.LENGTH_SHORT).show();
            return;
        }

        dlg.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(false);
        final JSONArray selectedFinal = selected;
        final ArrayList<String> idsFinal = ids;

        new Thread(() -> {
            try {
                String shipmentId = String.valueOf(getInherited("shipmentId"));
                JSONObject response = postSetOrders(shipmentId, idsFinal);
                runOnUiThread(() -> {
                    try {
                        if (response.optInt("ok", 0) != 1 && !response.optBoolean("ok", false)) {
                            dlg.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(true);
                            showError(response.optString("err", "Operation failed"));
                            return;
                        }

                        setInherited("selectedOrders", selectedFinal);
                        setInherited("selectedOrder", selectedFinal.optJSONObject(0));
                        setInherited("multiPo", selectedFinal.length() > 1);
                        setInherited("shipmentSkuLines", new JSONArray());

                        Field stepField = findField("step");
                        Class<?> enumType = stepField.getType();
                        @SuppressWarnings({"rawtypes", "unchecked"})
                        Object shipScan = Enum.valueOf((Class<? extends Enum>) enumType, "SHIP_SCAN");
                        stepField.set(this, shipScan);

                        JSONObject multi = response.optJSONObject("multi");
                        if (multi != null) {
                            try {
                                findMethod("applyComparison", 2).invoke(this, multi, false);
                            } catch (Exception ignored) {}
                        }

                        dlg.dismiss();
                        findMethod("render", 0).invoke(this);
                    } catch (Exception e) {
                        dlg.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(true);
                        showError("PO selection error: " + e.getMessage());
                    }
                });
            } catch (Exception e) {
                runOnUiThread(() -> {
                    dlg.getButton(AlertDialog.BUTTON_POSITIVE).setEnabled(true);
                    showError("Connection error: " + e.getMessage());
                });
            }
        }).start();
    }

    @Override
    void showOrders(JSONArray a) {
        if (a == null || a.length() == 0) {
            showError("No open orders found");
            return;
        }

        boolean[] checked = new boolean[a.length()];
        ArrayList<View> details = new ArrayList<>();
        ArrayList<Button> toggles = new ArrayList<>();
        final int[] openIndex = new int[]{-1};

        LinearLayout list = new LinearLayout(this);
        list.setOrientation(LinearLayout.VERTICAL);
        list.setPadding(dp2(10), dp2(6), dp2(10), dp2(8));

        for (int i = 0; i < a.length(); i++) {
            final int index = i;
            JSONObject order = a.optJSONObject(i);
            if (order == null) continue;

            LinearLayout card = new LinearLayout(this);
            card.setOrientation(LinearLayout.VERTICAL);
            card.setPadding(dp2(12), dp2(10), dp2(12), dp2(10));
            card.setBackgroundColor(Color.rgb(31, 45, 63));
            LinearLayout.LayoutParams cardLp = new LinearLayout.LayoutParams(-1, -2);
            cardLp.setMargins(0, 0, 0, dp2(12));

            LinearLayout top = new LinearLayout(this);
            top.setGravity(Gravity.CENTER_VERTICAL);

            CheckBox cb = new CheckBox(this);
            int[][] cbStates = new int[][]{
                    new int[]{android.R.attr.state_checked},
                    new int[]{-android.R.attr.state_checked}
            };
            int[] cbColors = new int[]{
                    Color.rgb(33, 150, 243),
                    Color.rgb(226, 232, 240)
            };
            cb.setButtonTintList(new android.content.res.ColorStateList(cbStates, cbColors));
            cb.setScaleX(1.25f);
            cb.setScaleY(1.25f);
            cb.setGravity(Gravity.CENTER);
            cb.setOnCheckedChangeListener((buttonView, isChecked) -> checked[index] = isChecked);
            top.addView(cb, new LinearLayout.LayoutParams(dp2(56), dp2(60)));

            LinearLayout info = new LinearLayout(this);
            info.setOrientation(LinearLayout.VERTICAL);

            TextView po = label("PO " + order.optString("po"), 19, Color.WHITE);
            po.setTypeface(null, 1);
            info.addView(po);

            TextView customer = label(order.optString("customer_name"), 15, Color.rgb(226, 232, 240));
            info.addView(customer);

            int totalCases = order.optInt("total_cases", 0);
            if (totalCases > 0) {
                TextView meta = label(totalCases + " cases", 14, Color.rgb(148, 163, 184));
                info.addView(meta);
            }

            top.addView(info, new LinearLayout.LayoutParams(0, -2, 1));

            Button toggle = smallButton("▼");
            top.addView(toggle, new LinearLayout.LayoutParams(dp2(56), dp2(52)));
            toggles.add(toggle);

            card.addView(top, new LinearLayout.LayoutParams(-1, -2));

            LinearLayout detail = buildPoDetails(order);
            detail.setVisibility(View.GONE);
            LinearLayout.LayoutParams detailLp = new LinearLayout.LayoutParams(-1, -2);
            detailLp.setMargins(0, dp2(10), 0, 0);
            card.addView(detail, detailLp);
            details.add(detail);

            View.OnClickListener expand = v -> {
                if (openIndex[0] == index) {
                    detail.setVisibility(View.GONE);
                    toggle.setText("▼");
                    openIndex[0] = -1;
                    return;
                }
                for (int k = 0; k < details.size(); k++) {
                    details.get(k).setVisibility(View.GONE);
                    if (k < toggles.size()) toggles.get(k).setText("▼");
                }
                detail.setVisibility(View.VISIBLE);
                toggle.setText("▲");
                openIndex[0] = index;
            };
            info.setOnClickListener(expand);
            toggle.setOnClickListener(expand);

            list.addView(card, cardLp);
        }

        ScrollView scroll = new ScrollView(this);
        scroll.setFillViewport(true);
        scroll.addView(list, new ScrollView.LayoutParams(-1, -2));

        AlertDialog dlg = new AlertDialog.Builder(this)
                .setTitle("Select one or more POs")
                .setView(scroll)
                .setPositiveButton("Continue", null)
                .setNegativeButton("Cancel", null)
                .create();

        dlg.setOnShowListener(x -> {
            Window w = dlg.getWindow();
            if (w != null) w.setLayout(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            dlg.getButton(AlertDialog.BUTTON_POSITIVE)
                    .setOnClickListener(v -> finishPoSelection(a, checked, dlg));
        });
        dlg.show();
    }
}
