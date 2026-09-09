<?php
/**
 * Color name -> representative hex.
 *
 * S&S sends a real hex per color (color1) and a swatch image, so its chips fill
 * in on their own. SanMar's PromoStandards product feed carries neither — it
 * returns color NAMES only — so every SanMar style rendered its color squares
 * as the '#dddddd' fallback in catalog.js. EG-PRO has the same gap and solved
 * it with a small hand-written map (bt_cat_egpro_hex), but SanMar's palette is
 * far too large for a fixed list: one Bella style alone ships ~90 colors, and
 * the names arrive abbreviated and often unspaced ("HtPrmDstyBl", "HthrMagenta",
 * "DkGyHthr", "SdNvyBlend").
 *
 * So this resolves a name instead of looking it up: split it into words (camel
 * case included), expand SanMar's abbreviations, find the longest phrase that
 * matches a base palette entry, then apply whatever modifier words are left
 * over (heather, dark, light, dusty, neon, sueded) as color math on the base.
 *
 * Returns a 6-digit hex WITHOUT '#', matching the S&S convention already stored
 * in the colors JSON. Returns '' when nothing matches, which leaves the existing
 * grey-chip fallback in place rather than inventing a color.
 */

if (!defined('ABSPATH')) exit;

/** Base palette: color phrase => hex (no '#'). Longest phrase wins at match time. */
function bt_cat_color_palette() {
    static $p = null;
    if ($p !== null) return $p;
    $p = array(
        /* neutrals */
        'black' => '101012', 'white' => 'ffffff', 'natural' => 'e8ddc8', 'ivory' => 'f2ead8',
        'cream' => 'f3ead6', 'soft cream' => 'f0e6d2', 'french vanilla' => 'efe3c4',
        'oatmeal' => 'ddd3bf', 'sand' => 'd9c9ac', 'sand dune' => 'cbb897', 'stone' => 'cfc6b6',
        'tan' => 'c8a97e', 'khaki' => 'bda97c', 'bone' => 'e4dccd',
        'grey' => '9b9ea3', 'silver' => 'c4c7cb', 'ash' => 'c9ccd0', 'asphalt' => '55585c',
        'charcoal' => '444649', 'cement' => 'a8a49d', 'slate' => '6b7280', 'storm' => '6e747c',
        'steel' => '71797e', 'graphite' => '4a4d50', 'smoke' => '8b8e93', 'pewter' => '85888c',
        'oxford' => '5b5e62', 'gravel' => '8d8880', 'pink gravel' => 'b9a9a4', 'dust' => 'b9b2a6',
        /* blue */
        'navy' => '1b2340', 'midnight' => '161c33', 'midnight navy' => '151b30', 'blue' => '2f5fa8',
        'royal' => '1e4fa3', 'true royal' => '1c4bb0', 'carolina' => '7ba7d7', 'carolina blue' => '7ba7d7',
        'columbia' => '8fb8de', 'columbia blue' => '8fb8de', 'baby blue' => 'a6cbe8',
        'light blue' => '8fc1e3', 'ice blue' => 'bcd7e6', 'blue storm' => '6b7a8a', 'sky' => '87bde0',
        'aqua' => '56b7c4', 'teal' => '1f7a80', 'deep teal' => '145c63', 'turquoise' => '34b3ae',
        'lapis' => '26619c', 'denim' => '3b5f86', 'indigo' => '32407b', 'dusty blue' => '7d97ae',
        'sapphire' => '15507f', 'glacier' => 'cfe0e6', 'periwinkle' => '9fa8da',
        'blue lagoon' => '3d8fa3', 'cobalt' => '1c4fd8', 'ocean' => '2c6e91',
        /* green */
        'green' => '2e7d4f', 'kelly' => '2f9e44', 'kelly green' => '2f9e44', 'forest' => '1d3b2a',
        'forest green' => '1d3b2a', 'grass green' => '4a9c3d', 'sea green' => '3f8f74',
        'mint' => 'a8dcc0', 'sage' => '9caf88', 'olive' => '6b6b3a', 'military' => '5a5f43',
        'military green' => '5a5f43', 'army' => '52573f', 'emerald' => '12805c', 'lime' => '8fd400',
        'hunter' => '2b4b3b', 'jade' => '1f8f6f',
        /* red / pink / purple */
        'red' => 'c8202f', 'true red' => 'c62234', 'canvas red' => 'c1272d', 'cardinal' => '8c1d2c',
        'maroon' => '6b1f2a', 'burgundy' => '6a1b2c', 'brick' => '9c3b2e', 'rust' => 'a4501f',
        'pink' => 'f4a7c0', 'hot pink' => 'e7508a', 'magenta' => 'c2185b', 'raspberry' => 'a5254b',
        'fuchsia' => 'd6338f', 'bubble gum' => 'f6a9c8', 'charity pink' => 'f2a0bd', 'blush' => 'f0c3c3',
        'coral' => 'f4795b', 'salmon' => 'ef8d75', 'peach' => 'f6c1a0', 'apricot' => 'f2b183',
        'purple' => '5b2a86', 'team purple' => '4b2a83', 'lilac' => 'c3a8d8', 'lavender' => 'b39ddb',
        'orchid' => 'a45fa5', 'plum' => '6b3f6b', 'mauve' => '9a7b8a', 'violet' => '6a3fa0',
        'eggplant' => '4a2545',
        /* orange / yellow / brown */
        'orange' => 'e2621b', 'burnt orange' => 'b1500f', 'texas orange' => 'bf5700',
        'autumn' => 'b4611f', 'sunset' => 'e88b4a', 'clay' => 'b06a4f', 'terracotta' => 'b0623f',
        'yellow' => 'f2cd39', 'yellow gold' => 'e0b13a', 'gold' => 'd4af37', 'vegas gold' => 'b6a76c',
        'mustard' => 'c9a227', 'maize' => 'f4d35e', 'butter' => 'f3e2a0',
        'brown' => '6b4a34', 'chocolate' => '4a3227', 'coffee' => '54463b', 'espresso' => '3d2f27',
    );
    return $p;
}

