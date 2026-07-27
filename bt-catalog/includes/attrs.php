<?php
/**
 * BT Catalog — derived product attributes (the extra filter dimensions).
 *
 * Suppliers give us a garment TYPE and a marketing title, nothing structured.
 * Everything customers actually shop by — gender/age, neckline, sleeve length,
 * closure, sizes, colors — has to be derived. We do that ONCE at import time
 * into real columns so the storefront can filter and COUNT them cheaply
 * (same pattern as the tier/perf columns).
 *
 * Columns written here (all on the catalog table):
 *   bucket     the consolidated category label (T-Shirts, Hoodies & Fleece, ...)
 *   aud        unisex | women | girls | youth | toddler        (gender / age)
 *   neck       crew | vneck | scoop | henley | hooded | mock | polo | collar | tank
 *   sleeve     sleeveless | short | threequarter | long
 *   closure    pullover | fullzip | quarterzip | snap | button
 *   size_set   canonical comma list, FIND_IN_SET-able  (S,M,L,XL,2XL,...)
 *   color_fams canonical comma list of color families present on the style
 *
 * An attribute that can't be determined stays '' — and an empty value is never
 * offered as a filter option, which is what makes "don't show me Sleeve Length
 * on a hat" work for free.
 *
 * Detection order matters. The NAME and CATEGORY are authoritative; the
 * description is marketing copy and only consulted where it can't mislead
 * ("pairs well with our long sleeve tee" must not make a tee long-sleeved).
 * Where the supplier says nothing, the garment type implies the answer:
 * a tee with no sleeve token is short sleeve, a hoodie is long sleeve.
 */
if (!defined('ABSPATH')) exit;

/* ============================== definitions ============================== */

/**
 * Every derived facet in one table. Order here is the order they render.
 *   col    DB column
 *   param  REST/URL parameter
 *   label  storefront group heading
 *   values key => display label, in display order
 */
function bt_cat_attr_defs() {
    static $d = null;
    if ($d !== null) return $d;
    $d = array(
        'aud' => array(
            'col' => 'aud', 'param' => 'fit', 'label' => 'Gender / Age',
            'values' => array(
                'unisex'  => "Unisex / Men's",
                'women'   => "Women's",
                'girls'   => "Girls",
                'youth'   => "Youth",
                'toddler' => "Toddler & Infant",
            ),
        ),
        'neck' => array(
            'col' => 'neck', 'param' => 'neck', 'label' => 'Neckline',
            'values' => array(
                'crew'   => 'Crew Neck',
                'vneck'  => 'V-Neck',
                'scoop'  => 'Scoop Neck',
                'henley' => 'Henley',
                'mock'   => 'Mock / Turtleneck',
                'hooded' => 'Hooded',
                'polo'   => 'Polo Collar',
                'collar' => 'Button-Down Collar',
                'tank'   => 'Tank / Racerback',
            ),
        ),
        'sleeve' => array(
            'col' => 'sleeve', 'param' => 'sleeve', 'label' => 'Sleeve Length',
            'values' => array(
                'sleeveless'   => 'Sleeveless',
                'short'        => 'Short Sleeve',
                'threequarter' => '3/4 Sleeve',
                'long'         => 'Long Sleeve',
            ),
        ),
        'closure' => array(
            'col' => 'closure', 'param' => 'closure', 'label' => 'Style',
            'values' => array(
                'pullover'   => 'Pullover',
                'quarterzip' => 'Quarter / Half-Zip',
                'fullzip'    => 'Full-Zip',
                'snap'       => 'Snap Front',
                'button'     => 'Button Front',
            ),
        ),
    );
    return $d;
}

/** Label => key for one facet (accepts the key itself too, for URL round-trips). */
function bt_cat_attr_key($facet, $val) {
    $defs = bt_cat_attr_defs();
    if (!isset($defs[$facet])) return '';
    $val = trim((string) $val);
    if ($val === '') return '';
    if (isset($defs[$facet]['values'][$val])) return $val;          // already a key
    $low = strtolower($val);
    foreach ($defs[$facet]['values'] as $k => $label) {
        if (strtolower($label) === $low) return $k;
    }
    // Tolerate the pre-0.22 Fit labels so old shared links keep working.
    if ($facet === 'aud') {
        if (strpos($low, 'women') !== false || strpos($low, 'ladies') !== false) return 'women';
        if (strpos($low, 'girl')  !== false) return 'girls';
        if (strpos($low, 'youth') !== false || strpos($low, 'boy') !== false) return 'youth';
        if (strpos($low, 'infant') !== false || strpos($low, 'toddler') !== false) return 'toddler';
        if (strpos($low, 'unisex') !== false || strpos($low, 'men') !== false) return 'unisex';
    }
    return '';
}

