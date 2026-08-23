# Creating a product page

The whole job, once the spawner is live, is: **configure it, copy the link,
paste it, write the words.** Everything else is done for you.

---

## The five minutes

### 1 · Build the job in a calculator

Open any calculator and set it up exactly as you want the page to open for a
customer — size, quantity, pages, paper, colour, finishing. This is the *starting
state* of the page, not a limit: customers can still change anything.

Pick a **realistic** configuration, not the cheapest possible one. This is the
price that shows on the shop card, in search results and in Google Shopping.
Quantity 1 on the smallest trim advertises a low number and converts badly.

### 2 · Click **Save configuration**

Bottom of the calculator. It writes every setting into the URL, plus the quoted
total. Copy the whole URL out of the address bar.

### 3 · **PPS Calculators → New Product**

Three fields:

| Field | What to put |
|---|---|
| **Quote link** | The URL you just copied |
| **Product name** | "Mini Catalog Printing". The slug derives from it. |
| **Copy from** | An existing product to inherit description, images, categories and tags from |

*Copy from* is what makes this fast. A new booklet product is mostly the same
page as an existing booklet product — start from one and change what differs.

### 4 · **Create draft product**

You land on the new product's edit screen with a notice listing what it set.

### 5 · Write the part that needs you

The title/H1, the description, and the SEO panel. That is deliberately all
that's left — it's the work that actually earns the ranking, and the only part
a machine can't do.

### 6 · Publish

Within the hour it appears in the Google Shopping feed, if it has a price and a
featured image.

---

## What it does for you

- Sets every calculator default from the link
- Sets the price from the quoted total, on both the product and the shop card
- **Marks the product virtual.** PPS collects the shipping address and owns
  turnaround, so WooCommerce's own shipping must stay out of the cart. Forget
  this by hand and shipping plugins start charging on top of yours.
- **Registers it with the calculator.** Forget this by hand and the product page
  renders no calculator at all — and looks completely normal in the admin while
  doing it.
- Copies description, short description, featured image, gallery, categories and
  tags from whichever product you chose
- Prefills the Rank Math title and description as a starting point
- Keeps the original link on the product, so you can see later exactly what
  configuration it was built from

Those two bold ones are the reason this exists. Both fail *silently* — nothing
warns you, and you find out from a customer.

---

## What it does not do

**It does not write your copy.** Cloning a template gives you a starting point,
not a page worth ranking. Ten near-duplicates with swapped nouns is a
doorway-page pattern and can actively hurt you. The spawner removes the
mechanical cost so each page can carry *more* substance — not so you can make
more pages with less.

**It does not publish.** Everything arrives as a draft. Nothing goes live until
you say so.

---

## Several at once

Same screen, bottom. One per line:

```
Mini Catalog Printing       | https://…?size=…&qty=250&q=284.50
Wedding Program Printing    | https://…?size=…&qty=150&q=161.75
Full Color Booklet Printing | https://…?size=…&qty=100&q=196.20
```

Uses the calculator and *copy from* selections above, and creates one draft per
line. Still drafts. Still needs the copy written.

---

## Changing which calculator a product uses

There is now a **PPS Calculator** dropdown on the product edit screen, under
General. It shows which calculator renders on that product — including *"None:
this product will not render a calculator"*, which is worth being able to see.

Changing it moves the product between calculators. You no longer edit a
comma-separated list of product IDs on a different screen.

---

## If something looks wrong

**The notice says fewer settings than expected.** The link was copied before the
configuration was finished, or from a stale tab. Re-copy and paste it into the
product's **PPS Defaults → Apply from quote link** field.

**"Ignored unrecognised parameters".** Harmless. The link carried something that
isn't a calculator setting — usually a tracking parameter. Nothing was stored
from it.

**No price got set.** The link had no quoted total, which happens if the
calculator was showing an error when you copied it. Fix the configuration,
re-copy, re-paste.

**The product doesn't appear in Google Shopping.** Open
`/pps-product-feed.xml?debug=1` while logged in as an admin. It lists every
product that is in the feed, every one that isn't, and why. A product needs a
price and a featured image; nothing else keeps it out.
