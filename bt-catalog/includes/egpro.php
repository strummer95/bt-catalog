<?php
/**
 * BT Catalog — EG-PRO ingest + supplier page.
 *
 * EG-PRO is not a distributor (no PromoStandards / no REST API like S&S or
 * SanMar). It's a single manufacturer of house-brand performance blanks running
 * on Shopify, so the catalog comes straight from Shopify's public products.json
 * feed:  https://egpro.com/products.json?limit=250&page=N
 *
 * That one feed carries everything we need per style — title (style # + name),
 * product type (category), color + size options, per-variant price, and
 * per-color photos — so there's no two-phase discover/detail step like S&S.
 * We page-walk the feed server-side, parse each product, and upsert it under
 * supplier='egpro'. Everything EG-PRO makes is exclusive to them, so there's
 * nothing to dedup against S&S/SanMar.
 *
 * Pricing reuses the house rule (bt_cat_autoprice): cost x 2, rounded up to the
 * nearest .95. The representative cost is the cheapest variant (the base size),
 * so extended-size upcharges (5XL/6XL) never inflate the headline price.
 */
if (!defined('ABSPATH')) exit;

define('BT_CAT_EGPRO_BASE', 'https://egpro.com/products.json');
define('BT_CAT_EGPRO_PER',  250);   // Shopify max page size
define('BT_CAT_EGPRO_MAXP', 40);    // hard page cap (10k styles) — safety stop

/* ============================ FEED FETCH ============================ */

/**
 * Fetch one page of the products.json feed.
 * Returns ['ok'=>true,'products'=>array] or ['error'=>string].
 */
function bt_cat_egpro_fetch($page = 1, $limit = BT_CAT_EGPRO_PER, $timeout = 25) {
    $url  = add_query_arg(array('limit' => (int) $limit, 'page' => (int) $page), BT_CAT_EGPRO_BASE);
    $resp = wp_remote_get($url, array(
        'timeout' => $timeout,
        'headers' => array(
            'Accept'     => 'application/json',
            // Some Shopify stores reject blank user agents.
            'User-Agent' => 'BTCatalog/' . (defined('BT_CAT_VERSION') ? BT_CAT_VERSION : '1.0') . ' (+boomerts.com)',
        ),
    ));

    if (is_wp_error($resp)) return array('error' => $resp->get_error_message());
    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code !== 200) return array('error' => 'HTTP ' . $code, 'body' => substr($body, 0, 300));

    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['products']) || !is_array($json['products'])) {
        return array('error' => 'Unexpected feed format (no products array).', 'body' => substr($body, 0, 300));
    }
    return array('ok' => true, 'products' => $json['products']);
}

/* ============================ PARSERS ============================ */

/**
 * Split an EG-PRO title into style number + name.
 * Titles look like "#E360Y / Basic Training Youth 6\" Short" — sometimes with
 * no space before the slash ("#D346/ Victory ..."). Returns ['code','name'] or
 * null when the title doesn't carry a style number (e.g. test products).
 */
function bt_cat_egpro_parse_title($title) {
    $title = trim((string) $title);
    // Code is everything between '#' and the first slash (may contain hyphens,
    // e.g. "J728-71"); the name is everything after. Non-greedy so a slash
    // inside the name (e.g. "1/4 Zip") doesn't get mistaken for the separator.
    if (!preg_match('/^#\s*(.+?)\s*\/\s*(.+)$/u', $title, $m)) {
        return null;
    }
    return array('code' => trim($m[1]), 'name' => trim($m[2]));
}

/** Strip EG-PRO's trailing color code ("Forest Green-10" -> "Forest Green"). */
function bt_cat_egpro_clean_color($val) {
    return trim(preg_replace('/-\d+$/', '', (string) $val));
}

/**
 * EG-PRO's feed carries no color hex, so the storefront swatch chips render
 * blank. Map their finite, named palette to representative hex values (stored
 * without '#', matching the S&S convention) so the chips fill in — and so the
 * navy-first ranking can use hex too. Unknown names fall back to '' (grey chip).
 * Two-tone codes (Black/WH, OX/BK…) map to their dominant color.
 */
