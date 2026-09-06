package com.smproduce.palletsshipping;

import android.app.AlertDialog;
import android.view.View;
import android.view.View;
import org.json.JSONArray;

public final class MainActivity$$ExternalSyntheticLambda66 implements View.OnClickListener {
    public final MainActivity f$0;
    public final JSONArray f$1;
    public final boolean[] f$2;
    public final AlertDialog f$3;

    public MainActivity$$ExternalSyntheticLambda66(MainActivity mainActivity, JSONArray jSONArray, boolean[] zArr, AlertDialog alertDialog) {
        this.f$0 = mainActivity;
        this.f$1 = jSONArray;
        this.f$2 = zArr;
        this.f$3 = alertDialog;
    }

    @Override
    public final void onClick(View view) {
        this.f$0.m65lambda$showOrders$55$comsmproducepalletsshippingMainActivity(this.f$1, this.f$2, this.f$3, view);
    }
}
