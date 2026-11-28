# Missive side panel: brief for a dedicated session

Written 2026-11-28. The panel is the shop's production desk inside Missive (the shared inbox
where every order conversation lives). Read this, then the five rows of `CLAUDE.md`'s file table
it points to, before changing anything. Those rows are the detailed record of every owner
decision. This brief is the map.

---

## 1. What it is

A Missive **custom iFrame integration** that loads a page from the WordPress site
(`/?pps_missive_panel=1&k=<panel key>`). It finds order numbers in the open conversation and
shows, beside the email:

| Surface | What the team uses it for | File |
|---|---|---|
| **Order card** | The open order's jobs as coloured spec pills, the Job Ticket, the production window (start → must-ship → delivery), paper, "run together" jobs on the same paper and sheet, Ship to (with Change), Google Drive folder and files | `pps-missive-panel.php` |
| **Subtasks** | Each job paired with its Missive subtask: state (Start / Done / Reopen), an editable due-date pill (4pm / 5pm / 7pm), "Create N subtasks — one per job" | `pps-missive-panel.php` |
| **Board** (no order open) | Every open job, sortable and groupable, with filters for status, ship date, paper, add-ons, rush and late start, plus Missive-only tasks that have no open order | `pps-missive-panel.php` |
| **Ship page** | Shippo rates from every active carrier account, label purchase (many boxes, signature, insurance), Copy tracking, and an address check against Shippo's order | `pps-missive-ship.php` (+ `pps-shipto.php` for address changes) |
| **Impose** | The imposition tool in a frame, scoped to the one order | `pps-missive-impose.php` → `imposition-tool.html` |
| **📊 Analytics** | Production Analytics: intake, fulfilment, sheets, paper + colour cost, and labels bought per day | `pps-analytics.php` |

Order 87347 and similar numbers in the conversation are matched; phone numbers and sheet counts never are.

## 2. Where everything stands (checked against production 2026-11-28)

