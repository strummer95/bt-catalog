<?php
/**
 * SanMar (PromoStandards) integration — FOUNDATION + CONNECTION TEST.
 *
 * SanMar exposes product data over PromoStandards SOAP services on port 8080.
 * Auth = your SanMar.com username (or customer #) + password. Two prerequisites
 * must be true on the LIVE server before any call works:
 *   1. The SanMar account is onboarded for Web Services access.
 *   2. boomerts.com's outbound server IP is whitelisted by SanMar, and port 8080
 *      is open outbound. (SanMar IP-whitelists callers.)
 *
 * This file only verifies the connection. The full importer (discover → brand
 * filter to SanMar-exclusive lines → product/media/pricing → store supplier='sanmar')
 * is built once the test returns real data.
 */
if (!defined('ABSPATH')) exit;

if (!defined('BT_SANMAR_WSDL_PRODUCT')) define('BT_SANMAR_WSDL_PRODUCT', 'https://ws.sanmar.com:8080/promostandards/ProductDataServiceBinding?wsdl');
if (!defined('BT_SANMAR_WSDL_MEDIA'))   define('BT_SANMAR_WSDL_MEDIA',   'https://ws.sanmar.com:8080/promostandards/MediaContentServiceBinding?wsdl');
if (!defined('BT_SANMAR_WSDL_PRICE'))   define('BT_SANMAR_WSDL_PRICE',   'https://ws.sanmar.com:8080/promostandards/PricingAndConfigurationServiceBinding?wsdl');

function bt_cat_sanmar_creds() {
    return array(
        'id'   => trim((string) get_option('bt_cat_sanmar_id', '')),
        'pw'   => (string) get_option('bt_cat_sanmar_pw', ''),
        'cust' => trim((string) get_option('bt_cat_sanmar_cust', '')),
    );
}

/**
 * Build a SOAP client for a SanMar PromoStandards WSDL. Returns array
 * ['client'=>SoapClient|null, 'error'=>string|null].
 */
function bt_cat_sanmar_client($wsdl) {
    if (!class_exists('SoapClient')) {
        return array('client' => null, 'error' => 'PHP SOAP extension (php-soap) is not enabled on this server. It must be enabled to talk to SanMar.');
    }
    try {
        $client = new SoapClient($wsdl, array(
            'trace'              => 1,
            'exceptions'         => true,
            'connection_timeout' => 25,
            'cache_wsdl'         => defined('WSDL_CACHE_NONE') ? WSDL_CACHE_NONE : 0,
            'user_agent'         => 'BTCatalog/' . (defined('BT_CAT_VERSION') ? BT_CAT_VERSION : '0'),
        ));
        return array('client' => $client, 'error' => null);
    } catch (Throwable $e) {
        return array('client' => null, 'error' => 'Could not load the SanMar WSDL (' . $wsdl . '). This usually means the server IP is not whitelisted by SanMar, port 8080 is blocked, or the account is not onboarded for Web Services. Raw: ' . $e->getMessage());
    }
}

/**
 * Connection test: pull one known SanMar-exclusive style (Port & Company PC61)
 * via PromoStandards Product Data getProduct. Returns a structured result.
 */
