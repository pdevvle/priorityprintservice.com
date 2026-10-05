# Order status updates — brief

**For:** the owner (decisions) and the Claude session that builds it.
**Written:** 2026-10-05. **Status:** proposal; nothing built yet.
**Meaning of "status updates" here:** telling customers, and staff, where a job is — received,
proof, printing, shipped, delivered — with the stages and wording changeable from the admin
screen, not in code.

---

## 1. What happens today (checked on production 2026-10-05)

| Moment | What the customer gets | What staff see |
|---|---|---|
| Pays | WooCommerce "Processing order" email | "New order" email, Job Ticket, order notes |
| Artwork, proof, printing | **Nothing** | Order notes; the daily exceptions email for problems |
| Label bought in Shippo | **Nothing.** Shippo moves the order to *Completed* and adds the tracking number as a **private** note ("ups 2nd Day Air® label with tracking number 1Z… has been created on Shippo", order 87334). The WooCommerce **"Completed order" email is switched off** (`woocommerce_customer_completed_order_settings.enabled = no`). | Private note with tracking |
| In transit / delivered | Nothing from the site. No Shippo tracking webhooks are registered, so the site never hears of delivery. | Nothing |
| Customer looks up an order (`[pps_order_lookup]`) | A status pill from the WooCommerce status: "Processing", "Completed"… | — |

So a customer hears from us once, when they pay. Unless Shippo is sending its own tracking
emails, they never get a tracking number. Check **Shippo → Settings → Notifications** to find
out. Every question this leaves open — "has it shipped?", "where's my proof?" — arrives as a
call or an email to the office.

**Quick win, available today with no code:** WooCommerce → Settings → Emails → **Completed
order** → enable. Customers then get an email when Shippo marks the order Completed. It will
not include the tracking number (that note is private), so it is a stopgap, not the fix.

## 2. What the system already knows

No new data entry is needed for most stages, because the order already carries:

- **Payment** — the WooCommerce status.
- **Artwork state** — self-approved and hash-bound, awaiting a staff proof, prepress review
  (NOT approved), art to be emailed later, Canva. All of these are on the Job Ticket.
- **Artwork on Google Drive** — `_pps_artwork_processed`.
- **Production start, must-ship and delivery dates** — `PPS-Production-Start` and
  `_pps_delivery_date`.
- **Shipped and tracking** — Shippo creates the label, and the tracking number is in its note.
- **Delivered** — available from Shippo tracking, once we subscribe to it.

## 3. Proposal

### 3.1 Stages are data, not code

A new admin screen, **PPS Calculators → Order Updates**, edits a list of stages stored in
`wp_options['pps_order_stages']`. That is the same pattern as FAQs and tooltips: authored on
production, versioned defaults in the repo.

Each stage has:

- **key**
- **customer label** — shown on the order lookup page
- **customer message** — an email template with placeholders: `{first_name}`, `{order}`,
  `{job}`, `{delivery_date}`, `{tracking_link}`, `{proof_link}`
- **email on/off** — whether reaching the stage sends the message
- **staff-only flag**

Proposed defaults, for the owner to edit:

| Stage | Customer label | Entered | Email by default |
|---|---|---|---|
| received | Order received | automatically on payment | already sent by WooCommerce today |
| artwork_check | Checking your artwork | staff proof or prepress review ordered | no |
| proof_sent | Proof ready for your approval | staff marks proof sent | **yes** (with proof link if any) |
| in_production | In production | automatically: artwork approved, on Drive, and production-start date reached | **yes** |
| finishing | Finishing | staff, optional | no |
| shipped | Shipped | **automatically when Shippo buys the label** | **yes, with tracking link** |
| delivered | Delivered | automatically from Shippo tracking | optional |
| ready_for_pickup | Ready for pickup | staff | yes |

### 3.2 Keep WooCommerce statuses as they are

Do **not** add custom WooCommerce order statuses such as `wc-printing`. Shippo imports and syncs
orders by WooCommerce status, and Stripe, reports and pi-edd all assume the core statuses. A
custom status risks silently stopping Shippo's import — the same class of failure the WCPA
checkout block was.

Instead, the stage lives alongside the status:

- order meta `_pps_stage`, plus a history `_pps_stage_log` of who set it, when, and what was
  sent;
- a **Stage** column in the Orders list, filterable;
- a dropdown on the order screen;
- **bulk actions** ("Set stage → In production" for 12 orders at once).

### 3.3 Most stages move by themselves

| Trigger | Stage |
|---|---|
| Payment (existing hooks) | received |
| Staff proof / prepress review on the order | artwork_check |
| Hourly cron: art approved + on Drive + production-start date reached | in_production |
| Shippo label note on the order, or a Shippo tracking webhook | shipped (tracking parsed from the note) |
| Shippo tracking webhook `DELIVERED` | delivered |
| Must-ship date passed, no label | not a stage: a line in the daily exceptions email ("late — tell the customer?"), plus an optional proactive "running a day late" email template |

Staff can always set a stage by hand. Stages only move forward unless staff override them.

### 3.4 Where customers see it

- **Email**, sent through WooCommerce's own mailer so it carries the site's branding. Each send
  is logged as an order note.
- **The order lookup page** shows a timeline of the stages reached, with dates and the
  tracking link, instead of the single status pill.
- **Optionally later:** text messages, through a provider the owner chooses.

### 3.5 Guardrails

- **Never the same stage email twice.** It is keyed on order + stage + line.
- **Multi-job orders** take the least-advanced job's stage for the order-level message.
  "Shipped" waits for every label, or says which job shipped.
- **Test mode.** A switch sends every customer email to the office instead. Use it for the
  first week.
- **Previews.** The admin screen previews each template filled in from a real recent order.
- **Failures are visible.** If an email cannot be sent, the order gets a note and the daily
  digest lists it, so nothing fails silently.
- **No credentials** in stage data. The Shippo webhook endpoint verifies a shared secret,
  stored server-side.

## 4. Phases

1. **Shipped email with tracking.** This is the biggest gap.
   - Parse Shippo's label note.
   - Set `_pps_stage = shipped`.
   - Send the "Shipped" template with the carrier tracking link.
   - Record a stage history.
   - Show the timeline on the lookup page.

   Gate: a PHP test replaying 87334's notes, including that it sends exactly once.
2. **The Order Updates admin screen**, plus the column, the order-screen dropdown and bulk
   actions. Add proof_sent and in_production, with the automatic in_production cron.
3. **Shippo tracking webhook** for delivered, plus the late-shipment line in the daily email
   and the optional "running late" template.

Each phase follows the usual pipeline: repo first, tests first run against the live code,
staging, then production by pinned SHA.

## 5. Decisions for the owner

1. Is Shippo already emailing customers tracking? (Shippo → Settings → Notifications.) If
   yes, phase 1 shrinks to the timeline only, or we turn Shippo's emails off and send our own,
   consistently branded.
2. Turn on WooCommerce's "Completed order" email now, as a stopgap?
3. Which stages, which of them email, and the wording. The table in §3.1 is a starting point.
4. Pickup orders: do any exist, and should they get "Ready for pickup"?
5. Text messages — wanted, or email only?
