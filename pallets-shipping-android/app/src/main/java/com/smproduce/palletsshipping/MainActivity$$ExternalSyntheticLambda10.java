package com.smproduce.palletsshipping;

import android.app.AlertDialog;
import android.content.DialogInterface;
import android.content.DialogInterface;
import org.json.JSONArray;

public final class MainActivity$$ExternalSyntheticLambda10 implements DialogInterface.OnShowListener {
    public final MainActivity f$0;
    public final AlertDialog f$1;
    public final JSONArray f$2;
    public final boolean[] f$3;

    public MainActivity$$ExternalSyntheticLambda10(MainActivity mainActivity, AlertDialog alertDialog, JSONArray jSONArray, boolean[] zArr) {
        this.f$0 = mainActivity;
        this.f$1 = alertDialog;
        this.f$2 = jSONArray;
        this.f$3 = zArr;
    }

    @Override
    public final void onShow(DialogInterface dialogInterface) {
        this.f$0.m66lambda$showOrders$56$comsmproducepalletsshippingMainActivity(this.f$1, this.f$2, this.f$3, dialogInterface);
    }
}
