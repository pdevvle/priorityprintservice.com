# Order ingest — every order in WooCommerce, wherever it was sold

**Goal.** One row in WooCommerce for every order the shop takes, including
the ones invoiced through QuickBooks, paid by a QuickBooks quick-pay link,
quoted by email, or taken over the phone. WooCommerce stops being "the
website's orders" and becomes the order and production record for the whole
business.

**The frame that keeps this safe:**

> **WooCommerce is the system of record for _orders and production_.
> QuickBooks and Stripe remain the systems of record for _money_.**

Ingested orders are *records of a sale that already happened elsewhere*.
They must never run a payment, never chase a payment, and never be the
authority on what was collected. Every design decision below follows from
that sentence.

## Why this is the same project as the reorder bridge

`docs/LEGACY_REORDER_BRIDGE.md` defines a **spec envelope** — `raw`,
`canon`, `unmapped`, `calc` — for order lines whose product no longer
exists. An off-platform order is structurally identical: order facts with no
WooCommerce row behind them. Give both the same envelope and they share one
payoff — **a customer who ordered by phone in 2024 and paid a QuickBooks
invoice can log into `/reorders/` and reorder it**, because the line carries
canonical specs the calculators can read.

So: one envelope, one ingest path, four sources.

## One contract, four sources

`POST /pps/v1/orders/ingest` — authenticated, idempotent, accepts a
normalized order document and returns the created (or previously created)
order ID.

| Source | How it arrives | Specs? | Reorderable? |
|---|---|---|---|
| **Admin calculator** | Staff configures a job in wp-admin, hits *Create Order* | Full `canon` | Yes |
| **QuickBooks** | Make.com scenario on invoice/payment → endpoint | Usually description only | Only if specs added |
| **CSV backfill** | One-off importer for history | Whatever the file has | Depends |
| **Legacy replay** | The reorder bridge | `canon` from translator | Yes |

### The order document

```jsonc
{
  "source": "quickbooks",                       // quickbooks|manual|legacy|web
  "external": { "system": "quickbooks", "doc_type": "invoice",
                "id": "1042", "number": "INV-1042", "url": "https://..." },
  "idempotency_key": "quickbooks:invoice:1042", // REQUIRED
  "customer": { "email": "...", "name": "...", "company": "...",
                "phone": "...", "billing": {...}, "shipping": {...} },
  "items": [ {
      "name": "Saddle stitch booklet — 5.5×8.5, 24pg",
      "qty": 1, "unit_price": 450.00, "total": 450.00,
      "spec": { "raw": {...}, "canon": {...}, "calc": "saddle",
                "confidence": "high" },      // same envelope as the bridge
      "artwork": { "drive_folder": "...", "files": [...] }
  } ],
  "totals": { "subtotal": 450.00, "shipping": 0, "tax": 0,
              "total": 450.00, "currency": "USD" },
  "payment": { "state": "paid", "method_title": "QuickBooks Invoice INV-1042",
               "paid_at": "2026-08-01T10:00:00Z", "amount": 450.00 },
  "dates": { "placed_at": "2026-07-28", "due_at": "2026-08-11" },
  "production": { "start": "2026-07-29", "due": "2026-08-05" },
  "notes": "Phoned in by Tina; artwork emailed."
}
```

Every ingested order also gets `_pps_order_source`, `_pps_external_key`,
`_pps_external_url` as order meta — so admin, Make, Sheets and Missive can
all tell at a glance where an order came from and jump to the source
document.

## The five decisions that make or break this

### 1. Idempotency is mandatory, not optional

`idempotency_key` is looked up in order meta before anything is created. A
repeat returns the existing order ID and changes nothing. Without this, one
Make scenario retry — or one operator re-running a backfill — silently
doubles your books. This is the single highest-risk failure mode in the
whole design, and it is cheap to prevent.

**Loop guard, same idea, other direction.** If you later push Woo orders
*into* QuickBooks, that scenario must skip any order carrying
`_pps_external_key` — otherwise a QB invoice becomes a Woo order becomes a
QB invoice.

### 2. Payment must be inert

Ingested orders are created at their final status (`processing` or
`completed`) with a **synthetic payment method** — `method_title` is
descriptive text ("Paid via QuickBooks — INV-1042"), not a gateway. No Woo
payment processing runs, no "pay now" link is valid, and the order is never
`pending`. A customer who already paid a QuickBooks invoice must never
receive a WooCommerce payment reminder.