/** SanMar's abbreviations. A value may expand to two words ("bblgum" => "bubble gum"). */
function bt_cat_color_abbr() {
    static $a = null;
    if ($a !== null) return $a;
    $a = array(
        'ht' => 'heather', 'hth' => 'heather', 'hthr' => 'heather', 'htr' => 'heather',
        'hther' => 'heather', 'hr' => 'heather',
        'blk' => 'black', 'bk' => 'black', 'wh' => 'white', 'wht' => 'white',
        'gy' => 'grey', 'gry' => 'grey', 'gray' => 'grey',
        'ny' => 'navy', 'nvy' => 'navy', 'nav' => 'navy',
        'bl' => 'blue', 'blu' => 'blue', 'ble' => 'blue',
        'ry' => 'royal', 'ryl' => 'royal', 'roy' => 'royal',
        'rd' => 'red', 'gn' => 'green', 'grn' => 'green',
        'org' => 'orange', 'orng' => 'orange', 'ylw' => 'yellow', 'yel' => 'yellow', 'ylo' => 'yellow',
        'pnk' => 'pink', 'ppl' => 'purple', 'prpl' => 'purple', 'purp' => 'purple',
        'mar' => 'maroon', 'mrn' => 'maroon', 'crdnl' => 'cardinal', 'brgndy' => 'burgundy',
        'chrcl' => 'charcoal', 'chrc' => 'charcoal', 'chcl' => 'charcoal',
        'snd' => 'sand', 'asph' => 'asphalt', 'oatml' => 'oatmeal', 'slvr' => 'silver', 'stl' => 'steel',
        'emrld' => 'emerald', 'lvndr' => 'lavender', 'lav' => 'lavender', 'lvn' => 'lavender',
        'chrty' => 'charity', 'buble' => 'bubble', 'bubl' => 'bubble', 'bblgum' => 'bubble gum',
        'columb' => 'columbia', 'clmb' => 'columbia', 'carol' => 'carolina',
        'mdnght' => 'midnight', 'mdnt' => 'midnight', 'nat' => 'natural', 'natrl' => 'natural',
        'vanl' => 'vanilla', 'vanla' => 'vanilla', 'trq' => 'turquoise', 'mgnta' => 'magenta',
        'dk' => 'dark', 'lt' => 'light', 'dp' => 'deep', 'dsty' => 'dusty', 'vint' => 'vintage',
        'prm' => 'premium', 'sol' => 'solid', 'sld' => 'solid', 'blnd' => 'blend', 'bld' => 'blend',
        'sd' => 'sueded', 'athl' => 'athletic', 'ath' => 'athletic',
        'mil' => 'military', 'mlt' => 'military', 'hgh' => 'high',
    );
    return $a;
}

