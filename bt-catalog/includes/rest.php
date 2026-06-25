<?php
/**
 * BT Catalog — public REST API for the storefront.
 *
 *   GET /wp-json/boomerts/v1/catalog            list (search/filter/paginate)
 *   GET /wp-json/boomerts/v1/catalog/item       one product, full detail
 *   GET /wp-json/boomerts/v1/catalog/facets     brands + categories for menus
 *
 * Only customer-safe fields go out. Cost / sale_cost never leave the server.
 * Price returned = manual override if set, else auto retail.
 */
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    $pub = '__return_true';
    register_rest_route('boomerts/v1', '/catalog', array(
        'methods' => 'GET', 'permission_callback' => $pub, 'callback' => 'bt_cat_rest_list',
    ));
    register_rest_route('boomerts/v1', '/catalog/item', array(
        'methods' => 'GET', 'permission_callback' => $pub, 'callback' => 'bt_cat_rest_item',
    ));
    register_rest_route('boomerts/v1', '/catalog/facets', array(
        'methods' => 'GET', 'permission_callback' => $pub, 'callback' => 'bt_cat_rest_facets',
    ));
});

/** Map a color family name to the substrings that identify it (for filtering). */
function bt_cat_family_terms($fam) {
    $map = array(
        'Black'   => array('black'),
        'White'   => array('white','natural'),
        'Grey'    => array('grey','gray','heather','charcoal','ash','graphite','silver'),
        'Blue'    => array('navy','royal','blue','sapphire','sky','carolina','indigo','oceana'),
        'Red'     => array('red','cardinal','maroon','cherry','paprika'),
        'Green'   => array('green','forest','irish','military','kelly','lime','jade','kiwi','mint','sage','pistachio'),
        'Yellow'  => array('gold','daisy','yellow','cornsilk','vegas'),
        'Orange'  => array('orange','tangerine','salmon'),
        'Pink'    => array('pink','heliconia','azalea','rose','mauve'),
        'Purple'  => array('purple','lilac','orchid','violet','iris'),
        'Neutral' => array('sand','brown','chestnut','khaki','choc','natural','pfd','camo'),
    );
    return isset($map[$fam]) ? $map[$fam] : array(strtolower($fam));
}

function bt_cat_rest_list($req) {
    global $wpdb;
    $t = bt_cat_table();

    $s     = sanitize_text_field((string) $req->get_param('s'));
    $brand = sanitize_text_field((string) $req->get_param('brand'));
    $cat   = sanitize_text_field((string) $req->get_param('category'));
    $color = sanitize_text_field((string) $req->get_param('color'));
    $page  = max(1, (int) $req->get_param('page'));
    $per   = min(48, max(1, (int) ($req->get_param('per') ?: 24)));
    $off   = ($page - 1) * $per;

    // Featured: when the default page loads (no search/filter) and a featured
    // list is configured, show those styles in the configured order.
    $featured = (int) $req->get_param('featured');
    if ($featured && $s === '' && $brand === '' && $cat === '' && $color === '') {
        $flist = bt_cat_featured();
        if (!empty($flist)) {
            $ph    = implode(',', array_fill(0, count($flist), '%s'));
            $base  = "FROM $t WHERE detail_done=1 AND active=1 AND style_no IN ($ph)";
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $base", $flist));
            $sql   = "SELECT id, brand, style_no, name, category, colors, retail, retail_override
                      $base ORDER BY FIELD(style_no, $ph) LIMIT %d OFFSET %d";
            $params = array_merge($flist, $flist, array($per, $off));
            $rows   = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
            return array(
                'items'    => bt_cat_rest_rows_to_items($rows),
                'total'    => $total,
                'page'     => $page,
                'pages'    => max(1, (int) ceil($total / $per)),
                'per'      => $per,
                'featured' => true,
            );
        }
    }

    $where = array("detail_done=1", "active=1");
    $args  = array();

    if ($s !== '') {
        $like = '%' . $wpdb->esc_like($s) . '%';
        $where[] = "(brand LIKE %s OR style_no LIKE %s OR name LIKE %s)";
        array_push($args, $like, $like, $like);
    }
    if ($brand !== '') {
        // fuzzy brand match (BELLA + CANVAS vs Bella+Canvas)
        $nb = preg_replace('/[^a-z0-9]/', '', strtolower($brand));
        $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(LOWER(brand),' ',''),'+',''),'&',''),'-','') = %s";
        $args[] = $nb;
    }
    if ($cat !== '') {
        $where[] = "category = %s";
        $args[] = $cat;
    }
    if ($color !== '') {
        $terms = bt_cat_family_terms($color);
        $ors = array();
        foreach ($terms as $term) { $ors[] = "colors LIKE %s"; $args[] = '%' . $wpdb->esc_like($term) . '%'; }
        if ($ors) $where[] = '(' . implode(' OR ', $ors) . ')';
    }

    $wsql = implode(' AND ', $where);

    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $wsql", $args));

    $sql  = "SELECT id, brand, style_no, name, category, colors, retail, retail_override
             FROM $t WHERE $wsql ORDER BY brand ASC, style_no ASC LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($args, array($per, $off))), ARRAY_A);

    return array(
        'items' => bt_cat_rest_rows_to_items($rows),
        'total' => $total,
        'page'  => $page,
        'pages' => max(1, (int) ceil($total / $per)),
        'per'   => $per,
    );
}

/** Map DB rows to customer-safe list items (cost never leaves the server). */
function bt_cat_rest_rows_to_items($rows) {
    $items = array();
    foreach ((array) $rows as $r) {
        $cols  = json_decode($r['colors'], true);
        $cols  = is_array($cols) ? $cols : array();
        $thumb = !empty($cols[0]['img']) ? $cols[0]['img'] : '';
        $items[] = array(
            'id'     => (int) $r['id'],
            'brand'  => $r['brand'],
            'style'  => $r['style_no'],
            'name'   => $r['name'],
            'cat'    => $r['category'],
            'price'  => bt_cat_price_row($r),
            'colors' => count($cols),
            'thumb'  => $thumb,
        );
    }
    return $items;
}

function bt_cat_rest_item($req) {
    global $wpdb;
    $t  = bt_cat_table();
    $id = (int) $req->get_param('id');
    $r  = $wpdb->get_row($wpdb->prepare(
        "SELECT id, brand, style_no, name, category, description, specs, colors, sizes, retail, retail_override
         FROM $t WHERE id=%d AND detail_done=1", $id), ARRAY_A);
    if (!$r) return new WP_REST_Response(array('error' => 'not found'), 404);

    $cols = json_decode($r['colors'], true); $cols = is_array($cols) ? $cols : array();
    $specs = json_decode($r['specs'], true); $specs = is_array($specs) ? $specs : array();

    return array(
        'id'     => (int) $r['id'],
        'brand'  => $r['brand'],
        'style'  => $r['style_no'],
        'name'   => $r['name'],
        'cat'    => $r['category'],
        'desc'   => $r['description'],
        'specs'  => $specs,
        'colors' => $cols,
        'sizes'  => array_values(array_filter(array_map('trim', explode(',', $r['sizes'])))),
        'price'  => bt_cat_price_row($r),
    );
}

function bt_cat_rest_facets() {
    global $wpdb;
    $t = bt_cat_table();
    $brands = $wpdb->get_col("SELECT DISTINCT brand FROM $t WHERE detail_done=1 AND brand<>'' ORDER BY brand ASC");
    $cats   = $wpdb->get_col("SELECT DISTINCT category FROM $t WHERE detail_done=1 AND category<>'' ORDER BY category ASC");
    return array('brands' => $brands, 'categories' => $cats);
}
