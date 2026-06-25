<?php
/**
 * BT Catalog — S&S Activewear ingest.
 *
 * Mirrors the proven PresStora call pattern:
 *   styles : GET /V2/styles/?styleSearch=<style>
 *   skus   : GET /V2/products/?style=<styleID>&pageSize=500
 *   auth   : HTTP Basic, username = account number, password = API key
 *   images : https://www.ssactivewear.com/ + colorFrontImage
 *   cost   : customerPrice (YOUR price)   sale: salePrice   hex: color1
 */
if (!defined('ABSPATH')) exit;

/** base64("account:key") from saved settings, or '' if missing. */
function bt_cat_ss_auth() {
    $acct = bt_cat_opt('ss_account');
    $key  = bt_cat_opt('ss_apikey');
    if ($acct === '' || $key === '') return '';
    return base64_encode($acct . ':' . $key);
}

/** GET a V2 endpoint. Returns ['ok'=>true,'data'=>...] or ['error'=>...]. */
function bt_cat_ss_get($path, $timeout = 25) {
    $auth = bt_cat_ss_auth();
    if ($auth === '') return array('error' => 'No S&S credentials saved.');

    $url  = 'https://api.ssactivewear.com/V2/' . ltrim($path, '/');
    $resp = wp_remote_get($url, array(
        'timeout' => $timeout,
        'headers' => array(
            'Authorization' => 'Basic ' . $auth,
            'Accept'        => 'application/json',
        ),
    ));

    if (is_wp_error($resp)) return array('error' => $resp->get_error_message());
    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code === 429) return array('error' => 'rate limited', 'rate' => true);
    if ($code !== 200) return array('error' => 'HTTP ' . $code, 'body' => substr($body, 0, 500));
    $json = json_decode($body, true);
    if (!is_array($json)) return array('error' => 'Unexpected response', 'body' => substr($body, 0, 500));
    return array('ok' => true, 'data' => $json);
}

/**
 * Resolve a style to its S&S record.
 * Accepts "Gildan 5000" (brand + number) or bare "18500".
 * When a brand is given, it must match — prevents Bayside 5000 vs Gildan 5000 mixups.
 */
function bt_cat_ss_style($styleNo) {
    // Split optional leading brand words from the trailing style token.
    $brand = '';
    $style = trim($styleNo);
    if (preg_match('/^(.*\D)\s+([A-Za-z0-9\-]+)$/', $style, $m)) {
        // Has words before the final token -> treat leading part as brand.
        $maybeBrand = trim($m[1]);
        $maybeStyle = trim($m[2]);
        // Only treat as brand if the final token looks like a style (has a digit).
        if (preg_match('/\d/', $maybeStyle)) { $brand = $maybeBrand; $style = $maybeStyle; }
    }

    $r = bt_cat_ss_get('styles/?styleSearch=' . urlencode($style) . '&pageSize=50');
    if (empty($r['ok'])) return $r;
    $rows = $r['data'];
    if (empty($rows)) return array('error' => 'Style not found: ' . $styleNo);

    $nstyle = strtolower($style);
    $nbrand = strtolower($brand);

    // 1) exact style + exact brand
    if ($nbrand !== '') {
        foreach ($rows as $s) {
            if (strtolower($s['styleName'] ?? '') === $nstyle
                && strtolower($s['brandName'] ?? '') === $nbrand) {
                return array('ok' => true, 'style' => $s);
            }
        }
        // 1b) exact style + brand contains (handles "Bella" vs "BELLA + CANVAS")
        foreach ($rows as $s) {
            if (strtolower($s['styleName'] ?? '') === $nstyle
                && strpos(strtolower($s['brandName'] ?? ''), $nbrand) !== false) {
                return array('ok' => true, 'style' => $s);
            }
        }
        // Brand requested but no brand match found -> report the choices.
        $opts = array();
        foreach ($rows as $s) {
            if (strtolower($s['styleName'] ?? '') === $nstyle) {
                $opts[] = ($s['brandName'] ?? '?');
            }
        }
        return array('error' => 'No "' . $brand . '" style ' . $style
            . (empty($opts) ? '' : '. Brands offering style ' . $style . ': ' . implode(', ', array_unique($opts))));
    }

    // 2) no brand given -> first exact style match, and warn if ambiguous
    $exact = array();
    foreach ($rows as $s) {
        if (strtolower($s['styleName'] ?? '') === $nstyle) $exact[] = $s;
    }
    if (!empty($exact)) {
        if (count($exact) > 1) {
            $brands = array();
            foreach ($exact as $s) $brands[] = ($s['brandName'] ?? '?');
            return array('ok' => true, 'style' => $exact[0],
                'warn' => 'Style ' . $style . ' exists for: ' . implode(', ', array_unique($brands))
                          . '. Used ' . ($exact[0]['brandName'] ?? '?') . '. Add a brand (e.g. "Gildan ' . $style . '") to pick.');
        }
        return array('ok' => true, 'style' => $exact[0]);
    }

    return array('error' => 'Style not found: ' . $styleNo);
}

