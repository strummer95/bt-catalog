# BT Catalog

Boomer T's unified blank-apparel catalog. Ingests three suppliers into one cache table and
renders a BT-branded storefront with a quote flow: browse blanks, pick sizes, pick
decoration, send to the quote desk. No checkout. Retail is cost times markup; **cost is
never exposed to customers**.

- Live: boomerts.com/catalog/ via the `[bt_catalog]` shortcode
- Current version: **0.25.0**. Constant `BT_CAT_VERSION`, function prefix `bt_cat_`.
- Repo: `strummer95/bt-catalog`

`HANDOFF.md` in this repo is a long historical record of how each feature was built and why.
It is accurate but stale on version numbers. Read it when you need the reasoning behind a
design; trust the code and this file for current state.

## Environment

**Boomer T's Ink & Thread is a separate company from Duck and Rabbit Co.** Dillon's dad's
shop. **AWS Lightsail Bitnami WordPress + Elementor, NOT IONOS.** Boomer T's is or would be
a dealer on PresStora, but the two are not affiliated and BT must never depend on PresStora
at runtime.

**Dillon works only through the WordPress dashboard.** No SSH, no SFTP. Everything ships as
a plugin update.

Brand: navy `#27267e`, pink/magenta `#e535ab`, Oswald.

## Release process

Every version bump touches four places, and **they must all match** or WordPress loops
forever. This has bitten this repo before (see the commit "Fix version mismatch, zip
declared the old version, causing an update loop").

1. `Version:` in `bt-catalog/bt-catalog.php`
2. `BT_CAT_VERSION` in the same file
3. `version` in `manifest.json`
4. The version inside the zip

Steps:

1. Edit files under `bt-catalog/`.
2. Bump the header `Version:` and `BT_CAT_VERSION` together.
3. `node --check` all touched JS. No PHP binary in the container, so brace-audit PHP by hand.
4. Build `bt-catalog-X.Y.Z.zip` and plain `bt-catalog.zip` at the repo root. Zip the
   `bt-catalog/` folder itself, excluding `.DS_Store`.
5. Update `manifest.json`: version, `download_url` at the **versioned** raw URL, changelog.
6. Commit and push to `main`.
7. Dillon: **BT Catalog → Updates → Check now**, then **Plugins → Update Now**.

`uploads.github.com` is blocked from the container, so GitHub Release assets can't be
attached. That is why releases use versioned raw zips. The updater reads `manifest.json`
through `api.github.com` with `Accept: application/vnd.github.raw`, so a push is live
instantly.

`includes/bt-admin.php` is byte-identical across bt-portal, bt-catalog, bt-quote and
bt-accounts. Don't fork it; if it changes, re-copy into all four in the same round.

## Suppliers

**S&S Activewear** (`ingest.php`, `ss-admin.php`). Base `https://api.ssactivewear.com/V2/`,
HTTP Basic, username = account number, password = API key. Account **#11351**. Credentials
live in plugin settings, server-only, never in the repo. Styles:
`GET /V2/styles/?pageSize=10000` (~5,700). Colors and pricing:
`GET /V2/products/?style=<styleID>&pageSize=500`. `customerPrice` is YOUR cost. Image URLs
are `https://www.ssactivewear.com/` plus the path. **Rate limit 60 calls/min.**

**SanMar** (`sanmar.php`). Native PromoStandards SOAP on port 8080, no fee, no API key.
Auth is SanMar.com username plus password, customer **#108175**. Three services must all
agree: ProductData `getProduct` at **wsVersion 1.0.0** (1.1.0 returns error code 115),
MediaContent `getMediaContent` at 1.1.0, PricingAndConfiguration
`getConfigurationAndPricing` at 1.0.0 with `priceType` Customer. `getProduct` returns no
hex, no images and no price, which is why all three are needed. Customer pricing is the
current **effective net** price, so it returns sale pricing during a sale window and a
re-sync after the sale updates it back.

Requires on the live server: account onboarded for Web Services, boomerts.com server IP
whitelisted by SanMar, port 8080 outbound open, php-soap enabled. All confirmed working.
Cannot be tested from the container.

**EG-PRO** (`egpro.php`). Not a distributor. A house-brand manufacturer on Shopify, so the
catalog comes from the public feed `egpro.com/products.json?limit=250&page=N`, page-walked
server-side with a 40-page cap. No credentials, no dedup, everything they make is
exclusive. Representative cost is the **cheapest** variant so extended-size upcharges never
inflate the headline price. EG-PRO is always tier `best` by code rule, not by the map.

## The dedup model

S&S and SanMar overlap. Two mechanisms:

1. **Full-skip denylist** (`bt_cat_sanmar_denylist`): brands S&S stocks completely and
   SanMar re-codes, so style numbers can't be matched (Gildan G500 vs 5000, Hanes, Jerzees,
   Fruit of the Loom, Bella+Canvas, Next Level, Comfort Colors, Champion, Anvil, American
   Apparel, New Era).
