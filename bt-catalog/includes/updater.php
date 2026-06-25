<?php
/**
 * BT Catalog — self-updater (GitHub API, no CDN delay).
 *
 * Reads manifest.json through the GitHub *API* (api.github.com), which reflects
 * a push immediately — unlike raw.githubusercontent.com, which is cached ~5 min.
 * Each release ships a uniquely-named zip so its download URL is never stale.
 */
if (!defined('ABSPATH')) exit;

function bt_cat_gh_repo() {
    $r = bt_cat_opt('gh_repo');
    return $r !== '' ? $r : 'strummer95/bt-catalog';
}

/** Read the manifest through the GitHub API (instant; reflects latest push). */
function bt_cat_update_manifest() {
    $cached = get_transient('bt_cat_manifest');
    if ($cached !== false) return $cached;

    $url  = 'https://api.github.com/repos/' . bt_cat_gh_repo() . '/contents/manifest.json';
    $resp = wp_remote_get($url, array(
        'timeout' => 10,
        'headers' => array(
            'Accept'     => 'application/vnd.github.raw',
            'User-Agent' => 'BT-Catalog-Updater',
        ),
    ));

    $info = array();
    if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
        $j = json_decode(wp_remote_retrieve_body($resp), true);
        if (is_array($j)) $info = $j;
    }
    set_transient('bt_cat_manifest', $info, 30 * MINUTE_IN_SECONDS);
    return $info;
}

add_filter('pre_set_site_transient_update_plugins', 'bt_cat_push_update');
function bt_cat_push_update($transient) {
    if (!is_object($transient) || empty($transient->checked)) return $transient;
    $info = bt_cat_update_manifest();
    if (empty($info['version']) || empty($info['download_url'])) return $transient;

    $file = plugin_basename(BT_CAT_FILE);
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

function bt_cat_force_update_check() {
    delete_transient('bt_cat_manifest');
    delete_site_transient('update_plugins');
}

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
