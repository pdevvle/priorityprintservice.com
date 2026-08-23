# Merchant feed, GBP rating, llms.txt — build notes

Three jobs from the SEO map. The useful technical answer is that **they are not
three jobs — they are one missing primitive and three thin consumers of it.**

Build the primitive first and the rest gets much smaller.

---

## 0 · The primitive: `pps_catalog()`

All three need the same sentence answered: *"give me every PPS product, with its
calculator, its price, its images and its defaults."* Nothing in the plugin can
answer it today. What exists instead is this, copy-pasted in three places
(`pps-calculators.php:242`, `:448`, `:1102`):

```php
$ids = array_map( 'intval', array_filter( preg_split( '/[\s,]+/', $meta['products'] ?? '' ) ) );
```

That is the registry's ID string being re-parsed by whoever needs it, with no
shared notion of "is this product actually publishable."

### One function, roughly 40 lines

```php
/**
 * Every product a PPS calculator owns, resolved and filtered.
 *
 * @param array $args  ['require_price' => bool, 'require_image' => bool]
 * @return array<int, array{
 *   id:int, calc:string, product:WC_Product, url:string,
 *   price:float|null, title:string, description:string,
 *   image:string, gallery:string[], defaults:array, categories:string[]
 * }>
 */
function pps_catalog( array $args = array() ) { … }
```

Rules it centralises, so no consumer has to remember them:

- skip IDs that no longer resolve to a product, or aren't `publish`
- price = `_pps_defaults_price`, falling back to `_regular_price`, else `null`
- `defaults` = `_pps_defaults`, already an array
- `calc` = which registry key claimed the ID
- image = featured image absolute URL; gallery = `_product_image_gallery` resolved

Give it a `pps_catalog_row` filter so a consumer can adjust one field without
forking the whole thing.

**Do this refactor first.** It is the difference between three medium features
and three small ones, and it retires a triplicated parse that will otherwise
drift.

---

## 1 · Merchant Center feed

### Format: scheduled XML fetch. Not the Content API.

| | Scheduled XML fetch | Content API |
|---|---|---|
| Auth | None — Google fetches a URL | OAuth, token refresh, quota handling |
| Code | One endpoint, ~150 lines | Client, retry, error mapping |
| Debugging | Open the URL in a browser | Read API logs |
| Freshness | Daily (configurable) | Real-time |
| **Pattern already in this codebase** | **Yes** | No |

Daily is entirely adequate for print products whose price changes when *you*
change it. Take the XML.

### It is the presets sitemap with a different dialect

`/pps-presets-sitemap.xml` (`pps-calculators.php:6390–6416`) is already exactly
this shape: `add_rewrite_rule` → `query_vars` → `template_redirect` → set the
content-type → echo XML → `exit`. Copy that block, change the namespace and the
loop body.

```php
define( 'PPS_PRODUCT_FEED_SLUG', 'pps-product-feed.xml' );
// rewrite + query var, identical to PPS_PRESETS_SITEMAP_SLUG

add_action( 'template_redirect', function() {
    if ( ! get_query_var( 'pps_product_feed' ) ) return;

    $xml = get_transient( 'pps_product_feed_xml' );
    if ( $xml === false ) {
        $xml = pps_render_product_feed( pps_catalog( array(
            'require_price' => true,   // see "the exclusion rule"
            'require_image' => true,
        ) ) );
        set_transient( 'pps_product_feed_xml', $xml, HOUR_IN_SECONDS );
    }

    header( 'Content-Type: application/xml; charset=utf-8' );
    header( 'Cache-Control: public, max-age=3600' );
    echo $xml;
    exit;
} );
```

Bust the transient on `save_post_product` and on registry save, so an edit shows
up without waiting an hour.

### Attributes

RSS 2.0 with `xmlns:g="http://base.google.com/ns/1.0"`, one `<item>` per product.

| Attribute | Source | Note |
|---|---|---|
| `g:id` | product ID | Must stay stable forever. Never reuse. |
| `title` | product title | ≤150 chars, truncate on a word boundary |
| `description` | `post_content` | Strip shortcodes **then** tags; ≤5000 |
| `link` | permalink | |
| `g:image_link` | featured image | **Required.** No image, no listing. |
| `g:additional_image_link` | gallery | Up to 10, one element each |
| `g:availability` | `in_stock` | Print-to-order never goes out of stock |
| `g:price` | `109.04 USD` | See price parity below |
| `g:condition` | `new` | |
| `g:brand` | `Priority Print Service` | |
| `g:identifier_exists` | `no` | See below — omitting this causes disapprovals |
| `g:product_type` | your category path | Your taxonomy, free text |
| `g:google_product_category` | config field | Google's taxonomy — pick once, per category |
| `g:custom_label_0` | calc type | Free segmentation for campaigns later |

`g:google_product_category` wants a value from Google's own published taxonomy.
Don't hardcode a guess — add it as a per-category config field and set it once
from the current taxonomy file.

### Two rules that keep the account healthy

**Price parity.** Merchant Center crawls the landing page and compares its price
to the feed. A mismatch disapproves the item, and a pattern of them warns the
account. Your chain already makes this work: `_pps_defaults_price` →
`_regular_price` → the Woo price element, the shop card, and the schema
`AggregateOffer` `lowPrice` all read the same number.

**The exclusion rule that follows: a product with no `_pps_defaults_price` must
be left out of the feed entirely.** Not sent with `_regular_price` as a guess,
not sent at 0. Absent. An omitted product loses one listing; a mismatched one
costs account standing.

