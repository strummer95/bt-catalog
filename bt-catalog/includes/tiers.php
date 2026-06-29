<?php
/**
 * Quality tiers (Good / Better / Best).
 *
 * Source of truth: the SanMar "Navigator" Good/Better/Best guides (Tee, Polo,
 * Sweatshirt, Outerwear, Wovens/Dress, Headwear, Bags). Each style number is
 * assigned the tier of the row it sits in. Matching is by style number across
 * ALL suppliers (so an S&S Gildan 5000 and a SanMar one both inherit the tier).
 *
 * Two special rules, per spec:
 *   - EG-PRO (house brand): every style is BEST, handled in code (not the map).
 *   - Nike: intentionally excluded from the tiers entirely (not in the map).
 *
 * Tees follow the guide's page layout (it isn't a clean 3-row grid):
 *   Basics -> good, Better Basics -> better, Price Point Premium -> better,
 *   Premium -> best, Performance -> its own best/better/good rows.
 *
 * To tweak: move a style between the $good/$better/$best lists below and bump
 * BT_CAT_VERSION — existing rows are re-tagged automatically on the next admin
 * load (see the admin_init hook at the bottom), and every import re-tags too.
 */
if (!defined('ABSPATH')) exit;

/** Normalize a style number for matching: uppercase, alphanumerics only. */
function bt_cat_tier_norm($style) {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $style));
}

function bt_cat_quality_labels() { return array('Good', 'Better', 'Best'); }

function bt_cat_quality_key($q) {
    $q = strtolower(trim((string) $q));
    if ($q === 'good' || $q === 'better' || $q === 'best') return $q;
    return '';
}

