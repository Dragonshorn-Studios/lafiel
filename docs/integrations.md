# Provider integrations

> Source: AFFiNE page `Integracje: Lafiel — analiza providerów i kontrakty v1`. This repository copy is the implementation source of truth for provider work.

## Shared adapter rules

- Provider access is read-only. Never request create/update/delete scopes.
- Adapters return canonical DTO batches and do not persist Eloquent models.
- Inventory, subscriptions, renewal quotes, usage, and invoices are independent capabilities.
- Each batch carries completeness, `observed_at`, warnings, and a stable source reference.
- Preserve provider external ID and type separately from canonical category.
- Missing price means unknown. Do not infer it from a public catalog unless the integration explicitly models catalog output as an estimate.
- A sync failure or partial response preserves the last good state and cannot imply cancellation.
- Use one `SyncRun` per account, per-account locking, bounded backoff, and sanitized logs.
- Credentials are encrypted and redacted from all output and fixtures.

Canonical categories: `compute`, `storage`, `network`, `domain`, `dns`, `database`, `observability`, `security`, `email`, `saas`, `other`.

## Evidence and completeness

Keep these dimensions separate:

- amount: known or unknown;
- evidence: actual, estimate, quote, or manual;
- normalization: source amount versus presentation equivalent;
- allocation: direct, shared unallocated, or allocated;
- freshness: observation and last-success state;
- capability completeness: complete, partial, unsupported, or failed.

Dashboard copy must reflect this. Examples:

- `187.42 PLN/mo`
- `~187.42 PLN/mo`
- `187.42 PLN/mo + 2 unknown`
- `187.42 PLN/mo fixed + metered usage pending`

## Manual records

Manual is the zero-provider and a first-class path. It covers SaaS, domains, AI subscriptions, licenses, plugins/themes, support, and one-time purchases, and can overlay a price on a provider-discovered service.

Fields: vendor/provider, name, category, known/unknown amount, currency, period, next renewal, auto-renew, optional start/end, URL, and notes. A price change preserves history. Ending a record removes it from future spend while keeping historical evidence.

## OVHcloud — v1

### Capabilities

- inventory: required;
- renewal quotes: required where available;
- usage and invoice actuals: not promised in v1.

Start discovery from the common Service API, but isolate endpoint/version details inside the adapter. Inventory identity, renewal strategy, and price parts are separate source concepts.

### Credentials

Use the smallest read-only rights, provide a connection test, encrypt at rest, and redact logs. No OVH mutating endpoint belongs in Lafiel.

### Mapping rules

- Preserve external ID, provider type, source endpoint/reference, observation time, source amount/currency, billing period, and renewal date.
- Treat renewal offers as quote/estimate, never invoice actual.
- A renewal strategy may cover multiple services. Create one cost item per logical strategy/price part and relate it to all covered services; never duplicate the full price per service.
- If allocation cannot be determined, use `shared_unallocated` or unknown/ambiguous state rather than double-counting.

### Required real-account spike

With read-only access, establish service counts, priced versus unknown records, renewal coverage, tax-basis availability, stable identifiers, pagination, rate limits, and unsupported cases. If tax basis is unclear, store `tax_basis=unknown`. Produce only redacted fixtures.

Reference: [OVH API rights delegation](https://help.ovhcloud.com/csm/de-api-api-rights-delegation?id=kb_article_view&sysparm_article=KB0068603).

## Cloudflare — v1.1

Account and zone subscriptions can provide fixed recurring costs with Billing Read. Metered usage is a separate capability and may be restricted, incomplete, or unavailable. Fixed subscriptions alone must not be presented as a complete Cloudflare total when metered products may exist.

Requirements:

- read account and zone fixed subscriptions;
- preserve price, currency, frequency, rate plan, and current period;
- do not duplicate one subscription charge;
- expose usage as a separate capability;
- missing usage does not fail subscription sync;
- show `metered usage unavailable/pending` when appropriate;
- distinguish free-plan zero from an unknown missing cost field.

References: [Cloudflare account usage API](https://developers.cloudflare.com/api/resources/billing/subresources/usage/methods/get_account_usage_v2/) and [billable usage](https://developers.cloudflare.com/billing/manage/billable-usage/).

## Contabo — later

Contabo exposes Compute and Object Storage inventory, product IDs, add-ons, lifecycle dates/status, region, and storage usage. The documented API does not establish a dependable invoice/subscription/price feed.

- Use OAuth2 plus a dedicated API user with a custom READ-only role.
- Discover Compute and Object Storage; Storage VPS is explicitly unsupported by the Compute API/CLI.
- Attach manual price overlays to stable discovered identity until a reliable billing surface is proven.
- Do not request provisioning rights merely because the API supports mutations.
- Spike the real account for any account-specific billing surface before hard-coding the manual-price assumption.

Reference: [Contabo API](https://api.contabo.com/).

## Hetzner — later

Treat Hetzner Cloud and Robot as separate adapters with separate credentials and capabilities.

### Cloud

- A token is project-scoped; multiple projects become multiple provider accounts.
- Join resource type/location to `GET /pricing` and include separately billable resources.
- Catalog output is estimate with currency, VAT, and `observed_at`, not invoice actual.
- A later catalog change must not silently rewrite historical conditions.

### Robot

- Identify dedicated servers by `server_number`.
- Preserve product, data center, `cancelled`, and `paid_until`.
- Current public/catalog pricing may not match an old contract; allow a manual overlay.

References: [Hetzner Cloud API](https://docs.hetzner.cloud/reference/cloud) and [Robot Webservice](https://robot.hetzner.com/doc/webservice/en.html).