/** Display label for a key (falls back to the key). */
function bt_cat_attr_label($facet, $key) {
    $defs = bt_cat_attr_defs();
    return isset($defs[$facet]['values'][$key]) ? $defs[$facet]['values'][$key] : (string) $key;
}

/* ============================== derivation ============================== */

/** Lowercased name + category (authoritative signal). */
function bt_cat_attr_hay($row) {
    return strtolower(
        (isset($row['name']) ? $row['name'] : '') . ' ' .
        (isset($row['category']) ? $row['category'] : '')
    );
}

/** True if any needle appears in the haystack. */
function bt_cat_attr_has($hay, $needles) {
    foreach ((array) $needles as $n) { if (strpos($hay, $n) !== false) return true; }
    return false;
}

/**
 * Word-start match, for tokens that are substrings of unrelated words:
 * plain strpos('snap') fires on "Snapback Trucker Cap", which is a hat.
 */
function bt_cat_attr_word($hay, $stems) {
    foreach ((array) $stems as $st) {
        if (preg_match('/\b' . preg_quote($st, '/') . '/', $hay)) return true;
    }
    return false;
}

/**
 * Gender / age from the raw supplier category (plus the title as a backstop —
 * SanMar puts "Ladies" in the product name more reliably than the category).
 * Toddler/infant is split out of Youth so the age ladder is actually useful.
 */
function bt_cat_derive_aud($row) {
    $hay = bt_cat_attr_hay($row);
    if (bt_cat_attr_has($hay, array('infant', 'toddler', 'onesie', 'newborn'))) return 'toddler';
    if (bt_cat_attr_has($hay, array("girl"))) return 'girls';
    if (bt_cat_attr_has($hay, array('women', 'ladies', "ladies'", 'juniors'))) return 'women';
    if (bt_cat_attr_has($hay, array('youth', 'boys', 'kids', 'juvenile'))) return 'youth';
    return 'unisex';
}

/**
 * Garment family, used to pick sensible defaults. Reuses the shipped category
 * buckets so this never drifts from the Categories filter.
 */
function bt_cat_attr_bucket($row) {
    $cat = isset($row['category']) ? $row['category'] : '';
    $b   = function_exists('bt_cat_norm_category') ? bt_cat_norm_category($cat) : '';
    if ($b !== '') return $b;
    // Bare gender/age categories normalize to '' — fall back to the title.
    $hay = bt_cat_attr_hay($row);
    if (bt_cat_attr_has($hay, array('hoodie', 'sweatshirt', 'fleece'))) return 'Hoodies & Fleece';
    if (bt_cat_attr_has($hay, array('polo')))                          return 'Polos';
    if (bt_cat_attr_has($hay, array('tank')))                          return 'Tanks';
    if (bt_cat_attr_has($hay, array('tee', 't-shirt')))                return 'T-Shirts';
    return '';
}

/** Buckets that have a neckline at all (a tote bag does not). */
function bt_cat_attr_necked($bucket) {
    return in_array($bucket, array(
        'T-Shirts', 'Polos', 'Tanks', 'Hoodies & Fleece', 'Quarter-Zips & Layering',
        'Woven Shirts', 'Outerwear', 'Activewear',
    ), true);
}

/** Buckets that actually wear sleeves (everything else gets no sleeve facet). */
function bt_cat_attr_sleeved($bucket) {
    return in_array($bucket, array(
        'T-Shirts', 'Polos', 'Tanks', 'Hoodies & Fleece', 'Quarter-Zips & Layering',
        'Woven Shirts', 'Outerwear', 'Activewear',
    ), true);
}

/** Buckets where a closure is a meaningful distinction. */
function bt_cat_attr_closable($bucket) {
    return in_array($bucket, array(
        'Hoodies & Fleece', 'Quarter-Zips & Layering', 'Outerwear', 'Woven Shirts', 'Activewear',
    ), true);
}