/** style# (normalized) => tier. Built once per request from the lists below. */
function bt_cat_tier_map() {
    static $map = null;
    if ($map !== null) return $map;

    $good = array(
        // --- Tees: Basics ---
        'PC43','PC43LS','PC43Y','PC54','PC54LS','PC54T','LPC54','PC54Y','3000','3000B',
        '363M','363L','363LH','363Y','5000','5400','5000L','5000B','PC01',
        'PC55','PC55LS','PC55T','LPC55','PC55Y','29M','29LS','29B','8000','8300','8400','8000B',
        'DT5000','PC61','PC61LS','PC61T','LPC61','PC61Y','2000','G2400','2000T','2000L','2000B',
        // --- Tees: Performance (good row) ---
        'PC380','PC380LS','LPC380','PC380Y','PC381','PC381LS','LPC381V','PC381Y',
        '42000','42400','42000B','21M','21LS',
        // --- Sweatshirts: good rows ---
        'PC78','PC78PKT','PC90Y','18000','18000B','562M','562B','PC90','PC90T',
        'PC78H','PC78HT','LPC78H','18500','18500B','996M','996Y','PC90H',
        'PC78ZH','LPC78ZH','18600','18600B','993M','993B','PC90ZH',
        'PC78Q','995M','K807','ST253',
        'PC590','PC590Q','PC590H','PC590YH','ST241','LST241','YST241',
        // --- Polos: good rows ---
        'TM1MAA370','TM1MU410','TM1WX002','TM1MU411','TM1WW001',
        'OG170','LOG170','OG101','LOG101','OG138','LOG138',
        'K240','LK240','K572','L572','K100','TLK100','L100','Y100',
        'ST520','LST520','ST550','LST550','ST640','LST640','YST640',
        'CS4020','CS4020P','CS450','TLCS450','CS451',
        // --- Outerwear: good rows ---
        'J901','L901','J317','TLJ317','L317','Y317','J717','L717',
        'J850','L850','J852','L852','J902','L902',
        'J754','TLJ754','L354','J354','JST56','YST56',
        'F223','L223','K595','LK595','F217','L217','Y217',
        'J407','L407','J714','L714','J333','TLJ333','L333',
        'ST941','LST941','J851','L851','F219','L219',
        // --- Headwear: good rows ---
        'C865','C813','C812','C833','YC833','STC39','STC26','YSTC26',
        'STC38','DT624','STC19','C402','YC402','STC54','C911',
        'CP80','YCP80','CP86','PWU','LPWU','C855','C930','C114',
        'PWSH2','C980','C976','C983','C840','CP45','C977','CP90','CP90L',
        // --- Bags: good rows ---
        '411092','CT89241804','BG203','BG217','BG1020','BG615','BG611','BG6200',
        'BG516','BG512','BG513','BG99','BG970','BG980','B0750','B050','BG1500',
        'BG302','BG304','BG305','BG905','BG1010','BG936',
        // --- Wovens / Dress: good rows ---
        'W100','TW100','LW100','S608','TLS608','S608ES','L608','S658','TS658','L658',
        'W960','LW960','CSW176','CSW174','S535','W400','LW400','LW701','LW713',
    );

    $better = array(
        // --- Tees: Better Basics ---
        'PC450','PC450LS','LPC450','PC450Y','PC330','PC330LS','LPC330V','PC340','PC340Y',
        'PC455','PC455LS','LPC455V','PC455Y','560M','560LS','65000','65000L','65000B',
        '980','NL1810','1717','6014','3023CL','9018','IC46M','IC46L','IC46B',
        // --- Tees: Price Point Premium (whole page -> better) ---
        'DT6000','DT6001','DT6000Y','DM108','DT109','DM108L','DT108Y',
        '64000','64000L','64000B','570M','64000CVC','64440CVC','64001LCVC','64000BCVC',
        // --- Tees: Performance (better row) ---
        'ST350','ST350LS','TST350','LST350','YST350','ST360','ST360LS','YST360',
        'ST450','ST450LS','ST340','ST340LS','LST340','YST340',
        // --- Sweatshirts: better rows ---
        'IC48M','PC850','DT6104','SF000','DT6100','DT6100Y','IC49M','PC850H','PC850YH','SF500',
        'DT6102','PC850ZH','SF600','ST258','PC850Q','ST561','LST561','DT6106','K829',
        'ST710','F244','YST244','ST850','TST850','LST850','ST857',
        // --- Polos: better rows ---
        'TM1MAA369','TM1LD005','TMA41461','TM1LF071','TM1MY404','TM1WW002',
        'OG143','OG154','LOG154','OG125','LOG125',
        'K110','LK110','K200','LK200','K500','TLK500','L500','Y500',
        'ST665','LST665','ST740','LST740','YST740','ST405','LST405',
        'CS420','CS418','TLCS418','CS419',
        // --- Outerwear: better rows ---
        'J324','L324','ST980','LST980','OE720','LOE720',
        'MM7210','MM7213','MM7200','MM7201','EB514','EB515',
        'J321','L321','J332','L332','J792','L792',
        'OG727','LOG727','F904','L904','F428','L428',
        'J406','J920','L920','MM7000','MM7001',
        'OG741','J325','L325','J709',
        // --- Headwear: better rows ---
        'C938','NE1000','NE1020','NE406','NE209','OG604',
        'NE404','NE4020','STC64','C112ECO','C110','NE204',
        'STC43','DT600','CP78','C925','C869','C871',
        'C920','C948','C921','STC57','STC51','STC27','NE902','NE900','DT815',
        // --- Bags: better rows ---
        '411065','417054','NF0A3KX6','BG204','BG226','BG208','BG810','BST600','BG637',
        '408113','CT89251601','CSB505','95001','108087','BG435','B300','B400',
        '117023','417012','417015','97002','BG935','MMB600',
        // --- Wovens / Dress: better rows ---
        'MM2000','MM2001','W680','LW680','MM2002','MM2003','EB600',
        'SP14','SP14LONG','SLU2','MM2006','LOG1002','MM2011',
    );

    $best = array(
        // --- Tees: Premium ---
        'DM130','DM132','DM130L','DT130Y','DT184','DT185','DT188',
        'AL2004','AL6004','AL2015','AL207','AL2300','AL6204','NL6010','NL6710',
        'NL6210','NL6211','NL6610','NL3312','BC3413','BC3513','BC6413','BC3413Y',
        'BC3001CVC','BC3501CVC','BC6400CVC','BC3001YCVC','DT104','DT105','DM104L','DM1170L',
        'NL3600','NL3601','NL3900','BC3001','BC3501','BC6004','BC3001Y','SXU001','SXU022','SXW002',
        // --- Tees: Performance (best row; Nike excluded) ---
        'ST400','ST400LS','LST400','ST420','ST420LS','YST420','NEA200','NEA201','YNEA200',
        // --- Sweatshirts: best rows + heavyweight ---
        'DT1106','BC3945','1566','DT1101','AL4000','SXU003','CTK121',
        'SXU005','CTK122','NEA511','LNEA511','TM1MY397','OG813','NF0A8C5G','NEA512',
        'SXU029','SXU028','VL130','VL130H','VL130ZH','CT100615','CT100614','CT100617',
        'F280','F281','F282','ST283','ST284','19000','19500','S149','S101','DT6600',
        'DT6154','DT6150','DT2204','DT2200','NL9007','NL9087','NL9307','BC4711','BC4719',
        // --- Polos: best rows ---
        'TM1MY403','TM1MY402','TMA41462','OG109','OG152','OG122','LOG122',
        'K528','L528','K863','LK863','K8000','TK8000','LK8000',
        'T474','L474','ST655','TST655','LST655','ST650','TST650','LST650',
        'CS410','TLCS410','CS411','CS412','TLCS412','CS413',
        // --- Outerwear: best rows ---
        'EB544','EB545','CT102199','NF0A88D5','NF0A88D4',
        'NF0A3LH2','NF0A3LHK','CT102208','CT104314','NF0A7V6J','NF0A7V6K',
        'CT105533','EB656','EB657','CT106677',
        'NF0A7V64','NF0A7V62','NF0A3LH7','CT106416','CT106419',
        'CT104670','NF0A3LH4','NF0A3LH5','EB558','EB559',
        'CT105475','COTOM1689','COTOM1693',
        // --- Headwear: best rows (Nike excluded) ---
        'TM1MU426','NE400','NE4030','NE207','NE205','CT106577','TM1MU423',
        'NE200','CT103938','NE201','C892','C819','RU900',
        'NE800','C947','NE219','CT104597','CTA205',
        // --- Bags: best rows ---
        'CT89350303','411067','NF0A3KX7','CSB205','MMB200','BG223',
        '92000','412045','EB800','CT89132109','CT89032822',
        'BB18880','CT89260209','TMB205','BB18840','94000','MMB202',
        'BB18830','417018','711207','CT89098101','92002','COTOBFP',
        // --- Wovens / Dress: best rows ---
        'BB18000','TBB18000','BB18001','BB18002','TBB18002','BB18003',
        'BB18004','BB18005','CT107106','CT106689','CT105291',
        'ST325929TB','ST326815TB','BB18009','BB18007',
    );

    $map = array();
    foreach ($good   as $s) $map[bt_cat_tier_norm($s)] = 'good';
    foreach ($better as $s) $map[bt_cat_tier_norm($s)] = 'better';
    foreach ($best   as $s) $map[bt_cat_tier_norm($s)] = 'best';   // best wins on any overlap
    return $map;
}