### 3. Customer emails off by default

The customer already got a QuickBooks invoice and receipt. A WooCommerce
"Order received" on top of it reads as a double charge. Default: suppress
all customer-facing emails for ingested orders, per-source toggle if you
ever want the Woo confirmation to be the customer's copy. Admin/new-order
notifications stay on — you *do* want the production side to see it.

### 4. Woo revenue will stop matching Stripe deposits — on purpose

Once invoiced work lands in Woo, WooCommerce Analytics reports the whole
business, not just the website. That is the goal, but it must be a conscious
change: your Woo revenue number will exceed Stripe settlements by exactly
the invoiced volume. Two options, and I'd take the first:

- **Include, and segment.** Add a source filter/column so you can read
  "web only" when you want the old number. Woo becomes the true top line.
- **Exclude.** Filter `_pps_order_source != web` out of reports; Woo stays
  a website-revenue report and the ingested orders are records only.

Whichever you pick, pick it before backfilling history, because the choice
changes what every historical chart says.

### 5. Customer identity: match by email, stay guest

Match an existing Woo customer by email; otherwise create the order as a
**guest order with the billing email set** rather than minting an account.
This matters because `/reorders/` guest lookup works off billing email —
so an invoiced customer can find and reorder their job without ever having
had a website account. Creating accounts instead would sprawl the user
table and change nothing for the better.

## The admin calculator — the piece that pays for itself

The highest-value entry point, and it reuses the entire stack you already
built:

> wp-admin → **PPS Calculators → New Order** → pick a calculator → configure
> the job exactly as a customer would → enter/lookup the customer → **Create
> Order** (paid elsewhere / invoice to follow / send payment link).

Because it *is* the calculator, you get for free: correct pricing from the
live engine, the full `pps_metadata` + PPS-Spec production string, delivery
date computation, Google Drive artwork upload, and a `canon` spec envelope
that makes the order reorderable by the customer later. Phone and email
orders stop being double entry — one pass in the calculator produces both
the Woo record and (via Make → QuickBooks) the invoice.

This is also the only path that reliably produces *reorderable* off-platform
orders, which leads to the honest limitation below.

## What QuickBooks import can and cannot give you

A QuickBooks invoice line is usually prose — "Booklet printing, 250 qty" —
with no structured size, paper, or page count. So for QB-sourced orders the
envelope will typically be `raw` only, `canon` empty, `calc` null. Those
orders are **records**: they show in the customer's history, in Woo
analytics, in production's queue. They are **not reorderable** until someone
gives them specs.

That is not a flaw to engineer around — it is the honest split:

- Want it **on the books**? QuickBooks import is enough.
- Want it **reorderable**? Enter it through the admin calculator.

A "add specs to this order" admin action (open a calculator, save `canon`
back onto the existing order) closes the gap for any historical order worth
upgrading, one at a time, by choice.

## Transport: use Make, not a new QuickBooks integration

You already run Make with a linked WooCommerce connection and existing
QuickBooks scenarios. Building a direct QuickBooks Online API client here
would mean an Intuit developer app, OAuth2, token refresh, and another
credential store on the server — for a job Make already has the connectors
for.

Recommended: **QuickBooks (invoice created / payment received) → Make →
`POST /pps/v1/orders/ingest`.** Make owns the QB credentials and the retry
behaviour; the site owns one authenticated endpoint. If Make ever goes away,
the endpoint contract is unchanged and only the transport is rewritten.

Auth: a dedicated application password or a shared-secret HMAC header,
scoped to this endpoint, rate-limited, and logged. It creates financial
records — treat it like a payment webhook, not like a contact form.

## Backfill

Decide the horizon deliberately: **from a date forward** (clean, fast, and
Woo analytics stay interpretable) or **all history** (complete customer
records, but every historical chart changes shape). The CSV importer runs
dry-run first, reports what it would create and what it would skip as
duplicates, and only then writes. Idempotency keys make a re-run safe.

## Build order

1. **Ingest endpoint + envelope + idempotency + inert payment + email
   suppression.** The foundation every source needs. Testable with a curl
   payload before any integration exists.
2. **Admin calculator → Create Order.** Highest daily value; kills double
   entry; produces reorderable specs.