/**
 * Broad family words. In "<specific> <family>" names — Mint Green, Steel Grey,
 * Cardinal Red — the specific word carries the color and the family word is
 * just the bucket, so a non-generic single-word match outranks a generic one.
 */
function bt_cat_color_generic() {
    return array('black', 'white', 'grey', 'blue', 'green', 'red', 'pink',
                 'purple', 'orange', 'yellow', 'brown', 'gold');
}

/** Words that qualify a color but never name one. */
function bt_cat_color_noise() {
    return array('premium', 'solid', 'blend', 'high', 'cvc', 'tri', 'triblend', 'poly', 'cotton');
}

/** "HtPrmDstyBl" / "Ht Prm Dsty Bl" -> ['heather','premium','dusty','blue'] */
function bt_cat_color_words($name) {
    $s = (string) $name;
    $s = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $s);        // HthrMagenta -> Hthr Magenta
    $s = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $s);     // DKGYHthr    -> DKGY Hthr
    $s = preg_replace('/[^A-Za-z0-9]+/', ' ', $s);                // slashes, dashes, dots
    $toks = preg_split('/\s+/', strtolower(trim($s)), -1, PREG_SPLIT_NO_EMPTY);
    if (!$toks) return array();

    $abbr = bt_cat_color_abbr();
    $out  = array();
    foreach ($toks as $t) {
        $t = isset($abbr[$t]) ? $abbr[$t] : $t;
        foreach (explode(' ', $t) as $w) { if ($w !== '') $out[] = $w; }
    }
    return $out;
}

function bt_cat_hex_to_rgb_arr($hex) {
    $h = ltrim((string) $hex, '#');
    if (strlen($h) !== 6) return array(0, 0, 0);
    return array(hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)));
}

