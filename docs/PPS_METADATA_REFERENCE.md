# `_pps_metadata` — quick reference for the Imposition tool

The JSON blob every PPS calculator writes onto its WooCommerce **line item**.
It is the only record of what the customer actually ordered, and it is what the
imposition queue parses into a spec. This page is for having open beside
**PPS Calculators → Imposition**.

- **Where it lives:** order line item meta, key `_pps_metadata`, value is a JSON
  *string*. Hidden from the admin order screen (`pps-calculators.php` adds it to
  the hidden-meta list), so read it with `$item->get_meta('_pps_metadata')` or
  via the queue.
- **Who writes it:** `buildMetadata(config, result)` in each calculator HTML,
  posted as `pps_metadata` and stored verbatim by `pps-calculators.php:2728`.
- **Who reads it for imposition:** `pps_impose_list` in `pps-imposition.php`,
  then `specFromRow()` in `imposition-tool.html`.

> The plugin stores the string **unchanged**. A field missing from the blob is
> missing because that calculator never wrote it — not because something
> stripped it.

> **A condensed copy of this page ships inside the tool** — the "Order metadata
> reference" panel in `imposition-tool.html` (`MetaRef`), so the operator never
> has to leave the queue. Change one, change the other in the same commit.

---

## 1. The eleven fields imposition actually uses

Everything else in the blob is pricing, shipping and proofing history. These
are the only keys that reach the press:

| Key | Used for | Notes |
|---|---|---|
| `calcType` | which imposition scheme | **Flats + stickers only.** Saddle / perfect-bound / coupon don't write it — see §3. |
| `sizeMode` | trim cascade gate | `"preset"` means "ignore `longEdge`/`shortEdge`". |
| `longEdge`, `shortEdge` | trim size | Flats. Already long ≥ short. |
| `customLong`, `customShort` | trim size | Booklets with a custom size. |
| `sizeLabel` | trim size (last resort) **and saddle orientation** | e.g. `"8.5×11 Letter"`. The only place page orientation survives. |
| `bindDir` | saddle orientation fallback | `"short"` \| `"long"`. |
| `sides` | 1 or 2 | Flats. Booklets are always 2. |
| `totalQty` / `qty` / `sets[].qty` | run length | First non-zero wins; see §4. |
| `jobName` | output filename + slug | Flats only; booklets fall back to the product name. |

---

## 2. How the trim size is derived (three tries, in order)

`pps-imposition.php` — the first branch that matches wins:

```php
1. sizeMode !== 'preset'  AND  longEdge AND shortEdge   →  use them
2. customLong AND customShort                           →  use them
3. sizeLabel matches /(\d+(\.\d+)?)\s*[×x]\s*(\d+…)/    →  use the two numbers
   then: if (long < short) swap                         →  normalised to long/short
```

**That final swap is the trap.** It throws away orientation. For flats that is
correct — a 6×4 postcard and a 4×6 postcard are the same press job. For saddle
stitch they are different products, because **the spine sits on the height
edge**.

So the client re-derives width × height for saddle only (`specFromRow`):

```js
let w = row.long_edge, h = row.short_edge;          // flats: long × short
if (row.calc === "saddle") {                        // saddle: width × height
  const m = String(row.size_label).match(/([\d.]+)\s*[×x]\s*([\d.]+)/);
  if (m) { w = +m[1]; h = +m[2]; }                  // sizeLabel keeps the real order
  else if (row.bind_dir !== "short") { w = row.short_edge; h = row.long_edge; }
}
```

**Consequences worth knowing at the press:**

- A saddle item whose `sizeLabel` has no `×` **and** whose `bindDir` is missing
  falls through to `long × short` — i.e. **landscape**. A 5.5×8.5 booklet then
  images as 8.5×5.5: layout `1 × 2` instead of `2 × 1 rotated`, every page
  turned 90°.
- Since v1.21 the engine **refuses** that job rather than printing it —
  *"Page orientation mismatch: the artwork's pages are 5.5×8.5″ (portrait) but
  the spec says 8.5×5.5″ (landscape)."* Fix the spec, not the artwork.
- Sanity check in the queue: for a portrait booklet the panel must read
  **`2 × 1`**, never `1 × 2`.

---

## 3. Calc type: newer calculators stamp it, older ones don't

| Calculator | `calcType` in blob? | Resolved how |
|---|---|---|
| Brochure / flat | ✅ `"brochure"` | Straight from the blob |
| Postcard, letterhead, greeting card, sticker | ✅ | Straight from the blob |
| **Saddle stitch** | ❌ | Product registry → calculator filename → calc type |
| **Perfect bound** | ❌ | Same fallback |
| **Coupon book** | ❌ | Same fallback |

`pps_impose_calc_type()` does the fallback. **If a product is missing from
`pps_get_registry()`, a booklet order shows `NO SPEC` in the queue** even though
its metadata is perfectly good — the calc type is the missing piece, not the size.

Imposition support: flats + stickers + **saddle** are supported;
`perfect-bound` and `coupon` refuse cleanly (`UNSUPPORTED_BOOKLETS`, v2 work).

---

## 4. Quantity

```php
$qty = totalQty ?? qty ?? 0;
if (!$qty && is_array(sets))  $qty = array_sum(column(sets, 'qty'));
if (!$qty)                    $qty = $item->get_quantity();
```

