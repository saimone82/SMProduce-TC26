package com.smproduce.palletsshipping;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

class MainActivity$3 extends BroadcastReceiver {
    final MainActivity this$0;

    MainActivity$3(MainActivity mainActivity) {
        this.this$0 = mainActivity;
    }

    @Override
    public void onReceive(Context context, Intent intent) {
        this.this$0.checkNetwork();
    }
}
