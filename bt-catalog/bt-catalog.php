<?php
/*
Plugin Name: BT Catalog
Plugin URI: https://boomerts.com
Description: Boomer T's unified blank-apparel catalog (S&S Activewear + SanMar + EG-PRO) with quote flow. Pulls live product data into a local cache and renders it via the [bt_catalog] shortcode.
Version: 0.1.0
Author: Duck and Rabbit Co.
*/

if (!defined('ABSPATH')) exit;

define('BT_CAT_VERSION', '0.14.1');
define('BT_CAT_DIR', plugin_dir_path(__FILE__));
define('BT_CAT_URL', plugin_dir_url(__FILE__));
define('BT_CAT_FILE', __FILE__);

require_once BT_CAT_DIR . 'includes/db.php';
require_once BT_CAT_DIR . 'includes/tiers.php';
require_once BT_CAT_DIR . 'includes/ingest.php';
require_once BT_CAT_DIR . 'includes/sync.php';
require_once BT_CAT_DIR . 'includes/admin.php';
require_once BT_CAT_DIR . 'includes/ss-admin.php';
require_once BT_CAT_DIR . 'includes/pricing.php';
require_once BT_CAT_DIR . 'includes/sanmar.php';
require_once BT_CAT_DIR . 'includes/egpro.php';
require_once BT_CAT_DIR . 'includes/rest.php';
require_once BT_CAT_DIR . 'includes/shortcode.php';
require_once BT_CAT_DIR . 'includes/updater.php';

// Build the cache table when the plugin is activated.
register_activation_hook(__FILE__, 'bt_cat_install');

// Safety net: also make sure the table is current on version bumps.
add_action('init', function () {
    if (get_option('bt_cat_db_version') !== BT_CAT_VERSION) {
        bt_cat_install();
        update_option('bt_cat_db_version', BT_CAT_VERSION);
    }
});