Make that visible rather than silent — a WP-admin notice, or an `?debug=1` mode
on the feed URL listing what was skipped and why. Otherwise "my product isn't in
Shopping" becomes an unanswerable question.

**`g:identifier_exists = no`.** Custom-printed goods have no GTIN, and no MPN
worth inventing. Declaring their absence is the correct and required move;
leaving the field off makes Google assume you forgot one.

### Delivery

Merchant Center → Products → Feeds → Add → **Scheduled fetch** → the URL, daily,
in your timezone. Confirm `robots.txt` doesn't disallow it — the plugin already
appends to `robots.txt` (`pps-calculators.php:6331`), so add an `Allow` line
there in the same commit.

---

## 2 · Automating the Business Profile rating

### Places API, not the Business Profile API

The Business Profile API is for *managing* listings and needs an access request
approved by Google. You want two numbers. The Places API returns them in one
call:

```
POST https://places.googleapis.com/v1/places/{PLACE_ID}
X-Goog-FieldMask: rating,userRatingCount
```

One-time setup: a Google Cloud project, Places API enabled, an API key
restricted by IP, and your Place ID (a one-time lookup, then stored). A daily
call is ~30 requests/month — inside the free tier.

### Where the key lives

`pps_calc_config`, alongside the Shippo token and the rest. That option is
already covered by the standing rule — never bulk-copied between sites, never
read raw into a session — so the key inherits the right handling with no new
policy.

### The job

There is no `wp_schedule_event` in the plugin yet, but Action Scheduler is
already loaded (WooCommerce ships it, and `pps-gdrive.php:453` already uses it
for artwork uploads). Either works; Action Scheduler gives you a visible admin
queue and retry, which is worth having for something that fails silently.

`wp_remote_get` with timeout and error handling is well-established here —
`pps-calculators.php:1326` (VirusTotal) and `:3588` (Shippo) are the house
pattern to copy.

### The failure mode that matters

**A failed fetch must never overwrite the stored values.** The schema only emits
`aggregateRating` when rating > 0 and count > 0 (`pps_emit_localbusiness_schema`),
so writing zeros on a timeout would silently strip the rating from every page.

```php
$new = pps_fetch_place_rating();          // null on any failure
if ( $new === null ) {
    $seo['gbp_sync_error'] = current_time( 'mysql' );   // record, change nothing else
} else {
    $seo['gbp_rating_value'] = $new['rating'];
    $seo['gbp_review_count'] = $new['count'];
    $seo['gbp_synced_at']    = current_time( 'mysql' );
}
```

Show `gbp_synced_at` next to the fields in the SEO tab. A rating that silently
stopped updating in October is worse than one you know is manual. Keep the
manual inputs editable — they become the fallback when the key is absent.

### One thing worth knowing before spending the effort

Google's structured-data guidance is explicit that `aggregateRating` on
LocalBusiness should reflect reviews **you** collected, and marking up Google's
own review score on your own site is a documented rich-result disapproval risk.
Automating the mirror keeps it current; it doesn't change that exposure.

That is your call and you've already made it once. But it points at a better
target: **Woo product reviews.** Reviews collected on your product pages are
unambiguously yours to mark up, they attach to the product rather than the
business, and Merchant Center can ingest them as product ratings — so the same
work feeds §1 as well. If review volume is ever going to be worth chasing, that
is the version that pays twice.

---

## 3 · llms.txt for products

The cheapest of the three once `pps_catalog()` exists — the Presets loop
(`pps-calculators.php:6310–6326`) is the template, and the product version is
the same twenty lines against a different array.

```
## Products

### Saddle Stitch Booklet Printing
- URL: https://priorityprintservice.com/product/…
- From: $109.04
- Sizes: 5.5×8.5, 8.5×11, … | Pages: 8–64 | Papers: 70lb–100lb text, 80lb–18pt cover
- Instant pricing, no quote request
```

Emit the from-price. It is the single most common question an answer engine is
trying to resolve about a print shop, and being the source that states it
plainly is most of how you get cited.

### While you are in there — the hardcoded prose is now wrong

`/llms.txt` currently opens with *"specializing in custom saddle-stitch booklet
printing"* and its only detailed section is Saddle Stitch. You have eight
calculators. Everything an AI can currently learn about your brochures,
postcards, letterhead, greeting cards, stickers, perfect-bound books and coupon
books is: nothing.

Generate the Services list from the registry so it cannot go stale again. That
is a bigger real-world improvement than the product section itself.

---

## Order, and what each costs

| # | Build | Rough size | Why here |
|---|---|---|---|
| 1 | `pps_catalog()` | ~40 lines | Everything below is thin on top of it; retires a triplicated parse |
| 2 | llms.txt products + registry-driven services | ~40 lines | Immediate, no external dependency, fixes prose that is currently wrong |
| 3 | GBP rating automation | ~80 lines | Small, self-contained, no product-data dependency — can run in parallel |
| 4 | Merchant feed endpoint | ~150 lines | The real system; wants the primitive solid first |
| 5 | Feed skip-reasons diagnostic | ~30 lines | Ship with 4, not after — otherwise exclusions are invisible |

Items 2 and 3 are independent and could be done in either order. Item 4 is the
one with an external system attached, so it benefits from 1 being proven by two
other consumers before it leans on it.

### The dependency worth stating plainly

The feed's coverage equals **how many products have a definite price**. If
`_pps_defaults_price` is set on three products, the feed carries three products
regardless of how well it is written.

Which puts the product spawner (`docs/PRODUCT_SPAWNER_SPEC.md`) upstream of the
feed's *value*, though not of its construction. Build the feed whenever you
like — but it earns its keep once there is a catalogue behind it.
