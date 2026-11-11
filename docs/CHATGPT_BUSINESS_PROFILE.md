# Business Hub profile — wording to paste (Phase 0)

For the owner, at **chatgpt.com/business-hub**. Only the owner can do this: it runs on their
ChatGPT account and ends in a Persona identity check of a person. Claude prepared the text and
does not sign in.

**Read this first — it cannot be undone.** OpenAI's help article ("Setting up your business on
ChatGPT"): *"OpenAI Support is unable to delete, unpublish, or hide a published profile."* Only a
draft can be deleted (draft's Overview page → ⋯ → Delete draft). OpenAI reviews every edit before
it shows, so a correction later is possible but not instant. Check every line below before
publishing.

## 1. Claim and verify

Pick **one** verification method (OpenAI's list: business phone, business email, website domain
via a DNS TXT record, or Shopify):

- **Business email — recommended:** `Office@priorityprintservice.com`. It is the address the site
  already publishes everywhere (schema, llms.txt). Do not use a personal address.
- Business phone: `(623) 267-9479`.
- DNS TXT: possible (the domain is on Cloudflare) if email does not work. Ask Claude to walk through
  the Cloudflare screen with you.

Then the **Persona** identity check (a person's ID). Nothing can be published, and Insights stay
hidden, until it is approved.

## 2. Profile fields

OpenAI lists these as editable after the claim: website, description, category, logo, delivery
policy, return policy. (Coverage also mentioned a cover photo. OpenAI's help page does not list
one, so use it only if the form offers it.)

**Website**
```
https://priorityprintservice.com/
```

**Category** — choose the closest the form offers, in this order of preference:
`Commercial Printing` → `Printing Services` → `Print Shop`.

**Description** (short, factual, no prices — OpenAI's business-agent rules forbid invented prices
and guarantees, and the profile should hold itself to the same):
```
Priority Print Service is an online commercial printer in Phoenix, Arizona. We print saddle-stitched and perfect-bound booklets, catalogs, coupon books, brochures, flyers, postcards, rack cards, greeting cards, letterhead and stickers, and ship them anywhere in the US. Every product page has a live price calculator: choose size, paper, page count, quantity and finishing, see the price and the delivery date before you order, and upload your artwork with the order. Free ground shipping US-wide; rush production is available. Online proofing, design help and reorders of past jobs are available too. For anything unusual, request a custom quote.
```

Check before pasting: every product named is one the site sells today (the list comes from the
calculators in the registry), and "Phoenix, Arizona" is the city the site's own business schema
gives.

**Logo** — the site logo:
`https://priorityprintservice.com/wp-content/uploads/2021/09/cropped-printing-in-color-min.jpg`
(download it and upload the file). If the form wants a square mark, the site icon is
`…/2021/05/cropped-priorityprintservice-printing-mailing-design-brochures-flyers-folders-booklets-favicon2021-w-background.png`.

**Delivery policy** — if the form takes a link:
`https://priorityprintservice.com/shipping/` · if it takes text:
```
Free ground shipping to US addresses on calculator orders. Each product page shows the delivery date before you order, and you can pick an earlier date for a rush charge. We ship with FedEx, UPS and USPS and can deliver rush jobs in person around the Phoenix valley. PO Boxes are delivered by USPS and add a day. We do not ship to APO/FPO/DPO addresses online — contact us for those.
```

**Return policy** — if the form takes a link:
`https://priorityprintservice.com/return-reprint-revision-and-cancellation-policy/` · if text:
```
Printing is made to order, so all sales are final. We reprint or refund, in part or in full, when an order arrives late, damaged, or more than 10% short; when a significantly different paper was used than ordered; or when it goes missing in transit. Color variation from screen, differences from an on-screen proof, and artwork errors supplied by the customer are not covered. Cancellations and artwork changes can be requested by email but cannot be guaranteed once production has started.
```
This is the policy page's own content, shortened. If the page changes, change this too.

## 3. After publishing

- **Product feed** — Business Hub takes a CSV upload, a link to a hosted feed, or Shopify. Once
  the feed is live on the site (Phase 1), give it the link
  `https://priorityprintservice.com/pps-openai-feed.csv`. A link stays current as the products
  change. An uploaded file would have to be uploaded again after every price change, so use the
  link.
- **Insights** (mentions, website clicks, over 28 days; shown only above an unpublished threshold)
  appear after Persona approval.
- **Business Agent** — separate review; see the brief, Phase 2. Do not create one until its
  instructions are drafted.
