# Pallets / Shipping

Native Android app for Zebra TC26. It provides an English/Spanish question wizard for palletizing and shipping, DataWedge barcode input, and an idempotent local offline scan queue.

Current recovered production baseline: `2.0.14-pallet-state-fix` (`versionCode 29`). The source was reconstructed from the installed production APK and verified with a clean Android build.

## Deploy the PHP API

Copy these two files from the supplied webapp package, preserving paths:

- `api/pallets_shipping_app.php`
- `config/pallets_shipping_app.php`

The token in the PHP config must match the `SMPS_APP_TOKEN` Gradle property or environment variable. Store it as a GitHub Actions repository secret named `SMPS_APP_TOKEN`; do not commit it to the repository.

## Zebra DataWedge profile

Create a profile associated with package `com.smproduce.palletsshipping`:

- Barcode input: enabled
- Intent output: enabled
- Intent action: `com.smproduce.PALLETS_SHIPPING.SCAN`
- Intent delivery: Broadcast intent
- Keystroke output: disabled

## Build

Push this folder to GitHub and run `Build Pallets Shipping APK`. Download the `Pallets-Shipping-APK` artifact.

The existing desktop pages, `mobile.php`, `tc26_pallet.php`, and `tc26_shipping.php` are not changed or removed.
