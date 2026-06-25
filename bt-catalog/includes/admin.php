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

    // Save credentials (merge — never wipe other settings).
    if (isset($_POST['bt_cat_save'])) {
        check_admin_referer('bt_cat_save');
        $o = get_option('bt_cat_settings', array());
        if (!is_array($o)) $o = array();
        $o['ss_account'] = sanitize_text_field(wp_unslash($_POST['ss_account']));
        $o['ss_apikey']  = sanitize_text_field(wp_unslash($_POST['ss_apikey']));
        $o['markup']     = (float) $_POST['markup'];
        update_option('bt_cat_settings', $o);
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }

    // Save featured style list (its own option).
    if (isset($_POST['bt_cat_save_feat'])) {
        check_admin_referer('bt_cat_feat');
        update_option('bt_cat_featured', sanitize_textarea_field(wp_unslash($_POST['featured'])));
        echo '<div class="notice notice-success is-dismissible"><p>Featured list saved.</p></div>';
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

    // Probe handler (read-only).
    $probe = null;
    if (isset($_POST['bt_cat_probe'])) {
        check_admin_referer('bt_cat_pull');
        $style = sanitize_text_field(wp_unslash($_POST['probe_style']));
        $probe = function_exists('bt_cat_ss_probe') ? bt_cat_ss_probe($style) : array('error' => 'ingest not loaded');
    }

    // Full sync start/stop.
    $syncMsg = '';
    if (isset($_POST['bt_cat_sync_start'])) {
        check_admin_referer('bt_cat_pull');
        $res = bt_cat_sync_start();
        $syncMsg = empty($res['ok'])
            ? '<span style="color:#b32d2e">Sync failed: ' . esc_html($res['error'] ?? 'unknown') . '</span>'
            : 'Sync started — ' . (int) $res['seeded'] . ' styles queued.';
    }
    if (isset($_POST['bt_cat_sync_stop'])) {
        check_admin_referer('bt_cat_pull');
        bt_cat_sync_stop();
        $syncMsg = 'Sync stopped.';
    }

    // Clear catalog handler.
    if (isset($_POST['bt_cat_clear'])) {
        check_admin_referer('bt_cat_pull');
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . bt_cat_table());
        echo '<div class="notice notice-warning is-dismissible"><p>Catalog cleared.</p></div>';
    }

    // Import handler (writes rows).
    $import = null;
    if (isset($_POST['bt_cat_import'])) {
        check_admin_referer('bt_cat_pull');
        $raw  = sanitize_textarea_field(wp_unslash($_POST['import_styles']));
        $list = array_filter(array_map('trim', preg_split('/[\s,]+/', $raw)));
        $import = array();
        foreach ($list as $sn) {
            $res = function_exists('bt_cat_ss_import_style') ? bt_cat_ss_import_style($sn) : array('error' => 'ingest not loaded');
            $import[$sn] = $res;
        }
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
                        : '<span style="color:#b32d2e;font-weight:600">Not set</span> — add them below'; ?></td></tr>
            </tbody>
        </table>

        <h2>Supplier connection</h2>
        <form method="post">
            <?php wp_nonce_field('bt_cat_save'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ss_account">S&amp;S Account #</label></th>
                    <td><input id="ss_account" name="ss_account" type="text" value="<?php echo $acct; ?>" class="regular-text" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ss_apikey">S&amp;S API Key</label></th>
                    <td><input id="ss_apikey" name="ss_apikey" type="text" value="<?php echo $key; ?>" class="regular-text" autocomplete="off">
                        <p class="description">From your S&amp;S Activewear account &rarr; API settings. Used only on this server.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="markup">Retail markup</label></th>
                    <td><input id="markup" name="markup" type="text" value="<?php echo $mk; ?>" class="small-text"> &times; your cost
                        <p class="description">Shown price = your S&amp;S cost &times; this number. Your cost is never shown to customers.</p></td>
                </tr>
            </table>
            <p><button type="submit" name="bt_cat_save" value="1" class="button button-primary">Save settings</button></p>
        </form>

        <hr style="margin:28px 0">
        <h2>Featured on default page</h2>
        <p class="description">Style numbers to show (in order) when the catalog first loads, before anyone searches or filters. One per line or comma-separated. Example: 5000, 8000, 18000, 18500, 3001</p>
        <?php
            $feat_raw   = (string) get_option('bt_cat_featured', '');
            $feat_list  = function_exists('bt_cat_featured') ? bt_cat_featured() : array();
            $feat_found = 0; $feat_missing = array();
            if (!empty($feat_list)) {
                global $wpdb; $t = bt_cat_table();
                $ph = implode(',', array_fill(0, count($feat_list), '%s'));
                $have = $wpdb->get_col($wpdb->prepare("SELECT style_no FROM $t WHERE style_no IN ($ph)", $feat_list));
                $have = array_map('strval', (array) $have);
                foreach ($feat_list as $sn) { if (in_array((string)$sn, $have, true)) $feat_found++; else $feat_missing[] = $sn; }
            }
        ?>
        <form method="post">
            <?php wp_nonce_field('bt_cat_feat'); ?>
            <textarea name="featured" rows="6" class="large-text code" placeholder="5000&#10;8000&#10;18000&#10;18500&#10;3001"><?php echo esc_textarea($feat_raw); ?></textarea>
            <?php if (!empty($feat_list)): ?>
                <p class="description">
                    Matched <strong><?php echo (int) $feat_found; ?></strong> of <?php echo count($feat_list); ?> in the catalog.
                    <?php if (!empty($feat_missing)): ?>
                        <span style="color:#b32d2e">Not found: <?php echo esc_html(implode(', ', $feat_missing)); ?></span>
                        — these may not be imported yet, or the style number differs from S&S.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <p><button type="submit" name="bt_cat_save_feat" value="1" class="button button-primary">Save featured</button></p>
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

        <hr style="margin:28px 0">

        <h2>Full catalog sync</h2>
        <p class="description">Pulls every S&amp;S style automatically in the background and auto-prices each (cost &times; 2, rounded up to .95). One-time — it stops when finished. Leave this page open to watch it, or walk away; it keeps running.</p>

        <?php
        $prog = bt_cat_sync_progress();
        if ($syncMsg) echo '<p><strong>' . wp_kses_post($syncMsg) . '</strong></p>';
        ?>
        <div id="bt-sync-box" style="max-width:620px;margin:10px 0 16px">
            <div style="background:#e6e6e6;border-radius:10px;overflow:hidden;height:22px">
                <div id="bt-sync-bar" style="height:100%;width:<?php echo (int) $prog['pct']; ?>%;background:#2271b1;transition:width .3s"></div>
            </div>
            <p id="bt-sync-stat" style="margin:8px 0 0">
                <span id="bt-sync-done"><?php echo (int) $prog['done']; ?></span> of
                <span id="bt-sync-total"><?php echo (int) $prog['total']; ?></span> styles detailed
                (<span id="bt-sync-pct"><?php echo (int) $prog['pct']; ?></span>%)<span id="bt-sync-run"><?php echo $prog['active'] ? ' — running…' : ''; ?></span>
            </p>
        </div>

        <form method="post" style="display:inline">
            <?php wp_nonce_field('bt_cat_pull'); ?>
            <button type="submit" name="bt_cat_sync_start" value="1" class="button button-primary">Start full sync</button>
        </form>
        <form method="post" style="display:inline">
            <?php wp_nonce_field('bt_cat_pull'); ?>
            <button type="submit" name="bt_cat_sync_stop" value="1" class="button">Stop</button>
        </form>

        <script>
        (function(){
            var nonce = <?php echo wp_json_encode(wp_create_nonce('bt_cat_tick')); ?>;
            var ajax  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var active = <?php echo $prog['active'] ? 'true' : 'false'; ?>;
            function tick(){
                var fd = new FormData();
                fd.append('action','bt_cat_tick');
                fd.append('_ajax_nonce',nonce);
                fetch(ajax,{method:'POST',body:fd,credentials:'same-origin'})
                  .then(function(r){return r.json();})
                  .then(function(j){
                    if(!j || !j.success){return;}
                    var p=j.data;
                    document.getElementById('bt-sync-bar').style.width=p.pct+'%';
                    document.getElementById('bt-sync-done').textContent=p.done;
                    document.getElementById('bt-sync-total').textContent=p.total;
                    document.getElementById('bt-sync-pct').textContent=p.pct;
                    document.getElementById('bt-sync-run').textContent = p.pending>0 ? ' — running…' : ' — done.';
                    if(p.pending>0){ setTimeout(tick, 5000); }
                  })
                  .catch(function(){ setTimeout(tick, 8000); });
            }
            if(active){ setTimeout(tick, 3000); }
        })();
        </script>

        <hr style="margin:28px 0">

        <h2>Manual pull (single styles)</h2>

        <?php if ($probe !== null): ?>
            <div class="notice <?php echo empty($probe['ok']) ? 'notice-error' : 'notice-success'; ?>" style="padding:10px 14px">
                <?php if (empty($probe['ok'])): ?>
                    <p><strong>Probe failed:</strong> <?php echo esc_html($probe['error'] ?? 'unknown'); ?>
                       <?php if (!empty($probe['body'])) echo '<br><code>' . esc_html($probe['body']) . '</code>'; ?></p>
                <?php else: ?>
                    <p style="margin:0 0 6px"><strong>Live data confirmed for style <?php echo esc_html($probe['style_no']); ?>:</strong></p>
                    <?php if (!empty($probe['warn'])): ?>
                        <p style="color:#8a6d00;margin:0 0 8px">⚠ <?php echo esc_html($probe['warn']); ?></p>
                    <?php endif; ?>
                    <table class="widefat striped" style="max-width:560px">
                        <tr><td>Brand</td><td><?php echo esc_html($probe['brand']); ?></td></tr>
                        <tr><td>Name</td><td><?php echo esc_html($probe['title']); ?></td></tr>
                        <tr><td>Category</td><td><?php echo esc_html($probe['category']); ?></td></tr>
                        <tr><td>Colors</td><td><?php echo (int) $probe['colors']; ?></td></tr>
                        <tr><td>Sizes</td><td><?php echo esc_html($probe['sizes']); ?></td></tr>
                        <tr><td>Your cost</td><td>$<?php echo esc_html(number_format((float) $probe['your_cost'], 2)); ?></td></tr>
                        <tr><td>Sale cost</td><td><?php echo $probe['sale_cost'] > 0 ? '$' . esc_html(number_format((float) $probe['sale_cost'], 2)) : '—'; ?></td></tr>
                        <tr><td>Sample image</td><td><?php echo $probe['sample_img']
                            ? '<a href="' . esc_url($probe['sample_img']) . '" target="_blank">view photo</a>' : '—'; ?></td></tr>
                    </table>
                    <?php if ($probe['sample_img']): ?>
                        <p><img src="<?php echo esc_url($probe['sample_img']); ?>" style="max-height:160px;border:1px solid #ddd;border-radius:8px;margin-top:8px"></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p class="description">Step A — confirm the connection by probing one style (reads only, writes nothing). Use <em>brand + number</em> to avoid wrong-brand matches:</p>
        <form method="post" style="margin-bottom:22px">
            <?php wp_nonce_field('bt_cat_pull'); ?>
            <input type="text" name="probe_style" value="Gildan 5000" class="regular-text">
            <button type="submit" name="bt_cat_probe" value="1" class="button">Probe one style</button>
        </form>

        <?php if ($import !== null): ?>
            <div class="notice notice-info" style="padding:10px 14px">
                <p style="margin:0 0 6px"><strong>Import results:</strong></p>
                <table class="widefat striped" style="max-width:620px">
                    <thead><tr><th>Style</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($import as $sn => $res): ?>
                        <tr>
                            <td><?php echo esc_html($sn); ?></td>
                            <td><?php echo empty($res['ok'])
                                ? '<span style="color:#b32d2e">' . esc_html($res['error'] ?? 'failed') . '</span>'
                                : esc_html($res['brand'] . ' ' . $res['style_no'] . ' — ' . $res['colors'] . ' colors, ' . $res['sizes'] . ' sizes, cost $' . number_format((float) $res['cost'], 2) . ($res['sale'] > 0 ? ' (sale $' . number_format((float) $res['sale'], 2) . ')' : ''))
                                . (!empty($res['warn']) ? '<br><span style="color:#8a6d00">⚠ ' . esc_html($res['warn']) . '</span>' : ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p class="description">Step B — import styles into the catalog. One per line or comma-separated. Use <em>brand + number</em> (e.g. <code>Gildan 5000</code>):</p>
        <form method="post">
            <?php wp_nonce_field('bt_cat_pull'); ?>
            <textarea name="import_styles" rows="4" class="large-text code" style="max-width:620px">Gildan 5000
Gildan 18500
Gildan 18000
Gildan 64000
Gildan 2000
Bella 3001</textarea>
            <p><button type="submit" name="bt_cat_import" value="1" class="button button-primary">Import these styles</button></p>
        </form>

        <hr style="margin:24px 0">
        <form method="post" onsubmit="return confirm('Clear ALL cached products? You can re-import them.');">
            <?php wp_nonce_field('bt_cat_pull'); ?>
            <button type="submit" name="bt_cat_clear" value="1" class="button button-secondary" style="color:#b32d2e;border-color:#b32d2e">Clear catalog</button>
            <span class="description" style="margin-left:8px">Wipes the cache table (e.g. to remove wrong-brand matches), then re-import.</span>
        </form>
    </div>
    <?php
}