/** Neckline. '' for anything that doesn't have one (hats, bags, bottoms). */
function bt_cat_derive_neck($row, $bucket) {
    // Gate on the garment type FIRST — otherwise a "Hooded Towel" in
    // Accessories picks up a neckline it doesn't have.
    if (!bt_cat_attr_necked($bucket)) return '';
    $hay = bt_cat_attr_hay($row);

    // Explicit tokens win, most specific first.
    if (bt_cat_attr_has($hay, array('v-neck', 'vneck', 'v neck')))                    return 'vneck';
    if (bt_cat_attr_has($hay, array('scoop')))                                       return 'scoop';
    if (bt_cat_attr_has($hay, array('henley')))                                      return 'henley';
    if (bt_cat_attr_has($hay, array('mock neck', 'mockneck', 'mock-neck', 'turtleneck', 'turtle neck'))) return 'mock';
    if (bt_cat_attr_word($hay, array('hood')))                                       return 'hooded';
    if (bt_cat_attr_has($hay, array('racerback', 'racer back')))                      return 'tank';
    if (bt_cat_attr_has($hay, array('crewneck', 'crew neck', 'crew-neck')))           return 'crew';

    // Otherwise the garment type implies it.
    switch ($bucket) {
        case 'Tanks':        return 'tank';
        case 'Polos':        return 'polo';
        case 'Woven Shirts': return 'collar';
        case 'T-Shirts':     return 'crew';   // S&S convention: unmarked tee = crew
        case 'Hoodies & Fleece':
            return 'crew';                    // hooded was caught above
        case 'Quarter-Zips & Layering':
            return 'mock';                    // 1/4-zips are mock-neck unless stated
    }
    return '';
}

/**
 * Sleeve length. '' for anything sleeveless-by-nature (hats, bags, bottoms).
 * $closure is passed in because a zip/pullover layer is long-sleeved whatever
 * bucket it landed in — a "Sport-Wick Mock Neck Pullover" filed under
 * Activewear is not a short sleeve garment.
 */
function bt_cat_derive_sleeve($row, $bucket, $closure = '') {
    if (!bt_cat_attr_sleeved($bucket)) return '';
    $hay = bt_cat_attr_hay($row);

    if (bt_cat_attr_has($hay, array('3/4 sleeve', 'three-quarter', 'three quarter', '3/4-sleeve'))) return 'threequarter';
    if (bt_cat_attr_has($hay, array('long sleeve', 'long-sleeve', 'longsleeve', 'ls tee')))         return 'long';
    if (bt_cat_attr_has($hay, array('short sleeve', 'short-sleeve', 'shortsleeve')))                return 'short';
    if (bt_cat_attr_has($hay, array('sleeveless', 'tank', 'racerback', 'muscle')))                  return 'sleeveless';

    if (in_array($closure, array('pullover', 'quarterzip', 'fullzip'), true)) return 'long';

    // Type defaults where the supplier said nothing.
    switch ($bucket) {
        case 'Tanks':        return 'sleeveless';
        case 'T-Shirts':     return 'short';
        case 'Polos':        return 'short';
        case 'Activewear':   return 'short';
        case 'Hoodies & Fleece':
        case 'Quarter-Zips & Layering':
        case 'Woven Shirts':
        case 'Outerwear':    return 'long';
    }
    return '';
}

/** Closure / silhouette. '' for garments where it means nothing (tees, tanks). */
function bt_cat_derive_closure($row, $bucket) {
    if (!bt_cat_attr_closable($bucket)) return '';
    $hay = bt_cat_attr_hay($row);

    if (bt_cat_attr_has($hay, array('1/4 zip', '1/4-zip', 'quarter zip', 'quarter-zip',
                                    '1/2 zip', '1/2-zip', 'half zip', 'half-zip'))) return 'quarterzip';
    if (bt_cat_attr_has($hay, array('full zip', 'full-zip', 'fullzip', 'zip up', 'zip-up',
                                    'zip front', 'zip-front', 'zip hood')))         return 'fullzip';
    if (bt_cat_attr_word($hay, array('snap')))                                      return 'snap';
    if (bt_cat_attr_has($hay, array('button-down', 'button down', 'button front',
                                    'button-front', 'dress shirt')))                return 'button';
    if (bt_cat_attr_has($hay, array('pullover')))                                   return 'pullover';

    switch ($bucket) {
        case 'Hoodies & Fleece':        return 'pullover';
        case 'Quarter-Zips & Layering': return 'quarterzip';
        case 'Woven Shirts':            return 'button';
    }
    return '';   // Outerwear varies too much to guess
}

