package com.smproduce.palletsshipping;

import android.view.View;
import android.view.View;

final class MainActivity$MismatchResolveClick implements View.OnClickListener {
    final MainActivity activity;

    MainActivity$MismatchResolveClick(MainActivity mainActivity) {
        this.activity = mainActivity;
    }

    @Override
    public void onClick(View view) {
        this.activity.reopenPendingMismatch();
    }
}
