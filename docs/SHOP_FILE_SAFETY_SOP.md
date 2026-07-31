# Shop File Safety — Standard Operating Procedure

**Purpose:** Customer-uploaded artwork is untrusted. A malicious PDF or image
can attack a computer that merely *stores or previews* it — not just one that
opens it. This SOP keeps those files off shop workstation disks and limits what
staff open, so a bad file can't harm a production machine.

Applies to every computer that has **Google Drive for desktop** installed and
syncs order/artwork folders.

---

## 1. For operators — the two rules

1. **Only open files named `CLEAN_…` or `IMPOSED_…`.** These are produced by
   the imposition tool and have had all active content (scripts, auto-run
   actions, attachments, forms) stripped out. They are safe to open and print.
2. **Never open a customer's original file** (anything *without* the `CLEAN_`
   or `IMPOSED_` prefix) directly from Drive. If you need a safe version of an
   original, run it through the imposition tool and use the **CLEAN 1:1 copy**
   button — then open that.

If the imposition tool shows a red **⚠ ACTIVE CONTENT** badge on a file, that's
expected for some customer files — it means the tool found scripts/actions in
it. Make the CLEAN copy and work from that. Do **not** open the flagged
original in Acrobat or a browser.

---

## 2. For whoever sets up the computers — Drive must be in STREAM mode

This is the most important protection. It keeps customer originals in the cloud
and off the local disk until a file is actually opened, which shuts down the
"attacked just by being downloaded/previewed" risk.

**On each shop computer:**
Google Drive for desktop → **Settings (gear) → Preferences → Google Drive** →
set **"My Drive streaming options"** to **Stream files** (NOT *Mirror files*).

**Enforce it for everyone (do this too):**
Google Workspace **Admin console → Apps → Google Workspace → Drive and Docs →
Google Drive for desktop** → force **stream-only** and disable mirror mode
org-wide. This stops the setting from being quietly switched back on one
machine.

---

## 3. Two settings that silently break this — do NOT use them

1. **"Available offline" / pinning.** Right-clicking a file or folder and
   choosing *Make available offline* downloads it permanently to the local
   disk — the exact thing stream mode is preventing. **Never pin the raw order
   or artwork folders offline.**
2. **Re-enabling "Mirror files."** If anyone flips a machine back to mirror
   mode, every customer file downloads to that disk automatically. Keep it on
   Stream. (The admin-console enforcement in section 2 is what guarantees
   this.)

---

## 4. Why the two layers work together

| Layer | What it stops |
|---|---|
| **Stream mode** (section 2) | The original never lands on the disk just from syncing/browsing — kills the "no click needed" risk (thumbnail generators, search indexers, and antivirus scanners auto-parsing every file). |
| **Only open CLEAN files** (section 1) | Even when a file *is* downloaded by opening it, staff only ever open the sanitized version — so a customer original is never executed. |

Stream mode keeps the bad file off the disk; the CLEAN rule keeps anyone from
opening it. Keep **both** — they cover different gaps.

---

## 5. Still to come (not yet in place)

A future improvement will file the tool's `IMPOSED_`/`CLEAN_` output into a
separate **Press-Ready** Drive folder, and only that folder gets synced to shop
machines. Then a workstation can *only* ever receive files the tool already
sanitized — making section 1 structural instead of a habit. Until then, the
Stream-mode setup above is the protection in force.

---

*Questions about the imposition tool's security behavior:
see `docs/IMPOSITION_TOOL.md` → "Security — malicious-PDF defense."*
