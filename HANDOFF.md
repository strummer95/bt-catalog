> **START HERE (read this first).** This file is the authoritative, current state of the BT Catalog project. If any auto-generated conversation summary or memory says we're on an HTML mock, "12 fake products," or mid-Step-3 wiring, that is STALE — ignore it. The plugin is built, live, and self-updating. Trust this file over older summaries. Current version: **v0.6.12**.

# BT Catalog — Project Handoff (current as of v0.6.12)

## What this is
A custom WordPress plugin, **BT Catalog**, on **boomerts.com** (Boomer T's Ink & Thread — family print shop). It ingests the S&S Activewear API into a cache table and renders a BT-branded blank-apparel catalog with a quote flow (browse blanks → pick sizes → decoration → send to quote desk; no checkout). Retail = S&S cost × markup; cost is never shown to customers.

**boomerts.com = AWS Lightsail Bitnami WordPress + Elementor. NOT IONOS.** Brand: navy #27267e, pink/magenta #e535ab, Oswald font. This is SEPARATE from Dillon's Duck and Rabbit Co. / PresStora / IONOS stack. Dillon works ONLY through the WP dashboard (WPCode + Plugins), no SSH/SFTP/server file access.

## Repos / deploy
- **Plugin repo (public):** `strummer95/bt-catalog` — holds plugin source `bt-catalog/`, `manifest.json`, and versioned zips `bt-catalog-X.Y.Z.zip` + `bt-catalog.zip`.
- Token (GitHub, works for strummer95 repos): `(GitHub token — in Dillon's notes/Claude memory)`
- **Auto-update is live and instant.** The plugin reads `manifest.json` via the **GitHub API** (`api.github.com/repos/strummer95/bt-catalog/contents/manifest.json`, `Accept: application/vnd.github.raw`), which reflects pushes immediately (no CDN cache). Each release uses a **versioned zip filename** so the download URL is never stale. Dillon updates via **BT Catalog → Updates → Check now**, then **Plugins → Update Now**. No delete/reinstall.

### Release process (do every version bump)
1. Edit files in `/home/claude/bt-catalog/`.
2. Bump `BT_CAT_VERSION` in `bt-catalog/bt-catalog.php`.
3. `node --check` all JS; brace-audit touched PHP (no PHP lint in container).
4. Build: `cd /home/claude && rm -f /mnt/user-data/outputs/bt-catalog.zip && zip -r /mnt/user-data/outputs/bt-catalog.zip bt-catalog -x '*.DS_Store'`
5. Publish: copy plugin into `/home/claude/bt-catalog-dist/bt-catalog/`, copy zip to `bt-catalog-X.Y.Z.zip` AND `bt-catalog.zip`, write `manifest.json` (version + versioned `download_url`), `git commit` + `git push origin main`.
6. Tell Dillon to Check now → Update Now.

## Environment (container)
- `/home/claude/bt-catalog/` = plugin source.
- `/home/claude/bt-catalog-dist/` = clone of public repo (push target).
- `/home/claude/pressly-peek/` = shallow clone of PresStora (`strummer95/pressly`) for S&S code patterns only — BT must NOT depend on PresStora at runtime.
- bash network allows api.github.com, github.com, raw.githubusercontent.com, codeload.github.com. **uploads.github.com is BLOCKED** → can't attach GitHub Release assets from here → that's why we use versioned raw zips, not Releases.
- Can't reach boomerts.com or S&S from container. No PHP installed (validate via brace-count + `node --check`).

## S&S API facts (mirrored from PresStora)
- Base `https://api.ssactivewear.com/V2/`, HTTP Basic auth: username = account #, password = API key.
- Dillon's account **#11351** (BT Apparel Inc dba Boomer T's). Creds are entered in the plugin settings (server-only, not in repo).
- Styles: `GET /V2/styles/?pageSize=10000` (all ~5,707). Fields: styleID, styleName(→style_no), brandName, title, description, baseCategory.
- SKUs/colors/pricing: `GET /V2/products/?style=<styleID>&pageSize=500`. Fields: colorName, color1(=hex), colorFrontImage, colorSwatchImage, sizeName, customerPrice(=YOUR cost), salePrice.
- Image URL = `https://www.ssactivewear.com/` + ltrim(path,'/'). Same for colorFrontImage and colorSwatchImage.
- Rate limit 60 calls/min.