| File | Live bytes | Repo source | Owner |
|---|---|---|---|
| `pps-missive-panel.php` | 104,898 | `claude/woocommerce-domain-search-ly4vff` @ `fde58ae` (identical) | this work |
| `pps-missive-ship.php` | 73,321 | same branch @ `fe818b6` (identical) | this work |
| `pps-shipto.php` | 20,397 | same branch @ `7cbc9dd` (identical) | this work |
| `pps-analytics.php` | 49,667 | same branch @ `a1d723b` (identical) | this work |
| `pps-missive-impose.php` | **17,930** | **`claude/imposition-tool-google-drive-185wyl` @ `8d24670`** (this branch's copy, 17,122, is older) | **the imposition session** |
| `imposition-tool.html` / `pps-imposition.php` | 576,261 / 41,429 | imposition branch (tool v1.68) | the imposition session |

**The bridge now belongs to the imposition branch.** On 2026-11-24 it added the Brief A row
fields (`raw_hash`, `proof_profile`) and the `RETOUCHED_` upload prefix. Never deploy this
branch's `pps-missive-impose.php` over the live one. Change it on the imposition branch, or bring
`8d24670`'s copy here first.

**Switches on production right now:**
- Change subtask state + edit due dates: **on** (`pps_missive_allow_state` = 1).
- Create subtasks: **on** (`pps_missive_allow_create` = 1). Four jobs have panel-made subtasks so far (`pps_missive_item_tasks`).
- Change Ship to: on by default, since `pps_missive_allow_address` is unset (unset = on).
- Buy labels: **on** (`pps_missive_ship.allow_buy` = true). Ship-from is Anthem AZ, phone and Office@ set.

**Tests, all passing on 2026-11-28:**
- `tools-missive-panel-test.php`: 169
- `tools-missive-ship-test.php`: 127
- `tools-analytics-test.php`: 91
- `tools-shipto-test.php`: 30

There are browser suites too: `tools-missive-panel-page.mjs`, `tools-missive-ship-page.mjs`,
`tools-missive-ship-address-page.mjs` and `tools-analytics-page.mjs`. `tools-missive-impose-test.php`
needs git, node and playwright, and pulls the live imposition files from git.

## 3. How it fits together

- **Auth.**
  - The panel page and its REST calls (`/wp-json/pps/v1/missive/*`) are keyed by `pps_missive_panel_key`. It is made on first visit to PPS Calculators → Missive Panel, and regenerating it cuts off the old link. **Do not read or print the key.**
  - The Ship, Impose and Analytics frames carry **order-scoped HMAC tokens** derived from the key: `pps-ship|<order>` and `pps-impose|<order>`. A token for one order cannot act on another.
- **Missive API.** The owner's token is in `pps_missive_api_token` (autoload off, never sent to a browser, **do not read**). It is sent as a Bearer token to `https://public.missiveapp.com/v1/` and needs Missive's Productive plan.
  - Reads: open and recently closed `type=task` subtasks, kept in a 2-minute transient `pps_missive_tasks`.
  - Writes are only these, and only for a subtask the panel itself lists (`pps_mp_task_listed()`):
    - `PATCH {"tasks":{"state":…}}`
    - `PATCH {"tasks":{"due_at":<unix>}}`. A due date counts as saved only when Missive reads it back. It is resent once, then reported as an error naming the date Missive still shows.
    - `POST /v1/tasks` (`subtask: true`) to create one subtask per job.
    - `PATCH description` on the panel's own subtasks after a ship-to change.
    - `POST /v1/posts` to leave a note in the conversation.
- **Conversation ↔ order** links are kept in `pps_missive_conv_orders`. They come from what the panel saw, an order number in a task title, or the conversation subject (at most 8 lookups per refresh).
- **Data.**
  - Every job fact comes from the order line's `_pps_metadata` (calculator orders).
  - Paper comes from the Paper Order's own reading (`pps_po_build`).
  - Open jobs are cached 5 minutes in the transient `pps_missive_panel_open`.
  - A custom order (quote, pay link, legacy) has no metadata, so it shows less.
- **Shippo** (Ship page):
  - The `pcf.shippo_api_token` never leaves the server.
  - The browser names an option of a server-held quote (`pps_ms_q_<order>`, 30 minutes, used up by the purchase), never a rate ID.
  - Each label is saved to `_pps_shipments` as it is bought.
  - It never voids, refunds, emails, or changes order status.

## 4. Rules that bite here

1. **Never use the CSS class `.alert`.** Missive's own stylesheet turns it into a full-screen overlay. Pills use `pill`, `pill-flag`, `pill-late` and `k-*` (one colour per kind; dark red with white text only for unusual things: paper not in stock, add-ons, RUSH, MIXED COLOR).
2. **The page may call only `Missive.on`, `fetchConversations`, `search` and `openURL`.** Links (Drive, labels, tracking) are passed to the panel as `{ppsOpen:url}` and opened through Missive. The test pins this list.
3. **Every write is behind a switch the owner ticks** on PPS Calculators → Missive Panel / Missive Shipping, and every purchase is a confirm that names carrier, service, account, price and address.
4. **Shippo's dashboard edits are invisible to its API** (confirmed on 87675). The address check compares the site with Shippo's order *record* only, and says so.
5. Code rules from `CLAUDE.md` apply unchanged:
   - Repo first, deployed pull-based by pinned SHA.
   - Compare live sizes before overwriting.
   - Never write a PHP file to the server that is not in git.
   - Never read `pps_calc_config` raw.
   - Customers are never blocked at checkout.
   - The owner has no terminal.

## 5. Open items and unverified points

- **Automatic subtasks on a new order** are not built. Creating subtasks without someone opening the conversation needs the order email's Message-ID (Missive's `references`) to find the conversation.
- **Unverified until the first real label bought from the panel:**
  - Whether attaching the label to the Shippo order makes Shippo's store sync mark the WooCommerce order Completed, as buying in Shippo does.
  - Whether every carrier prints the return address (Peoria) rather than the sender (Anthem).
- **Paper Order → Missive:** a custom line still needs its paper plan confirmed once on the Paper Order screen before the panel can show sheets for it.
- **Analytics** counts "fulfilled" by the Completed date (a stand-in for the ship date), and costs a custom line as 4/4 colour.
- **Bridge drift:** when the imposition tool adds an AJAX action or config key, `pps-missive-impose.php` needs the same addition, or the feature quietly degrades inside Missive. This happened for six minutes on 2026-10-18.

## 6. Starting a session on it

1. Check out `claude/woocommerce-domain-search-ly4vff` **from origin**. A fresh container on 2026-11-28 had that branch name sitting on `pps-pricing-config`'s history (no Missive files at all). Reset it to the remote branch before reading anything.
2. Compare the five live sizes in §2 with the repo. Any difference means someone else deployed; find their commit first.
3. Ask the owner what they see in Missive (a screenshot of the panel and the build stamp at its foot, which is an md5 of the file). The panel's behaviour depends on the conversation that is open.
