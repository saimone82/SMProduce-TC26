package com.smproduce.palletsshipping;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

class MainActivity$2 extends BroadcastReceiver {
    final MainActivity this$0;

    MainActivity$2(MainActivity mainActivity) {
        this.this$0 = mainActivity;
    }

    @Override
    public void onReceive(Context context, Intent intent) {
        String stringExtra = intent.getStringExtra("com.symbol.datawedge.data_string");
        if (stringExtra == null) {
            stringExtra = intent.getStringExtra("com.motorolasolutions.emdk.datawedge.data_string");
        }
        this.this$0.onScan(stringExtra);
    }
}