## Plugin structure (`bt-catalog/`)
- `bt-catalog.php` — main; defines BT_CAT_VERSION, BT_CAT_DIR/URL/FILE; requires includes; install on activation + version bump.
- `includes/db.php` — table `wp_bt_catalog` (cols: id, supplier, supplier_style_id, style_no, brand, name, category, description, specs JSON, colors JSON [{name,hex,img,swatch}], sizes, cost, sale_cost, retail, retail_override, detail_done 0/1/2, tier, active, updated_at). Helpers: `bt_cat_table`, `bt_cat_install`, `bt_cat_upsert`, **`bt_cat_featured()`** (parses featured option → [{style,brand}]), **`bt_cat_brand_norm()`**, **`bt_cat_featured_resolve()`** (brand-aware ordered rows).
- `includes/ingest.php` — S&S fetch + import. Color objects now include `swatch` (colorSwatchImage). Brand-aware style matching.
- `includes/sync.php` — autoprice (cost×markup, round up to .95), cron 'bt_cat_minute', discover (seed all styles detail_done=0), batch import w/ transient lock + 429 handling, progress, start/stop, ajax tick.
- `includes/admin.php` — top-level menu. Settings: S&S creds + markup; **Featured on default page** (brand-aware, per-line match display); Updates (GitHub repo field, default strummer95/bt-catalog); Full sync progress; Manual Pull (probe/import/clear). Save handlers MERGE into option so forms don't wipe each other. `bt_cat_opt()` lives here.
- `includes/pricing.php` — submenu "Pricing": searchable/paginated table, editable retail_override.
- `includes/rest.php` — public endpoints under `boomerts/v1`: `catalog` (list; server-side filters + **featured-by-default when unfiltered**, via bt_cat_featured_resolve), `catalog/item?id=`, `catalog/facets`. Cost never exposed. `bt_cat_rest_rows_to_items()` shared mapper.
- `includes/shortcode.php` — `[bt_catalog]`; enqueues catalog.css/js (versioned by BT_CAT_VERSION → cache-bust); localizes `btcatCfg={rest:rest_url('boomerts/v1/'),nonce}` → JS uses `REST` base.
- `includes/updater.php` — GitHub-API self-updater (see above).
- `assets/catalog.css` — approved mock CSS, scoped `#btcat-root`. Color grid `.colorgrid/.copt/.csq/.clabel`; sizes `.sizegrid/.sizebox/.sz`. Compact overrides appended at end of file.
- `assets/catalog.js` — data-driven storefront in `#btcat-root`. Builds header (My Quote button above search), grid, PDP, 3-step quote drawer.

