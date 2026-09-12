# Data-semantics glossary

> How Lafiel's stored fields should be read. These are product semantics, not implementation details — the UI and every integration must respect them.

## Money and amounts

| Term | Meaning |
| --- | --- |
| Source amount | The amount exactly as the source stated it, in its own currency and period. Never converted, never normalized. |
| Monthly equivalent | The source amount converted to one month (quarterly ÷ 3, annual ÷ 12) with exact rational arithmetic, rounded once. |
| Unknown amount | The source gave no usable price. It stays out of every known total and is counted instead. |
| Known zero | `0.00` is a price, not an absence. |

## Evidence

| Term | Meaning |
| --- | --- |
| Invoice actual | What an invoice says was paid. Strongest evidence. |
| Usage actual | What metered usage says was consumed. |
| Subscription / renewal quote | What the provider says renewal will cost. A quote is an estimate of the future, never a past fact. |
| Estimate | Catalog-derived or otherwise modelled pricing. Provenance is always visible. |
| Manual | Entered by a person. Never goes stale. |
| Manual override | A conscious human replacement of provider evidence. Wins over everything, and is labelled as an override. |

Precedence for one logical charge: invoice actual > usage actual > subscription or renewal quote > manual, with a conscious manual override above all. Actual replaces a quote; the two are never added.

## Allocation

| Term | Meaning |
| --- | --- |
| Direct | The price belongs to the covered services as stated. |
| Shared unallocated | A package price covers several services and cannot be attributed per service. Counted once, flagged as uncertain. |

## Completeness

| Term | Meaning |
| --- | --- |
| Priced | Winning charges with a known amount and a known, recurring cadence. |
| Unknown | Winning charges with no price or no placeable cadence. Counted, never summed. |
| Estimate / stale / shared unallocated | Visible counters on the snapshot and Overview — incompleteness is a product feature, never hidden. |

## Freshness and lifecycle

| Term | Meaning |
| --- | --- |
| Fresh | Synced evidence observed within the freshness window (default 7 days). |
| Stale | Synced evidence older than the window, or with no observation. Still counted; flagged amber. |
| Manual evidence | Never goes stale. |
| Missing | A service absent from one complete inventory run. |
| Inactive | Absent from `LAFIEL_INACTIVE_AFTER_COMPLETE_RUNS` consecutive complete runs (default 3). Partial runs never advance this. |

## Provider health

| Term | Meaning |
| --- | --- |
| Capability | What an adapter can do: inventory, renewal quotes, subscriptions, usage, invoices. |
| Healthy | The last attempt produced complete, usable data. |
| Stale / failed | Supported, but the last attempt degraded or failed. |

## History

| Term | Meaning |
| --- | --- |
| Snapshot | The captured projection output for one date: totals, completeness counters, full breakdown, calculation version, FX used. One row per date; historical output, never a second source of truth. |
| Calculation version | The projection algorithm's version at capture. Bumped on meaningful change so stored snapshots stay comparable. |
| Rebuild | Recomputing a snapshot for a past date from stored cost-item history. Reproduces totals under the current calculation version. |
