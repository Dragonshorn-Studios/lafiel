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

Canonical categories: `account`, `compute`, `storage`, `network`, `domain`, `dns`, `database`, `observability`, `security`, `email`, `saas`, `other`.

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

## OVHcloud — v1.1

### Capabilities

- inventory: required, discovered through the common Services API: `GET /services` returns numeric ids, then `GET /services/{id}` loads each `services.expanded.Service`;
- renewal quotes: required where available, taken from that expanded service's `billing.pricing` (the contracted plan). `GET /service/{id}/renew` is only a fallback, and only a solo strategy for the configured period is accepted;
- usage and invoice actuals: not implemented yet — Public Cloud resource usage is the main gap and is reported as unsupported, never as a known zero. `/me/bill*` remains reserved.

The numeric OVH `serviceId` is the canonical inventory identity; the technical service name and `route.path` are preserved next to it, and product-specific endpoints only enrich the common record — they never replace the id. Provider lifecycle fields live in `services.metadata`, a column owned by the provider adapter and overwritten wholesale on every sync; nothing else may shape it.

OVH publishes two overlapping families, plus a third `services.Service` shape on every product's `/serviceInfos` endpoint. Only the plural `/services` family is inventory:

- `GET /services` → `long[]` of ids (filters exist; there is no pagination). `GET /services/{id}` → `services.expanded.Service`: nested `resource`, `route` (object with `path`/`url`/`vars`, never a string), `billing.plan`, `billing.pricing` (`services.billing.Pricing`: `pricingType` `consumption|purchase|rental`, `capacities` including `renew`, `priceInUcents` in micro-cents), `billing.renew.current.mode` (`automatic|manual`), `billing.nextBillingDate`.
- `GET /service` (singular, beta) also returns `long[]`, but `GET /service/{id}` is a *different* type (`serviceList.Service`) whose `renew.mode` enum is the old `automaticV2016` family — not used. The useful singular read is `GET /service/{id}/renew` → `service.renew.RenewDescription[]` of *possible order combinations*, not the current charge.
- Product `/serviceInfos` (`GET /vps/{name}/serviceInfos`, …) returns yet another `services.Service` (quantity, `renewalType`). It is not the inventory surface.

Treating `/renew` as the price (or treating `/services` as an object listing with `selectedPrice` / `priceInUtv`) is what made imported amounts wrong. Classification uses published product `route.path` prefixes (`GET /domain/{serviceName}`, `GET /hosting/web`, `GET /ipLoadbalancing` before `GET /ip`); there is no `/domain/name` API. A service whose expanded `billing.lifecycle.current.state` is `terminated` stays in inventory without a quote — `GET /services` can still list it.

### Renewal quotes and pricing sources

- One fact per inventoried service. Sibling services that OVH would bundle on a purchase order (a VPS and its failover IP) keep their own `billing.pricing` amounts; they are never merged into one `shared_unallocated` charge.
- `billing.pricing` is a quote/estimate of the contracted plan, never an invoice actual. Amounts use `priceInUcents` (micro-cents: 1 EUR = 100_000_000 ucents).
- `GET /service/{id}/renew` is consulted only when `billing.pricing` is missing. A multi-service strategy is an order preview and is never used as that service's charge — it would mix in other services' prices. Several solo strategies for the same period are ambiguous and stay unknown.
- The public formatted catalog (`order.catalog.Catalog` for vps/ip) is a last-resort estimate only, marked as such in the charge notes, and is requested for the account's own subsidiary and checked against its currency (both read from `/me`). Domain formatted catalogs are a different paginated TLD shape and are not used as fallback. A missing or ambiguous catalog match warns and degrades the run.
- Public Cloud projects (`pricingType: consumption`) are never catalog-priced and never take a `/renew` order preview: their real cost comes from resources and usage, which is a separate unsupported capability. A project's price is an explicit unknown plus a standing warning.
- `billing.nextBillingDate` (falling back to `billing.renew.current.nextDate`) is the renewal date; `billing.renew.current.mode` is auto-renew (`automatic`/`manual` on the expanded service — not the singular `automaticV2016` enum). Cadences OVH expresses that Lafiel has no period for (`P6M`, `P2Y`, `P3Y`) stay `unknown` with a warning.
- A `terminated` expanded lifecycle is not quoted, even when `billing.pricing` is still present. Options are inventoried only when they appear on `GET /services` (they carry `parentServiceId`); `GET /services/{id}/options` is not a second listing.

### Credentials

Use the smallest read-only rights, provide a connection test, encrypt at rest, and redact logs. No OVH mutating endpoint belongs in Lafiel, and the adapter client can only express GET. Minimum delegated rights — GET on:

- `/me` (identity: subsidiary, currency, connection test);
- `/services` and `/services/*` (inventory and contracted billing);
- `/service/*` (solo `/renew` fallback);
- `/order/catalog/formatted/*` (last-resort fallback pricing);
- `/me/bill*` (invoice history, reserved for the billing sync).

### Required real-account spike

