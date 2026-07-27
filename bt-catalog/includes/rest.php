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

/**
 * Normalize every filter parameter once, into canonical keys. Both the list
 * and the facet counts read from this — same input, same interpretation.
 */
function bt_cat_read_params($req) {
    return array(
        's'        => sanitize_text_field((string) $req->get_param('s')),
        'brand'    => sanitize_text_field((string) $req->get_param('brand')),
        'category' => bt_cat_bucket_param((string) $req->get_param('category')),
        'color'    => sanitize_text_field((string) $req->get_param('color')),
        'aud'      => bt_cat_attr_key('aud',     (string) $req->get_param('fit')),
        'neck'     => bt_cat_attr_key('neck',    (string) $req->get_param('neck')),
        'sleeve'   => bt_cat_attr_key('sleeve',  (string) $req->get_param('sleeve')),
        'closure'  => bt_cat_attr_key('closure', (string) $req->get_param('closure')),
        'size'     => bt_cat_size_canon((string) $req->get_param('size')),
        'quality'  => bt_cat_quality_key((string) $req->get_param('quality')),
    );
}

/**
 * Resolve a `category` parameter to a canonical bucket label. Accepts a bucket
 * name directly, the Performance pseudo-category, or a raw supplier category
 * from an older shared link ("Polos/Knits" -> "Polos").
 */
function bt_cat_bucket_param($raw) {
    $raw = sanitize_text_field((string) $raw);
    if ($raw === '' || $raw === 'Performance') return $raw;
    $buckets = bt_cat_cat_buckets();
    if (isset($buckets[$raw])) return $raw;
    $norm = bt_cat_norm_category($raw);
    return $norm !== '' ? $norm : $raw;
}

/** True when no filter or search is active (the featured-by-default case). */
function bt_cat_params_empty($p) {
    foreach ($p as $v) { if ($v !== '') return false; }
    return true;
}

/**
 * Build the WHERE clause for a filter set.
 *
 * $skip omits ONE filter key — that's how a facet counts its own options.
 * Counting "Neckline" with the neckline filter still applied would zero out
 * every sibling the moment you picked one, so each facet is counted against
 * every OTHER active filter and not itself. Selecting Crew therefore leaves
 * V-Neck clickable, while Sleeve Length still narrows to what crews offer.
 *
 * Returns array('sql' => "...", 'args' => array()).
 */
function bt_cat_filter_where($p, $skip = '') {
    global $wpdb;
    $where = array('detail_done=1', 'active=1');
    $args  = array();

    if ($skip !== 's' && $p['s'] !== '') {
        $like = '%' . $wpdb->esc_like($p['s']) . '%';
        $where[] = "(brand LIKE %s OR style_no LIKE %s OR name LIKE %s)";
        array_push($args, $like, $like, $like);
    }
    if ($skip !== 'brand' && $p['brand'] !== '') {
        // fuzzy brand match (BELLA + CANVAS vs Bella+Canvas)
        $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(LOWER(brand),' ',''),'+',''),'&',''),'-','') = %s";
        $args[]  = bt_cat_brand_norm($p['brand']);
    }
    if ($skip !== 'category' && $p['category'] !== '') {
        if ($p['category'] === 'Performance') {
            $where[] = "perf = 1";
        } else {
            // Exact match on the derived bucket column. This used to expand the
            // bucket back into LIKE substrings, which quietly matched the wrong
            // rows -- '%tshirt%' is a substring of "SweaTSHIRTs", so every
            // sweatshirt was being returned under T-Shirts while the facet
            // count (bucket priority, fleece before tees) disagreed. One
            // derived value, written once at import, keeps them identical.
            $where[] = "bucket = %s";
            $args[]  = $p['category'];
        }
    }
    if ($skip !== 'color' && $p['color'] !== '') {
        // color_fams is the derived per-colorway family list; the LIKE fallback
        // covers rows imported before the column existed (self-heals on refresh).
        $terms = bt_cat_family_terms($p['color']);
        $ors   = array();
        foreach ($terms as $term) { $ors[] = "colors LIKE %s"; }
        $where[] = "(FIND_IN_SET(%s, color_fams) > 0 OR (color_fams = '' AND (" . implode(' OR ', $ors) . ")))";
        $args[]  = $p['color'];
        foreach ($terms as $term) { $args[] = '%' . $wpdb->esc_like($term) . '%'; }
    }
    // Derived single-value columns: one bound equality each.
    foreach (array('aud', 'neck', 'sleeve', 'closure') as $k) {
        if ($skip === $k || $p[$k] === '') continue;
        $where[] = "$k = %s";
        $args[]  = $p[$k];
    }
    if ($skip !== 'size' && $p['size'] !== '') {
        $where[] = "FIND_IN_SET(%s, size_set) > 0";
        $args[]  = $p['size'];
    }
    if ($skip !== 'quality' && $p['quality'] !== '') {
        $where[] = "tier = %s";
        $args[]  = $p['quality'];
    }

    return array('sql' => implode(' AND ', $where), 'args' => $args);
}