function bt_cat_egpro_hex($name) {
    static $map = array(
        'black' => '101012', 'black/wh' => '101012',
        'white' => 'ffffff',
        'navy' => '1b1f3b', 'nb' => '1b1f3b',
        'royal' => '1e4fa3',
        'red' => 'c8202f', 'cardinal' => '8c1d2c', 'maroon' => '6b1f2a',
        'forest green' => '1d3b2a', 'kelly green' => '2f9e44', 'od green' => '5a5a3c', 'sage' => '9caf88',
        'purple' => '5b2a86', 'mauve' => '9a7b8a',
        'pink' => 'f4a7c0', 'hot pink' => 'e7508a',
        'gold' => 'd4af37', 'vegas gold' => 'b6a76c', 'maize' => 'f4d35e',
        'burnt orange' => 'b1500f', 'texas orange' => 'bf5700', 'safety orange' => 'ff6a13',
        'safety yellow' => 'd7e600',
        'light blue' => '8fc1e3',
        'light grey' => 'c7c9cc', 'graphite' => '4a4d50', 'carbon' => '3a3d40',
        'steel' => '71797e', 'oxford' => '5b5e62', 'ox/bk' => '5b5e62', 'ox/rd' => '5b5e62', 'ox/ry' => '5b5e62',
    );
    $k = strtolower(trim((string) $name));
    return isset($map[$k]) ? $map[$k] : '';
}

/**
 * Pull the useful parts out of EG-PRO's Shopify body_html and leave the rest.
 * Their description carries Fabric + Weight + a feature bullet list, but also a
 * "$X MSRP (sign in to view net pricing)" line (must NOT show on our store), an
 * embedded widget's invisible <div> junk, and a "Learn more" link back to
 * egpro.com. Returns ['fabric','weight','html'] where html is a clean,
 * self-built <ul> of features (escaped — no raw supplier HTML reaches the page).
 */
function bt_cat_egpro_desc($html) {
    $html = (string) $html;
    $out  = array('fabric' => '', 'weight' => '', 'html' => '');

    // Collapse runs of whitespace INCLUDING non-breaking spaces (EG-PRO's markup
    // uses U+00A0 between words), then trim.
    $ws = function ($s) {
        $s = str_replace("\xC2\xA0", ' ', (string) $s);   // nbsp -> space
        return trim(preg_replace('/\s+/u', ' ', $s));
    };

    if (preg_match('/Fabric:.*?<\/strong>(.*?)<\/p>/is', $html, $m)) {
        $f = html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8');
        $f = $ws(preg_replace('/\s*Learn more\.?\s*$/i', '', $ws($f)));   // drop egpro.com link text
        $out['fabric'] = $f;
    }
    if (preg_match('/Weight:.*?<\/strong>(.*?)<\/p>/is', $html, $m)) {
        $out['weight'] = $ws(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
    if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $mm)) {
        $feat = array();
        foreach ($mm[1] as $li) {
            $t = $ws(html_entity_decode(wp_strip_all_tags(str_ireplace(array('<br>', '<br/>', '<br />'), ' ', $li)), ENT_QUOTES, 'UTF-8'));
            if ($t === '' || stripos($t, 'Sizes:') === 0) continue;      // sizes shown separately
            $feat[] = $t;
        }
        if ($feat) {
            $lis = '';
            foreach ($feat as $t) $lis .= '<li>' . esc_html($t) . '</li>';
            $out['html'] = '<ul>' . $lis . '</ul>';
        }
    }
    return $out;
}

/** Find the variant option position (1..3) for an option name, or 0 if absent. */
function bt_cat_egpro_option_pos($product, $name) {
    if (empty($product['options']) || !is_array($product['options'])) return 0;
    foreach ($product['options'] as $o) {
        if (isset($o['name']) && strcasecmp($o['name'], $name) === 0) {
            return (int) ($o['position'] ?? 0);
        }
    }
    return 0;
}

/** Ordered option values for a named option (preserves the store's order). */
function bt_cat_egpro_option_values($product, $name) {
    if (empty($product['options']) || !is_array($product['options'])) return array();
    foreach ($product['options'] as $o) {
        if (isset($o['name']) && strcasecmp($o['name'], $name) === 0) {
            return is_array($o['values'] ?? null) ? array_values($o['values']) : array();
        }
    }
    return array();
}

/**
 * Reduce one Shopify product into a catalog row (or null to skip).
 * Skips products with no variants or no parseable style number.
 *
 * Returns array with keys:
 *   code, name, category, description, colors[], sizes[], cost, weight_oz, img
 * where colors[] = ['name','hex'=>'','img','swatch'=>''].
 */
