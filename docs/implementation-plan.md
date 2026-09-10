# Implementation plan

> Source: AFFiNE page `Przekazanie implementacyjne: Lafiel — v1 i mapa zadań`. This repository version maps the handoff to the existing GitHub issues and is authoritative for agents.

## Definition of v1 done

From a fresh deployment, a user can create the single local administrator, add a manual cost, connect OVHcloud with read-only credentials, sync inventory and renewal quotes, inspect known and uncertain totals, see renewals and history, disable a provider without losing history, and update the container without manual data repair.

v1 does not promise invoice actuals, Cloudflare, metered usage, budgets, or infrastructure changes.

## Milestones

| Milestone | Outcome | Issues |
| --- | --- | --- |
| M0 — Foundation | Bootable app, administrator, schema, queue, scheduler, health, CI | #1–#3 |
| M1 — Manual vertical slice | A manual recurring cost reaches Overview and history through the production model | #4–#6 |
| M2 — Sync and OVH | Provider contract, lifecycle, credentials, real-account spike, inventory and renewals | #7–#12 |
| M3 — History and release | Replayable snapshots, operations hardening, target deployment dogfood | #13–#14 |
| M4 — Cloudflare v1.1 | Fixed subscriptions with explicit metered-usage incompleteness | #15 |
| Later providers | Contabo and Hetzner after v1 | #16–#17 |

## Dependency graph

```text
#18 docs bootstrap
  └─ #1 app skeleton
      ├─ #2 administrator
      └─ #3 canonical schema
          ├─ #5 cost projection
          │   ├─ #4 manual cost slice
          │   ├─ #6 Imperial Ledger shell
          │   └─ #13 snapshots
          └─ #7 provider contract
              ├─ #8 lifecycle
              │   └─ #10 OVH inventory
              └─ #9 credentials + OVH spike
                  └─ #10 OVH inventory
                      └─ #11 OVH renewals
                          ├─ #12 service/provider views
                          └─ #13 snapshots
#14 v1 release requires #1–#13
#15–#17 follow #14
```

## Issue briefs

### #1 App skeleton and delivery pipeline

Laravel 13/PHP 8.3, PostgreSQL, Vite/Tailwind, formatting, static analysis, tests, and CI. A clean checkout boots; migrations and checks are documented; the production image contains built assets and no Vite dev server.

### #2 First administrator and install state

Create the administrator exactly once, disable public registration, protect mutations with auth and CSRF, and test setup concurrency and idempotency.

### #3 Canonical schema, enums, and Money

Implement the model from [Architecture](architecture.md). Database constraints own service and charge identity. No floats. Keep evidence, amount, normalization, allocation, completeness, and freshness independent. One owner controls migrations and canonical DTOs until this merges.

### #4 Manual cost vertical slice

Create, list, edit, and end manual services/costs. Price changes preserve history; ended items leave future projections but remain historical. Manual cost uses the shared projection and can overlay a provider-discovered service.

### #5 Cost projection and aggregation

Build monthly/annual equivalents, evidence precedence, completeness counters, coverage, and snapshot breakdown. Each logical charge is counted once; actual supersedes quote; unknown stays outside the known sum.

### #6 Imperial Ledger shell and Overview

Build the desktop/mobile shell from [Design system](design-system.md), initially using fixtures if necessary. The UI renders the projection and never implements a second calculator.

### #7 Provider contract and sync orchestration

Implement adapter registry, canonical DTO batches, capabilities, per-account locks, `SyncRun`, bounded retries, and idempotent persistence. Provider adapters do not save Eloquent directly.

### #8 Service lifecycle and freshness

Track first/last seen and per-capability freshness. Partial inventory cannot mark resources missing. Default inactive threshold is three successful complete inventories. Errors preserve last good data.

### #9 OVH credentials and real-account spike

Add encrypted read-only credentials, connection test, least-privilege documentation, and a spike on a real account. Record redacted fixtures for ordinary, unknown-price, and missing-on-next-complete-run cases.

### #10 OVH inventory adapter

Map OVH services through stable external IDs with explicit completeness. Re-running the same inventory is idempotent. A complete omission follows #8; a partial inventory does not.

### #11 OVH renewal quotes and package coverage

Map renewal strategies and price parts to cost facts and coverage relations. A package charge is counted once. Quotes remain quotes/estimates; uncertain allocation is `shared_unallocated`; absent prices are unknown.

### #12 Services, providers, and renewals views

Expose source amount, equivalent, renewal, freshness, evidence, packages, and per-capability health on desktop and mobile. Disabling a provider stops schedule and preserves history.

### #13 Snapshots and cost history

Create replayable daily/material-change snapshots with calculation version and captured FX. Identical runs add no noise. Current FX never rewrites history.

### #14 Ops hardening and v1 release

Document and verify backup/restore, `APP_KEY`, updates, health, queue/scheduler failure visibility, error pages, and a full scheduled OVH cycle on the target Coolify deployment.

### #15 Cloudflare fixed subscriptions

After v1, read account/zone fixed subscriptions with Billing Read. Usage remains a separate capability; the UI must not call a fixed-subscription subtotal a complete provider total when metered products may exist.

### #16 Contabo inventory and manual price overlay

After v1, discover Compute and Object Storage using a dedicated read-only API user. Storage VPS is explicitly unsupported. Manual prices bind to stable resource identity and survive re-sync/rename.

### #17 Hetzner Cloud and Robot inventory

After v1, spike Cloud, Robot, or both. Cloud catalog prices are estimates; Robot inventory may require manual price overlays. Credentials and capabilities remain separate and read-only.

## Review gates

1. Schema, Money/Period, and aggregation invariants are reviewed before parallel domain work.
2. A manual cost reaches Overview through the production projection.
3. OVH behavior is proven with redacted fixtures from a read-only real-account spike.
4. Database restore with the same `APP_KEY` and a full scheduled Coolify cycle are demonstrated.

## Provider-ticket definition of done

- capabilities are explicit;
- success, partial, and error fixtures are redacted;
- idempotency is tested;
- observation timestamps and source references are stored;
- unknown data is visible;
- logs and payloads contain no secrets;
- errors preserve last good state;
- UI distinguishes evidence from projection.

## Agent task preamble

When assigning an implementation issue, use this context:

> Implement only the assigned Lafiel issue. Read `docs/architecture.md` and `docs/implementation-plan.md`; for provider work also read `docs/integrations.md`, and for UI work read `docs/design-system.md`. Preserve independent evidence/completeness/normalization/allocation/freshness dimensions, aggregate charges rather than service rows, and keep provider access read-only. Do not add deferred features. Run the issue acceptance checks and report behavior changes, validation, and any provider uncertainty.