/** Resolve the tier for a row. EG-PRO is always best; Nike is never in the map. */
function bt_cat_tier_for($supplier, $style_no) {
    if (strtolower((string) $supplier) === 'egpro') return 'best';
    $map = bt_cat_tier_map();
    $k   = bt_cat_tier_norm($style_no);
    return isset($map[$k]) ? $map[$k] : '';
}

/**
 * Re-tag every existing row from the current map. Style numbers are normalized
 * in PHP (the column isn't), so we resolve per row then batch the UPDATEs by
 * tier via id IN (...). Returns counts. Safe to run repeatedly.
 */
function bt_cat_apply_tiers() {
    global $wpdb;
    $t = bt_cat_table();

    $rows = $wpdb->get_results("SELECT id, supplier, style_no FROM $t", ARRAY_A);
    if (!is_array($rows)) return array('good' => 0, 'better' => 0, 'best' => 0);

    $byTier = array('good' => array(), 'better' => array(), 'best' => array());
    foreach ($rows as $r) {
        $tier = bt_cat_tier_for($r['supplier'], $r['style_no']);
        if ($tier !== '' && isset($byTier[$tier])) $byTier[$tier][] = (int) $r['id'];
    }

    $wpdb->query("UPDATE $t SET tier=''");
    $counts = array();
    foreach ($byTier as $tier => $ids) {
        $counts[$tier] = count($ids);
        foreach (array_chunk($ids, 500) as $chunk) {
            if (!$chunk) continue;
            $in = implode(',', array_map('intval', $chunk)); // ints only -> safe to inline
            $wpdb->query($wpdb->prepare("UPDATE $t SET tier=%s WHERE id IN ($in)", $tier));
        }
    }
    if (function_exists('bt_cat_facets_flush')) bt_cat_facets_flush();
    return $counts;
}

/* Re-apply tiers once per plugin version when an admin loads wp-admin. Keeps
   existing rows in sync after a map edit (which always ships with a version
   bump) without needing a manual button or a re-import. */
add_action('admin_init', function () {
    if (get_option('bt_cat_tier_stamp') === BT_CAT_VERSION) return;
    global $wpdb;
    $t = bt_cat_table();
    if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return; // table not ready yet
    bt_cat_apply_tiers();
    update_option('bt_cat_tier_stamp', BT_CAT_VERSION);
});
