package com.smproduce.palletsshipping;

import android.content.DialogInterface;
import android.content.DialogInterface;

final class MainActivity$MismatchBackClick implements DialogInterface.OnClickListener {
    final MainActivity activity;

    MainActivity$MismatchBackClick(MainActivity mainActivity) {
        this.activity = mainActivity;
    }

    @Override
    public void onClick(DialogInterface dialogInterface, int i) {
        this.activity.reopenPendingMismatch();
    }
}