function bt_cat_egpro_reduce($product) {
    $variants = (isset($product['variants']) && is_array($product['variants'])) ? $product['variants'] : array();
    if (empty($variants)) return null;                       // test/junk products

    $parsed = bt_cat_egpro_parse_title($product['title'] ?? '');
    if ($parsed === null) return null;                       // no style number

    $colorPos = bt_cat_egpro_option_pos($product, 'Color');
    $sizePos  = bt_cat_egpro_option_pos($product, 'Size');

    // Representative cost = cheapest priced variant (base size, no upcharge).
    $cost = 0.0;
    foreach ($variants as $v) {
        $p = (float) ($v['price'] ?? 0);
        if ($p > 0 && ($cost === 0.0 || $p < $cost)) $cost = $p;
    }

    // Per-color photo: first variant of that color carrying a featured_image.
    $colorImg = array();
    if ($colorPos > 0) {
        $okey = 'option' . $colorPos;
        foreach ($variants as $v) {
            $cv = isset($v[$okey]) ? (string) $v[$okey] : '';
            if ($cv === '' || isset($colorImg[$cv])) continue;
            if (!empty($v['featured_image']['src'])) $colorImg[$cv] = (string) $v['featured_image']['src'];
        }
    }
    // Product-level fallback image.
    $fallbackImg = '';
    if (!empty($product['images'][0]['src'])) $fallbackImg = (string) $product['images'][0]['src'];

    // Colors, in the store's listed order.
    $colors = array();
    foreach (bt_cat_egpro_option_values($product, 'Color') as $cv) {
        $clean = bt_cat_egpro_clean_color($cv);
        $colors[] = array(
            'name'   => $clean,
            'hex'    => bt_cat_egpro_hex($clean),            // mapped from name (feed has none)
            'img'    => isset($colorImg[$cv]) ? $colorImg[$cv] : $fallbackImg,
            'swatch' => '',
        );
    }

    // Sizes, in the store's listed order.
    $sizes = bt_cat_egpro_option_values($product, 'Size');

    // Weight (oz) from grams on the first variant that reports it (fallback only).
    $weight = null;
    foreach ($variants as $v) {
        if (!empty($v['grams'])) { $weight = round((float) $v['grams'] / 28.3495, 2); break; }
    }

    // Parse the description: structured Fabric/Weight + clean feature bullets.
    $desc = bt_cat_egpro_desc($product['body_html'] ?? '');

    return array(
        'code'        => $parsed['code'],
        'name'        => $parsed['name'],
        'category'    => (string) ($product['product_type'] ?? ''),
        'description' => $desc['html'],                      // clean, escaped feature bullets
        'fabric'      => $desc['fabric'],
        'weight_text' => $desc['weight'],                    // EG-PRO's stated fabric weight
        'colors'      => $colors,
        'sizes'       => array_values($sizes),
        'cost'        => $cost,
        'weight_oz'   => $weight,                            // grams-derived fallback
        'img'         => !empty($colors[0]['img']) ? $colors[0]['img'] : $fallbackImg,
    );
}

/** Upsert one reduced product into the cache table. Returns a status row. */
function bt_cat_egpro_store($product) {
    $r = bt_cat_egpro_reduce($product);
    if ($r === null) return array('status' => 'skipped');

    $specs = array(array('Brand', 'EG-PRO'));
    if ($r['fabric'] !== '')      $specs[] = array('Fabric', $r['fabric']);
    if ($r['weight_text'] !== '') $specs[] = array('Weight', $r['weight_text']);
    elseif ($r['weight_oz'] !== null) $specs[] = array('Weight', $r['weight_oz'] . ' oz');

    bt_cat_upsert(array(
        'supplier'          => 'egpro',
        'supplier_style_id' => (string) ($product['id'] ?? $r['code']),
        'style_no'          => $r['code'],
        'brand'             => 'EG-PRO',
        'name'              => $r['name'],
        'category'          => $r['category'],
        'description'       => $r['description'],
        'specs'             => wp_json_encode($specs),
        'colors'            => wp_json_encode($r['colors']),
        'sizes'             => implode(',', $r['sizes']),
        'cost'              => $r['cost'],
        'sale_cost'         => 0,
        'retail'            => function_exists('bt_cat_autoprice') ? bt_cat_autoprice($r['cost']) : round($r['cost'] * 2, 2),
        'detail_done'       => 1,
        'active'            => 1,
    ));

    return array('status' => 'imported', 'code' => $r['code'], 'colors' => count($r['colors']),
                 'sizes' => count($r['sizes']), 'cost' => $r['cost']);
}

/* ============================ IMPORT ============================ */

