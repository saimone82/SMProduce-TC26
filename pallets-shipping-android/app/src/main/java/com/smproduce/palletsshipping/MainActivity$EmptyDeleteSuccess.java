package com.smproduce.palletsshipping;

import org.json.JSONObject;

final class MainActivity$EmptyDeleteSuccess implements MainActivity$Success {
    final MainActivity activity;

    MainActivity$EmptyDeleteSuccess(MainActivity mainActivity) {
        this.activity = mainActivity;
    }

    @Override
    public void run(JSONObject jSONObject) {
        this.activity.onEmptyPalletDeleted(jSONObject);
    }
}
