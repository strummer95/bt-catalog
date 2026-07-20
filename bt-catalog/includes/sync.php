<?php
/**
 * BT Catalog — full-catalog sync engine.
 *
 * One-time pull of every S&S style, run in rate-limited batches so it never
 * times out and stays under the 60-calls/minute API cap. Self-running:
 *   - WP-Cron fires a batch each minute while a sync is active
 *   - the admin progress page also nudges batches via admin-ajax while open
 *   - when the queue is empty it unschedules itself and stops (no recurring sync)
 */
if (!defined('ABSPATH')) exit;

define('BT_CAT_BATCH', 45);              // styles per batch (under 60/min)
define('BT_CAT_CRON_HOOK', 'bt_cat_sync_tick');

/** Auto retail = cost x 2, rounded UP to the nearest .95 (e.g. 3.11 -> 6.95). */
function bt_cat_autoprice($cost) {
    $cost = (float) $cost;
    if ($cost <= 0) return 0.0;
    $x    = $cost * 2;
    $base = floor($x);
    $v    = $base + 0.95;
    if ($v < $x - 0.0001) $v = $base + 1 + 0.95;
    return round($v, 2);
}

/** Effective retail a customer sees: manual override, else auto retail. */
function bt_cat_price_row($row) {
    if (isset($row['retail_override']) && $row['retail_override'] !== null && (float) $row['retail_override'] > 0) {
        return (float) $row['retail_override'];
    }
    return (float) ($row['retail'] ?? 0);
}

/**
 * Customer price pair — sale-aware.
 * When the supplier has an active sale (sale_cost > 0 and below regular cost),
 * the sale retail is the same auto formula applied to the sale cost. A manual
 * override always wins and suppresses the sale display entirely.
 * Returns array('price' => what the customer pays, 'was' => regular retail when
 * on sale, else null).
 */
function bt_cat_price_pair($row) {
    $regular = bt_cat_price_row($row);
    if (isset($row['retail_override']) && $row['retail_override'] !== null && (float) $row['retail_override'] > 0) {
        return array('price' => $regular, 'was' => null);
    }
    $sc = isset($row['sale_cost']) ? (float) $row['sale_cost'] : 0.0;
    $c  = isset($row['cost']) ? (float) $row['cost'] : 0.0;
    if ($sc > 0 && ($c <= 0 || $sc < $c)) {
        $sale = bt_cat_autoprice($sc);
        if ($sale > 0 && $sale < $regular) return array('price' => $sale, 'was' => $regular);
    }
    return array('price' => $regular, 'was' => null);
}

/* ---- 1-minute cron schedule -------------------------------------------- */
add_filter('cron_schedules', function ($s) {
    $s['bt_cat_minute'] = array('interval' => 60, 'display' => 'Every minute (BT Catalog)');
    return $s;
});

/* ---- progress counters ------------------------------------------------- */
function bt_cat_sync_progress() {
    global $wpdb;
    $t = bt_cat_table();
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
    $done  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE detail_done<>0");
    $err   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE detail_done=2");
    $active = (bool) wp_next_scheduled(BT_CAT_CRON_HOOK);
    return array(
        'total'   => $total,
        'done'    => $done,
        'errors'  => $err,
        'pending' => max(0, $total - $done),
        'pct'     => $total > 0 ? round($done / $total * 100) : 0,
        'active'  => $active,
    );
}

/* ---- discovery: list every style and seed the table -------------------- */
function bt_cat_sync_discover() {
    global $wpdb;
    $t = bt_cat_table();

    // One big call returns all styles (meta only; no colors/pricing yet).
    $r = bt_cat_ss_get('styles/?pageSize=10000', 60);
    if (empty($r['ok'])) return $r;
    $rows = $r['data'];
    if (empty($rows)) return array('error' => 'No styles returned.');

    $seeded = 0;
    foreach ($rows as $s) {
        $styleID = (string) ($s['styleID'] ?? '');
        if ($styleID === '') continue;

        // Insert new styles as "pending"; refresh meta on existing without
        // touching detail_done / pricing / overrides already gathered.
        $sql = "INSERT INTO $t
                (supplier, supplier_style_id, style_no, brand, name, category, description, detail_done, active, updated_at)
                VALUES ('ss', %s, %s, %s, %s, %s, %s, 0, 1, %s)
                ON DUPLICATE KEY UPDATE
                style_no=VALUES(style_no), brand=VALUES(brand), name=VALUES(name),
                category=VALUES(category), description=VALUES(description)";
        $wpdb->query($wpdb->prepare($sql, array(
            $styleID,
            (string) ($s['styleName']    ?? ''),
            (string) ($s['brandName']    ?? ''),
            (string) ($s['title']        ?? ''),
            (string) ($s['baseCategory'] ?? ''),
            (string) ($s['description']  ?? ''),
            current_time('mysql'),
        )));
        $seeded++;
    }
    return array('ok' => true, 'seeded' => $seeded);
}