function bt_cat_rgb_to_hex_arr($c) {
    $out = '';
    foreach ($c as $v) {
        $v = (int) round($v);
        if ($v < 0) $v = 0;
        if ($v > 255) $v = 255;
        $out .= str_pad(dechex($v), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

/** Linear blend of two rgb triples; $w is how far to move from $a toward $b. */
function bt_cat_rgb_mix($a, $b, $w) {
    return array(
        $a[0] + ($b[0] - $a[0]) * $w,
        $a[1] + ($b[1] - $a[1]) * $w,
        $a[2] + ($b[2] - $a[2]) * $w,
    );
}

/**
 * Resolve a supplier color name to a representative hex (no '#'), or '' if the
 * name carries no recognizable color.
 */
function bt_cat_color_hex($name) {
    $toks = bt_cat_color_words($name);
    if (!$toks) return '';

    $palette = bt_cat_color_palette();
    $n = count($toks);

    // Longest phrase wins; at a given width, the last match wins ("yellow gold"
    // beats "gold", "Ht Prm Dsty Bl" resolves as "dusty blue" not "blue").
    $base = null; $from = -1; $to = -1;
    for ($win = 3; $win >= 1 && $base === null; $win--) {
        $hits = array();
        for ($i = 0; $i + $win <= $n; $i++) {
            $phrase = implode(' ', array_slice($toks, $i, $win));
            if (isset($palette[$phrase])) $hits[] = array($i, $phrase);
        }
        if (!$hits) continue;
        $pick    = $hits[count($hits) - 1];                       // default: last match
        $generic = bt_cat_color_generic();
        if ($win === 1) {
            foreach ($hits as $h) {                               // but a specific word beats a family word
                if (!in_array($h[1], $generic, true)) { $pick = $h; break; }
            }
        }
        $base = $palette[$pick[1]];
        $from = $pick[0];
        $to   = $pick[0] + $win;
    }

    $noise = bt_cat_color_noise();
    $mods  = array();
    foreach ($toks as $i => $t) {
        if ($from >= 0 && $i >= $from && $i < $to) continue;      // part of the base phrase
        if (in_array($t, $noise, true)) continue;
        $mods[] = $t;
    }

    $heather = in_array('heather', $mods, true);
    if ($base === null) {
        // "Athletic Ht", "Deep Ht", "DpHeather" — heather with no named base is grey.
        if (!$heather) return '';
        $base = $palette['grey'];
    }

    $c = bt_cat_hex_to_rgb_arr($base);
    if (in_array('light', $mods, true) || in_array('pale', $mods, true)) {
        $c = bt_cat_rgb_mix($c, array(255, 255, 255), 0.32);
    }
    if (in_array('dark', $mods, true) || in_array('deep', $mods, true)) {
        $c = bt_cat_rgb_mix($c, array(0, 0, 0), 0.26);
    }
    if (in_array('dusty', $mods, true) || in_array('sueded', $mods, true) || in_array('vintage', $mods, true)) {
        $c = bt_cat_rgb_mix($c, array(190, 186, 178), 0.22);
    }
    if (in_array('neon', $mods, true)) {
        $m = ($c[0] + $c[1] + $c[2]) / 3;
        foreach ($c as $k => $v) $c[$k] = $v + ($v - $m) * 0.55 + 18;
    }
    if ($heather) {
        $c = bt_cat_rgb_mix(bt_cat_rgb_mix($c, array(154, 158, 163), 0.30), array(255, 255, 255), 0.06);
    }
    return bt_cat_rgb_to_hex_arr($c);
}

/**
 * Fill empty hex values on already-imported rows so existing products get their
 * chips without a re-import. Only touches colors whose hex AND swatch are both
 * empty, so a real supplier hex or swatch image is never overwritten.
 *
 * Runs in BATCHES. A full SanMar catalog is ~3,000 rows and a single row's
 * colors JSON can hold 90 colors, so decoding every row in one request and
 * firing one UPDATE each is enough work to hit max_execution_time or
 * memory_limit. Instead each admin request handles a bounded slice, remembers
 * where it stopped, and picks up on the next page load.
 *
 * Returns ['done' => bool, 'updated' => int, 'scanned' => int].
 */
function bt_cat_colorhex_backfill($supplier = 'sanmar', $limit = 150) {
    global $wpdb;
    $t = bt_cat_table();
    if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
        return array('done' => true, 'updated' => 0, 'scanned' => 0);
    }

    $cursor = (int) get_option('bt_cat_colorhex_cursor', 0);

    // Only rows that still carry an empty hex are candidates. The cursor is
    // what guarantees progress: a row whose colour names don't resolve keeps
    // matching this LIKE forever, and without the cursor we'd re-scan it every
    // request and never finish.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, colors FROM $t
          WHERE supplier = %s AND id > %d AND colors LIKE %s
       ORDER BY id ASC LIMIT %d",
        $supplier, $cursor, '%"hex":""%', $limit
    ), ARRAY_A);

    if (!is_array($rows) || !$rows) {
        return array('done' => true, 'updated' => 0, 'scanned' => 0);
    }

    $updated = 0; $last = $cursor;
    foreach ($rows as $r) {
        $last = (int) $r['id'];
        $cols = json_decode((string) $r['colors'], true);
        if (!is_array($cols) || !$cols) continue;

        $changed = false;
        foreach ($cols as $i => $c) {
            if (!is_array($c)) continue;
            $hasHex = isset($c['hex']) && $c['hex'] !== '';
            $hasSw  = isset($c['swatch']) && $c['swatch'] !== '';
            if ($hasHex || $hasSw) continue;
            $hex = bt_cat_color_hex(isset($c['name']) ? $c['name'] : '');
            if ($hex === '') continue;
            $cols[$i]['hex'] = $hex;
            $changed = true;
        }
        if (!$changed) continue;

        $wpdb->update($t, array('colors' => wp_json_encode($cols)), array('id' => $last));
        $updated++;
    }

    update_option('bt_cat_colorhex_cursor', $last, false);

    // A short page means we reached the end of the table.
    return array('done' => count($rows) < $limit, 'updated' => $updated, 'scanned' => count($rows));
}

/**
 * Advance the backfill one batch per admin request until it finishes, then
 * stamp the version so it never runs again for this release.
 */
function bt_cat_colorhex_ensure() {
    if (get_option('bt_cat_colorhex_stamp') === BT_CAT_VERSION) return;
    $r = bt_cat_colorhex_backfill('sanmar');
    if (!empty($r['done'])) {
        update_option('bt_cat_colorhex_stamp', BT_CAT_VERSION);
        delete_option('bt_cat_colorhex_cursor');
    }
}
add_action('admin_init', 'bt_cat_colorhex_ensure', 21);
