# Address checks — setup and running brief

**For:** the owner (setting it up) and any Claude session (supporting it).
**Written:** 2026-10-05. **Code:** draft on `claude/woocommerce-domain-search-ly4vff`
(`a45bfa3`, `773e1d3`, then the fix `f845122`). Source is on the integration branch (`5fa8c85`).

**Where it is deployed:**

- The server half is live on production.
- The calculator half is on **staging only** (`f845122`).
- `773e1d3`'s calculators crashed with React error #321 on production. They were rolled
  back the same morning.
- Production calculators stay on `482509f` until the owner says go.
The first version (ZIP vs state, PO Box, Shippo check at Add to Order) is live since
2026-10-04 at `482509f`, with Verify Addresses **off**.

---

## 1. What the customer sees

Nothing happens while they type. When they leave the shipping address fields with a
complete address (street, city, state, ZIP), it is checked once and the answer appears
under the fields:

| Answer | What they see | Their choices |
|---|---|---|
| Correction found | "Did you mean 100 N 1st Ave Ste 2, Phoenix AZ 85003-1902?" | **Use this address** / **Keep mine** |
| Apartment number missing | "This address may need an apartment, suite or unit number." | **Add one** (jumps to line 2) / **None needed** |
| Not in postal records | "We couldn't find this address in postal records…" | **It's correct** |
| All good | "✓ Address verified" | — |
| City doesn't match the ZIP (free, always on) | "ZIP 85003 is Phoenix. Did you mean Phoenix?" | **Use Phoenix** / **Keep …** |

Rules that do not change:

- **Nothing ever blocks an order.** Every question has a "keep it" answer.
- **Their answer is remembered**, so Add to Order does not ask again. If they never left the
  fields (the browser autofilled and they clicked straight through), Add to Order asks the
  same questions instead.
- **If Google is down, slow (6 seconds) or refuses the key**, the order goes through marked
  "not checked".
- **Staff see the outcome** on the Job Ticket ("Address check: Verified (commercial)", "NOT
  FOUND — customer kept it as entered", …). The daily email lists any address the customer
  kept against the check's advice.

Already live and unaffected by this setup: the ZIP-vs-state question, PO Box handling (one
extra transit day, "ship Ground Advantage" on the ticket), the state-typed-into-City tidy-up,
and one ship-to address per cart.

## 2. What it costs

| | Price | Our use (~300–450 orders a month) |
|---|---|---|
| Google Address Validation ("Pro") | First **5,000 a month free**, then $17 per 1,000 | **$0** |
| Shippo (used only if no Google key is set) | 2¢ per US address | ~$5–10 a month |
| ZIP → city hint | Free (our own server, GeoNames data) | $0 |

Three limits keep it from ever running up a bill:

1. Each address is checked once, then remembered for 30 days.
2. The server allows each visitor 10 checks a minute and stops at 300 a day.
3. Google's own daily quota, which you set in step 4 below.

## 3. Setting it up

**Before you start:** the draft's calculators must be on production first. The admin fields
are already there, but the inline suggestions are not. It is already on staging to try. Tell
Claude "deploy the address check draft to production". The proofer session's saddle build on
staging is left alone.

1. **Open the Google Cloud project.** Go to console.cloud.google.com and sign in with the
   shop's Google account. If a Places API key already exists for the review-rating refresh,
   use the project it lives in (**APIs & Services → Credentials** lists it). Otherwise create
   a project called "Priority Print Website".
2. **Turn billing on.** Under **Billing**, attach a card. Google requires this even inside
   the free allowance. Then go to **Billing → Budgets & alerts** and add a $5 monthly budget
   with an email alert.
3. **Enable the API.** Go to **APIs & Services → Library**, search for
   **Address Validation API**, and click **Enable**.
4. **Cap the daily use.** Go to **APIs & Services → Address Validation API → Quotas** and
   set requests per day to **150**. That is about 4,500 a month, under the free 5,000.
5. **Create a key just for this.** Go to **APIs & Services → Credentials → Create
   credentials → API key**, then edit the key:
   - Name: "PPS address check"
   - API restrictions: **Address Validation API** only
   - Application restrictions: **IP addresses**. Add the server's public IP, which you'll
     find in Cloudways under **Servers → (server) → Server Management → Public IP**. Add
     staging's IP too if staging is on a different server.

   Use a new key rather than the existing Places key (see step 7).
6. **Paste it into the site.** In **PPS Config → Shippo Integration**:
   - **Google Address Key:** paste the key
   - **Verify Addresses:** **1**

   Save. To try it on staging first, do the same in staging's PPS Config. **Never paste the
   key into a chat, an email or the repo.** It belongs only in PPS Config.
7. **Replace the old Places key, if one was set.** Until the draft deploys, the calculators
   send the whole SEO settings block to the browser, Places key included. If that key was
   restricted to the server's IP, as the admin hint says, a copy is useless. Replace it anyway:
   in Google Cloud go to **Credentials → (Places key) → Regenerate key**, and paste the new
   value into **PPS Config → SEO → Places API key**.

Google renames menus from time to time. If a step doesn't match what you see, describe the
screen to Claude.

## 4. Checking it works

- **On the calculator:** enter a real address with a small mistake (for example "100 N 1st
  Av", Phoenix AZ 85003), click into another field, and you should see "Did you mean …?".
  Enter a misspelt city ("Pheonix") and you should see the city hint.
- **Usage count:** Claude can read `wp_options['pps_addrv_spend']` without ever seeing the
  key. It holds today's count, the running total and a per-provider count (`google`).
  - A `google` count that grows means it is working.
  - Orders marked "Not checked" on the ticket while the count does not grow means Google is
    refusing the key. The usual causes are a wrong IP or the API not being enabled.
- **Google Cloud:** **APIs & Services → Address Validation API → Metrics** shows requests and
  errors.

## 5. Turning it off or changing provider

- **Off:** set Verify Addresses to **0**. The free city hint and the live first-version checks
  stay on.
- **Back to Shippo:** clear the Google Address Key, and clear the SEO Places key too, since it
  is used as a fallback. With no Google key, Shippo is used when a Shippo token is set.
- **Rollback of the code:** redeploy `482509f`, the same pull-based call with the older commit.

## 6. For a Claude session supporting this

The design is in `CLAUDE.md` → "Shipping address checks". The gates are
`tools-address-check-test.mjs` (browser, all eight calculators; 249/249 at `f845122`, including a second React on the page) and
`tools-address-verify-test.php` (server; 87/87).

Pieces:

- Endpoint: `POST /pps/v1/shipping/verify`.
- Classifiers: `pps_addr_verify_classify_google()` and `pps_addr_verify_classify()` (Shippo).
- City hint: `pps_zip_city_hint()`, reading `pps-zip-city.php`. That file is generated from
  GeoNames, CC BY 4.0; rebuild it with `tools-build-zip-city.mjs`.
- Calculator side: `PpsShipNotes`, `ppsConfirmAddress`.

Rules:

- Do not read `pps_calc_config` to "check the key". It carries live credentials.
  `pps_addrv_spend` answers the question.
