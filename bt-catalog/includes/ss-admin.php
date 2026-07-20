<?php
/**
 * S&S Activewear — dedicated supplier page (mirrors the SanMar page):
 * supplier connection, full catalog sync, and manual single-style pull.
 * Reuses the existing helpers (bt_cat_ss_probe, bt_cat_ss_import_style,
 * bt_cat_sync_*) and option keys, so behavior is unchanged — just relocated.
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page('bt-catalog', 'S&S Activewear', 'S&S Activewear', 'manage_options', 'bt-catalog-ss', 'bt_cat_ss_page');
});

function bt_cat_ss_page() {
    if (!current_user_can('manage_options')) return;

    // Save credentials + markup (merge — never wipe other settings).
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

    // Probe / test connection (read-only).
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

    // Price refresh start (re-pulls cost/sale/colors for all imported S&S styles).
    $refMsg = '';
    if (isset($_POST['bt_cat_refresh_start'])) {
        check_admin_referer('bt_cat_pull');
        $res = bt_cat_refresh_start();
        $refMsg = empty($res['ok'])
            ? '<span style="color:#b32d2e">' . esc_html($res['error'] ?? 'unknown') . '</span>'
            : 'Refresh started — ' . (int) $res['queued'] . ' styles queued.';
    }

    // Clear catalog.
    if (isset($_POST['bt_cat_clear'])) {
        check_admin_referer('bt_cat_pull');
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . bt_cat_table());
        if (function_exists('bt_cat_facets_flush')) bt_cat_facets_flush();
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

    $acct = esc_attr(bt_cat_opt('ss_account'));
    $key  = esc_attr(bt_cat_opt('ss_apikey'));
    $mk   = esc_attr(bt_cat_opt('markup', '1.9'));
    $have_creds = ($acct !== '' && $key !== '');
    $prog = bt_cat_sync_progress();
    ?>
    <div class="wrap">
        <h1>S&amp;S Activewear</h1>
        <p class="description" style="max-width:760px">Your primary supplier. Connect your S&amp;S account, run the full catalog sync, or pull individual styles by hand.</p>

        <h2>Supplier connection</h2>
        <p style="margin:.2em 0 10px">Credentials: <?php echo $have_creds
            ? '<strong style="color:#1a7f37">entered</strong>'
            : '<strong style="color:#b32d2e">not set</strong>'; ?></p>
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
        <h2>Full catalog</h2>
        <p class="description">Pulls every S&amp;S style automatically in the background and auto-prices each (cost &times; 2, rounded up to .95). One-time — it stops when finished. Leave this page open to watch it, or walk away; it keeps running.</p>

        <?php if ($syncMsg) echo '<p><strong>' . wp_kses_post($syncMsg) . '</strong></p>'; ?>
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
        <h2>Price refresh</h2>
        <p class="description">S&amp;S sale prices rotate constantly, so cached prices go stale. This re-pulls cost, <strong>sale price</strong>, colors, and sizes for every imported S&amp;S style — products stay live the whole time, and your manual price overrides are never touched. <strong>Runs automatically every night at 3am</strong>; use the button to run it now. Same pace as the full sync (~45 styles/min).</p>
        <?php
            $refPend   = function_exists('bt_cat_refresh_pending') ? bt_cat_refresh_pending() : 0;
            $refActive = (bool) wp_next_scheduled(BT_CAT_REFRESH_HOOK);
            $refLast   = get_option('bt_cat_refresh_last', '');
        ?>
        <?php if ($refMsg) echo '<p><strong>' . wp_kses_post($refMsg) . '</strong></p>'; ?>
        <p id="bt-ref-stat" style="margin:8px 0 12px">
            <span id="bt-ref-pend"><?php echo (int) $refPend; ?></span> styles pending<span id="bt-ref-run"><?php echo $refActive ? ' — running…' : ''; ?></span>
            <?php if ($refLast && !$refActive): ?><span style="color:#787c82"> · last completed <?php echo esc_html($refLast); ?></span><?php endif; ?>
        </p>
        <form method="post" style="display:inline">
            <?php wp_nonce_field('bt_cat_pull'); ?>
            <button type="submit" name="bt_cat_refresh_start" value="1" class="button button-primary" <?php disabled($refActive); ?>>Refresh prices now</button>
        </form>
        <script>
        (function(){
            var nonce = <?php echo wp_json_encode(wp_create_nonce('bt_cat_tick')); ?>;
            var ajax  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var active = <?php echo $refActive ? 'true' : 'false'; ?>;
            function tick(){
                var fd = new FormData();
                fd.append('action','bt_cat_refresh_tick');
                fd.append('_ajax_nonce',nonce);
                fetch(ajax,{method:'POST',body:fd,credentials:'same-origin'})
                  .then(function(r){return r.json();})
                  .then(function(j){
                    if(!j || !j.success){return;}
                    var p=j.data;
                    document.getElementById('bt-ref-pend').textContent=p.pending;
                    document.getElementById('bt-ref-run').textContent = p.pending>0 ? ' — running…' : ' — done.';
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