2. **Per-style dedup** for every other brand: match on brand plus normalized style number
   against the S&S rows, import only what S&S lacks. Richardson 112 skips, 168 imports.

The skip list must match **SanMar's** brand spellings, e.g. "Port & Co" not "Port &
Company". Dedup requires the S&S full sync to be complete, since the index is built from
catalog rows. `bt_cat_sanmar_cleanup()` re-applies the rules to already-imported rows.

## Derived attributes

Suppliers give a garment type and a marketing title and nothing else. Every shopping
dimension is derived **once at import** into real columns, never computed at query time:
`bucket`, `aud`, `neck`, `sleeve`, `closure`, `size_set`, `color_fams`, plus `tier` and
`perf`.

Rules that matter:

- Name and category are authoritative. **Description is marketing copy and stays out of
  it.**
- An attribute that can't be determined stays `''`, and an empty value is never offered as
  a filter. That alone is what makes "no Sleeve Length on a hat" work.
- Detection order: bucket gate first, then explicit tokens, then a type default (unmarked
  tee = short sleeve crew, unmarked hoodie = long sleeve pullover). Layer pieces force long
  sleeve regardless.
- Use `bt_cat_attr_word()` for stems that are substrings of other words. Plain
  `strpos('snap')` tagged "**Snap**back Trucker Cap" as a snap-front garment.
- Sizes are canonicalized (`XXL`/`2X` → `2XL`, `Youth L` → `YL`, `One Size` → `OSFA`).
  Unrecognized tokens are stored but never offered as a chip.

**Every write path must go through `bt_cat_upsert()`.** Three paths once bypassed it and
left `bucket` empty, which makes a row invisible to every facet: `bt_cat_sync_seed()`,
`bt_cat_sync_batch()` and `bt_cat_refresh_batch()`. If you add a write path, merge
`bt_cat_derive_attrs()` into it.

Backfills are version-gated. Attrs run on **init** (not `admin_init` like tiers) because
the storefront filters on those columns and waiting for an admin page load would show
visitors an empty rail.

## Filters and facets

`bt_cat_read_params()` normalizes every filter once. `bt_cat_filter_where($p, $skip)`
builds the WHERE, and **both the list and the facet counts call it**, so counts cannot
drift from results by construction. `$skip` omits one filter so a facet never narrows
itself: picking Crew leaves V-Neck clickable.

A category filter must read the derived `bucket` column, never expand to LIKE substrings.
`LIKE '%tshirt%'` matches "Swea**tshirt**s", which is how sweatshirts once appeared under
T-Shirts while the count disagreed.

One `GROUPS` config in `catalog.js` drives both the header dropdowns and the sidebar rail,
so a filter can't exist in one and not the other. A group renders only with 2+ options, or
exactly one that is currently selected.

Quality tiers (`tiers.php`) are a static style-number map from the SanMar Navigator guides,
647 styles, matched across all suppliers by normalized style number. **Nike is intentionally
excluded from tiers**, though Nike performance items do still show under Performance.
Performance is detected from fabric and specs, not names, and is surfaced as a synthetic
category that overlaps others rather than as its own filter group.

## Structure

`bt-catalog.php` main · `includes/db.php` table `wp_bt_catalog` plus `bt_cat_upsert` ·
`ingest.php` S&S · `sanmar.php` · `egpro.php` · `sync.php` autoprice, cron, batch import,
429 handling · `attrs.php` derived attributes · `tiers.php` quality plus performance ·
`pricing.php` retail overrides · `rest.php` public `boomerts/v1` endpoints (`catalog`,
`catalog/item`, `catalog/facets`) · `export.php` export for PresStora import ·
`admin.php` / `ss-admin.php` / `bt-admin.php` · `assets/catalog.css` scoped to `#btcat-root`
· `assets/catalog.js` the storefront

Autoprice is cost times markup, rounded up to `.95`.

## Open items

- DONE 0.25.0: the drawer's Send step posts to BT Quote's `boomerts/v1/quote` (same
  endpoint and inbox as Quick Quote, params `your-name` / `your-email` /
  `your-organization` / `your-phone` / `your-message`). Before that, Send only flipped the
  drawer to "sent" client-side and every catalog quote request was silently lost. Never
  show a success state the server has not confirmed.
- Verify Quick Quote price parity against the portal for a known combo, and decide whether
  `retail` should send the doubled retail (current) or raw cost.
- Price Point Premium tees are tagged whole-page `better` rather than split good/better,
  pending Dillon.
- **Next up for Boomer T's**: the Bruce AI integration. Quote step feeds a Woo cart with
  quote meta, and a Bruce embed joins on quote ID. First step is an event-discovery probe
  to find the design-submitted event.

## Working notes

- Compact, always. Text sizing errs UP: table body 15px or larger, badges 13px or larger.
- Terse and results-first. Ship the deliverable, not narration.
