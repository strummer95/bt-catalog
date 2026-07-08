<?php
/**
 * BT Catalog — export endpoint (one-time PresStora import).
 *
 * Exposes the full catalog table as paginated JSON so PresStora can pull the
 * data once (products, colors/images, cost, retail + overrides, tiers, perf).
 * This is NOT a live feed — it exists so the curated catalog can be copied
 * into PresStora's creator catalog without touching phpMyAdmin.
 *
 * Security: a random 32-char key (option bt_cat_export_key, generated on
 * first admin view) must be passed as ?key=. Wrong/missing key -> 403.
 * Cost data IS included (PresStora is Dillon's own Creator account).
 *
 * Route:  GET /wp-json/boomerts/v1/catalog/export?key=KEY&page=1&per=100
 * Reply:  { ok, total, pages, page, per, rows: [ {…}, … ] }
 * Row:    supplier, supplier_style_id, style_no, brand, name, category,
 *         description, specs (decoded), colors (decoded [{name,hex,img,swatch}]),
 *         sizes (csv string), cost, sale_cost, retail, retail_override,
 *         retail_effective, tier, perf, active, updated_at
 */
if (!defined('ABSPATH')) exit;

/** Get (or lazily create) the export key. */
function bt_cat_export_key() {
    $k = (string) get_option('bt_cat_export_key', '');
    if ($k === '') {
        $k = strtolower(wp_generate_password(32, false, false));
        update_option('bt_cat_export_key', $k);
    }
    return $k;
}

add_action('rest_api_init', function () {
    register_rest_route('boomerts/v1', '/catalog/export', array(
        'methods'             => 'GET',
        'callback'            => 'bt_cat_export_handler',
        'permission_callback' => '__return_true', // key checked in handler
    ));
});

function bt_cat_export_handler(WP_REST_Request $req) {
    $key = (string) $req->get_param('key');
    if ($key === '' || !hash_equals(bt_cat_export_key(), $key)) {
        return new WP_REST_Response(array('ok' => false, 'error' => 'bad key'), 403);
    }

    global $wpdb;
    $t    = bt_cat_table();
    $page = max(1, (int) $req->get_param('page'));
    $per  = (int) $req->get_param('per');
    if ($per < 1)   $per = 100;
    if ($per > 250) $per = 250;
    $offset = ($page - 1) * $per;

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE detail_done=1");
    $pages = max(1, (int) ceil($total / $per));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT supplier, supplier_style_id, style_no, brand, name, category,
                description, specs, colors, sizes, cost, sale_cost,
                retail, retail_override, tier, perf, active, updated_at
         FROM $t WHERE detail_done=1
         ORDER BY id ASC LIMIT %d OFFSET %d",
        $per, $offset
    ), ARRAY_A);
    if (!is_array($rows)) $rows = array();

    $out = array();
    foreach ($rows as $r) {
        $colors = json_decode((string) $r['colors'], true);
        $specs  = json_decode((string) $r['specs'], true);
        $retail = (float) $r['retail'];
        $ov     = ($r['retail_override'] === null || $r['retail_override'] === '')
                    ? null : (float) $r['retail_override'];
        $out[] = array(
            'supplier'          => (string) $r['supplier'],
            'supplier_style_id' => (string) $r['supplier_style_id'],
            'style_no'          => (string) $r['style_no'],
            'brand'             => (string) $r['brand'],
            'name'              => (string) $r['name'],
            'category'          => (string) $r['category'],
            'description'       => (string) $r['description'],
            'specs'             => is_array($specs)  ? $specs  : array(),
            'colors'            => is_array($colors) ? $colors : array(),
            'sizes'             => (string) $r['sizes'],
            'cost'              => (float) $r['cost'],
            'sale_cost'         => (float) $r['sale_cost'],
            'retail'            => $retail,
            'retail_override'   => $ov,
            'retail_effective'  => ($ov !== null && $ov > 0) ? $ov : $retail,
            'tier'              => (string) $r['tier'],
            'perf'              => (int) $r['perf'],
            'active'            => (int) $r['active'],
            'updated_at'        => (string) $r['updated_at'],
        );
    }

    return new WP_REST_Response(array(
        'ok'    => true,
        'total' => $total,
        'pages' => $pages,
        'page'  => $page,
        'per'   => $per,
        'rows'  => $out,
    ), 200);
}

/* ---------------- Admin submenu: Export ---------------- */

add_action('admin_menu', function () {
    add_submenu_page(
        'bt-catalog',
        'Export',
        'Export',
        'manage_options',
        'bt-catalog-export',
        'bt_cat_export_page'
    );
});

function bt_cat_export_page() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $t = bt_cat_table();

    if (isset($_POST['bt_cat_export_regen'])) {
        check_admin_referer('bt_cat_export');
        update_option('bt_cat_export_key', strtolower(wp_generate_password(32, false, false)));
        echo '<div class="notice notice-success is-dismissible"><p>Export key regenerated. The old key no longer works.</p></div>';
    }

    $key      = bt_cat_export_key();
    $endpoint = rest_url('boomerts/v1/catalog/export') . '?key=' . rawurlencode($key);
    $counts   = $wpdb->get_results("SELECT supplier, COUNT(*) c FROM $t WHERE detail_done=1 GROUP BY supplier ORDER BY supplier", ARRAY_A);
    $total    = 0;
    foreach ((array) $counts as $c) $total += (int) $c['c'];

    $importUrl = 'https://presstora.duckandrabbit.co/creator/api/import-bt-catalog.php'
               . '?src=' . rawurlencode(rest_url('boomerts/v1/catalog/export'))
               . '&key=' . rawurlencode($key);
    ?>
    <div class="wrap">
        <h1>Export</h1>
        <p class="description">One-time catalog export for PresStora. This is a pull endpoint — nothing is sent anywhere until PresStora fetches it.</p>

        <h2 class="title" style="margin-top:18px">What's exportable</h2>
        <table class="widefat striped" style="max-width:420px">
            <thead><tr><th>Supplier</th><th style="text-align:right">Products</th></tr></thead>
            <tbody>
            <?php foreach ((array) $counts as $c): ?>
                <tr><td><?php echo esc_html($c['supplier']); ?></td><td style="text-align:right"><?php echo (int) $c['c']; ?></td></tr>
            <?php endforeach; ?>
                <tr><td><strong>Total</strong></td><td style="text-align:right"><strong><?php echo (int) $total; ?></strong></td></tr>
            </tbody>
        </table>

        <h2 class="title" style="margin-top:24px">Run the import</h2>
        <p>Log in to PresStora as the Creator in this browser, then click:</p>
        <p><a class="button button-primary" href="<?php echo esc_url($importUrl); ?>" target="_blank" rel="noopener">Import into PresStora &rarr;</a></p>
        <p class="description">PresStora pages through the endpoint below and upserts everything into the Creator Catalog (products, colors, images, cost, retail incl. your overrides, Quality tier, Performance flag). Safe to run more than once.</p>

        <h2 class="title" style="margin-top:24px">Endpoint (advanced)</h2>
        <p><code style="user-select:all;word-break:break-all"><?php echo esc_html($endpoint . '&page=1&per=100'); ?></code></p>

        <form method="post" style="margin-top:18px">
            <?php wp_nonce_field('bt_cat_export'); ?>
            <button class="button" name="bt_cat_export_regen" value="1"
                onclick="return confirm('Regenerate the export key? Any saved import links stop working.');">Regenerate key</button>
        </form>
    </div>
    <?php
}