Booklets carry quantity **per set**, not at the top level — a 3-set booklet
order has three `sets[]` entries and the queue shows their sum. The imposed
sheet count in the slug is derived from this, so a wrong `qty` means a wrong
run figure on the press sheet, nothing more.

---

## 5. Sample blobs

### Flat (brochure) — the complete shape

```json
{
  "calcType": "brochure",
  "calcLabel": "Brochure / Flat Print",
  "jobName": "Spring menu",
  "qty": 500,
  "sizeMode": "preset",
  "sizeLabel": "8.5×11",
  "longEdge": 11,
  "shortEdge": 8.5,
  "foldType": "trifold",
  "foldDir": "long",
  "frontColor": "4/4",
  "backColor": "4/4",
  "sides": 2,
  "paper": "100lb-gloss-text",
  "vivid": false,
  "coating": "none",
  "coatSides": 1,
  "bundling": "none",
  "perforation": false,
  "perfDir": "",
  "perfPositions": [],
  "roundCorner": false,
  "artwork": "upload",
  "bleed": true,
  "proof": "pdf",
  "proofAddrSame": true,
  "proofAddr": {},
  "canvaLink": "",
  "canvaInstructions": "",
  "shipState": "CA",
  "shipZip": "92101",
  "shipAddr": { "line1": "…", "city": "…", "state": "CA", "zip": "92101" },
  "needByDate": "2026-09-15",
  "estWeightLb": 22.4,
  "estCartons": 1,
  "transitDays": 3,
  "productionBizDays": 4,
  "requestedBizDays": 7,
  "freeDeliveryBizDays": 7,
  "rushMultiplier": 1,
  "rushCost": 0,
  "productionStartDate": "2026-09-02",
  "mustShipByDate": "2026-09-10",
  "estimatedDeliveryDate": "2026-09-15",
  "total": 812.40,
  "baseTotal": 812.40,
  "perUnit": 1.62,
  "totalQty": 500,
  "days": 7,
  "debug": { }
}
```

### Saddle stitch booklet — note what is **absent**

```json
{
  "sizeLabel": "5.5×8.5",
  "customLong": 0,
  "customShort": 0,
  "bindDir": "short",
  "sets": [ { "qty": 100, "pages": 36, "name": "" } ],
  "insideColor": "4/4",
  "coverColor": "4/4",
  "insidePaper": "80lb-gloss-text",
  "insidePaperType": "text",
  "coverMode": "self",
  "coverPaper": "",
  "coverPaperType": "cover",
  "twoStaple": false,
  "vividPrint": false,
  "coating": "none",
  "bundling": "none",
  "roundCorner": false,
  "artwork": "upload",
  "artEditPages": {},
  "bleed": true,
  "proof": "pdf",
  "pageTransforms": { "0": { "fitMode": "fit", "artScale": 1, "rotateSteps": 0 } },
  "artDims": { "wIn": 5.5, "hIn": 8.5 },
  "shipState": "CA",
  "shipZip": "92101",
  "shipAddr": { },
  "needByDate": "2026-09-15",
  "estWeightLb": 41.0,
  "estCartons": 2,
  "transitDays": 3,
  "productionBizDays": 5,
  "requestedBizDays": 8,
  "freeDeliveryBizDays": 8,
  "rushMultiplier": 1,
  "rushCost": 0,
  "productionStartDate": "2026-09-02",
  "mustShipByDate": "2026-09-10",
  "estimatedDeliveryDate": "2026-09-15",
  "total": 1480.00,
  "baseTotal": 1480.00,
  "perUnit": 14.80,
  "totalQty": 100,
  "days": 8,
  "debug": { }
}
```

**No `calcType`. No `jobName`. No `sizeMode`. No `longEdge`/`shortEdge`. No
`sides`.** All five are derived or defaulted downstream — which is exactly why a
saddle row depends on the registry and on `sizeLabel` more than a flat does.

Perfect bound is the same shape as saddle. Coupon adds `bindStyle`,
`sidesPrinted`, `frontColor`/`backColor`.

---

## 6. Queue row → where each column comes from

| Queue column | Source |
|---|---|
| Order # | `$order->get_id()` — links to the Drive folder |
| Job | `jobName`, else the product name |
| Spec | `calcType` (or registry) · derived long×short · `sides` · derived qty |
| Artwork | Drive folder listing, `*_print-ready.pdf` preferred |
| Imposition | Drive listing for `IMPOSED_Order-<id>-i<item>_…` |
| Order status | `$order->get_status()` — live WooCommerce status, editable |

---

## 7. Things that bite

1. **`long_edge`/`short_edge` in a queue row are always normalised.** Never read
   them as width/height for a booklet. `sizeLabel` is the only orientation record.
2. **Booklet metadata has no `calcType`** — a registry gap reads as `NO SPEC`.
3. **`sets[]` is per set**, so `qty` at the top level may be absent on booklets.
4. **`debug` is deliberately ignored** by the plugin (`pps-calculators.php:1790`)
   — never derive production values from it.
5. **The blob is the customer's order record.** Editing it by hand rewrites what
   was bought. To change how something images, change the spec in the tool for
   that run; don't edit metadata.
6. **`artDims`** (booklets) records the artwork's own measured page size at order
   time — useful for confirming an orientation dispute against what was uploaded.

---

## See also

- `docs/IMPOSITION_TOOL.md` — the engine, layout rules, every spec option
- `pps-imposition.php` — `pps_impose_list()` for the parse, endpoints for the queue
- `imposition-tool.html` — `specFromRow()` for row → engine spec