function bt_cat_rest_list($req) {
    global $wpdb;
    $t = bt_cat_table();

    $p     = bt_cat_read_params($req);
    $s     = $p['s'];
    $cat   = $p['category'];
    $sort  = sanitize_text_field((string) $req->get_param('sort'));
    if (!in_array($sort, array('price_asc', 'price_desc', 'name_asc', 'brand_asc'), true)) $sort = '';
    $page  = max(1, (int) $req->get_param('page'));
    $per   = min(48, max(1, (int) ($req->get_param('per') ?: 24)));
    $off   = ($page - 1) * $per;

    // Featured: default page (no search/filter) leads with the configured styles,
    // brand-aware so "Gildan 5000" doesn't collide with another brand's 5000.
    if (bt_cat_params_empty($p) && $sort === '') {
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

    $w    = bt_cat_filter_where($p);
    $wsql = $w['sql'];
    $args = $w['args'];

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

    // Explicit sort (from the toolbar dropdown) takes over the whole ORDER BY —
    // relevance/popular/type ordering only apply in the default "Featured" mode.
    // Price sorts on the effective price: manual override if set, else auto retail
    // (same COALESCE the customer-facing price uses).
    // Effective customer price, sale-aware (SQL mirror of bt_cat_price_pair):
    // override wins; else an active supplier sale prices at autoprice(sale_cost);
    // else the stored auto retail. Autoprice = 2x rounded up to the nearest .95.
    $eff = "CASE
        WHEN retail_override IS NOT NULL AND retail_override > 0 THEN retail_override
        WHEN sale_cost > 0 AND sale_cost < cost THEN FLOOR(2*sale_cost) + IF(FLOOR(2*sale_cost) + 0.95 >= 2*sale_cost - 0.0001, 0.95, 1.95)
        ELSE retail
    END";
    $sortSql = '';
    switch ($sort) {
        case 'price_asc':  $sortSql = "$eff ASC, brand ASC, style_no ASC";  break;
        case 'price_desc': $sortSql = "$eff DESC, brand ASC, style_no ASC"; break;
        case 'name_asc':   $sortSql = 'name ASC, brand ASC, style_no ASC';  break;
        case 'brand_asc':  $sortSql = 'brand ASC, style_no ASC'; break;
    }

    if ($sortSql !== '') {
        $sql  = "SELECT id, supplier, brand, style_no, name, category, colors, cost, sale_cost, retail, retail_override
                 FROM $t WHERE $wsql ORDER BY $sortSql LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($args, array($per, $off))), ARRAY_A);
    } else {
        $sql  = "SELECT id, supplier, brand, style_no, name, category, colors, cost, sale_cost, retail, retail_override
                 FROM $t WHERE $wsql ORDER BY $relCase $popCase $typeCase brand ASC, style_no ASC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($args, $relArgs, $popArgs, array($per, $off))), ARRAY_A);
    }

    return array(
        'items' => bt_cat_rest_rows_to_items($rows),
        'total' => $total,
        'page'  => $page,
        'pages' => max(1, (int) ceil($total / $per)),
        'per'   => $per,
    );
}


/**
 * Per-color customer pricing. The colors JSON carries internal supplier
 * cost/sale per colorway (S&S specials differ by color) — strip those and
 * emit customer-safe price/was per color instead. A manual style override
 * flattens every color to the override. Rows imported before per-color
 * pricing existed fall back to the style-level pair.
 */
function bt_cat_rest_colors_out($r, $cols, $pp) {
    $ov = isset($r['retail_override']) && $r['retail_override'] !== null && (float) $r['retail_override'] > 0;
    $out = array();
    foreach ((array) $cols as $c) {
        $cost = isset($c['cost']) ? (float) $c['cost'] : 0;
        $sale = isset($c['sale']) ? (float) $c['sale'] : 0;
        unset($c['cost'], $c['sale']);
        // Standard price = the style's single base retail for every color —
        // per-color supplier costs vary by which SIZE happened to be read
        // (2XL+ upcharges), and that must never surface as a price range.
        // Only a genuine sale makes a color deviate, and only downward.
        $styleReg = $ov ? $pp['price'] : bt_cat_autoprice((float) $r['cost']);
        if ($styleReg <= 0) $styleReg = $pp['price'];
        $c['price'] = $styleReg; $c['was'] = null;
        if (!$ov && $sale > 0 && ($cost <= 0 || $sale < $cost)) {
            $sp = bt_cat_autoprice($sale);
            if ($sp > 0 && $sp < $styleReg) { $c['price'] = $sp; $c['was'] = $styleReg; }
        }
        $out[] = $c;
    }
    return $out;
}


