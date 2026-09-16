# OVH fixtures — SYNTHETIC, redacted

**These captures are synthetic.** They are shaped from the public OVHcloud
API documentation and use invented service names and ids; no real account
was read to produce them. They exist so the OVH adapter tests can run
without credentials. Before any OVH estimate is trusted, regenerate this
directory from a real read-only account spike (see `docs/integrations.md`,
"Required real-account spike") and confirm the shapes still match.

## The required cases

| Case | Fixtures |
| --- | --- |
| Ordinary priced service | `service-renew/400010002.json` (a single-service strategy with a selected price) |
| Multi-service strategy | `service-renew/400010001.json` — one strategy covers the VPS (`400010001`) and its failover IP (`400010005`); one fact carries the summed price linked to both services, never a copy per service. `service-renew/400010005.json` carries the same payload — sibling members of a strategy each get a copy, and the adapter must emit the strategy once |
| Public Cloud coverage gap | `service-renew/400010003.json` (no selected price; a Public Cloud project is never priced from the public catalog — its cost is an explicit unknown) |
| Catalog fallback | `service-renew/400010004.json` (no selected price) + `catalog/ip-eu.json` product `ip-block-2025` (the fallback estimate; remove the product to model a missing fallback match) |
| Missing on the next complete run | service `400010004` appears in `services-run-a.json` but not in `services-run-b.json` (both complete runs) |

## Layout

Files mirror the API paths an adapter reads:

- `me.json` — `GET /me` (identity: subsidiary, currency, connection test)
- `services-run-a.json`, `services-run-b.json` — `GET /services` listings (complete inventory runs)
- `service-renew/{serviceId}.json` — `GET /service/{serviceId}/renew` renewal strategies
- `catalog/{catalogName}.json` — `GET /order/catalog/formatted/{catalogName}` (fallback pricing only)

## Redaction rules

No credential material, no account identity: keys and person names are
`[redacted]`, service names are `*-synthetic-*`, ids are invented, and
contact fields carry `[redacted]`. The redaction test
(`tests/Feature/Domain/Providers/Ovh/OvhFixturesTest.php`) fails if a
future edit reintroduces secret-shaped values.