function bt_cat_sanmar_test($style = 'PC61') {
    $creds = bt_cat_sanmar_creds();
    if ($creds['id'] === '' || $creds['pw'] === '') {
        return array('ok' => false, 'message' => 'Enter your SanMar username/customer # and password first, then save.');
    }

    $c = bt_cat_sanmar_client(BT_SANMAR_WSDL_PRODUCT);
    if ($c['client'] === null) {
        return array('ok' => false, 'message' => $c['error']);
    }
    $client = $c['client'];

    $args = array(
        'wsVersion'            => '1.0.0',
        'id'                   => $creds['id'],
        'password'             => $creds['pw'],
        'localizationCountry'  => 'US',
        'localizationLanguage' => 'en',
        'productId'            => $style,
    );

    try {
        $resp = $client->getProduct($args);
    } catch (SoapFault $f) {
        return array(
            'ok'      => false,
            'message' => 'SanMar returned a SOAP fault: ' . $f->getMessage(),
            'detail'  => method_exists($client, '__getLastResponse') ? (string) $client->__getLastResponse() : '',
        );
    } catch (Throwable $e) {
        return array('ok' => false, 'message' => 'Request error: ' . $e->getMessage());
    }

    // Try to surface something human from the response without assuming exact shape.
    $json = json_decode(json_encode($resp), true);
    $err  = is_array($json) ? bt_cat_sanmar_find_key($json, array('description', 'Description', 'message', 'Message')) : null;
    $errCode = is_array($json) ? bt_cat_sanmar_find_key($json, array('code', 'Code')) : null;
    // If there's an ErrorMessage block, treat as failure.
    if (is_array($json) && (isset($json['ErrorMessage']) || isset($json['errorMessage']))) {
        return array(
            'ok'      => false,
            'message' => 'SanMar rejected the request' . ($errCode ? ' (code ' . $errCode . ')' : '') . ': ' . ($err ?: 'unknown error') . '. The request format needs adjusting — use Preview structure and send me the output.',
            'detail'  => method_exists($client, '__getLastRequest') ? (string) $client->__getLastRequest() : '',
        );
    }
    $name = '';
    if (is_array($json)) {
        $flat = bt_cat_sanmar_find_key($json, array('productName', 'ProductName', 'productBrand', 'ProductBrand'));
        if ($flat !== null) $name = is_scalar($flat) ? (string) $flat : '';
    }

    return array(
        'ok'      => true,
        'message' => 'Connected to SanMar. Pulled style "' . $style . '"' . ($name !== '' ? ' — ' . $name : '') . '.',
        'detail'  => method_exists($client, '__getLastResponse') ? substr((string) $client->__getLastResponse(), 0, 4000) : '',
    );
}

/** Recursively find the first value for any of the given keys. */
function bt_cat_sanmar_find_key($arr, $keys) {
    foreach ((array) $arr as $k => $v) {
        if (in_array($k, $keys, true) && is_scalar($v) && $v !== '') return $v;
        if (is_array($v)) { $r = bt_cat_sanmar_find_key($v, $keys); if ($r !== null) return $r; }
    }
    return null;
}

/**
 * Pull getProduct for one style and return its structure as readable JSON,
 * trimmed so big part arrays don't flood the screen. Used to design the parser.
 */
function bt_cat_sanmar_preview($style = 'PC61') {
    $creds = bt_cat_sanmar_creds();
    if ($creds['id'] === '' || $creds['pw'] === '') return array('ok' => false, 'message' => 'Save credentials first.');
    $c = bt_cat_sanmar_client(BT_SANMAR_WSDL_PRODUCT);
    if ($c['client'] === null) return array('ok' => false, 'message' => $c['error']);
    try {
        $resp = $c['client']->getProduct(array(
            'wsVersion' => '1.0.0', 'id' => $creds['id'], 'password' => $creds['pw'],
            'localizationCountry' => 'US', 'localizationLanguage' => 'en', 'productId' => $style,
        ));
    } catch (Throwable $e) {
        $req = method_exists($c['client'], '__getLastRequest') ? (string) $c['client']->__getLastRequest() : '';
        return array('ok' => false, 'message' => $e->getMessage(), 'json' => "REQUEST WE SENT:\n" . $req);
    }
    $req  = method_exists($c['client'], '__getLastRequest') ? (string) $c['client']->__getLastRequest() : '';
    $data = json_decode(json_encode($resp), true);
    // Trim any large numeric-indexed arrays (part lists) to first 2 entries for readability.
    $data = bt_cat_sanmar_trim($data, 2);
    $out  = "REQUEST WE SENT:\n" . $req . "\n\n----------\n\nRESPONSE (part arrays trimmed to 2):\n" . wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return array('ok' => true, 'message' => 'Diagnostic for "' . $style . '":', 'json' => $out);
}

/** Normalize a PromoStandards node that may be a single object or a list into a list. */
function bt_cat_sanmar_list($node) {
    if (!is_array($node)) return array();
    // associative (single object) vs sequential (already a list)
    if (array_keys($node) === range(0, count($node) - 1)) return $node;
    return array($node);
}

