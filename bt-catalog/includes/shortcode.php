<?php
/**
 * BT Catalog — [bt_catalog] shortcode.
 * Outputs the storefront root and loads the CSS/JS. The JS builds the UI
 * and pulls products from the REST API.
 */
if (!defined('ABSPATH')) exit;

add_action('init', function () {
    add_shortcode('bt_catalog', 'bt_cat_shortcode');
});

function bt_cat_shortcode($atts) {
    // Register assets (only emitted when the shortcode is on the page).
    wp_register_style('bt-catalog', BT_CAT_URL . 'assets/catalog.css', array(), BT_CAT_VERSION);
    wp_register_script('bt-catalog', BT_CAT_URL . 'assets/catalog.js', array(), BT_CAT_VERSION, true);
    wp_localize_script('bt-catalog', 'btcatCfg', array(
        'rest'  => esc_url_raw(rest_url('boomerts/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
    ));

    wp_enqueue_style('bt-catalog');
    wp_enqueue_script('bt-catalog');

    return '<div id="btcat-root"><div style="padding:40px;text-align:center;color:#8a8aa0;font-family:sans-serif">Loading catalog\u2026</div></div>';
}
