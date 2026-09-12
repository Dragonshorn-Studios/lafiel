# Architecture

> Source: AFFiNE page `Architektura bazowa: Lafiel — rejestr kosztów infrastruktury` (Architecture v2). This normalized repository copy is authoritative for implementation agents.

## Product boundary

Lafiel is a self-hosted infrastructure cost ledger. It combines provider inventory and billing evidence with manual cost records, projects recurring spend, tracks renewals, and preserves replayable history.

Lafiel is strictly observational. It may validate credentials and read provider data, but it must never create, scale, renew, disable, or delete infrastructure.

### v1

- one local administrator and disabled public registration;
- manual services and costs;
- OVHcloud inventory and renewal quotes;
- monthly and annual equivalents;
- renewals and historical snapshots;
- explicit `unknown`, `estimate`, `stale`, `partial`, and `ambiguous` states;
- Docker/Coolify deployment with PostgreSQL, queue worker, scheduler, backup, and restore.

Fixed Cloudflare subscriptions are v1.1. Provider invoice actuals, usage billing, budgets, alerts, public APIs, multi-tenancy, and infrastructure mutations are not v1.

## Technology and deployment

- PHP 8.5 and Laravel 13 (`^13.0`)
- PostgreSQL as the supported production database
- Blade/Livewire server-rendered UI
- Tailwind using Lafiel semantic tokens
- database queue by default; Redis optional
- Node LTS only to build frontend assets

One production image runs in separate roles for web, `queue:work`, and the scheduler. Health endpoints check the application and database, not external providers. Production assets are built into the image; the Vite development server is not a production dependency.

## Module boundaries

| Module | Responsibility |
| --- | --- |
| `Providers` | Accounts, encrypted credentials, capabilities, adapter registry |
| `Inventory` | Canonical services, provider identity, lifecycle |
| `Costs` | Cost facts, money, periods, normalization, allocation, aggregation |
| `Sync` | Locks, runs, retries, completeness, idempotent persistence |
| `History` | Replayable cost snapshots |
| `Support` | Clock, identifiers, redaction, shared value objects |
| `UI` | Livewire pages, forms, view models; no independent money calculation |

Provider-specific DTOs may use provider field names. Domain models must not depend on raw payloads, endpoint versions, or provider SDK types.

## Provider contract

Adapters return canonical batches and never persist Eloquent models directly.

```php
validateCredentials(): CredentialCheck
capabilities(): CapabilitySet
fetchInventory(SyncContext $context): InventoryBatch
fetchCostFacts(SyncContext $context, InventoryBatch $inventory): CostFactBatch
```

Optional capabilities include subscriptions, renewal quotes, usage, and invoices. Represent absence or partial support explicitly; do not add empty fake implementations. Every batch carries completeness, observation time, warnings, and a stable source reference. The application layer validates and persists it, applies lifecycle transitions, and creates projections.

## Canonical model

### Provider accounts and capabilities

`provider_accounts` stores provider key, display name, region, enabled state, and last attempt/success timestamps. Disabling an account stops scheduled sync and preserves history.

`provider_capability_states` is unique by `(provider_account_id, capability_key)`. Inventory, renewal quotes, subscriptions, usage, and invoices each keep their own support, health, attempt, success, and observation state. Fresh inventory must not imply fresh prices.

`provider_credentials` stores an encrypted payload, schema version, fingerprint, and last verification time. Credentials never appear in API resources, logs, exceptions, fixtures, or docs.

### Services

`services` is unique by `(provider_account_id, external_id)` and retains the provider type separately from the canonical category. Core lifecycle fields include `first_seen_at`, `last_seen_at`, `missing_complete_runs`, and `lifecycle_state`.

Lifecycle is `active | missing | inactive`. A service becomes inactive only after the configured number of successful complete inventories that omit it (default: three). Partial or failed inventory never advances that counter. Records are not hard-deleted.

### Cost items

A cost item is one cost fact. It may cover zero, one, or many services.

Important identity fields:

- `identity_key` identifies a stable source record and period;
- `logical_charge_key` groups competing evidence for the same charge;
- display names never participate in identity.

Independent enums:

```text
source_kind:      subscription | renewal_quote | usage | invoice | manual
charge_kind:      recurring_fixed | usage | one_time
amount_state:     known | unknown
evidence_state:   actual | estimate | quote | manual
tax_basis:        inclusive | exclusive | unknown
allocation_state: direct | shared_unallocated | allocated
```

Normalization is a calculation, not evidence. Ambiguity is an allocation state, not a synonym for unknown amount. Do not collapse these dimensions.

`cost_item_services` expresses coverage. A package charge that covers multiple services is one cost item with multiple relations; it is never copied onto every service row.

### Renewals, sync runs, and snapshots

`renewals` points at a cost item where possible and does not duplicate amount or currency.

`sync_runs` records trigger, `queued | running | succeeded | partial | failed | cancelled`, phase timestamps, counts, and sanitized summaries.

`cost_snapshots` stores captured totals, completeness counters, calculation version, the FX values used, and a replayable breakdown or input checksum. A snapshot is historical output, not a second source of truth.

#### Snapshot capture and rebuild

