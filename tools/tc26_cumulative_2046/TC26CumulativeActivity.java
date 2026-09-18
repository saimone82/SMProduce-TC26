package com.smproduce.palletsshipping;

import android.graphics.Color;
import android.view.Gravity;
import android.view.ViewGroup;
import android.widget.ImageButton;
import android.widget.LinearLayout;
import android.widget.TextView;
import java.util.HashSet;
import java.util.Locale;

/**
 * UI-only cumulative launcher. All Palletizing, Shipping, Modify Pallet and
 * integrated Scanner behaviour stays in the proven MODIFY-FINAL base APK.
 */
public final class TC26CumulativeActivity extends TC26Activity {
    private ImageButton scannerTop;
    private String seenShipment = "";
    private final HashSet<String> offlineSeenPallets = new HashSet<>();

    @Override void buildShell() {
        super.buildShell();
        LinearLayout oldTop = (LinearLayout) lang.getParent();
        ViewGroup networkParent = (ViewGroup) network.getParent();
        if (networkParent != null) networkParent.removeView(network);
        oldTop.removeAllViews();
        oldTop.setOrientation(LinearLayout.VERTICAL);
        oldTop.setPadding(dp(10), dp(7), dp(8), dp(5));
        oldTop.setBackgroundColor(Color.rgb(19,32,51));

        LinearLayout main = new LinearLayout(this);
        main.setOrientation(LinearLayout.HORIZONTAL);
        main.setGravity(Gravity.CENTER_VERTICAL);

        network.setTextSize(11);
        network.setGravity(Gravity.CENTER_VERTICAL | Gravity.START);
        network.setPadding(0,0,0,0);
        main.addView(network, new LinearLayout.LayoutParams(dp(88), dp(44)));

        TextView app = tv("Pallet Shipping", 18, Color.WHITE);
        app.setTypeface(null, 1);
        app.setGravity(Gravity.CENTER);
        app.setSingleLine(true);
        main.addView(app, new LinearLayout.LayoutParams(0, dp(44), 1));

        scannerTop = new ImageButton(this);
        scannerTop.setImageDrawable(new ScanIcon());
        scannerTop.setContentDescription(tr("Scanner", "Escáner"));
        scannerTop.setTooltipText(tr("Scanner", "Escáner"));
        scannerTop.setBackgroundColor(Color.TRANSPARENT);
        scannerTop.setPadding(dp(9),dp(9),dp(9),dp(9));
        scannerTop.setOnClickListener(v -> requestScanner());
        main.addView(scannerTop, new LinearLayout.LayoutParams(dp(44),dp(44)));

        ImageButton modifyTop = new ImageButton(this);
        modifyTop.setImageDrawable(new EditPalletIcon());
        modifyTop.setContentDescription(tr("Modify Existing Pallet", "Modificar pallet existente"));
        modifyTop.setTooltipText(tr("Modify Existing Pallet", "Modificar pallet existente"));
        modifyTop.setBackgroundColor(Color.TRANSPARENT);
        modifyTop.setPadding(dp(8),dp(8),dp(8),dp(8));
        modifyTop.setOnClickListener(v -> requestPalletEditPassword());
        editPalletIcon = modifyTop;
        main.addView(modifyTop, new LinearLayout.LayoutParams(dp(44),dp(44)));
        oldTop.addView(main, new LinearLayout.LayoutParams(-1, dp(44)));

        LinearLayout tools = new LinearLayout(this);
        tools.setGravity(Gravity.END | Gravity.CENTER_VERTICAL);
        lang.setTextSize(12);
        lang.setPadding(dp(4),0,dp(4),0);
        tools.addView(lang, new LinearLayout.LayoutParams(dp(48),dp(30)));
        oldTop.addView(tools, new LinearLayout.LayoutParams(-1,dp(32)));
    }

    @Override void scanPallet(String value) {
        String code = value == null ? "" : value.trim().toUpperCase(Locale.ROOT);
        if (!code.matches("P\\d+")) {
            error(tr("Scan a pallet code starting with P. Pallet count unchanged.",
                    "Escanee un código de pallet que empiece por P. El contador no cambia."));
            return;
        }
        if (!shipmentId.equals(seenShipment)) {
            seenShipment = shipmentId;
            offlineSeenPallets.clear();
        }
        if (!online && !offlineSeenPallets.add(code)) {
            error(tr("Pallet already scanned in this shipment",
                    "Pallet ya escaneado en este envío"));
            return;
        }
        super.scanPallet(code);
    }
}
