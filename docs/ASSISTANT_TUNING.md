# Assistant — operator guide

How to change what the bot says and does. Written for the wp-admin + Claude-on-the-web
workflow; nothing here needs a terminal.

Companion docs: `CUSTOMER_SERVICE_BOT.md` (why it's built this way),
`../tests/assistant/README.md` (the guardrail suite).

---

## The one thing to understand first

**Nothing is "trained."** No conversation teaches it anything; every chat starts cold.
When you want different behavior you are changing one of four things, and picking the
right one is most of the skill:

| Lever | Changes | Where | Effort |
|---|---|---|---|
| **Policy prompt** | How it behaves, talks, and when it escalates | wp-admin field | seconds |
| **Catalog** | Facts it knows about your products | your existing admin screens | minutes |
| **Tools** | Live data it can look up | code change | ask a session |
| **Evals** | Whether you can tell you improved it | a list you write | one afternoon |

Rules go in the prompt. Facts go in config. Data goes in tools. Mixing these up is the
main way this gets worse instead of better.

---

## Diagnose before you change anything

Match the symptom to the lever. Guessing wrong wastes an afternoon.

| What you saw | Real problem | Fix with |
|---|---|---|
| Too wordy / too formal / weird tone | Behavior | Policy prompt |
| Escalated something it should've handled (or didn't escalate a refund) | Behavior | Policy prompt |
| Didn't know a fact you've written down somewhere | Grounding | Catalog |
| Described a product you don't sell | Grounding | Catalog |
| Gave a confident answer about live status it can't see | **Missing tool** | New tool |
| Quoted a price it made up | **Bug — tell me immediately** | — |
| Stated a delivery date no tool returned | **Bug — tell me immediately** | — |

The last two are the ones that cost real money. Everything else is tuning.

**Critical distinction:** "didn't know" and "couldn't have known" are different failures.
If it can't tell a customer whether their proof was approved, no amount of prompt-writing
fixes that — it has no way to look it up. Adding prose only produces a confident guess.
That's a tool request, not a prompt edit.

---

## Lever 1 — the policy prompt

**PPS Calculators → Assistant → Policy prompt.** Blank uses the built-in default. Anything
you paste **replaces the default entirely** — it doesn't append, so copy the default out
first if you only want to tweak it.

Edit → Save → hard-refresh a front-end page → test. That's the whole loop.

### What works

Be specific and positive. Say what to do, not what to avoid.

```
Keep replies under four sentences unless the customer asks for detail.
```

```
When someone asks about a rush job, always ask what date they need it in hand
before discussing options.
```

```
If a customer mentions an event date, treat it as a hard deadline and say
explicitly whether standard turnaround clears it.
```

### What doesn't

| Don't | Why | Instead |
|---|---|---|
| "Be helpful and professional" | Means nothing operationally | Name the actual behavior |
| "NEVER EVER do X!!!" | Over-triggers; it'll refuse adjacent legitimate things | Plain declarative sentence |
| Pasting your whole price list | Goes stale silently, costs tokens every message | Put it in presets (Lever 2) |
| Ten rules for one rare edge case | Crowds out the rules that fire daily | Let it escalate |

### Rules worth keeping if you rewrite the default

Whatever else you change, keep the pricing rule, the dates rule, the verification rule,
and the escalation rule. They're the reason this is safe to point at customers. The
guardrail suite enforces verification in code, but the other three live only in the prompt.

---

## Lever 2 — the catalog (facts)

The bot's product knowledge is rebuilt automatically from config you already maintain.
**You teach it facts by editing your normal admin screens**, not by writing prompt text.

| Edit here | Teaches it |
|---|---|
| **Presets** (slug, description, `price_from`) | What each product is, what it starts at |
| **Tooltips** (`pps_tooltips`) | File prep, bleed, glossary — already customer-facing |
| **Registry** product labels | Which products exist at all |

Cached for 24h, but auto-invalidated the moment you save presets, tooltips, or Central
Config — so a change is live on the next message.

### Verifying it took

Ask the bot something only the new text would let it answer. If it doesn't know, the
cache didn't clear: save the Assistant settings page once (that force-clears it).

### Known gap — not yet wired in

Two sources of good content the bot **currently cannot see**:

- **`pps_default_faqs()`** — your calc-type FAQs (binding explained, minimum page counts,
  the multiple-of-4 rule, paper options, bleed specs, finishing). Already public in your
  schema markup, invisible to the bot.
- **Central Config → Papers / Sizes / Finishing** — the catalog invalidates when this
  changes but never actually reads it.

Ask a session to wire these in. It's the highest-leverage change available and it's writing
you've already done.

---

## Lever 3 — tools

A tool is how it looks something up live. It has five:

| Tool | Does |
|---|---|
| `verify_customer` | Order # + billing email check. Gates everything below it. |
| `get_order_status` | Status, specs, production start, artwork received |
| `get_transit_estimate` | Real UPS ground days — the only legitimate source of a timeframe |
| `build_calculator_link` | Hands over a calculator link instead of quoting a price |
| `escalate_to_human` | Emails the shop + adds an order note |