/**
 * Effective price range across colorways (for grid cards): min/max of each
 * color's customer price (sale-aware), plus whether any colorway is on sale.
 * Override flattens to a single price; legacy rows without per-color costs
 * fall back to the style-level pair.
 */
function bt_cat_price_range($r, $cols, $pp) {
    $ov = isset($r['retail_override']) && $r['retail_override'] !== null && (float) $r['retail_override'] > 0;
    $reg = $ov ? $pp['price'] : bt_cat_autoprice((float) $r['cost']);
    if ($reg <= 0) $reg = $pp['price'];
    $min = $reg; $max = $reg; $sale = false;
    if (!$ov) {
        foreach ((array) $cols as $c) {
            $cost = isset($c['cost']) ? (float) $c['cost'] : 0;
            $cs   = isset($c['sale']) ? (float) $c['sale'] : 0;
            if ($cs > 0 && ($cost <= 0 || $cs < $cost)) {
                $sp = bt_cat_autoprice($cs);
                if ($sp > 0 && $sp < $min) { $min = $sp; $sale = true; }
            }
        }
        if (!$sale && $pp['was'] !== null && $pp['price'] < $min) { $min = $pp['price']; $sale = true; }
    }
    return array('pmin' => $min, 'pmax' => $max, 'sale' => $sale);
}

/** Map DB rows to customer-safe list items (cost never leaves the server). */
function bt_cat_rest_rows_to_items($rows) {
    $popList = bt_cat_popular();
    $items = array();
    foreach ((array) $rows as $r) {
        $pp    = bt_cat_price_pair($r);
        $cols  = json_decode($r['colors'], true);
        $cols  = is_array($cols) ? $cols : array();
        $rng   = bt_cat_price_range($r, $cols, $pp);
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
            'price'    => $pp['price'],
            'was'      => $pp['was'],
            'pmin'     => $rng['pmin'],
            'pmax'     => $rng['pmax'],
            'sale'     => $rng['sale'],
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
        "SELECT id, supplier, brand, style_no, name, category, description, specs, colors, sizes, cost, sale_cost, retail, retail_override
         FROM $t WHERE id=%d AND detail_done=1", $id), ARRAY_A);
    if (!$r) return new WP_REST_Response(array('error' => 'not found'), 404);

    $pp   = bt_cat_price_pair($r);
    $cols = json_decode($r['colors'], true); $cols = is_array($cols) ? $cols : array();
    $cols = bt_cat_rest_colors_out($r, $cols, $pp);
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
        'price'  => $pp['price'],
        'was'    => $pp['was'],
    );
}

/** Drop the cached facet payloads (call after any catalog write).
    Counts now vary by filter combination, so instead of deleting one key we
    bump a generation number that's baked into every cache key — the stale
    entries simply expire on their own. */
function bt_cat_facets_flush() {
    delete_transient('bt_cat_facets_v2');   // legacy key from before counts existed
    update_option('bt_cat_facet_gen', (int) get_option('bt_cat_facet_gen', 0) + 1);
}

/** One grouped count, keyed by a single column, under every filter but its own. */
function bt_cat_facet_counts($col, $p, $skip) {
    global $wpdb;
    $t = bt_cat_table();
    $w = bt_cat_filter_where($p, $skip);
    $sql = "SELECT $col AS v, COUNT(*) AS n FROM $t WHERE {$w['sql']} GROUP BY $col";
    $rows = $w['args'] ? $wpdb->get_results($wpdb->prepare($sql, $w['args']), ARRAY_A)
                       : $wpdb->get_results($sql, ARRAY_A);
    $out = array();
    foreach ((array) $rows as $r) {
        $v = (string) $r['v'];
        if ($v === '') continue;              // undetermined never becomes an option
        $out[$v] = (int) $r['n'];
    }
    return $out;
}

/**
 * Counts for a comma-list column (size_set, color_fams). One scan, tallied in
 * PHP — a COUNT per possible value would mean 30+ full scans per facet load.
 */
function bt_cat_facet_counts_set($col, $p, $skip) {
    global $wpdb;
    $t = bt_cat_table();
    $w = bt_cat_filter_where($p, $skip);
    $sql = "SELECT $col AS v, COUNT(*) AS n FROM $t WHERE {$w['sql']} AND $col <> '' GROUP BY $col";
    $rows = $w['args'] ? $wpdb->get_results($wpdb->prepare($sql, $w['args']), ARRAY_A)
                       : $wpdb->get_results($sql, ARRAY_A);
    $out = array();
    foreach ((array) $rows as $r) {
        $n = (int) $r['n'];
        foreach (explode(',', (string) $r['v']) as $tok) {
            if ($tok === '') continue;
            if (!isset($out[$tok])) $out[$tok] = 0;
            $out[$tok] += $n;
        }
    }
    return $out;
}

