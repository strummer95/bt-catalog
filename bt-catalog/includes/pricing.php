<?php
/**
 * BT Catalog — pricing manager.
 * Submenu under BT Catalog. Search products, see cost + auto retail,
 * and set/override the retail price on any of them (flat dollar amount).
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page(
        'bt-catalog',          // parent slug
        'Pricing',             // page title
        'Pricing',             // menu label
        'manage_options',
        'bt-catalog-pricing',
        'bt_cat_pricing_page'
    );
});

function bt_cat_pricing_page() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $t = bt_cat_table();

    // Save overrides posted from the table.
    if (isset($_POST['bt_cat_save_prices'])) {
        check_admin_referer('bt_cat_prices');
        $ov = isset($_POST['ov']) && is_array($_POST['ov']) ? $_POST['ov'] : array();
        $saved = 0;
        foreach ($ov as $id => $val) {
            $id  = (int) $id;
            $val = trim(wp_unslash($val));
            if ($val === '') {
                $wpdb->update($t, array('retail_override' => null), array('id' => $id));   // blank = use auto
            } else {
                $wpdb->update($t, array('retail_override' => round((float) $val, 2)), array('id' => $id));
            }
            $saved++;
        }
        echo '<div class="notice notice-success is-dismissible"><p>Saved ' . (int) $saved . ' price(s).</p></div>';
    }

    $s      = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $paged  = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
    $per    = 50;
    $offset = ($paged - 1) * $per;

    $where  = "detail_done=1";
    $params = array();
    if ($s !== '') {
        $like   = '%' . $wpdb->esc_like($s) . '%';
        $where .= " AND (brand LIKE %s OR style_no LIKE %s OR name LIKE %s)";
        $params = array($like, $like, $like);
    }

    $total = (int) $wpdb->get_var(
        $params ? $wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $where", $params)
                : "SELECT COUNT(*) FROM $t WHERE $where"
    );
    $pages = max(1, (int) ceil($total / $per));

    $q = "SELECT id, brand, style_no, name, cost, sale_cost, retail, retail_override
          FROM $t WHERE $where ORDER BY brand ASC, style_no ASC LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($q, array_merge($params, array($per, $offset))), ARRAY_A);
    ?>
    <div class="wrap">
        <h1>Pricing</h1>
        <p class="description">Auto retail = your cost &times; 2, rounded up to the nearest .95. Type a number to override; clear it to fall back to auto.</p>

        <form method="get" style="margin:12px 0">
            <input type="hidden" name="page" value="bt-catalog-pricing">
            <input type="search" name="s" value="<?php echo esc_attr($s); ?>" placeholder="Search brand, style #, or name" class="regular-text">
            <button class="button">Search</button>
            <?php if ($s !== ''): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=bt-catalog-pricing')); ?>">Clear</a><?php endif; ?>
            <span class="description" style="margin-left:8px"><?php echo (int) $total; ?> product(s)</span>
        </form>

        <form method="post">
            <?php wp_nonce_field('bt_cat_prices'); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th style="width:140px">Brand</th>
                    <th style="width:90px">Style #</th>
                    <th>Name</th>
                    <th style="width:90px">Your cost</th>
                    <th style="width:90px">Sale cost</th>
                    <th style="width:100px">Auto retail</th>
                    <th style="width:130px">Your price</th>
                </tr></thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7">No products. Run a full sync first (BT Catalog &rarr; Start Full Sync).</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r['brand']); ?></td>
                        <td><?php echo esc_html($r['style_no']); ?></td>
                        <td><?php echo esc_html($r['name']); ?></td>
                        <td>$<?php echo esc_html(number_format((float) $r['cost'], 2)); ?></td>
                        <td><?php echo $r['sale_cost'] > 0 ? '$' . esc_html(number_format((float) $r['sale_cost'], 2)) : '—'; ?></td>
                        <td>$<?php echo esc_html(number_format((float) $r['retail'], 2)); ?></td>
                        <td>
                            <input type="number" step="0.01" min="0"
                                   name="ov[<?php echo (int) $r['id']; ?>]"
                                   value="<?php echo $r['retail_override'] !== null ? esc_attr(number_format((float) $r['retail_override'], 2, '.', '')) : ''; ?>"
                                   placeholder="auto" style="width:100px">
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <p style="margin-top:12px">
                <button type="submit" name="bt_cat_save_prices" value="1" class="button button-primary">Save prices on this page</button>
            </p>
        </form>

        <?php if ($pages > 1):
            $base = admin_url('admin.php?page=bt-catalog-pricing' . ($s !== '' ? '&s=' . urlencode($s) : '')); ?>
            <p>
                <?php if ($paged > 1): ?><a class="button" href="<?php echo esc_url($base . '&paged=' . ($paged - 1)); ?>">&lsaquo; Prev</a><?php endif; ?>
                <span style="margin:0 10px">Page <?php echo (int) $paged; ?> of <?php echo (int) $pages; ?></span>
                <?php if ($paged < $pages): ?><a class="button" href="<?php echo esc_url($base . '&paged=' . ($paged + 1)); ?>">Next &rsaquo;</a><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <?php
}
