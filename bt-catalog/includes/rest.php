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

    // Featured: default page (no search/filter) leads with the configured styles,
    // brand-aware so "Gildan 5000" doesn't collide with another brand's 5000.
    if ($s === '' && $brand === '' && $cat === '' && $color === '') {
        $resolved = bt_cat_featured_resolve();
        if (!empty($resolved)) {
            $total    = count($resolved);
            $pageRows = array_slice($resolved, $off, $per);
            return array(
                'items'    => bt_cat_rest_rows_to_items($pageRows),
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
        $buckets = bt_cat_cat_buckets();
        if (isset($buckets[$cat])) {
            $ors = array();
            foreach ($buckets[$cat] as $sub) { $ors[] = "category LIKE %s"; $args[] = '%' . $wpdb->esc_like($sub) . '%'; }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        } else {
            $where[] = "category = %s";
            $args[] = $cat;
        }
    }
    if ($color !== '') {
        $terms = bt_cat_family_terms($color);
        $ors = array();
        foreach ($terms as $term) { $ors[] = "colors LIKE %s"; $args[] = '%' . $wpdb->esc_like($term) . '%'; }
        if ($ors) $where[] = '(' . implode(' OR ', $ors) . ')';
    }

    $wsql = implode(' AND ', $where);

    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $wsql", $args));

    // Popular styles float to the top of any list (brand-aware), in configured order.
    $popCase = '';
    $popArgs = array();
    $popList = bt_cat_popular();
    if (!empty($popList)) {
        $whens = array();
        foreach ($popList as $i => $p) {
            $rank = (int) $i;
            if ($p['brand'] !== '') {
                $whens[] = "WHEN (style_no = %s AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(brand),' ',''),'+',''),'&',''),'-','') = %s) THEN $rank";
                $popArgs[] = $p['style'];
                $popArgs[] = bt_cat_brand_norm($p['brand']);
            } else {
                $whens[] = "WHEN (style_no = %s) THEN $rank";
                $popArgs[] = $p['style'];
            }
        }
        $popCase = 'CASE ' . implode(' ', $whens) . ' ELSE 9999 END ASC, ';
    }

    // Search relevance: exact style number first, then style prefix, then name match.
    $relCase = '';
    $relArgs = array();
    if ($s !== '') {
        $relCase = "CASE WHEN style_no = %s THEN 0 WHEN style_no LIKE %s THEN 1 WHEN name LIKE %s THEN 2 ELSE 3 END ASC, ";
        $relArgs[] = $s;
        $relArgs[] = $wpdb->esc_like($s) . '%';
        $relArgs[] = '%' . $wpdb->esc_like($s) . '%';
    }

    // Type "popularity" order: shirts first, accessories/masks last. Keyword
    // match on the category so it works across suppliers (applies to every
    // filtered/searched/browsed list; pinned Popular styles still win above it).
    $typeCase =
        "CASE
            WHEN LOWER(category) LIKE '%tee%' OR LOWER(category) LIKE '%t-shirt%' OR LOWER(category) LIKE '%tshirt%' THEN 0
            WHEN LOWER(category) LIKE '%polo%' THEN 1
            WHEN LOWER(category) LIKE '%tank%' THEN 2
            WHEN LOWER(category) LIKE '%hoodie%' OR LOWER(category) LIKE '%fleece%' OR LOWER(category) LIKE '%sweatshirt%' OR LOWER(category) LIKE '%crew%' OR LOWER(category) LIKE '%1/4 zip%' OR LOWER(category) LIKE '%quarter zip%' OR LOWER(category) LIKE '%pullover%' OR LOWER(category) LIKE '%layer%' THEN 3
            WHEN LOWER(category) LIKE '%short%' OR LOWER(category) LIKE '%pant%' OR LOWER(category) LIKE '%jogger%' OR LOWER(category) LIKE '%bottom%' OR LOWER(category) LIKE '%legging%' THEN 4
            WHEN LOWER(category) LIKE '%jacket%' OR LOWER(category) LIKE '%outerwear%' OR LOWER(category) LIKE '%vest%' THEN 5
            WHEN LOWER(category) LIKE '%cap%' OR LOWER(category) LIKE '%hat%' OR LOWER(category) LIKE '%headwear%' OR LOWER(category) LIKE '%beanie%' OR LOWER(category) LIKE '%bag%' OR LOWER(category) LIKE '%sock%' OR LOWER(category) LIKE '%accessor%' THEN 7
            WHEN LOWER(category) LIKE '%non-medical%' OR LOWER(category) LIKE '%mask%' THEN 8
            ELSE 6
        END ASC, ";

    $sql  = "SELECT id, supplier, brand, style_no, name, category, colors, retail, retail_override
             FROM $t WHERE $wsql ORDER BY $relCase $popCase $typeCase brand ASC, style_no ASC LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($args, $relArgs, $popArgs, array($per, $off))), ARRAY_A);

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
    $popList = bt_cat_popular();
    $items = array();
    foreach ((array) $rows as $r) {
        $cols  = json_decode($r['colors'], true);
        $cols  = is_array($cols) ? $cols : array();
        $pidx  = bt_cat_preferred_color_idx($cols);
        $thumb = (isset($cols[$pidx]['img']) && $cols[$pidx]['img'] !== '') ? $cols[$pidx]['img']
                 : (!empty($cols[0]['img']) ? $cols[0]['img'] : '');
        $pop = false;
        if (!empty($popList)) {
            $nb = bt_cat_brand_norm($r['brand']);
            foreach ($popList as $p) {
                if ((string) $p['style'] === (string) $r['style_no']
                    && ($p['brand'] === '' || bt_cat_brand_norm($p['brand']) === $nb)) { $pop = true; break; }
            }
        }
        $items[] = array(
            'id'       => (int) $r['id'],
            'supplier' => $r['supplier'],
            'brand'    => $r['brand'],
            'style'    => $r['style_no'],
            'name'     => $r['name'],
            'cat'      => $r['category'],
            'price'    => bt_cat_price_row($r),
            'colors'   => count($cols),
            'thumb'    => $thumb,
            'popular'  => $pop,
        );
    }
    return $items;
}