/** Shape one facet for the client: ordered [{k,v,n}], zero-count values dropped. */
function bt_cat_facet_out($counts, $order, $labels = null) {
    $out = array();
    foreach ($order as $k) {
        if (empty($counts[$k])) continue;
        $out[] = array(
            'k' => (string) $k,
            'v' => $labels === null ? (string) $k : (isset($labels[$k]) ? $labels[$k] : (string) $k),
            'n' => (int) $counts[$k],
        );
    }
    return $out;
}

/**
 * Facet lists WITH counts, computed against the currently active filters.
 *
 * Every value returned has at least one matching product, and the storefront
 * hides any group left with fewer than two options — so browsing crewneck
 * sweatshirts never offers a Short Sleeve option that would return nothing,
 * and a hat never shows a Neckline group at all.
 */
function bt_cat_rest_facets($req) {
    global $wpdb;
    $t = bt_cat_table();

    $p   = bt_cat_read_params($req);
    $gen = (int) get_option('bt_cat_facet_gen', 0);
    $key = 'bt_cat_fct_' . $gen . '_' . md5(wp_json_encode($p));
    $cached = get_transient($key);
    if (is_array($cached)) return $cached;

    // --- brands (raw values, alphabetical) ---
    $brandCounts = bt_cat_facet_counts('brand', $p, 'brand');
    $brandOrder  = array_keys($brandCounts);
    sort($brandOrder);
    $brands = bt_cat_facet_out($brandCounts, $brandOrder);

    // --- categories (derived bucket column; same value the list filters on) ---
    $catCounts = bt_cat_facet_counts('bucket', $p, 'category');
    // Performance is a cross-cutting attribute shown alongside the categories.
    $wp2   = bt_cat_filter_where($p, 'category');
    $psql  = "SELECT COUNT(*) FROM $t WHERE {$wp2['sql']} AND perf = 1";
    $perfN = (int) ($wp2['args'] ? $wpdb->get_var($wpdb->prepare($psql, $wp2['args']))
                                 : $wpdb->get_var($psql));
    if ($perfN > 0) $catCounts['Performance'] = $perfN;
    $catOrder = array_keys($catCounts);
    sort($catOrder);
    $cats = bt_cat_facet_out($catCounts, $catOrder);

    // --- derived single-value attributes ---
    $defs = bt_cat_attr_defs();
    $attr = array();
    foreach ($defs as $facet => $d) {
        $counts = bt_cat_facet_counts($d['col'], $p, $facet);
        $attr[$facet] = bt_cat_facet_out($counts, array_keys($d['values']), $d['values']);
    }

    // --- sizes + color families (comma-list columns) ---
    $sizes  = bt_cat_facet_out(bt_cat_facet_counts_set('size_set', $p, 'size'), bt_cat_size_order());
    $colors = bt_cat_facet_out(bt_cat_facet_counts_set('color_fams', $p, 'color'), bt_cat_color_families());

    // --- quality tiers ---
    $tierCounts = bt_cat_facet_counts('tier', $p, 'quality');
    $qualLabels = array();
    foreach (bt_cat_quality_labels() as $label) $qualLabels[bt_cat_quality_key($label)] = $label;
    $quals = bt_cat_facet_out($tierCounts, array_keys($qualLabels), $qualLabels);

    $out = array(
        'brands'     => $brands,
        'categories' => $cats,
        'fits'       => $attr['aud'],
        'necks'      => $attr['neck'],
        'sleeves'    => $attr['sleeve'],
        'closures'   => $attr['closure'],
        'sizes'      => $sizes,
        'colors'     => $colors,
        'qualities'  => $quals,
    );
    set_transient($key, $out, 10 * MINUTE_IN_SECONDS);
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
        'Safety Gear'             => array('non-medical', 'personal protection', 'protection', 'mask', 'face cover'),
        'Accessories'             => array('accessor', 'sock', 'scarf', 'towel', 'lanyard', 'apron', 'blanket', 'glove'),
        'Activewear'              => array('activewear'),
    );
}

/* Gender/age used to be derived from the raw category on every query (seven
   NOT LIKEs for "unisex"). It now lives in the `aud` column, derived once at
   import in attrs.php — see bt_cat_attr_defs()['aud']. The old bt_cat_fit_*
   helpers are gone; bt_cat_attr_key('aud', ...) still accepts the old labels
   so shared /catalog/?fit=Women%27s links keep working. */
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
