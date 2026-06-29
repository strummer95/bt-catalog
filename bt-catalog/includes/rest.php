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
    $fit   = sanitize_text_field((string) $req->get_param('fit'));
    $color = sanitize_text_field((string) $req->get_param('color'));
    $quality = sanitize_text_field((string) $req->get_param('quality'));
    $page  = max(1, (int) $req->get_param('page'));
    $per   = min(48, max(1, (int) ($req->get_param('per') ?: 24)));
    $off   = ($page - 1) * $per;

    // Featured: default page (no search/filter) leads with the configured styles,
    // brand-aware so "Gildan 5000" doesn't collide with another brand's 5000.
    if ($s === '' && $brand === '' && $cat === '' && $color === '' && $fit === '' && $quality === '') {
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
        if ($cat === 'Performance') {
            $where[] = "perf = 1";
        } else {
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
    }
    if ($color !== '') {
        $terms = bt_cat_family_terms($color);
        $ors = array();
        foreach ($terms as $term) { $ors[] = "colors LIKE %s"; $args[] = '%' . $wpdb->esc_like($term) . '%'; }
        if ($ors) $where[] = '(' . implode(' OR ', $ors) . ')';
    }
    if ($fit !== '') {
        // Bound LIKE params (never literal % in the prepared SQL).
        switch (bt_cat_fit_key($fit)) {
            case 'women':
                $where[] = "(category LIKE %s OR category LIKE %s)";
                array_push($args, '%women%', '%ladies%');
                break;
            case 'girls':
                $where[] = "(category LIKE %s)";
                $args[] = '%girl%';
                break;
            case 'youth':
                $where[] = "(category LIKE %s OR category LIKE %s OR category LIKE %s OR category LIKE %s)";
                array_push($args, '%youth%', '%boys%', '%infant%', '%toddler%');
                break;
            case 'unisex': // none of the above
                $where[] = "(category NOT LIKE %s AND category NOT LIKE %s AND category NOT LIKE %s AND category NOT LIKE %s AND category NOT LIKE %s AND category NOT LIKE %s AND category NOT LIKE %s)";
                array_push($args, '%women%', '%ladies%', '%girl%', '%youth%', '%boys%', '%infant%', '%toddler%');
                break;
        }
    }
    if ($quality !== '' && function_exists('bt_cat_quality_key')) {
        $q = bt_cat_quality_key($quality);
        if ($q !== '') { $where[] = "tier = %s"; $args[] = $q; }
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
    // match on the category so it works across suppliers. Skipped when a
    // category filter is active (results are already one type) or when searching
    // (relevance leads) — avoids a heavy CASE sort on large result sets.
    $typeCase = '';
    if ($cat === '' && $s === '')
    $typeCase =
        "CASE
            WHEN LOWER(category) LIKE '%%tee%%' OR LOWER(category) LIKE '%%t-shirt%%' OR LOWER(category) LIKE '%%tshirt%%' THEN 0
            WHEN LOWER(category) LIKE '%%polo%%' THEN 1
            WHEN LOWER(category) LIKE '%%tank%%' THEN 2
            WHEN LOWER(category) LIKE '%%hoodie%%' OR LOWER(category) LIKE '%%fleece%%' OR LOWER(category) LIKE '%%sweatshirt%%' OR LOWER(category) LIKE '%%crew%%' OR LOWER(category) LIKE '%%1/4 zip%%' OR LOWER(category) LIKE '%%quarter zip%%' OR LOWER(category) LIKE '%%pullover%%' OR LOWER(category) LIKE '%%layer%%' THEN 3
            WHEN LOWER(category) LIKE '%%short%%' OR LOWER(category) LIKE '%%pant%%' OR LOWER(category) LIKE '%%jogger%%' OR LOWER(category) LIKE '%%bottom%%' OR LOWER(category) LIKE '%%legging%%' THEN 4
            WHEN LOWER(category) LIKE '%%jacket%%' OR LOWER(category) LIKE '%%outerwear%%' OR LOWER(category) LIKE '%%vest%%' THEN 5
            WHEN LOWER(category) LIKE '%%cap%%' OR LOWER(category) LIKE '%%hat%%' OR LOWER(category) LIKE '%%headwear%%' OR LOWER(category) LIKE '%%beanie%%' OR LOWER(category) LIKE '%%bag%%' OR LOWER(category) LIKE '%%sock%%' OR LOWER(category) LIKE '%%accessor%%' THEN 7
            WHEN LOWER(category) LIKE '%%non-medical%%' OR LOWER(category) LIKE '%%mask%%' THEN 8
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

/** Fit key for a raw category string (PHP mirror of bt_cat_fit_clause). */
function bt_cat_fit_of($category) {
    $c = strtolower((string) $category);
    if (strpos($c, 'women') !== false || strpos($c, 'ladies') !== false) return 'women';
    if (strpos($c, 'girl') !== false) return 'girls';
    if (strpos($c, 'youth') !== false || strpos($c, 'boy') !== false || strpos($c, 'infant') !== false || strpos($c, 'toddler') !== false) return 'youth';
    return 'unisex';
}

/** Drop the cached facet payload (call after any catalog write). */
function bt_cat_facets_flush() { delete_transient('bt_cat_facets_v2'); }

function bt_cat_rest_facets() {
    $cached = get_transient('bt_cat_facets_v2');
    if (is_array($cached)) return $cached;

    global $wpdb;
    $t = bt_cat_table();
    $brands  = $wpdb->get_col("SELECT DISTINCT brand FROM $t WHERE detail_done=1 AND active=1 AND brand<>'' ORDER BY brand ASC");
    $rawCats = $wpdb->get_col("SELECT DISTINCT category FROM $t WHERE detail_done=1 AND active=1 AND category<>''");

    // Categories -> consolidated buckets.
    $seen = array();
    foreach ($rawCats as $c) { $b = bt_cat_norm_category($c); if ($b !== '') $seen[$b] = true; }
    $cats = array_keys($seen);
    sort($cats);

    // Fits derived from the SAME distinct categories — no extra queries.
    $fitSeen = array();
    foreach ($rawCats as $c) { $fitSeen[bt_cat_fit_of($c)] = true; }
    $fits = array();
    foreach (bt_cat_fit_labels() as $label) {
        if (!empty($fitSeen[bt_cat_fit_key($label)])) $fits[] = $label;
    }

    // Quality tiers present (one cheap grouped count, mirrors the list base).
    $tierRows = $wpdb->get_results("SELECT tier, COUNT(*) n FROM $t WHERE detail_done=1 AND active=1 AND tier<>'' GROUP BY tier", ARRAY_A);
    $tierHas = array();
    foreach ((array) $tierRows as $tr) { $tierHas[strtolower($tr['tier'])] = (int) $tr['n']; }
    $quals = array();
    foreach (bt_cat_quality_labels() as $label) {
        if (!empty($tierHas[bt_cat_quality_key($label)])) $quals[] = $label;
    }

    $perfN = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE detail_done=1 AND active=1 AND perf=1");
    if ($perfN > 0) { $cats[] = 'Performance'; sort($cats); }

    $out = array('brands' => $brands, 'categories' => $cats, 'fits' => $fits, 'qualities' => $quals);
    set_transient('bt_cat_facets_v2', $out, 10 * MINUTE_IN_SECONDS);
    return $out;
}

/* Category buckets: collapse S&S baseCategory variants into clean display labels.
   First match wins; substrings are case-insensitive. Used by both facets (collapse)
   and the list filter (expand a bucket back to all matching raw categories). */
function bt_cat_cat_buckets() {
    // First match wins; substrings are lowercased and matched against the raw
    // supplier category. Gender/age is intentionally ignored here (exposed
    // separately as the "Fit" filter), so Men's/Women's/Youth/Girl's
    // "Performance Tee" all collapse to "T-Shirts". Hoodies/sweatshirts are
    // checked before T-Shirts so a "Crewneck Sweatshirt" doesn't land in tees.
    return array(
        'Quarter-Zips & Layering' => array('1/4 zip', '1/4-zip', 'quarter zip', 'quarter-zip', 'layering'),
        'Polos'                   => array('polo'),
        'Tanks'                   => array('tank', 'racerback'),
        'Hoodies & Fleece'        => array('hoodie', 'sweatshirt', 'fleece', 'pullover'),
        'T-Shirts'                => array('t-shirt', 'tshirt', 't shirt', 'tee', 'crew neck', 'crewneck'),
        'Woven Shirts'            => array('woven', 'wovens', 'dress shirt', 'button-down', 'workwear'),
        'Bottoms'                 => array('short', 'pant', 'jogger', 'legging', 'bottom', 'capri'),
        'Outerwear'               => array('jacket', 'outerwear', 'vest', 'coat', 'windbreaker', 'parka'),
        'Headwear'                => array('cap', 'hat', 'headwear', 'beanie', 'visor', 'bucket'),
        'Bags'                    => array('bag', 'backpack', 'tote', 'duffel', 'duffle'),
        'Personal Protection'     => array('non-medical', 'personal protection', 'protection', 'mask', 'face cover'),
        'Accessories'             => array('accessor', 'sock', 'scarf', 'towel', 'lanyard', 'apron', 'blanket', 'glove'),
        'Activewear'              => array('activewear'),
    );
}

/* ---- Fit (Unisex/Men's, Women's, Youth, Girls) ----
   Derived from the raw category so it needs no schema change. Lets us strip
   gender/age out of the category list above and offer it as its own filter. */
function bt_cat_fit_labels() {
    return array("Unisex / Men's", "Women's", "Youth", "Girls");
}
function bt_cat_fit_key($fit) {
    $f = strtolower((string) $fit);
    if (strpos($f, 'women') !== false || strpos($f, 'ladies') !== false) return 'women';
    if (strpos($f, 'girl') !== false) return 'girls';
    if (strpos($f, 'youth') !== false || strpos($f, 'boy') !== false) return 'youth';
    if (strpos($f, 'unisex') !== false || strpos($f, 'men') !== false) return 'unisex';
    return '';
}
/** SQL condition (static literals — safe to inline) for a fit selection. */
function bt_cat_fit_clause($fit) {
    $women = "(category LIKE '%women%' OR category LIKE '%ladies%')";
    $girls = "(category LIKE '%girl%')";
    $youth = "(category LIKE '%youth%' OR category LIKE '%boys%' OR category LIKE '%infant%' OR category LIKE '%toddler%')";
    switch (bt_cat_fit_key($fit)) {
        case 'women':  return $women;
        case 'girls':  return $girls;
        case 'youth':  return $youth;
        case 'unisex': return "(NOT $women AND NOT $girls AND NOT $youth)"; // unisex/men's = none of the above
    }
    return '';
}
function bt_cat_norm_category($raw) {
    $low = strtolower((string) $raw);
    foreach (bt_cat_cat_buckets() as $label => $subs) {
        foreach ($subs as $s) { if (strpos($low, $s) !== false) return $label; }
    }
    // Bare gender/age categories (no garment type) are now covered by the Fit
    // filter — drop them from the category list so it stays garment-type only.
    $stripped = preg_replace('/\b(men\'?s|women\'?s|ladies|unisex|youth|girl\'?s|boy\'?s|infant|toddler|adult|kids?|juvenile|performance|lightweight)\b/', '', $low);
    $stripped = preg_replace('/[^a-z]/', '', $stripped);   // drop spaces, &, punctuation
    if ($stripped === '') return '';
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
