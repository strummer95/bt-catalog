<?php
/**
 * A garment lands in the category a shopper would look for it in.
 *
 *     php tests/bucket.test.php
 *
 * No WordPress, no database. The derivation functions are lifted out of
 * attrs.php and rest.php and run against real supplier rows.
 *
 * The case this exists for: S&S files Gildan 18810, "Unisex Heavy Blend
 * Quarter-Zip Sweatshirt", under category "Fleece - Core - Crew". The bucket
 * was read from the category alone, "fleece" matched, and it landed in
 * Hoodies & Fleece - so the Quarter-Zips & Layering filter returned "No
 * styles match" for a quarter-zip whose own closure column said quarterzip.
 */

$root = dirname(__DIR__) . '/bt-catalog/includes';
$src  = '';
foreach (array('attrs.php', 'rest.php') as $f) {
    $t = file_get_contents("$root/$f");
    if ($t === false) { fwrite(STDERR, "cannot read $f\n"); exit(1); }
    $src .= "\n" . $t;
}

$need = array(
    'bt_cat_attr_hay', 'bt_cat_attr_has', 'bt_cat_attr_word',
    'bt_cat_attr_bucket', 'bt_cat_attr_necked', 'bt_cat_attr_sleeved', 'bt_cat_attr_closable',
    'bt_cat_derive_neck', 'bt_cat_derive_closure', 'bt_cat_derive_sleeve',
    'bt_cat_cat_buckets', 'bt_cat_norm_category',
);
foreach ($need as $fn) {
    if (!preg_match('/\nfunction ' . $fn . '\(.*?\n\}\n/s', $src, $m)) {
        fwrite(STDERR, "could not lift $fn\n"); exit(1);
    }
    eval($m[0]);
}
// Lifted when present. Defined here when it is not, so this file still runs
// against a build that predates it and reports which behaviour is missing,
// instead of dying on a lift and looking like a broken test.
if (preg_match('/\nfunction bt_cat_attr_qzip_needles\(.*?\n\}\n/s', $src, $m)) {
    eval($m[0]);
} else {
    function bt_cat_attr_qzip_needles() {
        return array('1/4 zip', '1/4-zip', 'quarter zip', 'quarter-zip',
                     '1/2 zip', '1/2-zip', 'half zip', 'half-zip');
    }
}

$fails = 0;
function chk($label, $got, $want) {
    global $fails;
    $ok = $got === $want;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok ? '' : "   got '$got', wanted '$want'") . "\n";
    if (!$ok) $fails++;
}
function row($name, $cat) { return array('name' => $name, 'category' => $cat); }

/* ---- the reported bug -------------------------------------------------- */
$g18810 = row('Unisex Heavy Blend™ Quarter-Zip Sweatshirt', 'Fleece - Core - Crew');
chk('Gildan 18810 buckets as a quarter-zip', bt_cat_attr_bucket($g18810), 'Quarter-Zips & Layering');
$b = bt_cat_attr_bucket($g18810);
chk('  ...and still reads as a quarter-zip closure', bt_cat_derive_closure($g18810, $b), 'quarterzip');
chk('  ...and keeps the collar S&S gave it', bt_cat_derive_neck($g18810, $b), 'crew');
chk('  ...and is still long sleeved', bt_cat_derive_sleeve($g18810, $b, 'quarterzip'), 'long');

/* ---- the same trap in other wordings ----------------------------------- */
chk('a 1/4 zip in the title beats a fleece category',
    bt_cat_attr_bucket(row('Sport-Wick 1/4 Zip Pullover', 'Fleece - Performance')), 'Quarter-Zips & Layering');
chk('a half-zip in the title is a quarter-zip bucket',
    bt_cat_attr_bucket(row('Ladies Half-Zip Pullover', 'Sweatshirts')), 'Quarter-Zips & Layering');
chk('a 1/2 zip written with digits',
    bt_cat_attr_bucket(row('Mens 1/2 Zip Cadet Collar', 'Outerwear')), 'Quarter-Zips & Layering');
chk('a half-zip stated only in the CATEGORY',
    bt_cat_attr_bucket(row('Stratus Pullover', 'Outerwear - Half Zip')), 'Quarter-Zips & Layering');

/* ---- and nothing else moves -------------------------------------------- */
chk('a plain crewneck sweatshirt stays in fleece',
    bt_cat_attr_bucket(row('Heavy Blend Crewneck Sweatshirt', 'Fleece - Core - Crew')), 'Hoodies & Fleece');
chk('a full-zip hoodie stays in fleece',
    bt_cat_attr_bucket(row('Full-Zip Hooded Sweatshirt', 'Fleece - Core - Full Zip')), 'Hoodies & Fleece');
chk('a pullover hoodie stays in fleece',
    bt_cat_attr_bucket(row('Heavy Blend Hooded Sweatshirt', 'Fleece - Core - Hooded')), 'Hoodies & Fleece');
chk('a tee stays a tee',
    bt_cat_attr_bucket(row('Ultra Cotton T-Shirt', 'T-Shirts - Core')), 'T-Shirts');
chk('a polo stays a polo',
    bt_cat_attr_bucket(row('DryBlend Jersey Polo', 'Polos - Core')), 'Polos');
chk('a jacket stays outerwear',
    bt_cat_attr_bucket(row('Packable Puffy Jacket', 'Outerwear - Core')), 'Outerwear');
chk('a cap stays headwear',
    bt_cat_attr_bucket(row('Trucker Cap', 'Headwear - Caps')), 'Headwear');

/* ---- a crew sock is not a crew neck ------------------------------------ */
$sock = row('Cotton Crew Sock', 'Accessories - Socks');
$sb = bt_cat_attr_bucket($sock);
chk('a crew sock buckets as an accessory', $sb, 'Accessories');
chk('  ...and has no neckline at all', bt_cat_derive_neck($sock, $sb), '');

/* ---- an unstated quarter-zip still defaults to a mock neck ------------- */
$mock = row('Eddie Bauer 1/4 Zip Fleece', 'Fleece');
chk('a quarter-zip with no collar stated is mock',
    bt_cat_derive_neck($mock, bt_cat_attr_bucket($mock)), 'mock');

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