### Asking for a new one

Give a session the job, not the implementation:

> Add a tool to the assistant that tells a verified customer whether their proof has
> been approved and when. Then run the guardrails suite and add assertions that it's
> refused before verification and allowed after.

That last sentence matters. New tools that read customer data need matching test
assertions or the suite silently stops covering the thing it exists to cover.

---

## Lever 4 — evals

Prompt edits routinely fix one case and break two you weren't watching. The only defense
is a fixed list you re-run.

Pull **30–50 real questions from Missive history** where you know the correct answer.
Include the nasty ones: vague requests, angry customers, questions with a wrong premise,
someone asking about an order that isn't theirs.

Build this before you make many prompt edits, not after.

---

## Reading failures

Two different error strings mean two different things:

| Message | Meaning |
|---|---|
| `DEBUG (admin only): HTTP 403 — …` | Never reached Claude. Nonce, permissions, or plumbing. |
| "Sorry — something went wrong…" | Same as above, but shown to a customer (non-admin view) |
| "I'm not able to help with that right now — let me get a team member…" | **Reached Claude.** API error, safety refusal, budget cap, or a tool loop. |

The verbose DEBUG version only appears while **Visible to = Logged-in admins only**. Paste
that line to a session and it's usually diagnosed immediately.

### The log line

Every turn writes to your PHP error log:

```
[pps-assistant] in=1240 cache_read=4890 cache_write=0 out=210
```

`cache_read` should be **non-zero from the second message onward**. If it stays 0, the
prompt prefix is changing between messages and you're paying full price every time — that's
a bug worth reporting, usually something dynamic leaking into the system prompt.

---

## Dials

**PPS Calculators → Assistant**

| Setting | Guidance |
|---|---|
| **Effort** | `medium` default. Drop to `low` for faster, cheaper replies — Opus 5 holds up well there. Raise only if answers are genuinely shallow. |
| **Model** | `claude-opus-5` default. `claude-sonnet-5` is roughly a third the cost; `claude-haiku-4-5` cheaper still. Change one thing at a time and re-run evals. |
| **Daily cap** | Site-wide API calls per day. Hitting it degrades to the fallback message — it does not error. |
| **Visible to** | `Logged-in admins only` while testing. `Everyone` = customers see it. |

Roughly $0.06/conversation on Opus 5, ~$0.02 on Sonnet 5. Watch **API calls today** at the
top of the settings page for real usage.

---

## Stopping it

Three independent switches, any one is sufficient:

1. **Untick Enabled** — settings page. Instant, no API calls, widget shows the fallback.
2. **Deactivate the plugin** — wp-admin → Plugins → PPS Assistant.
3. **Daily cap → 0** — same effect as (1), reversible by raising it.

It never renders on cart or checkout under any setting. That's hardcoded.

---

## Before going public

Do not flip **Visible to → Everyone** until these are handled:

- [ ] **The nonce/cache landmine.** WP Rocket serves cached pages to logged-out visitors,
      and a cached footer carries a baked-in nonce that expires in ~12–24h. When it does,
      *every customer* gets a 403 at once. Needs the widget markup excluded from page
      caching, or the nonce fetched from an uncached endpoint on chat open.
- [ ] **`build_calculator_link` finished.** Questions that don't match a preset slug
      currently return a homepage link instead of the right calculator.
- [ ] **Eval set built and passing.**
- [ ] **A week of admin-only use** with no invented prices or dates.

---

## Working with a session

Copy-paste starters:

**Change behavior**
> The assistant is doing X when it should do Y. Suggest a policy prompt edit, show me the
> before and after, and tell me what else it might affect.

**Add knowledge**
> Wire `pps_default_faqs()` and the Central Config Papers/Sizes/Finishing tabs into the
> assistant's catalog block, then re-run the guardrails suite.

**Add capability**
> Add a tool that <does the thing>. Include matching guardrail assertions: refused before
> verification, allowed after.

**Diagnose**
> The assistant said: "<paste>" when I asked "<paste>". Which lever fixes this?

**Verify**
> Run `php tests/assistant/guardrails.php` and show me the output.

Always ask for the guardrails run alongside any change to a tool, the agent loop, or the
session store. Green isn't proof it's *good* — it's proof it isn't *dangerous*.

---

## What not to do

- **Don't paste prices into the policy prompt.** They go stale silently and every message
  pays for them. Presets are the live source.
- **Don't delete the pricing, dates, or verification rules** from the default policy.
- **Don't debug by loosening a guardrail.** If it won't show an order, that's the gate
  working — check whether verification actually ran.
- **Don't change model, effort, and prompt in one pass.** You won't know which moved the
  needle.
- **Don't turn it on for everyone to "see how it does."** Admin-only costs you nothing and
  tells you the same thing.