3. **Make scenario: QuickBooks → ingest.** Record keeping for invoiced work.
4. **Reorder bridge on top** (`docs/LEGACY_REORDER_BRIDGE.md`) — now serving
   legacy WCPA orders *and* off-platform orders through one path.
5. **CSV backfill** for history, once the horizon decision is made.

## Open questions for the owner

1. **Analytics:** should invoiced orders count in Woo revenue reports
   (segmented), or be excluded from them?
2. **Backfill horizon:** from a date forward, or all QuickBooks history?
3. **Direction:** do you also want Woo orders pushed *into* QuickBooks
   (invoice generation), or is the flow one-way into Woo?
4. **Wave:** the Make account carries Wave scenarios too — is Wave still
   live, and does it need the same pipe, or is QuickBooks now the only
   money system?

---

# The outbound leg — high-value orders route to a QuickBooks invoice

**Requirement (owner, 2026-08-11):** an order placed on the site above a
threshold (~$1,500) should not be charged by card at checkout. It should be
invoiced through QuickBooks, with a quick-pay link emailed to the customer.

This is the same loop running the other way, and it closes it: order born in
Woo → invoice in QuickBooks → payment in QuickBooks → payment state written
back to Woo. Woo still holds the order and the specs; QuickBooks still holds
the money.

## Why: chargeback protection, not fees

**Owner's motivation (clarified 2026-08-11):** these customers *want to pay
by card*. Routing the payment through QuickBooks Payments is about availing
the shop of QBO's chargeback protection on large card transactions. The
higher processing cost is accepted deliberately — it buys transfer of
dispute risk on the orders where a chargeback hurts most.

That inverts the usual fee logic, so state it plainly: **this feature costs
money on purpose.** A $3,000 card payment through QBO runs roughly $87–105
in processing versus ~$5 on the Stripe ACH rail already at checkout. The
premium is the insurance. (ACH remains the cheap option for customers who
volunteer it, and is worth offering alongside — but it is not what this
feature is for.)

### Verify the coverage before building on it

One caveat worth an email to Intuit before this gets built, because it
decides whether the pipeline is worth it:

**Chargeback-protection programs generally cover _fraud_ disputes — stolen
card, "I didn't authorize this" — and generally exclude "item not as
described" and "item not received."** For a custom print shop, the second
category is the likelier dispute: a customer unhappy with colour, trim, or
turnaround. If QBO's protection excludes those, the pipeline transfers a
risk you rarely face and leaves the one you actually face.

Confirm with Intuit, in writing: which dispute reason codes are covered,
whether coverage applies to **quick-pay links** or only to sent invoices,
any per-transaction or annual cap, eligibility requirements, and what
evidence the shop must retain. Terms change; do not build on a marketing
page.

### The defence you already own

Independent of processor, the strongest chargeback evidence for custom print
is the approval trail this system already produces: the proof modal's
approval package — raw file, print-ready PDF, preview JPEGs with guides, and
the manipulation manifest — plus timestamped customer approval and the
PPS-Spec the job was produced against. That set answers "not as described"
directly, which is the category protection programs usually decline.

Worth ensuring those artifacts are retained beyond the uploads-retention
window for orders above the invoice threshold — a dispute can arrive 60–120
days after delivery, and the retention policy currently thins uploads on a
schedule that predates this requirement.

## Checkout routing

A `woocommerce_available_payment_gateways` filter keyed on the order total:

- **Below threshold** — unchanged.
- **At or above** — expose the invoice method. Two postures, owner's call:
  - **Mandatory** (card hidden): guarantees the routing, but a customer who
    wanted to pay now and go can no longer self-serve — some will bounce.
  - **Preferred** (invoice pre-selected, card still available): no lost
    orders, most large orders still land on the cheap rail.

Threshold lives in Central Config (`invoice_threshold`, default 1500) and
is evaluated against the **order total the customer would be charged** —
including shipping and rush, since that is the number the fee is taken on.

**Rush is the exception worth carving out.** A $2,000 order needed in three
days cannot wait on an invoice-and-check cycle. Recommended: orders carrying
a rush charge stay on card regardless of total, or the checkout says plainly
that production starts when payment is received.

## The two traps

### 1. The delivery date becomes a lie

The calculator quotes "Estimated Delivery: Aug 27" from production starting
*now*. An invoiced order may sit four days awaiting a check or an ACH
settlement. The quoted date is then wrong by exactly that delay, and the
customer holds a screenshot of it.

