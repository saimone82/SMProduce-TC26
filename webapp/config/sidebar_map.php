<?php
/**
 * sidebar_map.php
 * ─────────────────────────────────────────────────────────────
 * Single source of truth for sidebar sections → pages.
 *
 * HOW TO ADD A NEW SECTION:
 *   1. Add a new entry to $SIDEBAR_MAP below.
 *   2. Add the corresponding items to includes/sidebar.php.
 *   ➜ The new section will automatically appear in users.php
 *     permissions UI — no further changes needed.
 *
 * STRUCTURE:
 *   'section_key' => [
 *       'label' => 'Human label',
 *       'icon'  => '🏠',
 *       'color' => 'blue',        // css accent hint (informational)
 *       'pages' => [
 *           'file.php' => 'Page Label',
 *           ...
 *       ]
 *   ]
 * ─────────────────────────────────────────────────────────────
 */

$SIDEBAR_MAP = [

    'main' => [
        'label' => 'Main',
        'icon'  => '🏠',
        'color' => 'blue',
        'pages' => [
            'dashboard_report.php' => 'Report',
        ],
    ],

    'unitec' => [
        'label' => 'UNiTEC',
        'icon'  => '🔄',
        'color' => 'violet',
        'pages' => [
            'case_label_control.php' => 'Exit UNiTEC',
            'exit_sku_config.php'    => 'Edit SKU',
        ],
    ],

    'bins' => [
        'label' => 'Bins',
        'icon'  => '📦',
        'color' => 'emerald',
        'pages' => [
            'bins_ingresso.php'       => 'Full Bins Inventory',
            'empty_bin_receiving.php' => 'Empty Bins',
            'dumping_bins.php'        => 'Dumping Bins',
        ],
    ],

    'production' => [
        'label' => 'Production',
        'icon'  => '🏭',
        'color' => 'amber',
        'pages' => [
            'production_summary.php' => 'Production Summary',
        ],
    ],

    'orders' => [
        'label' => 'Orders',
        'icon'  => '🛒',
        'color' => 'orange',
        'pages' => [
            'orders.php'     => 'Orders List',
            'orders_add.php' => 'New Order',
        ],
    ],

    'logistics' => [
        'label' => 'Logistics',
        'icon'  => '🚛',
        'color' => 'cyan',
        'pages' => [
            'pallets_manage.php'   => 'Pallets',
            'shipments_manage.php' => 'Shipments',
        ],
    ],

    'tc26' => [
        'label' => 'TC26',
        'icon'  => '📡',
        'color' => 'sky',
        'pages' => [
            'tc26_shipping.php' => 'TC26 Shipping',
            'tc26_pallet.php'   => 'TC26 Pallet',
        ],
    ],

    'labels' => [
        'label' => 'Labels',
        'icon'  => '🏷️',
        'color' => 'teal',
        'pages' => [
            'label_print_center.php' => 'Print Center',
            'manual_case_labels.php' => 'Manual Case Labels',
            'label_history.php'      => 'Label History',
            'label_templates.php'    => 'Templates',
            'label_printers.php'     => 'Printers',
        ],
    ],

    'ibc' => [
        'label' => 'IBC',
        'icon'  => '🧪',
        'color' => 'pink',
        'pages' => [
            'ibc_manager.php' => 'IBC Manager',
        ],
    ],

    'users' => [
        'label' => 'Users',
        'icon'  => '👥',
        'color' => 'indigo',
        'pages' => [
            'users.php' => 'Users',
        ],
    ],

    'settings' => [
        'label' => 'Settings',
        'icon'  => '⚙️',
        'color' => 'gray',
        'pages' => [
            'settings.php' => 'General Settings',
        ],
    ],

];