/** Generic SanMar SOAP call. Returns ['ok','data','error','request']. */
function bt_cat_sanmar_call($wsdl, $method, $args) {
    $c = bt_cat_sanmar_client($wsdl);
    if ($c['client'] === null) return array('ok' => false, 'error' => $c['error'], 'request' => '');
    try {
        $resp = $c['client']->$method($args);
    } catch (Throwable $e) {
        $req = method_exists($c['client'], '__getLastRequest') ? (string) $c['client']->__getLastRequest() : '';
        return array('ok' => false, 'error' => $e->getMessage(), 'request' => $req);
    }
    $req  = method_exists($c['client'], '__getLastRequest') ? (string) $c['client']->__getLastRequest() : '';
    $data = json_decode(json_encode($resp), true);
    if (is_array($data) && (isset($data['ErrorMessage']) || isset($data['errorMessage']))) {
        $desc = bt_cat_sanmar_find_key($data, array('description', 'message'));
        return array('ok' => false, 'error' => 'SanMar: ' . ($desc ?: 'error'), 'request' => $req, 'data' => $data);
    }
    return array('ok' => true, 'data' => $data, 'request' => $req);
}

/** getProduct (1.0.0) -> structured product. */
function bt_cat_sanmar_product($style) {
    $cr = bt_cat_sanmar_creds();
    $r = bt_cat_sanmar_call(BT_SANMAR_WSDL_PRODUCT, 'getProduct', array(
        'wsVersion' => '1.0.0', 'id' => $cr['id'], 'password' => $cr['pw'],
        'localizationCountry' => 'US', 'localizationLanguage' => 'en', 'productId' => $style,
    ));
    if (!$r['ok']) return $r;
    $p = isset($r['data']['Product']) ? $r['data']['Product'] : array();

    $desc = isset($p['description']) ? (is_array($p['description']) ? implode(' ', array_filter($p['description'], 'is_string')) : (string) $p['description']) : '';
    $cat  = bt_cat_sanmar_find_key(isset($p['ProductCategoryArray']) ? $p['ProductCategoryArray'] : array(), array('category')) ?: '';

    $colors = array();  // colorName => true (ordered)
    $sizes  = array();  // labelSize => true (ordered)
    $parts  = isset($p['ProductPartArray']['ProductPart']) ? bt_cat_sanmar_list($p['ProductPartArray']['ProductPart']) : array();
    foreach ($parts as $part) {
        $cn = bt_cat_sanmar_find_key(isset($part['ColorArray']) ? $part['ColorArray'] : array(), array('colorName'));
        $sz = isset($part['ApparelSize']['labelSize']) ? $part['ApparelSize']['labelSize'] : null;
        if ($cn) $colors[$cn] = true;
        if ($sz) $sizes[$sz] = true;
    }
    return array(
        'ok'       => true,
        'brand'    => isset($p['productBrand']) ? $p['productBrand'] : '',
        'name'     => isset($p['productName']) ? $p['productName'] : $style,
        'category' => $cat,
        'desc'     => $desc,
        'colors'   => array_keys($colors),
        'sizes'    => array_keys($sizes),
        'request'  => $r['request'],
    );
}

/** getMediaContent -> [colorName => image url]. Tries wsVersion 1.1.0. */
function bt_cat_sanmar_media($style) {
    $cr = bt_cat_sanmar_creds();
    $r = bt_cat_sanmar_call(BT_SANMAR_WSDL_MEDIA, 'getMediaContent', array(
        'wsVersion' => '1.1.0', 'id' => $cr['id'], 'password' => $cr['pw'],
        'mediaType' => 'Image', 'productId' => $style,
    ));
    if (!$r['ok']) return $r;
    $out = array();
    $items = bt_cat_sanmar_find_key($r['data'], array('MediaContentArray'));
    // Walk the data for MediaContent entries (url + color).
    $list = array();
    if (isset($r['data']['MediaContentArray']['MediaContent'])) $list = bt_cat_sanmar_list($r['data']['MediaContentArray']['MediaContent']);
    foreach ($list as $m) {
        $url   = bt_cat_sanmar_find_key($m, array('url'));
        $color = bt_cat_sanmar_find_key($m, array('color', 'colorName'));
        if ($url && $color && !isset($out[$color])) $out[$color] = $url;
    }
    return array('ok' => true, 'images' => $out, 'count' => count($list), 'request' => $r['request']);
}

