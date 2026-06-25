<?php
/**
 * BT Catalog — database layer.
 *
 * One row per style. Filled from the S&S (later SanMar) API by the ingest step.
 * Nothing here reaches outside the BT WordPress database at render time.
 */
if (!defined('ABSPATH')) exit;

/** Fully-qualified table name (respects the site's table prefix). */
function bt_cat_table() {
    global $wpdb;
    return $wpdb->prefix . 'bt_catalog';
}

/**
 * Ordered featured entries for the default page.
 * Each line is one entry: a style number, optionally prefixed with a brand,
 * e.g. "5000", "Gildan 5000", "Bella Canvas 3001". The LAST token on a line
 * is the style number; anything before it is the brand (for disambiguation,
 * since multiple brands reuse numbers like 5000).
 * Returns array of ['style'=>..., 'brand'=>...].
 */
function bt_cat_featured() {
    $raw = (string) get_option('bt_cat_featured', '');
    if (trim($raw) === '') return array();
    $lines = preg_split('/[\r\n,]+/', $raw);
    $out = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = preg_split('/\s+/', $line);
        if (count($parts) > 1) {
            $style = array_pop($parts);
            $brand = trim(implode(' ', $parts));
        } else {
            $style = $parts[0];
            $brand = '';
        }
        $out[] = array('style' => $style, 'brand' => $brand);
    }
    return $out;
}

/** Normalize a brand for fuzzy comparison (Bella+Canvas == bellacanvas). */
function bt_cat_brand_norm($b) {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string) $b));
}

/**
 * Resolve the featured list to actual catalog rows, in order, brand-aware.
 * Returns array of DB row arrays (id, brand, style_no, name, category, colors, retail, retail_override).
 */
function bt_cat_featured_resolve() {
    global $wpdb;
    $t = bt_cat_table();
    $flist = bt_cat_featured();
    if (empty($flist)) return array();

    $ors = array(); $args = array();
    foreach ($flist as $f) {
        if ($f['brand'] !== '') {
            $ors[] = "(style_no = %s AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(brand),' ',''),'+',''),'&',''),'-','') = %s)";
            $args[] = $f['style'];
            $args[] = bt_cat_brand_norm($f['brand']);
        } else {
            $ors[] = "(style_no = %s)";
            $args[] = $f['style'];
        }
    }
    $sql  = "SELECT id, brand, style_no, name, category, colors, retail, retail_override
             FROM $t WHERE detail_done=1 AND active=1 AND (" . implode(' OR ', $ors) . ")";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

    $ordered = array(); $seen = array();
    foreach ($flist as $f) {
        $nb = $f['brand'] !== '' ? bt_cat_brand_norm($f['brand']) : '';
        foreach ($rows as $r) {
            if ((string) $r['style_no'] !== (string) $f['style']) continue;
            if ($nb !== '' && bt_cat_brand_norm($r['brand']) !== $nb) continue;
            if (isset($seen[$r['id']])) continue;
            $seen[$r['id']] = true;
            $ordered[] = $r;
            break; // one row per featured line
        }
    }
    return $ordered;
}

/**
 * Create or migrate the cache table. Safe to run repeatedly (dbDelta diffs it).
 *
 * Column notes:
 *   supplier            'ss' | 'sanmar'              (one catalog, many suppliers)
 *   supplier_style_id   the API's internal style id  (lookup key for re-pulls)
 *   style_no            manufacturer style customers search: 5000, 18500, ...
 *   specs / colors      JSON blobs (decoded by the renderer)
 *   cost / sale_cost    YOUR S&S price + sale price (internal; never sent to browser)
 *   tier                good | better | best
 */
function bt_cat_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $t       = bt_cat_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        supplier VARCHAR(20) NOT NULL DEFAULT 'ss',
        supplier_style_id VARCHAR(40) NOT NULL DEFAULT '',
        style_no VARCHAR(60) NOT NULL DEFAULT '',
        brand VARCHAR(120) NOT NULL DEFAULT '',
        name VARCHAR(255) NOT NULL DEFAULT '',
        category VARCHAR(120) NOT NULL DEFAULT '',
        description MEDIUMTEXT NULL,
        specs MEDIUMTEXT NULL,
        colors MEDIUMTEXT NULL,
        sizes VARCHAR(255) NOT NULL DEFAULT '',
        cost DECIMAL(10,2) NOT NULL DEFAULT 0,
        sale_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
        retail DECIMAL(10,2) NOT NULL DEFAULT 0,
        retail_override DECIMAL(10,2) NULL DEFAULT NULL,
        detail_done TINYINT(1) NOT NULL DEFAULT 0,
        tier VARCHAR(20) NOT NULL DEFAULT '',
        active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY supplier_style (supplier, supplier_style_id),
        KEY style_no (style_no),
        KEY brand (brand),
        KEY category (category),
        KEY active (active),
        KEY detail_done (detail_done)
    ) $charset;";

    dbDelta($sql);
}

/**
 * Upsert one style row keyed by (supplier, supplier_style_id).
 * Used by the ingest step. Returns rows affected.
 */
function bt_cat_upsert($row) {
    global $wpdb;
    $t = bt_cat_table();

    $defaults = array(
        'supplier' => 'ss', 'supplier_style_id' => '', 'style_no' => '',
        'brand' => '', 'name' => '', 'category' => '', 'description' => '',
        'specs' => '', 'colors' => '', 'sizes' => '',
        'cost' => 0, 'sale_cost' => 0, 'retail' => 0, 'detail_done' => 1,
        'tier' => '', 'active' => 1,
    );
    $row = array_merge($defaults, array_intersect_key($row, $defaults));
    $row['updated_at'] = current_time('mysql');

    $cols   = implode(',', array_keys($row));
    $place  = implode(',', array_fill(0, count($row), '%s'));
    $update = implode(',', array_map(function ($c) { return "$c=VALUES($c)"; }, array_keys($row)));

    $sql = "INSERT INTO $t ($cols) VALUES ($place) ON DUPLICATE KEY UPDATE $update";
    return $wpdb->query($wpdb->prepare($sql, array_values($row)));
}
