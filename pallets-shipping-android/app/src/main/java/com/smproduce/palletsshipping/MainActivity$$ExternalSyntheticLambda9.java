package com.smproduce.palletsshipping;

import android.content.DialogInterface;
import android.content.DialogInterface;

public final class MainActivity$$ExternalSyntheticLambda9 implements DialogInterface.OnMultiChoiceClickListener {
    public final boolean[] f$0;

    public MainActivity$$ExternalSyntheticLambda9(boolean[] zArr) {
        this.f$0 = zArr;
    }

    @Override
    public final void onClick(DialogInterface dialogInterface, int i, boolean z) {
        MainActivity.lambda$showOrders$53(this.f$0, dialogInterface, i, z);
    }
}
