# TC26 2.0.52 Multi-Zebra overlay

This overlay is built on the owner-supplied `Pallet-Shipping-TC26-2.0.52(3).apk`.
It preserves `classes.dex` through `classes7.dex` byte-for-byte and adds only
`classes8.dex` plus a launcher-manifest change.

It sends a persistent per-Zebra `client_device_id` with each request and:

- lets only one Zebra open a shipment at a time;
- shows **Shipment already open** on every other Zebra;
- offers **Take Over Shipment** only after the operator enters password `2424`;
- keeps every Zebra's pallet session independent at all times;
- never blocks pallet creation, pallet scans, or pallet closing.

The paired server implementation is in:

- `webapp/api/tc26_shipment_collaboration.php`
- `webapp/api/pallets_shipping_app.php`

The APK must be signed with the production key to update an already-installed
2.0.52 directly. A newly generated signing key produces a valid test install,
but Android will require removal of the old app first.
