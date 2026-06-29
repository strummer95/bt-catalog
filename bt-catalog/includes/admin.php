<?php
/**
 * BT Catalog — admin settings page.
 * Adds  Settings -> BT Catalog  in the WordPress dashboard.
 * Stores S&S credentials + markup in the options table (no wp-config editing).
 */
if (!defined('ABSPATH')) exit;

/** Read one saved setting. */
function bt_cat_opt($key, $default = '') {
    $o = get_option('bt_cat_settings', array());
    return isset($o[$key]) ? $o[$key] : $default;
}

add_action('admin_menu', function () {
    add_menu_page(
        'BT Catalog',          // page title
        'BT Catalog',          // menu label (top-level)
        'manage_options',      // capability
        'bt-catalog',          // slug
        'bt_cat_admin_page',   // renderer
        'dashicons-tag',       // sidebar icon
        58                     // position (just below Settings-ish area)
    );
});

function bt_cat_admin_page() {
    if (!current_user_can('manage_options')) return;

    // Save featured style list (its own option).
    if (isset($_POST['bt_cat_save_feat'])) {
        check_admin_referer('bt_cat_feat');
        update_option('bt_cat_featured', sanitize_textarea_field(wp_unslash($_POST['featured'])));
        echo '<div class="notice notice-success is-dismissible"><p>Featured list saved.</p></div>';
    }

    // Save popular style list (its own option).
    if (isset($_POST['bt_cat_save_pop'])) {
        check_admin_referer('bt_cat_pop');
        update_option('bt_cat_popular', sanitize_textarea_field(wp_unslash($_POST['popular'])));
        echo '<div class="notice notice-success is-dismissible"><p>Popular list saved.</p></div>';
    }

    // Save update source (merge — preserves credentials).
    if (isset($_POST['bt_cat_save_upd'])) {
        check_admin_referer('bt_cat_upd');
        $o = get_option('bt_cat_settings', array());
        if (!is_array($o)) $o = array();
        $o['gh_repo'] = sanitize_text_field(wp_unslash($_POST['gh_repo']));
        update_option('bt_cat_settings', $o);
        if (function_exists('bt_cat_force_update_check')) bt_cat_force_update_check();
        echo '<div class="notice notice-success is-dismissible"><p>Update source saved.</p></div>';
    }

    // Force an update check.
    if (isset($_POST['bt_cat_checkupd'])) {
        check_admin_referer('bt_cat_upd');
        if (function_exists('bt_cat_force_update_check')) bt_cat_force_update_check();
        $m = function_exists('bt_cat_update_manifest') ? bt_cat_update_manifest() : array();
        $have = !empty($m['version']);
        echo '<div class="notice notice-info is-dismissible"><p>'
           . ($have
                ? 'Update source reachable. Latest published version: <strong>' . esc_html($m['version']) . '</strong> (you have ' . esc_html(BT_CAT_VERSION) . ').'
                : 'Could not read the update manifest at that URL. Open it in a browser to confirm it loads.')
           . '</p></div>';
    }

    global $wpdb;
    $t    = bt_cat_table();
    $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");

    $acct = esc_attr(bt_cat_opt('ss_account'));
    $key  = esc_attr(bt_cat_opt('ss_apikey'));
    $mk   = esc_attr(bt_cat_opt('markup', '1.9'));
    $have_creds = ($acct !== '' && $key !== '');
    ?>
    <div class="wrap">
        <h1>BT Catalog</h1>

        <table class="widefat striped" style="max-width:620px;margin:14px 0 24px">
            <tbody>
                <tr><td style="width:180px"><strong>Plugin</strong></td>
                    <td><span style="color:#1a7f37;font-weight:600">Active</span> &nbsp;(v<?php echo esc_html(BT_CAT_VERSION); ?>)</td></tr>
                <tr><td><strong>Cache table</strong></td><td><code><?php echo esc_html($t); ?></code></td></tr>
                <tr><td><strong>Products cached</strong></td><td><?php echo (int) $rows; ?></td></tr>
                <tr><td><strong>S&amp;S credentials</strong></td>
                    <td><?php echo $have_creds
                        ? '<span style="color:#1a7f37;font-weight:600">Entered</span>'
                        : '<span style="color:#b32d2e;font-weight:600">Not set</span>'; ?>
                        &nbsp;<a href="<?php echo esc_url(admin_url('admin.php?page=bt-catalog-ss')); ?>">Manage on the S&amp;S Activewear page →</a></td></tr>
            </tbody>
        </table>

        <hr style="margin:28px 0">
        <h2>Featured on default page</h2>
        <p class="description">Styles to show (in order) when the catalog first loads, before anyone searches or filters. One per line or comma-separated. If a style number is shared by several brands (like 5000), put the brand first: <code>Gildan 5000</code>.</p>
        <?php
            $feat_raw  = (string) get_option('bt_cat_featured', '');
            $feat_list = function_exists('bt_cat_featured') ? bt_cat_featured() : array();
            $feat_rows = array();
            if (!empty($feat_list)) {
                global $wpdb; $t = bt_cat_table();
                foreach ($feat_list as $f) {
                    $amb = 0; $row = null;
                    if ($f['brand'] !== '') {
                        $row = $wpdb->get_row($wpdb->prepare(
                            "SELECT brand, style_no, name FROM $t WHERE style_no=%s AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(brand),' ',''),'+',''),'&',''),'-','')=%s AND detail_done=1 AND active=1 LIMIT 1",
                            $f['style'], bt_cat_brand_norm($f['brand'])), ARRAY_A);
                    } else {
                        $row = $wpdb->get_row($wpdb->prepare(
                            "SELECT brand, style_no, name FROM $t WHERE style_no=%s AND detail_done=1 AND active=1 LIMIT 1", $f['style']), ARRAY_A);
                        $amb = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(DISTINCT brand) FROM $t WHERE style_no=%s AND detail_done=1 AND active=1", $f['style']));
                    }
                    $feat_rows[] = array('entry' => $f, 'row' => $row, 'amb' => ($amb > 1 ? $amb : 0));
                }
            }
        ?>
        <form method="post">
            <?php wp_nonce_field('bt_cat_feat'); ?>
            <textarea name="featured" rows="6" class="large-text code" placeholder="Type style numbers here, e.g.  Gildan 5000, Gildan 8000, Bella Canvas 3001"><?php echo esc_textarea($feat_raw); ?></textarea>
            <?php if (!empty($feat_list)): ?>
                <ul style="margin:8px 0 0;font-size:13px">
                    <?php foreach ($feat_rows as $fr): $f = $fr['entry']; $row = $fr['row']; ?>
                        <li style="padding:2px 0">
                            <code><?php echo esc_html(($f['brand'] ? $f['brand'].' ' : '') . $f['style']); ?></code> &rarr;
                            <?php if ($row): ?>
                                <strong><?php echo esc_html($row['brand']); ?></strong> &middot; <?php echo esc_html($row['name'] ?: $row['style_no']); ?>
                                <?php if ($fr['amb']): ?>
                                    <span style="color:#b26a00">&nbsp;&#9888; <?php echo (int) $fr['amb']; ?> brands use &ldquo;<?php echo esc_html($f['style']); ?>&rdquo; — add the brand (e.g. <em>Gildan <?php echo esc_html($f['style']); ?></em>) to pick the right one.</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#b32d2e">not found — may not be imported yet, or the style number/brand differs.</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="description" style="color:#b32d2e">No featured styles saved yet — the catalog is showing the full A&ndash;Z list. Type style numbers above (grey text is only an example) and click Save featured.</p>
            <?php endif; ?>
            <p><button type="submit" name="bt_cat_save_feat" value="1" class="button button-primary">Save featured</button></p>
        </form>

        <hr style="margin:28px 0">
        <h2>Popular styles</h2>
        <p class="description">These float to the <strong>top of every list</strong> (search and filtered results too) and show a <strong>POPULAR</strong> pill on the card. Same syntax as featured &mdash; one per line or comma-separated, brand first for shared numbers (<code>Gildan 5000</code>).</p>
        <?php
            $pop_raw  = (string) get_option('bt_cat_popular', '');
            $pop_list = function_exists('bt_cat_popular') ? bt_cat_popular() : array();
            $pop_rows = array();
            if (!empty($pop_list)) {
                global $wpdb; $t = bt_cat_table();
                foreach ($pop_list as $f) {
                    if ($f['brand'] !== '') {
                        $row = $wpdb->get_row($wpdb->prepare(
                            "SELECT brand, style_no, name FROM $t WHERE style_no=%s AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(brand),' ',''),'+',''),'&',''),'-','')=%s AND detail_done=1 AND active=1 LIMIT 1",
                            $f['style'], bt_cat_brand_norm($f['brand'])), ARRAY_A);
                    } else {
                        $row = $wpdb->get_row($wpdb->prepare(
                            "SELECT brand, style_no, name FROM $t WHERE style_no=%s AND detail_done=1 AND active=1 LIMIT 1", $f['style']), ARRAY_A);
                    }
                    $pop_rows[] = array('entry' => $f, 'row' => $row);
                }
            }
        ?>
        <form method="post">
            <?php wp_nonce_field('bt_cat_pop'); ?>
            <textarea name="popular" rows="5" class="large-text code" placeholder="Gildan 5000, Gildan 18000, Bella Canvas 3001"><?php echo esc_textarea($pop_raw); ?></textarea>
            <?php if (!empty($pop_list)): ?>
                <ul style="margin:8px 0 0;font-size:13px">
                    <?php foreach ($pop_rows as $pr): $f = $pr['entry']; $row = $pr['row']; ?>
                        <li style="padding:2px 0">
                            <code><?php echo esc_html(($f['brand'] ? $f['brand'].' ' : '') . $f['style']); ?></code> &rarr;
                            <?php if ($row): ?>
                                <strong><?php echo esc_html($row['brand']); ?></strong> &middot; <?php echo esc_html($row['name'] ?: $row['style_no']); ?>
                            <?php else: ?>
                                <span style="color:#b32d2e">not found &mdash; may not be imported yet, or the style number/brand differs.</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p><button type="submit" name="bt_cat_save_pop" value="1" class="button button-primary">Save popular</button></p>
        </form>

        <hr style="margin:28px 0">
        <h2>Updates</h2>
        <p class="description">Updates come from this GitHub repo via the API — instant, no waiting, no re-uploading. New version shows “Update Now” on the Plugins screen.</p>
        <form method="post">
            <?php wp_nonce_field('bt_cat_upd'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="gh_repo">GitHub repo</label></th>
                    <td><input id="gh_repo" name="gh_repo" type="text" value="<?php echo esc_attr(bt_cat_opt('gh_repo', 'strummer95/bt-catalog')); ?>" class="regular-text" placeholder="owner/repo">
                        <p class="description">Installed version: <strong><?php echo esc_html(BT_CAT_VERSION); ?></strong></p></td>
                </tr>
            </table>
            <p>
                <button type="submit" name="bt_cat_save_upd" value="1" class="button button-primary">Save</button>
                <button type="submit" name="bt_cat_checkupd" value="1" class="button">Check now</button>
            </p>
        </form>

    </div>
    <?php
}
