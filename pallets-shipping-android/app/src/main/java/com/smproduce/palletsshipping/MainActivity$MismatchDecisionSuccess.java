package com.smproduce.palletsshipping;

import org.json.JSONObject;

final class MainActivity$MismatchDecisionSuccess implements MainActivity$Success {
    final MainActivity activity;
    final boolean remove;
    final String serial;

    MainActivity$MismatchDecisionSuccess(MainActivity mainActivity, String str, boolean z) {
        this.activity = mainActivity;
        this.serial = str;
        this.remove = z;
    }

    @Override
    public void run(JSONObject jSONObject) {
        this.activity.onSkuDecisionSuccess(this.serial, this.remove, jSONObject);
    }
}
