# TC26 2.0.52 Multi-Zebra overlay

This overlay is built on the owner-supplied `Pallet-Shipping-TC26-2.0.52(3).apk`.
It preserves `classes.dex` through `classes7.dex` byte-for-byte and adds only
`classes8.dex` plus a launcher-manifest change.

It sends a persistent per-Zebra `client_device_id` with each request and:

- shows an informational message when another Zebra is active on the same shipment;
- keeps each Zebra's pallet session independent;
- offers **Take Over Shipment** with password;
- blocks only shipment operations on the other Zebras after Take Over;
- never blocks pallet creation, pallet scans, or pallet closing.

The paired server implementation is in:

- `webapp/api/tc26_shipment_collaboration.php`
- `webapp/api/pallets_shipping_app.php`

The APK must be signed with the production key to update an already-installed
2.0.52 directly. A newly generated signing key produces a valid test install,
but Android will require removal of the old app first.