## Live status (confirmed working)
- Plugin active **v0.6.12**, ~5,707 styles cached, full sync running/auto-pricing. Storefront live at **boomerts.com/catalog/** via `[bt_catalog]`.
- Real photos, auto-prices (e.g., Gildan 64000 = $6.95), search/filter/facets/pagination, PDP, swatches, formatted description all working.

## Feature history & key fixes
- **Shareable/deep-link URLs (DONE):** URL reflects filters/search/page + open product (`?brand=Gildan&pid=2743`). On load, state restored and product opened. `syncURL()` (replaceState) + `readURL()`.
- **Color swatches (DONE):** `.csq` must be a block `<div>` (inline span collapsed). Uses S&S `swatch` photo (colorSwatchImage) when present, else flat hex from `color1`. **NOTE:** swatch photos only populate for styles imported AFTER the swatch field was added — re-import a style (Manual Pull) or let sync re-import to get photos; flat hex is the fallback.
- **PDP layout (DONE):** product photo 480px square; sizes/quantity above color; small swatches (34px, 3px gap); Add to Quote next to sizes.
- **Quote drawer (DONE):** was opening off-screen — fixed by setting inline `transform:translateX(0)` + `show` class in openDrawer.
- **Pricing uses the real Quick Quote endpoint (DONE):** Step 2 calls `POST /wp-json/boomerts/v1/price` (same `boomerts/v1` namespace; that endpoint is Dillon's employee-portal "Price Return" snippet) with `garment=custom`, `retail=<blank's catalog price>`, `qty`, and either `locations` (print 1-3) or `embType` (embroidery: text/logo/hard). Response `{perShirt,total,discPct,breaks}`. Embroidery 84+ returns perShirt=null → shows "By quote". Print uses 1/2/3 locations; embroidery uses Names/Logo/Hard-to-handle (matches the formula). Changing qty now changes the price. (Removed the earlier made-up local tiers.)
  - OPEN QUESTION for Dillon to verify: that the catalog's price matches his portal for a known combo, and whether `retail` should send the doubled retail (current) or raw cost.
- **Featured on default page (DONE, brand-aware):** Admin "Featured" box, one entry per line/comma. Last token = style number, preceding words = brand (e.g., `Gildan 5000`, `Bella Canvas 3001`). Bare numbers collide (5000 = Bayside/Gildan/etc.), so brand prefix disambiguates. Served by DEFAULT server-side when catalog is unfiltered (robust to cached front-end). Admin shows per-line match ("Gildan 5000 → GILDAN · Heavy Cotton") and flags collisions (⚠ N brands use "5000"). Earlier gotcha: the placeholder text was the same as the example numbers, making an empty box look filled — removed.

- **Left filter sidebar (DONE, v0.6.12):** Restored the mock's left rail (Categories, Colors, Brands) alongside the header dropdowns. Both share the same filter state via setFilter/markActive — selecting in one highlights the other; active item gets navy pill. Brands list scrolls (max-height 320px). Sidebar built in loadFacets with the same data-brand/data-cat/data-color attrs so the existing binds cover it. Hidden under 860px (header dropdowns take over). `.btside/.fsec/.fhead/.fitem` in catalog.css.

- **Category buckets + compact scrolling sidebar (DONE, v0.6.12):** rest.php now normalizes S&S baseCategory variants into display buckets via bt_cat_cat_buckets()/bt_cat_norm_category() — all *t-shirt*/tee/*tshirt* → "T-Shirts", *fleece* → "Fleece". Facets collapse to buckets; the list filter expands a bucket back to LIKE-matching all raw categories (exact match for non-bucketed). Add buckets by extending bt_cat_cat_buckets(). Sidebar (.btside) is now a single sticky scroll panel (max-height calc(100vh - 24px)); per-section .fscroll cap removed; font 13px, tighter padding.

- **Compact PDP + aligned menus (DONE, v0.6.12):** PDP right column ~halved — .desc 12.5px/line-height 1.35 with compact .desc ul/li/p rules (S&S description was rendering as a default-spaced list), .specs li padding 4px/12.5px, h1 24px, price 23px, tighter .lab/.pdp padding. Header .cmenus shifted margin-left:254px (sidebar 228 + gap 26) to align with the product column; reset to 0 under 860px.

- **Popular styles (DONE, v0.6.12):** New admin "Popular styles" field (option bt_cat_popular, brand-aware via bt_cat_popular() — same syntax as featured). Seeded once on install with Gildan 5000/18000/18500 + Bella Canvas 3001. They float to the top of EVERY list (ORDER BY CASE in bt_cat_rest_list, in configured order) and render a POPULAR pill (.poptag, top-left) on the card; mapper sets item.popular. NOTE: on the unfiltered default page, the featured-resolve order wins (popular pill still shows); popular-to-top applies to searched/filtered lists.

- **Mobile fixes (DONE, v0.6.12):** PDP had an inline grid-template-columns on .pdp-grid that overrode the mobile single-column rule, squishing the image column (looked missing) and pushing content off the right. Removed the inline style (desktop two-col CSS stays; mobile media query stacks). Added to the 860px media query: #btcat-root{overflow-x:hidden} (safe here since the sticky .btside is hidden on mobile), centered/capped .pdp-img, .megabrands to 2 cols, and max-width:100% on mega/colorgrid/specs/desc/sizegrid.

- **Mobile PDP fit (DONE, v0.6.12):** v0.6.1 only clipped overflow (hid content). Real cause: .pdp-grid is a CSS grid and grid items have min-width:auto, so they would not shrink below content and pushed off-screen. Mobile media query now sets .pdp-grid{display:block} + >*{min-width:0;max-width:100%}; .pdp-img block centered/capped 380 with margin-bottom; .specs li wrap. Content fits phone width without scroll or clipping.

- **Mobile color preview (DONE, v0.6.12):** Replaced the v0.6.3 auto-scroll-on-color-tap (was obnoxious) with an inline live preview. New #btColorPrev thumb above the swatch grid; swapImage() fills both the hero image and the preview. .colorprev display:none on desktop (image already beside colors), shown 88px in the 860px media query. Order kept: image, info, sizes first; colors + preview at the bottom.

- **PDP native scroll + preview fix (DONE, v0.6.12):** (1) PDP was a position:fixed full-screen overlay with its own overflow-y:auto -> inner scrollbar on desktop. Now in-flow: openPDP sets .pdp.show + root.classList.add('bt-pdp-open'); CSS '#btcat-root.bt-pdp-open > :not(#btPdp):not(#btDrawer):not(#btScrim){display:none}' hides catalog; window scroll used; scrollY saved/restored on open/close. .pdp overflow-x:clip (not hidden) to avoid re-spawning a scrollbar. (2) Mobile color preview (#btColorPrev) wasn't showing: an end-of-file '.colorprev{display:none}' came after the 860px media query and overrode display:block. Changed to '@media(min-width:861px){.colorprev{display:none}}' (desktop-only).

- **Navy-first default colorway (DONE, v0.6.12):** Grid thumbnail + PDP default color now prefer navy. Rank: exact 'navy'=0, name contains 'navy'=1, dark blue=2 (lum<120 and ('blue' in name or blue-dominant hex; catches Royal/Cobalt, excludes Carolina Blue), gray=3 (name gray/grey/charcoal/graphite OR near-neutral hex max-min<=30 & lum 50-215; excludes black/white), else 99. PHP helpers bt_cat_hex_rgb/bt_cat_color_rank/bt_cat_preferred_color_idx in rest.php (used for thumb, only colors with img); mirrored JS hexRgb/colorRank/preferredColorIdx in catalog.js (PDP currentColor). Recomputes from cached colors, no re-import.

## NEXT / OUTSTANDING
1. **Wire the quote "Send" (Step 3) into Dillon's employee-portal quote tool** so catalog quotes land where his existing `[bt_quick_quote]` submissions go. Currently Send only confirms client-side. Need the submission storage shape (the quote tool is a single btq-prefixed HTML/JS file, posts to boomerts/v1; quote desk). This is the last real feature.
2. Verify Quick Quote price parity + decide retail-vs-cost input (see pricing note).
3. Re-import styles (or let sync finish) so swatch PHOTOS replace flat hex across the catalog. Optional: a one-click "refresh swatches on already-imported styles" button (re-pull detail for detail_done styles) — proposed, not built.
4. Good/Better/Best tiers: scaffolding survives (tier column in db; .tier/.t-good/.t-better/.t-best pills + .tierlegend in catalog.css). JS render + tier assignment were removed. Open design question: how tiers get assigned (manual per-style vs auto-by-price-within-category vs curated brand/style map). Revisit per Dillon.
5. Possible future: SanMar as 2nd supplier (cache table has `supplier` col; SanMar = PromoStandards/SOAP).

## Working style (Dillon)
Terse, results-first, wants the actual deliverable not narration. Complete ready-to-paste output. Owns/expects owned mistakes. Text sizing: err UP (table body ≥15px, headers/badges ≥13px). Never confuse Boomer T's (Lightsail WP) with Duck and Rabbit (IONOS). Boomer T's is/would be a dealer on PresStora; the two are not affiliated.
