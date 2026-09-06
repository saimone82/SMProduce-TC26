package com.smproduce.palletsshipping;

class MainActivity$1 implements Runnable {
    final MainActivity this$0;

    MainActivity$1(MainActivity mainActivity) {
        this.this$0 = mainActivity;
    }

    @Override
    public void run() {
        this.this$0.probeServer();
        this.this$0.healthHandler.postDelayed(this, this.this$0.online ? 12000L : 3000L);
    }
}
