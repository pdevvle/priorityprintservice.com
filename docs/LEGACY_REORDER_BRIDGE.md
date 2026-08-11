# Legacy reorder bridge — design

> Part of a larger pattern — see `docs/ORDER_INGEST_ARCHITECTURE.md`, which
> generalises this envelope to orders placed outside WooCommerce entirely
> (QuickBooks invoices, phone orders). Same envelope, same reorder payoff.

**Problem.** A WCPA-era order line whose product was retired from the 3.0
catalog cannot be reordered: there is no product row to add to the cart, and
no calculator that owns it. Today those cards render with a disabled action
(`ffe49b2`) and route to Contact Us. This design gives them a real path, and
does it in a way that **improves automatically as calculators are added**
rather than needing a migration each time.

## Two paths, one payload

| Path | When | Price | Editable |
|---|---|---|---|
| **1:1 replay** | Always available | Frozen at the original unit price | No |
| **Edit & re-quote** | When the specs map onto a live calculator | Re-quoted at today's prices | Yes, fully |

Both read the same stored envelope. The second path is the one that grows:
a spec set that maps to nothing today maps to a calculator the day that
calculator ships, with no change to the stored data.

## The envelope

Written once, at the moment a legacy item is replayed, onto the cart item
(and copied to the new order item at checkout) as `_pps_legacy_spec`:

```jsonc
{
  "v": 1,
  "source": {
    "order_id": 86418, "item_id": 129931, "order_date": "2026-01-16",
    "product_id": 22872, "product_name": "Small Booklet Printing"
  },
  "raw": {                                  // verbatim WCPA meta — never lossy
    "Booklet Finished Size": "5.5 x 8.5",
    "Total Page Count (Including Cover)": "24",
    "Insides Paper Type": "100lb Gloss Text",
    "Preformatted Quantity": "250"
  },
  "canon": {                                // pps_reorder_field_whitelist() keys
    "sizeMode": "preset", "sizeLabel": "5.5×8.5",
    "sets": [ { "qty": 250, "pages": 24 } ],
    "insidePaper": 0.003, "insideColor": "full"
  },
  "unmapped": [ "Some Legacy Field" ],      // honest record of what didn't map
  "calc": "saddle",                         // best target, or null
  "confidence": "high"                      // high | partial | none
}
```

Three properties matter:

1. **`raw` is never discarded.** Production and support always have the
   original words, whatever the translator did or didn't understand.
2. **`canon` speaks the calculators' own language** — the exact key set from
   `pps_reorder_field_whitelist()`, which is already the documented contract
   ("if changing it changes the price, it belongs in this list"). It is the
   same shape `PPS_CONFIG.reorder` carries, so any calculator that can
   restore an edit can restore a legacy replay. No adapter per calculator.
3. **`unmapped` + `confidence` make the gap visible** instead of silently
   quoting a wrong spec.

## The translator, and why it self-updates

`pps_legacy_translate( array $raw, $product ) : array` is two declarative
tables plus live config — no per-product code.

**Table A — label → canonical field.** Keyed by a normalized match on the
WCPA label (lowercased, punctuation stripped), so near-miss label variants
across years of WCPA forms collapse to one rule:

```php
'booklet finished size'   => array( 'size',    'sizeLabel' ),
'total page count'        => array( 'int',     'sets.0.pages' ),
'preformatted quantity'   => array( 'int',     'sets.0.qty' ),
'insides paper type'      => array( 'paper',   'insidePaper' ),
'cover paper type'        => array( 'paper',   'coverPaper' ),
'insides print color'     => array( 'color',   'insideColor' ),
'cover print colors'      => array( 'color',   'coverColor' ),
```

**Table B — value resolvers**, one per type (`size`, `paper`, `color`,
`int`, `bool`, `enum`). This is where the self-updating lives: `paper`
does **not** hold a hardcoded label→value map. It resolves against
`pps_get_config()`'s live paper rows, matching on label with a normalized
comparison and a weight/finish fallback. So:

- Retire or rename a stock in Central Config → the resolver follows it.
- Add a stock → legacy orders naming it start resolving immediately.
- The same resolver serves every calculator, because every calculator's
  papers come from the same config rows.

