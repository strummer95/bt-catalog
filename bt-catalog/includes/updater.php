<?php
/**
 * BT Catalog — self-updater.
 *
 * Checks a manifest JSON (hosted wherever you point it) for a higher version,
 * then WordPress shows "Update Now" on the Plugins screen — in-place, no
 * upload/delete. The manifest URL is set on the BT Catalog settings page.
 *
 * manifest.json shape:
 * { "name":"BT Catalog", "version":"0.3.3",
 *   "download_url":"https://.../bt-update/bt-catalog.zip",
 *   "homepage":"https://boomerts.com", "tested":"6.6", "changelog":"..." }
 */
if (!defined('ABSPATH')) exit;

function bt_cat_update_url() {
    $u = bt_cat_opt('update_url');
    return $u !== '' ? $u : 'https://raw.githubusercontent.com/strummer95/bt-catalog/main/manifest.json';
}

/** Cache the manifest briefly so we don't hammer the host on every admin load. */
function bt_cat_update_manifest() {
    $cached = get_transient('bt_cat_manifest');
    if ($cached !== false) return $cached;
    $resp = wp_remote_get(bt_cat_update_url(), array('timeout' => 10, 'headers' => array('Accept' => 'application/json')));
    $info = array();
    if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
        $j = json_decode(wp_remote_retrieve_body($resp), true);
        if (is_array($j)) $info = $j;
    }
    set_transient('bt_cat_manifest', $info, 6 * HOUR_IN_SECONDS);
    return $info;
}

add_filter('pre_set_site_transient_update_plugins', 'bt_cat_push_update');
function bt_cat_push_update($transient) {
    if (!is_object($transient) || empty($transient->checked)) return $transient;
    $info = bt_cat_update_manifest();
    if (empty($info['version']) || empty($info['download_url'])) return $transient;

    $file = plugin_basename(BT_CAT_FILE);   // bt-catalog/bt-catalog.php
    if (version_compare($info['version'], BT_CAT_VERSION, '>')) {
        $transient->response[$file] = (object) array(
            'slug'        => 'bt-catalog',
            'plugin'      => $file,
            'new_version' => $info['version'],
            'package'     => $info['download_url'],
            'url'         => isset($info['homepage']) ? $info['homepage'] : '',
            'tested'      => isset($info['tested']) ? $info['tested'] : '',
        );
    }
    return $transient;
}

/** Force a fresh check (used by the "Check now" link). */
function bt_cat_force_update_check() {
    delete_transient('bt_cat_manifest');
    delete_site_transient('update_plugins');
}

/** "View details" popup content. */
add_filter('plugins_api', 'bt_cat_update_info', 20, 3);
function bt_cat_update_info($res, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'bt-catalog') return $res;
    $info = bt_cat_update_manifest();
    if (empty($info)) return $res;
    $o = new stdClass();
    $o->name          = 'BT Catalog';
    $o->slug          = 'bt-catalog';
    $o->version       = isset($info['version']) ? $info['version'] : BT_CAT_VERSION;
    $o->author        = 'Duck and Rabbit Co.';
    $o->homepage      = isset($info['homepage']) ? $info['homepage'] : '';
    $o->download_link = isset($info['download_url']) ? $info['download_url'] : '';
    $o->tested        = isset($info['tested']) ? $info['tested'] : '';
    $o->sections      = array('changelog' => isset($info['changelog']) ? $info['changelog'] : '');
    return $o;
}