function bt_cat_rest_item($req) {
    global $wpdb;
    $t  = bt_cat_table();
    $id = (int) $req->get_param('id');
    $r  = $wpdb->get_row($wpdb->prepare(
        "SELECT id, supplier, brand, style_no, name, category, description, specs, colors, sizes, retail, retail_override
         FROM $t WHERE id=%d AND detail_done=1", $id), ARRAY_A);
    if (!$r) return new WP_REST_Response(array('error' => 'not found'), 404);

    $cols = json_decode($r['colors'], true); $cols = is_array($cols) ? $cols : array();
    $specs = json_decode($r['specs'], true); $specs = is_array($specs) ? $specs : array();

    return array(
        'id'     => (int) $r['id'],
        'supplier' => $r['supplier'],
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
    $seen = array();
    foreach ($cats as $c) { $b = bt_cat_norm_category($c); if ($b !== '') $seen[$b] = true; }
    $cats = array_keys($seen);
    sort($cats);
    return array('brands' => $brands, 'categories' => $cats);
}

/* Category buckets: collapse S&S baseCategory variants into clean display labels.
   First match wins; substrings are case-insensitive. Used by both facets (collapse)
   and the list filter (expand a bucket back to all matching raw categories). */
function bt_cat_cat_buckets() {
    return array(
        'T-Shirts' => array('t-shirt', 'tshirt', 't shirt', 'tee'),
        'Fleece'   => array('fleece'),
    );
}
function bt_cat_norm_category($raw) {
    $low = strtolower((string) $raw);
    foreach (bt_cat_cat_buckets() as $label => $subs) {
        foreach ($subs as $s) { if (strpos($low, $s) !== false) return $label; }
    }
    return $raw;
}

/* ---- Preferred default colorway (navy-first) ----
   Priority: exact "Navy" -> name contains "navy" -> a dark blue -> gray -> first.
   Used for the grid thumbnail (and mirrored in catalog.js for the PDP default). */
function bt_cat_hex_rgb($hex) {
    $h = ltrim((string) $hex, '#');
    if (strlen($h) === 3) { $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2]; }
    if (strlen($h) !== 6 || !ctype_xdigit($h)) return null;
    return array(hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2)));
}
function bt_cat_color_rank($name, $hex) {
    $n = strtolower(trim((string) $name));
    if ($n === 'navy') return 0;
    if (strpos($n, 'navy') !== false) return 1;
    $rgb = bt_cat_hex_rgb($hex);
    $lum = $rgb ? (($rgb[0] + $rgb[1] + $rgb[2]) / 3) : null;
    // a dark blue — by hex when available (S&S), else by name (SanMar/EG-PRO have no hex)
    if (($rgb && $lum < 120 && (strpos($n, 'blue') !== false || ($rgb[2] > $rgb[0] + 15 && $rgb[2] > $rgb[1] + 15)))
        || preg_match('/midnight|indigo|royal|cobalt|marine/', $n)) return 2;
    // gray (by name — works for all suppliers — or near-neutral hex that isn't black/white)
    if (preg_match('/gray|grey|charcoal|graphite|slate|oxford/', $n)) return 3;
    if ($rgb) { $mx = max($rgb); $mn = min($rgb); if (($mx - $mn) <= 30 && $lum >= 50 && $lum <= 215) return 3; }
    return 99;
}
function bt_cat_preferred_color_idx($cols) {
    $best = -1; $bestRank = 999;
    foreach ((array) $cols as $i => $c) {
        if (empty($c['img'])) continue; // only colors that actually have a photo
        $r = bt_cat_color_rank(isset($c['name']) ? $c['name'] : '', isset($c['hex']) ? $c['hex'] : '');
        if ($r < $bestRank) { $bestRank = $r; $best = $i; }
    }
    if ($best >= 0) return $best;
    foreach ((array) $cols as $i => $c) { if (!empty($c['img'])) return $i; }
    return 0;
}