`size` resolves against the calculator's own size list the same way, falling
back to `sizeMode: "custom"` + parsed `customLong`/`customShort` when no
preset matches — which is exactly what a customer would have had to do
anyway.

**Everything unresolved lands in `unmapped`.** A resolver never guesses; a
miss is recorded, not defaulted. `confidence` is then mechanical: `high` when
every price-affecting field resolved, `partial` when the identity fields
(size, quantity, pages) resolved but trim did not, `none` otherwise.

## Choosing the target calculator

`calc` resolves in three steps, cheapest first:

1. **Operator override** — `wp_options['pps_legacy_calc_map']`, keyed by
   legacy product ID → calc slug. One row fixes one product forever, editable
   in admin, no deploy.
2. **Registry inheritance** — if the legacy product shares a `product_cat`
   with a product that *is* in `pps_get_registry()`, inherit that calc.
   This is the rule that makes new calculators retroactive: the day a family
   gets a calculator and one product joins the registry, every legacy order
   in that category becomes editable.
3. **Field fingerprint** — the shape of `raw` itself. "Booklet Finished
   Size" + "Total Page Count" ⇒ a booklet; a fold field ⇒ brochure. Last
   resort, and it only ever produces `partial` confidence.

If all three miss, `calc` is `null`, the card offers 1:1 replay only, and
nothing is lost — the envelope still holds `canon`, so re-running the
resolver later (a nightly pass, or on next view) can promote it.

## What the customer sees

- **`confidence: high` + `calc`** — "Reorder" opens that calculator
  prefilled and re-quoted at today's prices. Same experience as a modern
  reorder.
- **`partial`** — same, but the calculator opens with a notice naming the
  fields we could not carry ("Confirm your paper — we couldn't match
  *60lb Vellum Bristol* to a current stock"), so the customer corrects one
  field instead of rebuilding the order.
- **`none` / no calc** — "Reorder (same as before)" replays 1:1 at the
  frozen price, or Contact Us for a manual re-quote.

## The 1:1 replay mechanics

- One hidden placeholder product ("Prior Order Reorder"), created once, ID
  in `wp_options['pps_legacy_placeholder_id']`, `_virtual = yes`, excluded
  from catalog and search.
- Cart line carries `pps_legacy_unit_price` (the existing frozen-price hook
  already honours it), the original item name for display, and the envelope.
- At checkout the envelope's `raw` lines are written onto the new order item
  as visible meta, plus a `Reorder of #86418` provenance note — so wp-admin,
  the packing surface and Missive read like the WCPA orders production
  already knows.

**Frozen-price guardrail.** Replaying a January price in August is a real
exposure. Recommended policy: replay freely inside a configurable window
(`pps_legacy_price_freeze_days`, default 180); beyond it, the button becomes
"Request this again" → Contact Us with the specs prefilled. Cheap to
implement, and it keeps a 2019 price from walking into today's cart.

## Known limits

- **Artwork.** The envelope carries the artwork *reference*, not the file.
  Uploads older than the retention policy may be gone; production pulls from
  Drive or asks the customer. The card should say so rather than implying
  the art is attached.
- **Reporting.** 1:1 replay lines attribute to the placeholder product, not
  the retired one. `source.product_id` preserves the truth for anyone who
  needs it.
- **The label table needs a real sample pass.** The rules above are drawn
  from the field names visible on a WCPA order (`Booklet Finished Size`,
  `Total Page Count (Including Cover)`, `Insides Print Color`, `Cover Print
  Colors`, `Insides Paper Type`, `Cover Paper Type`, `Preformatted
  Quantity`) — but not from their **value formats**, which no one has
  sampled. Before shipping the translator, dump `raw` for ~30 legacy orders
  across product families and let the actual strings drive Table B. Building
  the resolvers against guessed formats is the one way this design fails
  quietly.

## Build order

1. Envelope + 1:1 replay + placeholder product. Self-contained, ships the
   moment the connector returns, fixes 86418-class orders immediately.
2. Sample pass: dump `raw` across legacy orders; write Table A/B from real data.
3. Translator + `calc` resolution + the edit path.
4. Promotion pass: re-resolve stored envelopes when a calculator is added.