Fix both ends: at checkout, invoiced orders show **"Delivery calculated from
the date payment is received"** instead of a hard date; on payment, the
delivery date is **recomputed** from the payment date and written to the
order (the machinery already exists — `pps_quoted_delivery_date` and the
production-start meta). Without this, every large order becomes a support
ticket.

### 2. WooCommerce will cancel the order out from under you

Unpaid Woo orders sit `pending`, and WooCommerce's stock-hold setting
auto-cancels `pending` orders after N minutes. An invoiced order waiting a
week must be exempt — use `on-hold` (which is not auto-cancelled) rather
than `pending`, and confirm the hold-stock minutes setting.

## Flow — and why "email them a link" is the wrong default

The customer is at checkout with a card in hand, ready to pay. Sending them
away to wait for an email is where high-value orders get abandoned — they
came to buy, and the site just told them to check their inbox. Since the
goal is routing the *card* payment through QBO (not collecting a check), the
design should keep the pay-now moment intact and simply move it to QBO's
rail:

1. Checkout above threshold → order created **`on-hold`**, payment method
   "Card — QuickBooks", `_pps_payment_route = qbo_card`.
2. Artwork still uploads to Drive; PPS-Spec still written; production queue
   shows it as **awaiting payment**.
3. Invoice created in QuickBooks against the matched customer — PPS spec
   summary in the line description, Woo order number in the memo (that
   number is also the loop guard).
4. **Customer is handed straight to the QBO payment page** and pays by card
   in the same session. The QuickBooks invoice email is the *fallback* — it
   still goes, so an interrupted customer can finish later, and QBO handles
   reminders.
5. QuickBooks payment → Make → `POST /pps/v1/orders/payment` → order moves
   to `processing`, delivery date recomputed from the payment date,
   production start stamped, customer gets the normal "in production" notice.

### The transport tension this creates

Step 4 wants the payment link *during* checkout, which conflicts with the
async Make transport recommended for ingest. Three options:

| Option | UX | Infrastructure |
|---|---|---|
| **A. Direct QBO API from PHP** | Best — instant redirect | Intuit developer app, OAuth2, token refresh, credential storage on the server |
| **B. Make + interstitial** | Good — "preparing your secure payment link" page polls, auto-redirects when Make delivers (typically seconds) | None beyond the existing Make bus |
| **C. Email only** | Worst for a card-in-hand customer | None |

**Recommended: B.** It keeps the single Make transport, needs no OAuth app,
and the interstitial reads as a normal payment step rather than a dead end.
Escalate to A only if the observed wait proves too long. C stays as the
fallback path inside B — if the link has not arrived in ~30 seconds, the
page says the invoice is on its way by email and the order is safe.

### Refunds move to QuickBooks

An order paid through QBO cannot be refunded from the WooCommerce order
screen — the money never touched Stripe. Woo's refund button on these
orders would record a refund that never happens. Mark them clearly in
admin ("Paid via QuickBooks — refund in QBO") and record the QBO refund
back onto the Woo order through the same payment endpoint, so the order
history stays truthful.

## The loop guard stops being hypothetical

This design creates QuickBooks invoices *from* Woo orders while the ingest
design creates Woo orders *from* QuickBooks invoices. Without a guard, one
$1,500 order becomes an invoice becomes a second order, forever.

The rule, enforced on both sides:

- An invoice created from a Woo order carries the Woo order number in a
  field the ingest scenario reads. **Ingest skips any invoice that has one.**
- A Woo order created by ingest carries `_pps_external_key`. **The invoice
  scenario skips any order that has one.**

Both directions must ship together, or neither. This is the single most
important line in this document.

## Config knobs

| Key | Default | Meaning |
|---|---|---|
| `invoice_threshold` | 1500 | Order total at/above which invoicing engages |
| `invoice_posture` | `mandatory` | `preferred` \| `mandatory` — mandatory is the point when the goal is dispute protection: leaving Stripe available means the risky orders keep landing on the unprotected rail |
| `invoice_rush_bypass` | `true` | Rush orders stay on card |
| `invoice_production_start` | `on_payment` | `on_payment` \| `on_order` (credit risk) |

`invoice_production_start` is a **business decision, not a setting to
default blindly**: starting production before payment on a $1,500+ job
extends credit to a customer who has not paid. Safe default is
`on_payment`; known institutional accounts are the argument for the other.
