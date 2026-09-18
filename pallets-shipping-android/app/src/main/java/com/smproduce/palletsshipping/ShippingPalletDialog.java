package com.smproduce.palletsshipping;

import android.app.Activity;
import android.app.Dialog;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.ColorDrawable;
import android.graphics.drawable.GradientDrawable;
import android.util.DisplayMetrics;
import android.view.Gravity;
import android.view.Window;
import android.view.WindowManager;
import android.widget.*;
import org.json.*;

/** Shipping acknowledgement: show the server-confirmed pallet contents until OK is pressed. */
final class ShippingPalletDialog {
    final Dialog dialog;
    final Button ok;
    final LinearLayout content, rows;
    final TextView status, palletNumber, total;
    final ScrollView scroll;
    private final Activity activity;
    private final boolean spanish;
    private boolean acknowledged;
    private final int green = Color.rgb(0,130,90), ink = Color.rgb(27,43,56);

    ShippingPalletDialog(Activity activity, boolean spanish, String scannedCode,
                         boolean duplicate, JSONObject summary, Runnable onOk) {
        this.activity = activity; this.spanish = spanish;
        dialog = new Dialog(activity);
        dialog.requestWindowFeature(Window.FEATURE_NO_TITLE);
        dialog.setCancelable(false);
        dialog.setCanceledOnTouchOutside(false);
        content = column(); content.setPadding(dp(14), dp(14), dp(14), dp(14));
        content.setBackgroundColor(Color.WHITE);
        status = text(duplicate ? tr("Pallet already present", "Pallet ya presente")
                : tr("Pallet added", "Pallet añadido"), 18, true);
        status.setTextColor(duplicate ? Color.rgb(155,90,0) : green);
        content.addView(status);
        TextView label = text(tr("Pallet number", "Número de pallet"), 12, false);
        label.setPadding(0, dp(10), 0, 0); content.addView(label);
        palletNumber = text(scannedCode, 25, true);
        palletNumber.setPadding(0, 0, 0, dp(10)); content.addView(palletNumber);

        scroll = new ScrollView(activity);
        rows = column(); scroll.addView(rows);
        content.addView(scroll, new LinearLayout.LayoutParams(-1, 0, 1));
        JSONArray groups = summary == null ? null : summary.optJSONArray("groups");
        boolean available = summary != null && summary.optBoolean("available", false)
                && scannedCode.equalsIgnoreCase(summary.optString("pallet_id"))
                && !summary.isNull("cases_count") && summary.optLong("cases_count", -1) >= 0
                && groups != null;
        long sum = 0;
        if (available) {
            for (int i = 0; i < groups.length(); i++) {
                JSONObject group = groups.optJSONObject(i);
                if (group == null || group.optLong("cases", -1) < 0) { available = false; break; }
                sum += group.optLong("cases");
            }
            if (sum != summary.optLong("cases_count")) available = false;
        }
        if (available) {
            addRow(new String[]{tr("Variety", "Variedad"), tr("Size", "Calibre"),
                    tr("Packaging", "Empaque"), tr("Cases", "Cajas")}, true);
            for (int i = 0; i < groups.length(); i++) {
                JSONObject group = groups.optJSONObject(i);
                addRow(new String[]{value(group, "variety"), value(group, "size"),
                        value(group, "packaging"), Long.toString(group.optLong("cases"))}, false);
            }
            if (groups.length() == 0) rows.addView(text(tr("No cases on this pallet.", "Este pallet no contiene cajas."), 14, false));
        } else {
            rows.addView(text(tr("Pallet contents unavailable. Press OK and scan again.",
                    "Contenido del pallet no disponible. Pulse OK y vuelva a escanear."), 15, false));
        }
        LinearLayout footer = new LinearLayout(activity);
        footer.setGravity(Gravity.CENTER_VERTICAL);
        footer.setPadding(0, dp(10), 0, dp(10));
        footer.addView(text(tr("Total cases on pallet", "Total de cajas del pallet"), 15, true),
                new LinearLayout.LayoutParams(0, -2, 1));
        total = text(available ? Long.toString(sum) : "—", 27, true);
        total.setTextColor(green); total.setPadding(dp(8), 0, 0, 0); footer.addView(total);
        content.addView(footer);
        ok = new Button(activity); ok.setText("OK"); ok.setTextSize(18); ok.setAllCaps(false);
        ok.setTextColor(Color.WHITE); ok.setMinHeight(0); ok.setMinWidth(0); ok.setPadding(dp(12),0,dp(12),0);
        GradientDrawable okBg = new GradientDrawable(); okBg.setColor(green); okBg.setCornerRadius(dp(10));
        ok.setBackground(okBg);
        content.addView(ok, new LinearLayout.LayoutParams(-1, dp(52)));
        ok.setOnClickListener(v -> {
            if (acknowledged) return;
            acknowledged = true;
            dialog.dismiss();
            if (onOk != null) onOk.run();
        });
        dialog.setContentView(content);
    }

    void show() {
        dialog.show();
        Window window = dialog.getWindow();
        if (window != null) {
            DisplayMetrics m = activity.getResources().getDisplayMetrics();
            window.setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
            window.setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_STATE_ALWAYS_HIDDEN);
            window.setLayout(Math.min(dp(560), m.widthPixels - dp(24)),
                    Math.min(dp(500), m.heightPixels - dp(72)));
        }
    }
    boolean isShowing() { return dialog.isShowing(); }
    void dismiss() { dialog.dismiss(); }
    private String tr(String en, String es) { return spanish ? es : en; }
    private int dp(int n) { return Math.round(n * activity.getResources().getDisplayMetrics().density); }
    private LinearLayout column() { LinearLayout result = new LinearLayout(activity); result.setOrientation(LinearLayout.VERTICAL); return result; }
    private TextView text(String value, int size, boolean bold) {
        TextView view = new TextView(activity); view.setText(value); view.setTextSize(size); view.setTextColor(ink);
        if (bold) view.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        return view;
    }
    private String value(JSONObject group, String key) {
        String value = group.isNull(key) ? "" : group.optString(key).trim();
        return value.isEmpty() ? "—" : value;
    }
    private void addRow(String[] cells, boolean header) {
        LinearLayout row = new LinearLayout(activity);
        row.setBackgroundColor(header ? Color.rgb(234,241,238) : Color.WHITE);
        float[] weights = {27, 18, 33, 22};
        for (int i = 0; i < cells.length; i++) {
            TextView cell = text(cells[i], header ? 11 : 13, header || i == 3);
            cell.setPadding(dp(3), dp(9), dp(3), dp(9));
            cell.setGravity(i == 3 ? Gravity.RIGHT : Gravity.LEFT);
            row.addView(cell, new LinearLayout.LayoutParams(0, -2, weights[i]));
        }
        rows.addView(row, new LinearLayout.LayoutParams(-1, -2));
        TextView line = new TextView(activity); line.setBackgroundColor(Color.rgb(226,232,229));
        rows.addView(line, new LinearLayout.LayoutParams(-1, dp(1)));
    }
}