/* ================================ sizes ================================ */

/**
 * Canonical size token. Collapses supplier spellings so one filter chip covers
 * XXL / 2XL / 2X, and so tall and youth sizes stay distinct from adult ones.
 * Returns '' for anything unrecognized (numeric waists, "5/6", etc.) — those
 * stay out of the size facet rather than polluting it with one-off values.
 */
function bt_cat_size_canon($raw) {
    $s = strtoupper(trim((string) $raw));
    $s = str_replace(array(' ', '.', '_'), '', $s);
    if ($s === '') return '';

    if (in_array($s, array('OSFA', 'OS', 'ONESIZE', 'ONESIZEFITSALL', 'ONESIZEFITSMOST', 'ADJUSTABLE'), true)) return 'OSFA';
    if (in_array($s, array('NB', 'NEWBORN'), true)) return 'NB';

    // Infant months: 6M / 12MO / 18 Months
    if (preg_match('/^(\d{1,2})M(O|OS|ONTH|ONTHS)?$/', $s, $m)) return $m[1] . 'M';
    // Toddler: 2T..6T
    if (preg_match('/^([2-6])T$/', $s, $m)) return $m[1] . 'T';

    // Youth prefixed: YXS / YS / YM / YL / YXL / YOUTHL
    if (preg_match('/^Y(?:OUTH)?-?(XS|S|M|L|XL)$/', $s, $m)) return 'Y' . $m[1];

    // Tall: MT / LT / XLT / 2XLT / XXLT
    if (preg_match('/^(X{2,6})LT$/', $s, $m))      return strlen($m[1]) . 'XLT';
    if (preg_match('/^([2-6])XLT$/', $s, $m))      return $m[1] . 'XLT';
    if (preg_match('/^(M|L|XL)T$/', $s, $m))       return $m[1] . 'T';

    // Adult: XS / S / M / L / XL / XXL / 2XL / 2X
    if (preg_match('/^(X{2,6})L$/', $s, $m))       return strlen($m[1]) . 'XL';
    if (preg_match('/^([2-6])X(L)?$/', $s, $m))    return $m[1] . 'XL';
    if (in_array($s, array('XS', 'S', 'M', 'L', 'XL'), true)) return $s;

    return '';
}

/** Every canonical size, in the order they should render. */
function bt_cat_size_order() {
    static $o = null;
    if ($o !== null) return $o;
    $o = array(
        'NB', '3M', '6M', '9M', '12M', '18M', '24M',
        '2T', '3T', '4T', '5T', '6T',
        'YXS', 'YS', 'YM', 'YL', 'YXL',
        'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL', '6XL',
        'MT', 'LT', 'XLT', '2XLT', '3XLT', '4XLT',
        'OSFA',
    );
    return $o;
}

/** Canonical comma list for the size_set column (ordered, de-duplicated). */
function bt_cat_derive_sizes($row) {
    $raw = isset($row['sizes']) ? (string) $row['sizes'] : '';
    if ($raw === '') return '';
    $have = array();
    foreach (explode(',', $raw) as $z) {
        $c = bt_cat_size_canon($z);
        if ($c !== '') $have[$c] = true;
    }
    if (!$have) return '';
    $out = array();
    foreach (bt_cat_size_order() as $z) { if (isset($have[$z])) $out[] = $z; }
    return implode(',', $out);
}

/* ============================ color families ============================ */

/**
 * Which color families a style actually offers. Previously the color filter
 * ran LIKE '%navy%' against the whole colors JSON blob, which also matched
 * image paths and hex codes; matching per color NAME is both correct and
 * countable.
 */
