<?php
/**
 * Compatibility entry point for the Pallets / Shipping Android app.
 *
 * The main API is now MIX/AND/OR aware itself, including order_search
 * previews. Keep this URL for older installed APKs but route every action
 * through the authoritative implementation so the same rules are used
 * everywhere.
 */
require __DIR__.'/pallets_shipping_app.php';
