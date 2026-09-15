<?php
/**
 * BT Catalog — the signed bridge PresStora reads the catalog through.
 *
 * WHY THIS EXISTS
 *
 * PresStora needs SanMar styles — Port Authority, Sport-Tek, Port & Company,
 * District, CornerStone, New Era — and S&S does not sell any of them. Pulling
 * them from SanMar directly needs php-soap on the PresStora box AND SanMar
 * whitelisting its IP, and Dillon, Sep 15: "cant you get it the way boomer t's
 * does, i cant check with sanmar, i dont have the time."
 *
 * He does not have to. THIS server already imported them — 2,989 SanMar styles,
 * with colors, sizes, images and cost — and it is already whitelisted. So
 * PresStora asks here instead of asking SanMar.
 *
 * WHY IT IS NOT THE PUBLIC ROUTE
 *
 * /boomerts/v1/catalog is the storefront's own feed and deliberately strips
 * cost: "Cost / sale_cost never leave the server". That is right for a route
 * anyone can call, and it is why this is a separate one — PresStora needs cost,
 * because cost is what margin warnings and suggested retail are computed from.
 *
 * So: same data, plus cost, behind a signature. An unsigned request gets 401
 * and no hint about what is here.
 *
 * THE SIGNATURE
 *
 *   sig = hash_hmac('sha256', style . "\n" . ts, key)
 *
 * `ts` is a unix time and must be within five minutes, so a signature copied
 * off the wire stops working in five minutes. The key never travels — only the
 * signature does — and hash_equals() compares it, so a wrong key cannot be
 * guessed a character at a time by timing the answer.
 *
 * Set the key under BT Catalog → Bridge. Empty key = the route is off, which is
 * the default and what every other install keeps.
 */
if (!defined('ABSPATH')) exit;

/** How long a signature stays good. Long enough for a slow request, short
 *  enough that a captured URL is worthless by the time anyone tries it. */
const BT_BRIDGE_WINDOW = 300;

add_action('rest_api_init', function () {
    register_rest_route('boomerts/v1', '/bridge/style', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',   // checked inside, by signature
        'callback'            => 'bt_cat_bridge_style',
    ));
});

/** The shared secret, or '' when the bridge has not been turned on. */
function bt_cat_bridge_key() {
    return trim((string) bt_cat_opt('bridge_key', ''));
}

/**
 * Is this request signed with the key?
 *
 * Returns true, or a WP_REST_Response to send back. Every failure answers the
 * same way — 401, four words — because telling a caller WHICH part was wrong is
 * telling it how to get closer.
 */
function bt_cat_bridge_auth($req) {
    $key = bt_cat_bridge_key();
    if ($key === '') return new WP_REST_Response(array('error' => 'not enabled'), 404);

    $style = (string) $req->get_param('style');
    $ts    = (int) $req->get_param('ts');
    $sig   = (string) $req->get_param('sig');

    if ($ts <= 0 || abs(time() - $ts) > BT_BRIDGE_WINDOW) {
        return new WP_REST_Response(array('error' => 'unauthorized'), 401);
    }
    $want = hash_hmac('sha256', $style . "\n" . $ts, $key);
    if (!hash_equals($want, $sig)) {
        return new WP_REST_Response(array('error' => 'unauthorized'), 401);
    }
    return true;
}

/**
 * One style, by style NUMBER, with everything PresStora needs to sell it.
 *
 * Matched on the number with punctuation and case ignored — "PC-61", "pc61" and
 * "PC61" are one shirt, and the two catalogs do not have to agree on spelling.
 * `brand` breaks a tie when two suppliers use the same number (Richardson 112
 * is the standing example).
 */
function bt_cat_bridge_style($req) {
    $auth = bt_cat_bridge_auth($req);
    if ($auth !== true) return $auth;

    global $wpdb;
    $t     = bt_cat_table();
    $style = (string) $req->get_param('style');
    $norm  = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $style));
    if ($norm === '') return new WP_REST_Response(array('error' => 'no style'), 400);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT supplier, supplier_style_id, style_no, brand, name, category, description,
                specs, colors, sizes, cost, sale_cost, retail, retail_override, tier, updated_at
           FROM $t
          WHERE LOWER(REPLACE(REPLACE(REPLACE(style_no,'-',''),'/',''),' ','')) = %s
            AND detail_done = 1 AND active = 1
          ORDER BY id LIMIT 5", $norm), ARRAY_A);
    if (!$rows) return new WP_REST_Response(array('error' => 'not found'), 404);

    // A brand hint decides between two suppliers carrying the same number.
    $row  = $rows[0];
    $want = (string) $req->get_param('brand');
    if ($want !== '' && count($rows) > 1) {
        $wn = bt_cat_brand_norm($want);
        foreach ($rows as $r) {
            if (bt_cat_brand_norm((string) $r['brand']) === $wn) { $row = $r; break; }
        }
    }

    $cols = json_decode((string) $row['colors'], true);
    if (!is_array($cols)) $cols = array();

    // The colors go out WHOLE — name, hex, image, and the per-color cost the
    // public route strips. PresStora writes hex onto every listing, and hex is
    // what its color matching reads ("pink" finds Azalea by shade, not by name).
    $out = array();
    foreach ($cols as $c) {
        $out[] = array(
            'name' => isset($c['name']) ? (string) $c['name'] : '',
            'hex'  => isset($c['hex'])  ? (string) $c['hex']  : '',
            'img'  => isset($c['img'])  ? (string) $c['img']  : '',
            'back' => isset($c['back']) ? (string) $c['back'] : '',
            'cost' => isset($c['cost']) ? (float) $c['cost'] : 0,
        );
    }

    $specs = json_decode((string) $row['specs'], true);

    return array(
        'supplier'    => (string) $row['supplier'],
        'supplier_id' => (string) $row['supplier_style_id'],
        'style'       => (string) $row['style_no'],
        'brand'       => (string) $row['brand'],
        'name'        => (string) $row['name'],
        'category'    => (string) $row['category'],
        'description' => (string) $row['description'],
        'specs'       => is_array($specs) ? $specs : array(),
        'colors'      => $out,
        'sizes'       => array_values(array_filter(array_map('trim', explode(',', (string) $row['sizes'])))),
        // THE PART THE PUBLIC ROUTE WILL NOT SEND. Boomer T's negotiated cost is
        // a STARTING NUMBER for PresStora's platform catalog, never another
        // dealer's cost — that rule is in PresStora's DECISIONS.md and it is the
        // reason this is signed rather than open.
        'cost'        => (float) $row['cost'],
        'sale_cost'   => (float) $row['sale_cost'],
        'retail'      => (float) ($row['retail_override'] !== null && (float) $row['retail_override'] > 0
                                  ? $row['retail_override'] : $row['retail']),
        'tier'        => (string) $row['tier'],
        'updated_at'  => (string) $row['updated_at'],
    );
}