/** getConfigurationAndPricing -> lowest piece (your) cost. Tries wsVersion 1.0.0, Customer pricing. */
function bt_cat_sanmar_pricing($style) {
    $cr = bt_cat_sanmar_creds();
    $r = bt_cat_sanmar_call(BT_SANMAR_WSDL_PRICE, 'getConfigurationAndPricing', array(
        'wsVersion' => '1.0.0', 'id' => $cr['id'], 'password' => $cr['pw'],
        'productId' => $style, 'currency' => 'USD', 'fobId' => '1',
        'priceType' => 'Customer', 'localizationCountry' => 'US', 'localizationLanguage' => 'en',
        'configurationType' => 'Blank',
    ));
    if (!$r['ok']) return $r;
    // Find all numeric "price" values; take the lowest as the piece/base cost.
    $prices = array();
    array_walk_recursive($r['data'], function ($v, $k) use (&$prices) {
        if (in_array($k, array('price', 'salePrice'), true) && is_numeric($v)) $prices[] = (float) $v;
    });
    $cost = $prices ? min($prices) : 0;
    return array('ok' => true, 'cost' => $cost, 'request' => $r['request']);
}

/** Assemble a full catalog row for one SanMar style (no DB write). */
function bt_cat_sanmar_assemble($style) {
    $prod  = bt_cat_sanmar_product($style);
    if (!$prod['ok']) return array('ok' => false, 'stage' => 'product', 'error' => $prod['error'] ?? 'product failed', 'request' => $prod['request'] ?? '');
    $media = bt_cat_sanmar_media($style);
    $price = bt_cat_sanmar_pricing($style);

    $images = (!empty($media['ok'])) ? $media['images'] : array();
    $colors = array();
    foreach ($prod['colors'] as $cn) {
        $colors[] = array('name' => $cn, 'hex' => '', 'img' => isset($images[$cn]) ? $images[$cn] : '', 'swatch' => '');
    }
    return array(
        'ok'       => true,
        'brand'    => $prod['brand'],
        'name'     => $prod['name'],
        'category' => $prod['category'],
        'desc'     => $prod['desc'],
        'sizes'    => $prod['sizes'],
        'colors'   => $colors,
        'cost'     => (!empty($price['ok'])) ? $price['cost'] : 0,
        'media_ok' => !empty($media['ok']),
        'media_err'=> empty($media['ok']) ? ($media['error'] ?? '') : '',
        'price_ok' => !empty($price['ok']),
        'price_err'=> empty($price['ok']) ? ($price['error'] ?? '') : '',
    );
}

/* ---------------- Admin submenu ---------------- */
add_action('admin_menu', function () {
    add_submenu_page(
        'bt-catalog',
        'SanMar',
        'SanMar',
        'manage_options',
        'bt-catalog-sanmar',
        'bt_cat_sanmar_page'
    );
});