/* ---- one batch: fill colors/pricing for pending styles ----------------- */
function bt_cat_sync_batch($n = BT_CAT_BATCH) {
    global $wpdb;
    $t = bt_cat_table();

    // Throttle: at most one real batch per ~55s, no matter how often this is
    // called (cron + page nudges share this lock), keeping under 60 calls/min.
    if (get_transient('bt_cat_lock')) {
        return bt_cat_sync_progress();   // just report, do no work
    }
    set_transient('bt_cat_lock', 1, 55);

    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT id, supplier_style_id FROM $t WHERE detail_done=0 ORDER BY id ASC LIMIT %d", $n),
        ARRAY_A
    );
    $processed = 0;

    foreach ($rows as $row) {
        $styleID = $row['supplier_style_id'];
        $red = bt_cat_ss_reduce($styleID);

        if (empty($red['ok'])) {
            // Rate limited -> stop now, leave the rest pending for next minute.
            if (!empty($red['rate'])) break;
            // Genuine failure (not found, etc.) -> mark so we don't retry forever.
            $wpdb->update($t, array('detail_done' => 2, 'updated_at' => current_time('mysql')),
                          array('id' => $row['id']));
            continue;
        }

        $specs = array();
        if ($red['weight'] !== null) $specs[] = array('Weight', $red['weight'] . ' oz');

        $wpdb->update($t, array(
            'specs'       => wp_json_encode($specs),
            'colors'      => wp_json_encode(array_values($red['colors'])),
            'sizes'       => implode(',', $red['sizes']),
            'cost'        => $red['cost'],
            'sale_cost'   => $red['sale'],
            'retail'      => bt_cat_autoprice($red['cost']),
            'detail_done' => 1,
            'updated_at'  => current_time('mysql'),
        ), array('id' => $row['id']));
        $processed++;
    }

    $prog = bt_cat_sync_progress();

    // Stop the recurring job once everything is detailed.
    if ($prog['pending'] === 0 && wp_next_scheduled(BT_CAT_CRON_HOOK)) {
        wp_clear_scheduled_hook(BT_CAT_CRON_HOOK);
        $prog['active'] = false;
    }
    $prog['processed'] = $processed;
    return $prog;
}

/* ---- start / stop ------------------------------------------------------ */
function bt_cat_sync_start() {
    $d = bt_cat_sync_discover();
    if (empty($d['ok'])) return $d;
    if (!wp_next_scheduled(BT_CAT_CRON_HOOK)) {
        wp_schedule_event(time() + 5, 'bt_cat_minute', BT_CAT_CRON_HOOK);
    }
    // Run one batch immediately so progress starts moving right away.
    $prog = bt_cat_sync_batch();
    $prog['seeded'] = $d['seeded'];
    return array('ok' => true) + $prog;
}

function bt_cat_sync_stop() {
    wp_clear_scheduled_hook(BT_CAT_CRON_HOOK);
}

/* ---- cron + ajax hooks ------------------------------------------------- */
add_action(BT_CAT_CRON_HOOK, function () { bt_cat_sync_batch(); });

// Admin-page nudge: runs a batch and returns live progress JSON.
add_action('wp_ajax_bt_cat_tick', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('bt_cat_tick');
    wp_send_json_success(bt_cat_sync_batch());
});

/* ---- nightly price/data refresh (S&S) -----------------------------------
   S&S sale prices rotate constantly, and the catalog is a snapshot from the
   last pull — so sale_cost goes stale the moment a promo starts or ends.
   This re-pulls cost / sale_cost / colors / sizes for every imported S&S
   style through the same rate-limited batching (shared bt_cat_lock keeps
   sync + refresh jointly under the 60-calls/min API cap). Rows stay live
   the whole time (detail_done is never touched), and manual price
   overrides are never modified. Runs nightly at ~3am Central; can also be
   started on demand from the S&S admin page. */
define('BT_CAT_REFRESH_HOOK', 'bt_cat_refresh_tick');
define('BT_CAT_REFRESH_DAILY_HOOK', 'bt_cat_refresh_daily');