function bt_cat_derive_color_fams($row) {
    $cols = json_decode(isset($row['colors']) ? (string) $row['colors'] : '', true);
    if (!is_array($cols) || !$cols) return '';
    $fams = bt_cat_color_families();
    $hit  = array();
    foreach ($cols as $c) {
        $n = strtolower(isset($c['name']) ? (string) $c['name'] : '');
        if ($n === '') continue;
        foreach ($fams as $fam) {
            if (isset($hit[$fam])) continue;
            foreach (bt_cat_family_terms($fam) as $term) {
                if (strpos($n, $term) !== false) { $hit[$fam] = true; break; }
            }
        }
    }
    $out = array();
    foreach ($fams as $fam) { if (isset($hit[$fam])) $out[] = $fam; }
    return implode(',', $out);
}

/** Family names in swatch order (mirrors FAMILIES in catalog.js). */
function bt_cat_color_families() {
    return array('Black', 'White', 'Grey', 'Blue', 'Red', 'Green',
                 'Yellow', 'Orange', 'Pink', 'Purple', 'Neutral');
}

/* ============================== write path ============================== */

/** All derived attributes for one row, ready to merge into an upsert. */
function bt_cat_derive_attrs($row) {
    $bucket  = bt_cat_attr_bucket($row);
    $closure = bt_cat_derive_closure($row, $bucket);
    return array(
        'bucket'     => $bucket,
        'aud'        => bt_cat_derive_aud($row),
        'neck'       => bt_cat_derive_neck($row, $bucket),
        'sleeve'     => bt_cat_derive_sleeve($row, $bucket, $closure),
        'closure'    => $closure,
        'size_set'   => bt_cat_derive_sizes($row),
        'color_fams' => bt_cat_derive_color_fams($row),
    );
}

/**
 * Backfill every existing row. Groups identical attribute sets and updates them
 * in one statement each, so 6.5k rows cost a few dozen queries, not 6.5k.
 * Safe to run repeatedly.
 */
function bt_cat_apply_attrs() {
    global $wpdb;
    $t = bt_cat_table();
    if (!$wpdb->get_var("SHOW COLUMNS FROM $t LIKE 'bucket'")) return 0;   // columns not added yet

    $rows = $wpdb->get_results("SELECT id, name, category, sizes, colors FROM $t", ARRAY_A);
    if (!is_array($rows)) return 0;

    $groups = array();   // serialized attrs => [ids]
    foreach ($rows as $r) {
        $a = bt_cat_derive_attrs($r);
        $k = $a['bucket'] . '|' . $a['aud'] . '|' . $a['neck'] . '|' . $a['sleeve'] . '|'
           . $a['closure'] . '|' . $a['size_set'] . '|' . $a['color_fams'];
        if (!isset($groups[$k])) $groups[$k] = array('attrs' => $a, 'ids' => array());
        $groups[$k]['ids'][] = (int) $r['id'];
    }

    $n = 0;
    foreach ($groups as $g) {
        $a = $g['attrs'];
        foreach (array_chunk($g['ids'], 500) as $chunk) {
            if (!$chunk) continue;
            $in = implode(',', array_map('intval', $chunk));   // ints only -> safe to inline
            $wpdb->query($wpdb->prepare(
                "UPDATE $t SET bucket=%s, aud=%s, neck=%s, sleeve=%s, closure=%s,
                        size_set=%s, color_fams=%s
                 WHERE id IN ($in)",
                $a['bucket'], $a['aud'], $a['neck'], $a['sleeve'], $a['closure'],
                $a['size_set'], $a['color_fams']
            ));
            $n += count($chunk);
        }
    }
    if (function_exists('bt_cat_facets_flush')) bt_cat_facets_flush();
    return $n;
}

/**
 * Re-derive once per plugin version. Called from the version-gated init hook
 * (right after the dbDelta that adds the columns) rather than admin_init like
 * the tier backfill: the derived columns are what the STOREFRONT filters on,
 * so leaving them empty until an admin happens to load wp-admin would show
 * visitors an empty facet rail. One pass, on the first request after an update.
 */
function bt_cat_attrs_ensure() {
    if (get_option('bt_cat_attr_stamp') === BT_CAT_VERSION) return;
    global $wpdb;
    $t = bt_cat_table();
    if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return;   // table not ready yet
    bt_cat_apply_attrs();
    update_option('bt_cat_attr_stamp', BT_CAT_VERSION);
}

/* Safety net: if the init pass never ran (table created later), catch it in admin. */
add_action('admin_init', 'bt_cat_attrs_ensure', 20);