function bt_cat_sanmar_page() {
    if (!current_user_can('manage_options')) return;

    $test = null;

    if (isset($_POST['bt_cat_save_sanmar'])) {
        check_admin_referer('bt_cat_sanmar');
        update_option('bt_cat_sanmar_id', sanitize_text_field(wp_unslash($_POST['sanmar_id'] ?? '')));
        update_option('bt_cat_sanmar_cust', sanitize_text_field(wp_unslash($_POST['sanmar_cust'] ?? '')));
        if (isset($_POST['sanmar_pw']) && $_POST['sanmar_pw'] !== '') {
            update_option('bt_cat_sanmar_pw', wp_unslash($_POST['sanmar_pw']));
        }
        echo '<div class="notice notice-success is-dismissible"><p>SanMar credentials saved.</p></div>';
    }

    if (isset($_POST['bt_cat_test_sanmar'])) {
        check_admin_referer('bt_cat_sanmar');
        $test = bt_cat_sanmar_test(sanitize_text_field(wp_unslash($_POST['sanmar_test_style'] ?? 'PC61')) ?: 'PC61');
    }

    $preview = null;
    if (isset($_POST['bt_cat_preview_sanmar'])) {
        check_admin_referer('bt_cat_sanmar');
        $preview = bt_cat_sanmar_preview(sanitize_text_field(wp_unslash($_POST['sanmar_test_style'] ?? 'PC61')) ?: 'PC61');
    }

    $full = null;
    if (isset($_POST['bt_cat_full_sanmar'])) {
        check_admin_referer('bt_cat_sanmar');
        $full = bt_cat_sanmar_assemble(sanitize_text_field(wp_unslash($_POST['sanmar_test_style'] ?? 'PC61')) ?: 'PC61');
    }

    $creds = bt_cat_sanmar_creds();
    $soap  = class_exists('SoapClient');
    ?>
    <div class="wrap">
        <h1>SanMar</h1>
        <p class="description" style="max-width:760px">Pulls SanMar styles that S&amp;S doesn't carry (their exclusive lines &mdash; Port Authority, Port &amp; Company, District, Sport-Tek, CornerStone, OGIO, etc.) into the same catalog, via SanMar's PromoStandards web service. No API key &mdash; it authenticates with your SanMar.com login.</p>

        <div class="notice notice-info inline" style="max-width:760px;margin:14px 0"><p style="margin:.6em 0"><strong>Your account is already onboarded for SanMar automation, so web-services access is set.</strong> Read-only product/pricing/media (what this catalog uses) doesn't need the "automated purchasing" activation. The one thing still worth checking:</p>
            <ul style="margin:0 0 .6em 18px;list-style:disc">
                <li>SanMar whitelists the <strong>calling server's IP</strong>. Chipply calls from Chipply's servers; this plugin calls from <strong>boomerts.com's server</strong>. If the test below fails to connect, give SanMar integrations (<code>sanmarintegrations@sanmar.com</code>) this server's outbound IP to whitelist, and make sure <strong>port 8080</strong> is open outbound.</li>
            </ul>
            <p style="margin:.4em 0">PHP SOAP extension on this server: <strong style="color:<?php echo $soap ? '#1a7f37' : '#b32d2e'; ?>"><?php echo $soap ? 'enabled ✓' : 'NOT enabled — required'; ?></strong></p>
        </div>

        <form method="post">
            <?php wp_nonce_field('bt_cat_sanmar'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="sanmar_id">SanMar username</label></th>
                    <td><input name="sanmar_id" id="sanmar_id" type="text" class="regular-text" value="<?php echo esc_attr($creds['id']); ?>" autocomplete="off" placeholder="e.g. boomerts"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="sanmar_pw">SanMar password</label></th>
                    <td>
                        <input name="sanmar_pw" id="sanmar_pw" type="password" class="regular-text" value="" placeholder="<?php echo $creds['pw'] !== '' ? '•••••••• (saved — leave blank to keep)' : ''; ?>" autocomplete="new-password">
                        <p class="description">Stored on the server only, never in the repo. Leave blank to keep the saved password.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sanmar_cust">Customer number</label></th>
                    <td>
                        <input name="sanmar_cust" id="sanmar_cust" type="text" class="regular-text" value="<?php echo esc_attr($creds['cust']); ?>" autocomplete="off" placeholder="e.g. 108175">
                        <p class="description">Used for your account-specific pricing.</p>
                    </td>
                </tr>
            </table>
            <p><button type="submit" name="bt_cat_save_sanmar" value="1" class="button button-primary">Save credentials</button></p>

            <hr style="margin:24px 0;max-width:760px">
            <h2>Test connection</h2>
            <p class="description">Pulls one known SanMar style to confirm auth + whitelisting + onboarding all work.</p>
            <p>
                <label>Style to test: <input name="sanmar_test_style" type="text" value="PC61" class="small-text"></label>
                &nbsp;<button type="submit" name="bt_cat_test_sanmar" value="1" class="button">Test connection</button>
                &nbsp;<button type="submit" name="bt_cat_preview_sanmar" value="1" class="button">Preview structure</button>
                &nbsp;<button type="submit" name="bt_cat_full_sanmar" value="1" class="button button-secondary">Preview full import</button>
            </p>
        </form>

        <?php if ($full !== null): ?>
            <div class="notice notice-<?php echo !empty($full['ok']) ? 'success' : 'error'; ?>" style="max-width:900px">
                <?php if (empty($full['ok'])): ?>
                    <p><strong>Product fetch failed at stage "<?php echo esc_html($full['stage'] ?? ''); ?>":</strong> <?php echo esc_html($full['error'] ?? ''); ?></p>
                    <?php if (!empty($full['request'])): ?><details><summary style="cursor:pointer">Request sent</summary><textarea readonly rows="6" class="large-text code" style="font-size:11px"><?php echo esc_textarea($full['request']); ?></textarea></details><?php endif; ?>
                <?php else: ?>
                    <p style="margin:.6em 0"><strong>Assembled "<?php echo esc_html($full['name']); ?>"</strong></p>
                    <table class="widefat striped" style="max-width:640px">
                        <tr><td style="width:130px"><strong>Brand</strong></td><td><?php echo esc_html($full['brand']); ?></td></tr>
                        <tr><td><strong>Category</strong></td><td><?php echo esc_html($full['category']); ?></td></tr>
                        <tr><td><strong>Sizes</strong></td><td><?php echo esc_html(implode(', ', $full['sizes'])); ?></td></tr>
                        <tr><td><strong>Colors</strong></td><td><?php echo (int) count($full['colors']); ?> (<?php echo esc_html(implode(', ', array_slice(array_map(function($c){return $c['name'];}, $full['colors']), 0, 8))); ?>…)</td></tr>
                        <tr><td><strong>Your cost</strong></td><td><?php echo $full['price_ok'] ? '$' . number_format((float) $full['cost'], 2) : '<span style="color:#b32d2e">PRICING: ' . esc_html($full['price_err']) . '</span>'; ?></td></tr>
                        <tr><td><strong>Images</strong></td><td><?php
                            if (!$full['media_ok']) { echo '<span style="color:#b32d2e">MEDIA: ' . esc_html($full['media_err']) . '</span>'; }
                            else { $withImg = array_filter($full['colors'], function($c){ return $c['img'] !== ''; }); echo (int) count($withImg) . ' of ' . (int) count($full['colors']) . ' colors have an image'; $first = reset($withImg); if ($first) echo '<br><span style="font-size:11px;color:#666;word-break:break-all">' . esc_html($first['img']) . '</span>'; }
                        ?></td></tr>
                    </table>
                    <p class="description">Product data is parsing. If pricing or images show an error above, paste it to me and I'll fix that service — then I build the bulk importer.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($preview !== null): ?>
            <div class="notice notice-<?php echo $preview['ok'] ? 'info' : 'error'; ?>" style="max-width:900px">
                <p><strong><?php echo esc_html($preview['message']); ?></strong></p>
                <?php if (!empty($preview['json'])): ?>
                    <textarea readonly rows="22" class="large-text code" style="font-size:11px"><?php echo esc_textarea($preview['json']); ?></textarea>
                    <p class="description">Copy this and send it to me — it shows the exact field names so I can build the import parser correctly.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($test !== null): ?>
            <div class="notice notice-<?php echo $test['ok'] ? 'success' : 'error'; ?>" style="max-width:760px">
                <p><strong><?php echo $test['ok'] ? 'Success' : 'Could not connect'; ?>:</strong> <?php echo esc_html($test['message']); ?></p>
                <?php if (!empty($test['detail'])): ?>
                    <details style="margin:0 0 10px"><summary style="cursor:pointer">Raw response (for debugging)</summary>
                        <textarea readonly rows="8" class="large-text code" style="font-size:11px"><?php echo esc_textarea($test['detail']); ?></textarea>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p class="description" style="max-width:760px;margin-top:16px">Once this test returns real product data, the next step is the importer: discover SanMar styles, keep only the brands S&amp;S doesn't carry, pull product details + images + your cost, and store them alongside S&amp;S (the storefront already supports a second supplier).</p>
    </div>
    <?php
}
