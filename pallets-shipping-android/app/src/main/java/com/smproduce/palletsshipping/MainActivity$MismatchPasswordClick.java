package com.smproduce.palletsshipping;

import android.content.DialogInterface;
import android.content.DialogInterface;
import android.widget.EditText;

final class MainActivity$MismatchPasswordClick implements DialogInterface.OnClickListener {
    final MainActivity activity;
    final EditText input;
    final boolean remove;
    final String serial;

    MainActivity$MismatchPasswordClick(MainActivity mainActivity, EditText editText, String str, boolean z) {
        this.activity = mainActivity;
        this.input = editText;
        this.serial = str;
        this.remove = z;
    }

    @Override
    public void onClick(DialogInterface dialogInterface, int i) {
        this.activity.verifyCaseMismatchPassword(this.input.getText().toString(), this.serial, this.remove);
    }
}