/** Pull SKUs and reduce them to colors / sizes / representative pricing. */
function bt_cat_ss_reduce($styleID) {
    $p = bt_cat_ss_get('products/?style=' . urlencode($styleID) . '&pageSize=500', 12);
    if (empty($p['ok'])) return $p;  // carries 'rate' flag through on 429
    $skus = $p['data'];

    $colors = array();   // colorName => [name,hex,img]
    $sizes  = array();
    $bySize = array();   // sizeName => [cost,sale,wt]

    foreach ($skus as $k) {
        $c = $k['colorName'] ?? '';
        if ($c !== '' && !isset($colors[$c])) {
            $colors[$c] = array(
                'name' => $c,
                'hex'  => $k['color1'] ?? '',
                'img'  => !empty($k['colorFrontImage'])
                    ? 'https://www.ssactivewear.com/' . ltrim($k['colorFrontImage'], '/')
                    : '',
                'swatch' => !empty($k['colorSwatchImage'])
                    ? 'https://www.ssactivewear.com/' . ltrim($k['colorSwatchImage'], '/')
                    : '',
            );
        }
        $z = $k['sizeName'] ?? '';
        if ($z !== '') {
            if (!in_array($z, $sizes, true)) $sizes[] = $z;
            if (!isset($bySize[$z])) $bySize[$z] = array(
                'cost' => (float) ($k['customerPrice'] ?? 0),
                'sale' => (float) ($k['salePrice'] ?? 0),
                'wt'   => !empty($k['unitWeight']) ? round((float) $k['unitWeight'] * 16, 2) : null,
            );
        }
    }

    // Representative cost: prefer a common size, else first priced size.
    $cost = 0; $sale = 0; $weight = null;
    foreach (array('L','M','XL','S','2XL','3XL') as $z) {
        if (isset($bySize[$z]) && $bySize[$z]['cost'] > 0) {
            $cost = $bySize[$z]['cost']; $sale = $bySize[$z]['sale']; $weight = $bySize[$z]['wt']; break;
        }
    }
    if ($cost === 0) foreach ($bySize as $d) if ($d['cost'] > 0) { $cost=$d['cost']; $sale=$d['sale']; $weight=$d['wt']; break; }
    if ($weight === null) foreach ($bySize as $d) if ($d['wt'] !== null) { $weight=$d['wt']; break; }

    return array('ok'=>true, 'colors'=>$colors, 'sizes'=>$sizes, 'cost'=>$cost, 'sale'=>$sale, 'weight'=>$weight);
}

/** Probe one style — read-only sanity check, writes nothing. */
function bt_cat_ss_probe($styleNo) {
    $s = bt_cat_ss_style($styleNo);
    if (empty($s['ok'])) return $s;
    $style = $s['style'];
    $r = bt_cat_ss_reduce($style['styleID'] ?? '');
    if (empty($r['ok'])) return $r;

    $first = reset($r['colors']);
    return array(
        'ok'        => true,
        'warn'      => $s['warn'] ?? '',
        'style_no'  => $style['styleName']    ?? '',
        'brand'     => $style['brandName']    ?? '',
        'title'     => $style['title']        ?? '',
        'category'  => $style['baseCategory'] ?? '',
        'colors'    => count($r['colors']),
        'sizes'     => implode(', ', $r['sizes']),
        'your_cost' => $r['cost'],
        'sale_cost' => $r['sale'],
        'weight'    => $r['weight'],
        'sample_img'=> $first ? $first['img'] : '',
    );
}

/** Import one style into the cache table. */
function bt_cat_ss_import_style($styleNo) {
    $s = bt_cat_ss_style($styleNo);
    if (empty($s['ok'])) return $s;
    $style = $s['style'];
    $r = bt_cat_ss_reduce($style['styleID'] ?? '');
    if (empty($r['ok'])) return $r;

    $specs = array();
    if ($r['weight'] !== null)        $specs[] = array('Weight', $r['weight'] . ' oz');
    if (!empty($style['brandName']))  $specs[] = array('Brand', $style['brandName']);

    $row = array(
        'supplier'          => 'ss',
        'supplier_style_id' => (string) ($style['styleID'] ?? ''),
        'style_no'          => (string) ($style['styleName'] ?? $styleNo),
        'brand'             => (string) ($style['brandName'] ?? ''),
        'name'              => (string) ($style['title'] ?? ''),
        'category'          => (string) ($style['baseCategory'] ?? ''),
        'description'       => (string) ($style['description'] ?? ''),
        'specs'             => wp_json_encode($specs),
        'colors'            => wp_json_encode(array_values($r['colors'])),
        'sizes'             => implode(',', $r['sizes']),
        'cost'              => $r['cost'],
        'sale_cost'         => $r['sale'],
        'retail'            => bt_cat_autoprice($r['cost']),
        'detail_done'       => 1,
        'tier'              => '',
        'active'            => 1,
    );
    bt_cat_upsert($row);

    return array('ok'=>true, 'warn'=>$s['warn'] ?? '', 'style_no'=>$row['style_no'], 'brand'=>$row['brand'],
                 'colors'=>count($r['colors']), 'sizes'=>count($r['sizes']),
                 'cost'=>$r['cost'], 'sale'=>$r['sale']);
}
