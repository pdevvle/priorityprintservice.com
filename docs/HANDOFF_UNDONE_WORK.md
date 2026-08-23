# Handoff — work overwritten on staging, needs re-applying

**For:** whichever session is currently working on `pps-calculators` on staging
**From:** the session on branch `claude/customer-service-bot-guide-ikniqz`
**Repo:** `pdevvle/priorityprintservice.com` · **Commit to recover from:** `5f48e1e`

---

## What happened

Two sessions wrote whole files to the same plugin over MCP from different baselines. Whole-file
writes have no merge — last write wins, silently. My commit `5f48e1e` landed on staging on
**Aug 2**. Two of its four files were then overwritten from an older base:

| File | Deployed Aug 2 | On staging now | State |
|---|---|---|---|
| `pps-intake.php` | 43,275 | 43,275 | intact |
| `pps-assistant.php` | 82,202 | 82,202 | intact |
| `pps-term-shortcodes.php` | 125,030 | 128,173 | **my changes reverted** |
| `pps-calculators.php` | 229,326 | 286,533 | **my changes reverted** |

**Your work is not the problem and must not be rolled back.** `pps-calculators.php` has grown
57KB since, `pps-reorder.php` and the calculator HTML have moved too, and there is a new
`pps-shippo-test.php` require. None of that should be touched. Do **not** `git checkout 5f48e1e`
over these files — re-apply the two hunks below on top of what is there now.

---

## Repair 1 — `pps-calculators.php` (small, additive, cannot conflict)

The require of `pps-intake.php` is missing. Add it back next to the other sub-module requires,
after the `pps-imposition.php` block:

```php
// Loaded here as well as being separately activatable. The category wizard in
// pps-term-shortcodes.php submits through the shared intake pipeline, so a deactivated
// PPS Intake would stop recording quote requests from every category page. Its own
// co-load guard makes the double-load harmless.
if ( file_exists( PPS_CALC_DIR . 'pps-intake.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-intake.php';
}
```

### Why this is urgent

The home page, `/contact/` and `/reorders/` all carry `[pps_intake form="…"]` shortcodes — the
Forminator forms were retired and the block wrappers converted to `wp:shortcode`. With this
require missing, that shortcode **only exists if PPS Intake is activated as a plugin**. If it is
not active, the home page is rendering the literal text `[pps_intake form="quote"]` where the
quote form should be.

Check the home page first. Activating PPS Intake is the instant fix; adding the require makes it
robust regardless. `pps-intake.php` has a `PPS_INTAKE_VERSION` co-load guard, so requiring it
while it is also activated is safe.

---

## Repair 2 — `pps-term-shortcodes.php` (needs care; read the current file first)

`pps_wizard_email_handler()` and the wizard's markup were reverted to the pre-consolidation
version. Confirmed by content, not just size — these are all present again and should not be:

- `check_ajax_referer( 'pps_wizard_email', 'nonce' )`
- `wp_create_nonce( 'pps_wizard_email' )` and the `data-nonce` attribute on `.pps-wiz-actions`
- `fd.append("nonce",bar.dataset.nonce);` in the inline JS
- the handler's own upload block writing to `uploads/pps-quotes/<token>/`, with `$attachments`
  and `$cleanup`

Get the intended version with:

```
git show 5f48e1e:pps-term-shortcodes.php
git show 5f48e1e -- pps-term-shortcodes.php    # the diff, ~167 lines changed
```

Re-apply that handler rewrite onto the **current** file rather than replacing the whole file —
there are Aug 3 changes in there from another session.

### The four bugs this fixes

**1. Cached-nonce lead leak — the reason this matters most.**
The wizard mints a nonce into the **product category archive HTML**. Those archives are WP
Rocket-cached. Once a cached page outlives its 12–24h nonce, `check_ajax_referer` rejects every
quote request from that page — and retrying reloads the same cached page with the same dead
nonce. This is silently failing on the pages closest to a sale.

For a logged-out visitor the uid-0 nonce is shared and published in the markup anyway, so it was
never authentication. The replacement is the honeypot (already there) plus the shared per-IP rate
limit via `pps_intake_rate_key()`. The same landmine was removed from the standalone forms for
the same reason.

**2. It reports failure when the record succeeded.**
The old handler returns `wp_send_json_error( 'Could not send email…' )` whenever `wp_mail()`
returns false — telling a customer their request did not go through when the Calc Questions
record had already been written.

**3. No customer confirmation at all.**
Someone builds a full spec through the wizard, submits, and hears nothing back. Every other
intake path sends one.

**4. Upload hardening missing.**
The handler's own upload copy lacks the inner-dot flattening, so `shell.php.jpg` is stored
verbatim and executes under AddHandler-style Apache configs. `pps_intake_take_uploads()` flattens
inner dots (`shell-php.jpg`), applies a narrower extension allowlist, caps 20MB per file and 40MB
per submission, and writes both `.htaccess` and `index.php` into the token directory.

Also fixed in the rewrite: the re-open URL is host-checked before going into an email, so the
notification cannot be used as an open redirector — matching what `pps_ajax_quote_question()`
already does.

### What the rewrite delegates to

`pps-intake.php` (intact on staging) already exposes everything needed:

- `pps_intake_recipient()` — one address. The wizard hardcoded `get_option('admin_email')`; it was
  the last of **four** disagreeing recipient resolutions on this site.
- `pps_intake_take_uploads()` — returns `array( 'urls' => [], 'error' => '' )`, where `error` is a
  code resolved by `pps_intake_error_text( $form, $code, $field )`.
- `pps_intake_record( $key, $form, $values, $file_urls, $extra_meta )`
- `pps_intake_notify( $key, $form, $values, $file_urls, $post_id, $extra_lines )`
- `pps_intake_forms()['wizard']` and `['lead']` — internal definitions (`'internal' => true`, so
  the shortcode and the POST handler both refuse them) that exist purely so the wizard's record,
  notification and confirmation are byte-identical to the standalone forms.

The wizard's own value survives via the extra params: the **spec summary** goes to
`_pps_q_summary`, the **calculator link** to `_pps_q_reorder_url`, and both appear in the staff
email through `$extra_lines`.

---

## Also worth knowing

`pps_assistant_staff_email()` in `pps-assistant.php` now defers to `pps_intake_recipient()` when
it exists. That file is intact, but the deferral is a no-op unless `pps-intake.php` is loaded —
which is Repair 1 again. Without it, assistant escalations can go to a different address from
every other form whenever `question_recipient_email` is set in Central Config.

`_pps_q_source` and the **Source** column on Calc Questions come from `pps-intake.php`, with
inference for older rows (`Lead: ` / `Wizard: ` prefixes, `_pps_asst_order`). That is intact.

---

## Avoiding a repeat

The failure mode is whole-file MCP writes from stale baselines. Two things help:

1. **Deploy by pulling from git, not by writing bytes.** `pps_plugin_download_url` fetches a raw
   GitHub URL server-side:
   `https://raw.githubusercontent.com/pdevvle/priorityprintservice.com/<sha>/<file>.php`
   The deployed file is then byte-identical to a known commit, and the byte count in the response
   confirms it. No transcription risk, and the commit is a real audit trail.
2. **Read the file off staging before writing it** if another session may have touched it. A
   size mismatch against your own last deploy is the cheap early warning — that is how this was
   caught.