function bt_cat_refresh_pending() {
    $q = get_option('bt_cat_refresh_ids', array());
    return is_array($q) ? count($q) : 0;
}

/** Queue every imported S&S style and start the minute ticker. */
function bt_cat_refresh_start($runBatch = true) {
    global $wpdb;
    $t = bt_cat_table();
    // Don't fight an active full sync for the API budget.
    if (wp_next_scheduled(BT_CAT_CRON_HOOK)) {
        return array('error' => 'A full sync is running — the refresh can start once it finishes.');
    }
    $ids = $wpdb->get_col("SELECT id FROM $t WHERE supplier='ss' AND detail_done=1 AND active=1 ORDER BY id ASC");
    update_option('bt_cat_refresh_ids', array_map('intval', (array) $ids), false);
    if (!wp_next_scheduled(BT_CAT_REFRESH_HOOK)) {
        wp_schedule_event(time() + 5, 'bt_cat_minute', BT_CAT_REFRESH_HOOK);
    }
    // $runBatch=false when queued from a version bump on a live page load —
    // never make a visitor wait on 45 supplier API calls.
    $b = $runBatch ? bt_cat_refresh_batch()
                   : array('processed' => 0, 'pending' => bt_cat_refresh_pending());
    return array('ok' => true, 'queued' => count($ids), 'processed' => $b['processed'], 'pending' => $b['pending']);
}

/** One refresh batch: re-pull up to $n queued styles, keep the rest for later. */
function bt_cat_refresh_batch($n = BT_CAT_BATCH) {
    global $wpdb;
    $t = bt_cat_table();

    if (get_transient('bt_cat_lock')) {
        return array('processed' => 0, 'pending' => bt_cat_refresh_pending(), 'active' => (bool) wp_next_scheduled(BT_CAT_REFRESH_HOOK));
    }
    set_transient('bt_cat_lock', 1, 55);

    $q = get_option('bt_cat_refresh_ids', array());
    if (!is_array($q)) $q = array();
    $take = array_splice($q, 0, $n);
    $processed = 0;

    foreach ($take as $i => $id) {
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, supplier_style_id FROM $t WHERE id=%d", (int) $id), ARRAY_A);
        if (!$row) continue;
        $red = bt_cat_ss_reduce($row['supplier_style_id']);
        if (empty($red['ok'])) {
            if (!empty($red['rate'])) {
                // Rate limited — put this one and the rest back, resume next minute.
                array_splice($q, 0, 0, array_slice($take, $i));
                break;
            }
            continue;   // hard failure: skip; the existing cached row stays live
        }
        $specs = array();
        if ($red['weight'] !== null) $specs[] = array('Weight', $red['weight'] . ' oz');
        $wpdb->update($t, array(
            'specs'      => wp_json_encode($specs),
            'colors'     => wp_json_encode(array_values($red['colors'])),
            'sizes'      => implode(',', $red['sizes']),
            'cost'       => $red['cost'],
            'sale_cost'  => $red['sale'],
            'retail'     => bt_cat_autoprice($red['cost']),
            'updated_at' => current_time('mysql'),
        ), array('id' => $row['id']));
        $processed++;
    }

    update_option('bt_cat_refresh_ids', array_values($q), false);

    if (empty($q) && wp_next_scheduled(BT_CAT_REFRESH_HOOK)) {
        wp_clear_scheduled_hook(BT_CAT_REFRESH_HOOK);
        update_option('bt_cat_refresh_last', current_time('mysql'), false);
        if (function_exists('bt_cat_facets_flush')) bt_cat_facets_flush();
    }
    return array('processed' => $processed, 'pending' => count($q), 'active' => (bool) wp_next_scheduled(BT_CAT_REFRESH_HOOK));
}

add_action(BT_CAT_REFRESH_HOOK, function () { bt_cat_refresh_batch(); });
add_action(BT_CAT_REFRESH_DAILY_HOOK, function () { bt_cat_refresh_start(); });

// Self-schedule the nightly kickoff (08:00 UTC = 3am Central) if it's missing.
add_action('init', function () {
    if (!wp_next_scheduled(BT_CAT_REFRESH_DAILY_HOOK)) {
        wp_schedule_event(strtotime('tomorrow 08:00 UTC'), 'daily', BT_CAT_REFRESH_DAILY_HOOK);
    }
});

// Admin-page nudge for the refresh (mirrors bt_cat_tick).
add_action('wp_ajax_bt_cat_refresh_tick', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('bt_cat_tick');
    wp_send_json_success(bt_cat_refresh_batch());
});