/**
 * Page-walk the whole feed and import every style. Synchronous — the catalog is
 * small (a few dozen styles), so it finishes in one request. Stops on the first
 * empty page or the safety cap.
 * Returns ['ok'=>true,'imported'=>int,'skipped'=>int,'pages'=>int] or ['error'].
 */
function bt_cat_egpro_import_all() {
    $imported = 0; $skipped = 0; $page = 1;

    for (; $page <= BT_CAT_EGPRO_MAXP; $page++) {
        $r = bt_cat_egpro_fetch($page);
        if (empty($r['ok'])) {
            if ($page === 1) return $r;          // first page failed -> real error
            break;                                // later page error -> stop, keep what we got
        }
        if (empty($r['products'])) break;         // drained
        foreach ($r['products'] as $product) {
            $res = bt_cat_egpro_store($product);
            if ($res['status'] === 'imported') $imported++; else $skipped++;
        }
        if (count($r['products']) < BT_CAT_EGPRO_PER) break;  // last (partial) page
    }

    return array('ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'pages' => $page);
}

/** Read-only test: fetch page 1, parse the first real product. */
function bt_cat_egpro_test() {
    $r = bt_cat_egpro_fetch(1, 5);
    if (empty($r['ok'])) return $r;
    $total = count($r['products']);
    $sample = null;
    foreach ($r['products'] as $p) {
        $red = bt_cat_egpro_reduce($p);
        if ($red !== null) { $sample = $red; break; }
    }
    return array('ok' => true, 'count' => $total, 'sample' => $sample);
}

/* ============================ ADMIN PAGE ============================ */

add_action('admin_menu', function () {
    add_submenu_page('bt-catalog', 'EG-PRO', 'EG-PRO', 'manage_options', 'bt-catalog-egpro', 'bt_cat_egpro_page');
});

function bt_cat_egpro_page() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $t = bt_cat_table();

    $test = null; $importMsg = '';

    if (isset($_POST['bt_cat_egpro_test'])) {
        check_admin_referer('bt_cat_egpro');
        $test = bt_cat_egpro_test();
    }

    if (isset($_POST['bt_cat_egpro_import'])) {
        check_admin_referer('bt_cat_egpro');
        $res = bt_cat_egpro_import_all();
        $importMsg = empty($res['ok'])
            ? '<span style="color:#b32d2e">Import failed: ' . esc_html($res['error'] ?? 'unknown')
              . (!empty($res['body']) ? ' — <code>' . esc_html($res['body']) . '</code>' : '') . '</span>'
            : 'Imported / refreshed <strong>' . (int) $res['imported'] . '</strong> styles'
              . ($res['skipped'] ? ' (' . (int) $res['skipped'] . ' skipped)' : '') . '.';
    }

    if (isset($_POST['bt_cat_egpro_clear'])) {
        check_admin_referer('bt_cat_egpro');
        $n = (int) $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE supplier=%s", 'egpro'));
        echo '<div class="notice notice-warning is-dismissible"><p>Removed ' . $n . ' EG-PRO item(s).</p></div>';
    }

    $eg_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE supplier=%s", 'egpro'));
    $eg_rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT supplier_style_id, style_no, name, category, cost, retail, colors, sizes
         FROM $t WHERE supplier=%s ORDER BY updated_at DESC LIMIT 30", 'egpro'), ARRAY_A);
    ?>
    <div class="wrap">
        <h1>EG-PRO</h1>
        <p class="description" style="max-width:820px">
            EG-PRO is a house-brand performance manufacturer on Shopify, so there's nothing to connect —
            the catalog pulls straight from their public product feed
            (<code>egpro.com/products.json</code>). Everything they make is exclusive, so none of it
            collides with S&amp;S or SanMar. Each style is auto-priced (your cost &times; 2, rounded up to .95).
        </p>

        <h2>1. Test the feed</h2>
        <p class="description">Reads the feed and parses the first style. Writes nothing.</p>
        <form method="post" style="margin:8px 0 4px">
            <?php wp_nonce_field('bt_cat_egpro'); ?>
            <button type="submit" name="bt_cat_egpro_test" value="1" class="button">Test feed</button>
        </form>
        <?php if ($test !== null): ?>
            <div class="notice <?php echo empty($test['ok']) ? 'notice-error' : 'notice-success'; ?>" style="padding:10px 14px;max-width:620px">
                <?php if (empty($test['ok'])): ?>
                    <p style="margin:0"><strong>Feed unreachable:</strong> <?php echo esc_html($test['error'] ?? 'unknown'); ?>
                        <?php if (!empty($test['body'])) echo '<br><code>' . esc_html($test['body']) . '</code>'; ?></p>
                <?php else: $s = $test['sample']; ?>
                    <p style="margin:0 0 6px"><strong>Feed reachable.</strong> First page returned <?php echo (int) $test['count']; ?> product(s).</p>
                    <?php if ($s): ?>
                        <table class="widefat striped" style="max-width:560px">
                            <tr><td style="width:120px">Style #</td><td><strong><?php echo esc_html($s['code']); ?></strong></td></tr>
                            <tr><td>Name</td><td><?php echo esc_html($s['name']); ?></td></tr>
                            <tr><td>Category</td><td><?php echo esc_html($s['category']); ?></td></tr>
                            <tr><td>Colors</td><td><?php echo (int) count($s['colors']); ?></td></tr>
                            <tr><td>Sizes</td><td><?php echo esc_html(implode(', ', $s['sizes'])); ?></td></tr>
                            <tr><td>Your cost</td><td>$<?php echo esc_html(number_format((float) $s['cost'], 2)); ?></td></tr>
                            <tr><td>Auto retail</td><td>$<?php echo esc_html(number_format((float) (function_exists('bt_cat_autoprice') ? bt_cat_autoprice($s['cost']) : $s['cost'] * 2), 2)); ?></td></tr>
                        </table>
                        <?php if (!empty($s['img'])): ?>
                            <p style="margin-top:8px"><img src="<?php echo esc_url($s['img']); ?>" style="max-height:150px;border:1px solid #ddd;border-radius:8px"></p>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <hr style="margin:28px 0;max-width:900px">
        <h2>2. Import the catalog</h2>
        <p class="description">Pulls every EG-PRO style and auto-prices it. Safe to re-run anytime &mdash; it refreshes
            existing styles in place (matched on EG-PRO's product ID) and adds any new ones. Re-run to pick up new
            releases or price changes.</p>
        <?php if ($importMsg) echo '<p><strong>' . wp_kses_post($importMsg) . '</strong></p>'; ?>
        <form method="post" style="margin:8px 0">
            <?php wp_nonce_field('bt_cat_egpro'); ?>
            <button type="submit" name="bt_cat_egpro_import" value="1" class="button button-primary">Import / refresh EG-PRO catalog</button>
        </form>

        <hr style="margin:28px 0;max-width:900px">
        <h2>EG-PRO items in your catalog (<?php echo (int) $eg_total; ?>)</h2>
        <?php if (!$eg_rows): ?>
            <p class="description">None yet — run the import above.</p>
        <?php else: ?>
            <p class="description">Most recent 30. This is what shoppers see on the storefront. Spot-check a few costs and images.</p>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th>Style</th><th>Name</th><th>Category</th><th>Colors</th><th>Sizes</th><th>Your cost</th><th>Retail</th><th>Image</th></tr></thead>
                <tbody>
                <?php foreach ($eg_rows as $row):
                    $cols = json_decode((string) $row['colors'], true); if (!is_array($cols)) $cols = array();
                    $img = '';
                    foreach ($cols as $c) { if (!empty($c['img'])) { $img = $c['img']; break; } }
                    $nsz = $row['sizes'] !== '' ? count(explode(',', $row['sizes'])) : 0;
                ?>
                    <tr>
                        <td><strong><?php echo esc_html($row['style_no']); ?></strong></td>
                        <td style="max-width:240px"><?php echo esc_html($row['name']); ?></td>
                        <td><?php echo esc_html($row['category']); ?></td>
                        <td><?php echo (int) count($cols); ?></td>
                        <td><?php echo (int) $nsz; ?></td>
                        <td>$<?php echo esc_html(number_format((float) $row['cost'], 2)); ?></td>
                        <td>$<?php echo esc_html(number_format((float) $row['retail'], 2)); ?></td>
                        <td><?php echo $img ? '<img src="' . esc_url($img) . '" style="height:40px;border:1px solid #ddd;border-radius:4px">' : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <hr style="margin:24px 0;max-width:900px">
        <form method="post" onsubmit="return confirm('Remove all EG-PRO items from the catalog? You can re-import them.');">
            <?php wp_nonce_field('bt_cat_egpro'); ?>
            <button type="submit" name="bt_cat_egpro_clear" value="1" class="button button-secondary" style="color:#b32d2e;border-color:#b32d2e">Clear EG-PRO items</button>
            <span class="description" style="margin-left:8px">Only removes EG-PRO rows — leaves S&amp;S and SanMar untouched.</span>
        </form>
    </div>
    <?php
}