- Snapshots are written by `lafiel:snapshot` (scheduled daily) and automatically after every possible material input change: manual cost create/update/end and each successful or partial provider sync. Failed runs change nothing and write nothing. Capture is best-effort: a failed capture is logged, never fails the triggering operation, and is retried by the next trigger or the daily run.
- There is exactly **one snapshot row per date**. A later capture on the same date updates that row only when the stored output would change — the change detector, `input_checksum`, is a SHA-256 fingerprint of the stored payload itself (totals, completeness counters, breakdown, calculation version). Whatever moves the projection counts as a change: amounts, evidence upgrades, staleness, provider labels, the algorithm. Re-syncs that changed nothing are no-ops.
- `completeness` records how complete the sum was at capture (priced, unknown, estimate, stale, shared unallocated, one-time counts); `breakdown` stores every winning charge line. `fx_used` stays `null` until a real FX source exists (v1 has none) and is never rewritten after capture.
- **Rebuild:** snapshots recompute from `cost_items` history, which preserves past prices as closed versions. To rebuild, replay the command over the range: `lafiel:snapshot --date=YYYY-MM-DD` for each date (or a loop). A rebuild always recomputes under the **current** `costs.calculation_version` and overwrites the stored version and totals — it reproduces the original sum only while the calculation version is unchanged, which is why bumping `costs.calculation_version` on any algorithm change matters. Totals rebuild exactly; staleness counters and flags in a rebuilt row reflect current observations, not the original capture.
- The Overview chart recomputes past months from `cost_items` on purpose; it does not read snapshots. Snapshots are the audit/replay record.

## Money, periods, and FX

- Store money as integer minor units plus ISO-4217 currency. Never use binary floats.
- Calculate through `Money` and rational or decimal arithmetic.
- Monthly equivalents: monthly unchanged, quarterly divided by 3, annual divided by 12.
- One-time charges stay outside recurring spend.
- Closed-period usage may be actual; open-period usage is estimate.
- Unknown amounts stay outside the known sum and increment an explicit counter.
- Keep higher precision during calculation and round the presented total once, using half-even rounding.
- Persist the FX values used by a snapshot. Today's rate must not rewrite history.
- Until a reliable, licensed source is selected, require PLN or explicit manual FX. Never choose a silent FX provider.

## Aggregation invariant

Aggregate active cost items, never services. Include a cost item once when its amount is known, it applies on the snapshot date, and stronger evidence has not superseded it.

Evidence precedence for the same logical charge:

```text
invoice actual > usage actual > subscription or renewal quote > manual override
```

A manual override wins only when consciously enabled. Actual replaces a quote; the two are not added. An override also answers the provider's unknown on the services it covers: an unknown-amount synced charge whose covered services all carry an open override stops counting toward the incompleteness totals, while the charge itself remains as provenance.

Allowed UI forms include:

- `187.42 PLN/mo`
- `~187.42 PLN/mo`
- `187.42 PLN/mo + 2 unknown`
- `187.42 PLN/mo fixed + metered usage pending`

## Sync algorithm

1. Acquire a lock for the provider account and sync kind.
2. Create a `SyncRun`.
3. Validate credentials only when needed.
4. Fetch inventory and cost facts outside a database transaction.
5. Validate batch completeness and canonical invariants.
6. Persist idempotently in a short transaction.
7. Apply `missing`/`inactive` transitions only after complete inventory.
8. Recalculate projections and snapshot after a material input change or schedule.
9. Finish the run with counts and sanitized warnings.

Retry transient 429, 5xx, and timeout failures with bounded exponential backoff and jitter. Do not retry invalid credentials forever. “Sync now” dispatches the same job and must not create a parallel run.

A failed or partial sync keeps the last good data, marks affected capabilities stale, never writes zero as a substitute for missing data, and never treats absence as cancellation.

A complete cost batch ends the charges it no longer reports, judged per capability: the batch names the capabilities its observation represents (`reportedCapabilities`), and only complete ones open the ending gate. A capability the batch does not represent — Cloudflare's permanently partial metered usage — cannot undercut the evidence of one it does. A batch that names no reported capabilities keeps the conservative rule: every declared cost capability must be complete before anything ends.

## Security

- Encrypt provider credentials with Laravel and `APP_KEY`.
- Document that restoring encrypted credentials requires the same `APP_KEY`.
- Give every provider credential the smallest read-only scope.
- Redact secrets and sensitive raw payload fields before logging or fixture capture.
- Do not store raw provider payloads by default.
- v1 has one local administrator, authorization and CSRF on mutations, and no multi-tenancy.

## Testing strategy

Test domain behavior rather than framework behavior:

- Money/period conversion and deterministic rounding;
- no double-counting of shared charges;
- evidence precedence and unknown amounts;
- complete, partial, and failed provider batches;
- idempotent re-sync;
- per-account locking;
- the three-complete-run inactive threshold;
- preservation of the last good state on error;
- credential and log redaction;
- replayable snapshots and frozen-clock behavior;
- first-administrator race/idempotency;
- critical Livewire flows.

## Explicitly deferred

Infrastructure mutations, optimization advice, budgets and alerts, accounting/VAT features, multi-tenant SaaS, a provider plugin marketplace, Kubernetes, Kafka, event sourcing, microservices, SPA architecture, and an AI advisor.


