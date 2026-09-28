# Subscription catalog fixtures — SYNTHETIC

Shaped after the real catalog formats the import action supports; no
real account data was fetched to produce them.

- `china-ai-arbitrage-plans.json` — shaped after the china-ai-arbitrage
  plans export (`https://www.china-ai-arbitrage.xyz/data/plans.json`,
  data licensed CC BY 4.0): a `_fields` docs object and a `plans`
  array whose entries carry i18n `platform`/`plan` name objects,
  `provider_slug`/`plan_slug`, and display-string prices with embedded
  currency (`"$20"`, `"¥118.00"`). Includes deliberate edge cases: a
  suffixed price (`"$200/年"`), a free tier (`"$0"`), a placeholder
  price (`"—"`), and an entry missing its plan fields.
