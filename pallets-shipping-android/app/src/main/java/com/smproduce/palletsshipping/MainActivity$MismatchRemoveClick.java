package com.smproduce.palletsshipping;

import android.content.DialogInterface;
import android.content.DialogInterface;

final class MainActivity$MismatchRemoveClick implements DialogInterface.OnClickListener {
    final MainActivity activity;
    final boolean remove;
    final String serial;

    MainActivity$MismatchRemoveClick(MainActivity mainActivity, String str, boolean z) {
        this.activity = mainActivity;
        this.serial = str;
        this.remove = z;
    }

    @Override
    public void onClick(DialogInterface dialogInterface, int i) {
        this.activity.requestCaseMismatchPassword(this.serial, this.remove);
    }
}
