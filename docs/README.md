# Lafiel project documentation

> Migrated and normalized from the AFFiNE Lafiel workspace. These repository documents are the agent-readable source of truth. AFFiNE may contain exploration and working notes, but implementation must not require AFFiNE access.

Lafiel is a small, self-hosted infrastructure cost ledger for one person or a small trusted team. It reads provider data, accepts manual costs, and makes uncertainty visible. It never creates, renews, scales, or deletes infrastructure.

## Reading order

1. [Architecture](architecture.md) — product boundaries, domain invariants, data model, sync behavior, security, and testing.
2. [Implementation plan](implementation-plan.md) — milestones, issue map, dependencies, and review gates.
3. [Integrations](integrations.md) — provider capabilities, evidence rules, credentials, and provider-specific risks.
4. [Design system](design-system.md) — Imperial Ledger visual language, semantic tokens, layouts, and UI rules.

## Non-negotiable rules for agents

- Work only on the assigned issue and its acceptance criteria.
- Preserve separate dimensions for amount knowledge, evidence, normalization, allocation, completeness, and freshness. Do not replace them with one `confidence` field.
- Aggregate cost items, never service rows. A shared charge is counted once.
- Provider access is read-only. Lafiel must not expose infrastructure mutations.
- Unknown, estimated, stale, partial, and ambiguous data remain visible in domain state and UI.
- Adapters return canonical DTO batches; they do not persist Eloquent models directly.
- Keep credentials encrypted and out of serialization, logs, exceptions, fixtures, and documentation.
- Do not add deferred features while implementing v1.
- Use repository-relative references. Do not send another agent back to AFFiNE.

## Scope map

| Area | v1 | Later |
| --- | --- | --- |
| Authentication | One local administrator; no public registration | Multiple users / multi-tenancy |
| Manual costs | Full vertical slice | Advanced allocation workflows |
| OVHcloud | Inventory and renewal quotes | Invoice actuals if a reliable API is proven |
| Cloudflare | — | Fixed subscriptions in v1.1; metered usage only after a capability spike |
| Contabo | — | Inventory with manual price overlays |
| Hetzner | — | Cloud and Robot adapters |
| Currency | PLN or explicitly configured manual FX | A licensed, documented daily FX source |
| Operations | Docker/Coolify, queue, scheduler, backup/restore | Broader deployment targets |

## Source provenance

This documentation promotes the useful content from four AFFiNE pages:

- `Architektura bazowa: Lafiel — rejestr kosztów infrastruktury`
- `Przekazanie implementacyjne: Lafiel — v1 i mapa zadań`
- `Integracje: Lafiel — analiza providerów i kontrakty v1`
- `Projekt wizualny: Lafiel — Imperial Ledger`

The unrelated personal page `Cosplay Idea: Lafiel — Seikai no Monshou / Senki` was intentionally excluded.