With read-only access, establish service counts, priced versus unknown records, renewal coverage, tax-basis availability, stable identifiers, pagination, rate limits, and unsupported cases. If tax basis is unclear, store `tax_basis=unknown`. Produce only redacted fixtures. The fixtures under `tests/Fixtures/Ovh/` are synthetic shapes from the public docs and must be confirmed by this spike.

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

Implementation (adapter `cloudflare`, issue #15):

- one API token, created out-of-band with Billing Read (account subscriptions) and Zone Read (zone discovery and zone subscriptions); the read-only client can only express GET;
- the account itself is inventoried as a service (canonical category `account`), so account-level subscriptions have a charge anchor; zones inventory as `dns`;
- account and zone subscription listings are deduplicated by subscription id — one charge is never counted twice; the fixed components of one subscription sum into one recurring fact, metered components contribute nothing;
- a fixed subscription price is `actual` evidence (the provider's committed price, unlike a catalog quote); price `0` on a free plan is a known zero; a missing price component or currency is unknown, never inferred;
- the Usage capability is declared and reported `partial`, so every Cloudflare run reads partial and the Overview data-quality card reports the account's metered usage as unavailable;
- the batch's reported capabilities are `[subscriptions]` only: a cancelled subscription ends its charge on a complete run even though usage stays permanently partial;
- the subscription payload shape is a documented assumption (`tests/Fixtures/Cloudflare/README.md`) pending the real-account spike.

## Contabo — later

Contabo exposes Compute and Object Storage inventory, product IDs, add-ons, lifecycle dates/status, region, and storage usage. The documented API does not establish a dependable invoice/subscription/price feed.

- Use OAuth2 plus a dedicated API user with a custom READ-only role.
- Discover Compute and Object Storage; Storage VPS is explicitly unsupported by the Compute API/CLI.
- Attach manual price overlays to stable discovered identity until a reliable billing surface is proven.
- Do not request provisioning rights merely because the API supports mutations.
- Spike the real account for any account-specific billing surface before hard-coding the manual-price assumption.

Reference: [Contabo API](https://api.contabo.com/).

Implementation (adapter `contabo`, issue #16):

- OAuth2 client credentials held by a dedicated API user whose custom role carries READ scope only; the read-only resource client can only express GET (the token exchange is auth plumbing inside the client, never a resource call);
- Compute instances (`GET /v1/compute/instances`) and Object Storage (`GET /v1/object-storage/instances`) are discovered by their stable ids; Storage VPS is unsupported by the Compute API and never appears;
- Contabo exposes no dependable billing surface, so every discovered resource gets one recurring cost fact with an unknown amount (`estimate` evidence, monthly) — the charge demonstrably exists, its price is never inferred from product listings;
- the manual price overlay is the price path: attach a manual cost to the discovered service (`covers_service_id`) and it is flagged `is_manual_override` — it survives re-sync (disjoint key namespaces) and provider-side renames (pivot on stable identity);
- a conscious override on every service a charge covers stops that unknown from counting toward the incompleteness totals (the charge itself remains as provenance); a partial package override keeps the unknown counted;
- the real-account spike is still pending: it must confirm the payload shapes and probe for any account-specific billing surface before the manual-price assumption is hard-coded.

## Hetzner — Cloud shipped, Robot pending

Treat Hetzner Cloud and Robot as separate adapters with separate credentials and capabilities.

### Cloud

- A token is project-scoped; multiple projects become multiple provider accounts.
- Join resource type/location to `GET /pricing` and include separately billable resources.
- Catalog output is estimate with currency, VAT, and `observed_at`, not invoice actual.
- A later catalog change must not silently rewrite historical conditions.

### Robot — pending real-account spike

- Identify dedicated servers by `server_number`.
- Preserve product, data center, `cancelled`, and `paid_until`.
- Current public/catalog pricing may not match an old contract; allow a manual overlay.

Robot is deliberately not implemented yet: the Robot webservice shape must be validated by a real-account spike before an adapter is built (docs/operations.md, known gaps).

### Cloud implementation (adapter `hetzner-cloud`, issue #17)

- one project-scoped API token with a read-only role, created out-of-band; multiple projects become multiple provider accounts; the read-only client can only express GET;
- servers, load balancers, primary IPs, floating IPs, and volumes are inventoried by their stable numeric ids; primary IPs and floating IPs may carry no name — the IP itself is the display name;
- every resource is joined to `GET /pricing` by type and location; the catalog answer is an `estimate` with `TaxBasis::Exclusive` (net amounts; the VAT rate is preserved in the charge notes) — never an invoice actual;
- volumes are priced as the exact per-GB fraction times the size, rounded once, half-even;
- a resource with no matching pricing entry still gets a recurring fact with an unknown amount — the charge exists, the price is never inferred;
- a catalog change closes the old fact version and opens a new one; historical amounts and `observed_at` are never rewritten;
- the Hetzner Cloud real-account spike is pending: fixtures under `tests/Fixtures/HetznerCloud/` are synthetic; snapshots and images are separately billable spike items, not yet inventoried.

References: [Hetzner Cloud API](https://docs.hetzner.cloud/reference/cloud) and [Robot Webservice](https://robot.hetzner.com/doc/webservice/en.html).


