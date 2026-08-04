<?php
/**
 * BT Admin Kit — the Updates panel every BT plugin shares.
 *
 * This file is byte-identical in bt-catalog, bt-quote, bt-portal and
 * bt-accounts. Whichever plugin loads first defines the function; the rest see
 * function_exists() and skip. That means no load-order rules and no plugin
 * depending on another being active.
 *
 * The whole point is that this looks the same everywhere. If a plugin needs
 * something extra on its admin page, it goes ABOVE this panel, in that plugin's
 * own code. Do not fork this file.
 *
 * Usage — always the last thing on the plugin's main admin page:
 *
 *   bt_admin_updates_panel(array(
 *       'slug'     => 'bt-accounts',
 *       'version'  => BTA_VERSION,
 *       'manifest' => 'bta_update_manifest',   // callable, returns manifest array
 *       'flush'    => 'bta_force_update_check' // callable, clears the cache
 *   ));
 *
 * KIT VERSION 1 — bump the comment, not the function name, when this changes,
 * and re-copy it into all four plugins in the same release round.
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('bt_admin_updates_panel')) :

function bt_admin_updates_panel($args) {
    $slug     = isset($args['slug']) ? sanitize_key($args['slug']) : '';
    $version  = isset($args['version']) ? (string) $args['version'] : '?';
    $manifest = isset($args['manifest']) ? $args['manifest'] : null;
    $flush    = isset($args['flush']) ? $args['flush'] : null;
    if ($slug === '') return;

    $nonce = 'bt_check_updates_' . $slug;

    // Handle the button. One trigger name across every BT plugin.
    if (!empty($_POST['bt_check_updates']) && $_POST['bt_check_updates'] === $slug) {
        check_admin_referer($nonce);
        if ($flush && is_callable($flush)) call_user_func($flush);
        delete_site_transient('update_plugins');
        if (function_exists('wp_update_plugins')) wp_update_plugins();

        $info = ($manifest && is_callable($manifest)) ? call_user_func($manifest) : array();
        $new  = !empty($info['version']) ? $info['version'] : '';
        if ($new && version_compare($new, $version, '>')) {
            $msg  = 'Version ' . $new . ' is available.';
            $type = 'success';
        } elseif ($new) {
            $msg  = 'Up to date (' . $version . ').';
            $type = 'success';
        } else {
            $msg  = 'Could not reach GitHub to read the manifest. Try again shortly.';
            $type = 'warning';
        }
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg)
           . ' <a href="' . esc_url(admin_url('plugins.php')) . '">Go to Plugins</a></p></div>';
    }

    $info   = ($manifest && is_callable($manifest)) ? call_user_func($manifest) : array();
    $latest = !empty($info['version']) ? $info['version'] : '—';
    $behind = ($latest !== '—' && version_compare($latest, $version, '>'));

    echo '<h2>Updates</h2>';
    echo '<table class="widefat" style="max-width:640px"><tbody>';
    echo '<tr><td>Installed version</td><td><strong>' . esc_html($version) . '</strong></td></tr>';
    echo '<tr><td>Latest published</td><td>' . esc_html($latest);
    if ($behind) echo ' &nbsp;<strong style="color:#b26d00">&mdash; update available</strong>';
    echo '</td></tr>';
    echo '</tbody></table>';

    echo '<form method="post" style="margin-top:12px">';
    wp_nonce_field($nonce);
    echo '<button class="button button-primary" name="bt_check_updates" value="' . esc_attr($slug) . '">Check for updates</button> ';
    echo '<a class="button" href="' . esc_url(admin_url('plugins.php')) . '">Go to Plugins</a>';
    echo '</form>';
}

endif;
