<?php
/**
 * The price filter: what it accepts, and the one invariant it rests on.
 *
 *     php tests/price-filter.test.php
 *
 * No WordPress, no database. bt_cat_filter_where() is lifted out of rest.php
 * and run against stubs.
 *
 * The invariant: the effective customer price has ONE SQL definition. The
 * price SORT, the price FILTER and the MIN/MAX the slider is drawn from all
 * call it. If someone re-inlines a second copy, a style can sort into a range
 * the filter then excludes, or sit outside the handles that are supposed to
 * reach it, and nothing else would catch that.
 */

$rest = file_get_contents(__DIR__ . '/../bt-catalog/includes/rest.php');
if ($rest === false) { fwrite(STDERR, "cannot read rest.php\n"); exit(1); }

/* ---- stubs the lifted code leans on ---- */
class BTCatFakeWpdb { public function esc_like($s) { return addcslashes($s, '_%\\'); } }
$GLOBALS['wpdb'] = new BTCatFakeWpdb();
if (!defined('BT_CAT_LS_TEES')) define('BT_CAT_LS_TEES', 'Long Sleeve Tees');
function bt_cat_brand_norm($b) { return strtolower(preg_replace('/[ +&-]/', '', (string) $b)); }
function bt_cat_family_terms($c) { return array($c); }
function bt_cat_cat_buckets() { return array('T-Shirts' => array('tee')); }
function bt_cat_norm_category($r) { return $r; }

foreach (array('bt_cat_price_sql', 'bt_cat_price_param', 'bt_cat_filter_where') as $fn) {
    if (!preg_match('/\nfunction ' . $fn . '\(.*?\n\}\n/s', $rest, $m)) {
        fwrite(STDERR, "could not lift $fn out of rest.php\n"); exit(1);
    }
    eval($m[0]);
}

$fails = 0;
function ok($label, $cond, $extra = '') {
    global $fails;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($extra !== '' ? "   $extra" : '') . "\n";
    if (!$cond) $fails++;
}
function blank($over = array()) {
    return array_merge(array('s'=>'','brand'=>'','category'=>'','color'=>'','aud'=>'','neck'=>'',
        'sleeve'=>'','closure'=>'','material'=>'','size'=>'','quality'=>'','pmin'=>'','pmax'=>''), $over);
}

/* ---- 1. ONE definition of the price ---- */
$copies = substr_count($rest, 'WHEN retail_override IS NOT NULL AND retail_override > 0 THEN retail_override');
ok('the effective price is defined exactly once in rest.php', $copies === 1, "found $copies");

/* ---- 2. what a price bound accepts ---- */
ok('a plain number is kept',        bt_cat_price_param('19.95') === '19.95');
ok('an integer is kept',            bt_cat_price_param('20')    === '20');
ok('cents are rounded, not dropped',bt_cat_price_param('19.956')=== '19.96');
ok('zero is a real bound',          bt_cat_price_param('0')     === '0');
ok('empty is no bound',             bt_cat_price_param('')      === '');
ok('null is no bound',              bt_cat_price_param(null)    === '');
ok('a negative is refused',         bt_cat_price_param('-5')    === '');
ok('an absurd figure is refused',   bt_cat_price_param('999999')=== '');
ok('text is refused',               bt_cat_price_param('abc')   === '');
ok('an injection attempt is refused', bt_cat_price_param("1 OR 1=1") === '');

/* ---- 3. the WHERE it builds ---- */
$w = bt_cat_filter_where(blank());
ok('no bounds -> no price condition', strpos($w['sql'], 'retail_override') === false);

$w = bt_cat_filter_where(blank(array('pmin' => '15')));
ok('a minimum emits one >= condition', substr_count($w['sql'], '>= %f') === 1);
ok('  ...and binds the number',        $w['args'] === array(15.0), json_encode($w['args']));

$w = bt_cat_filter_where(blank(array('pmax' => '40')));
ok('a maximum emits one <= condition', substr_count($w['sql'], '<= %f') === 1);

$w = bt_cat_filter_where(blank(array('pmin' => '15', 'pmax' => '40')));
ok('both bounds emit both conditions',
   substr_count($w['sql'], '>= %f') === 1 && substr_count($w['sql'], '<= %f') === 1);
ok('  ...in the order they are bound', $w['args'] === array(15.0, 40.0), json_encode($w['args']));
ok('  ...using the shared price expression', strpos($w['sql'], 'retail_override') !== false);

/* The slider's own ends must be computed with the price filter skipped, or the
   track shrinks to the handles the moment you drag it. */
$w = bt_cat_filter_where(blank(array('pmin' => '15', 'pmax' => '40')), 'price');
ok("skip='price' drops BOTH bounds",
   strpos($w['sql'], '>= %f') === false && strpos($w['sql'], '<= %f') === false);
ok("  ...and binds nothing", $w['args'] === array(), json_encode($w['args']));

/* ...while still honouring every other active filter. */
$w = bt_cat_filter_where(blank(array('pmin' => '15', 'quality' => 'best')), 'price');
ok("skip='price' keeps the other filters", strpos($w['sql'], 'tier = %s') !== false);

/* And skipping a different facet must not drop the price. */
$w = bt_cat_filter_where(blank(array('pmin' => '15', 'quality' => 'best')), 'quality');
ok("skip='quality' keeps the price bound", strpos($w['sql'], '>= %f') !== false);

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
