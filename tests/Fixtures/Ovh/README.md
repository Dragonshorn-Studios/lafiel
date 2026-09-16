# OVH fixtures — SYNTHETIC, redacted

**These captures are synthetic.** They follow the published OVHcloud
API schemas (`GET /services` → `long[]`, `GET /services/{id}` →
`services.expanded.Service`, `GET /service/{id}/renew` →
`service.renew.RenewDescription[]`, formatted vps/ip catalogs →
`order.catalog.Catalog`) and use invented service names and ids; no
real account was read to produce them. They exist so the OVH adapter
tests can run without credentials. Before any OVH estimate is trusted,
regenerate this directory from a real read-only account spike (see
`docs/integrations.md`, "Required real-account spike") and confirm the
shapes still match.

## The required cases

| Case | Fixtures |
| --- | --- |
| Ordinary priced service | `services/400010002.json` — contracted `billing.pricing` (`priceInUcents`) |
| Sibling services billed separately | `services/400010001.json` (VPS 7.00) and `services/400010005.json` (failover IP 2.00). Official `/renew` payloads still list a bundled 9.00 order preview; the adapter must not use that preview as either service's charge |
| Public Cloud coverage gap | `services/400010003.json` (`pricingType: consumption`) — never catalog-priced |
| Catalog fallback | `services/400010004.json` (no `billing.pricing`, empty `/renew`) + `catalog/ip-eu.json` plan `ip-block-2025` |
| Missing on the next complete run | service `400010004` appears in `services-run-a.json` but not in `services-run-b.json` (both complete runs) |

Published `route.path` values used by classification tests (mutated on the VPS fixture, not extra files): `GET /domain/{serviceName}`, `GET /hosting/web/{serviceName}`, `GET /ipLoadbalancing/{serviceName}`. `/ip` must not match `/ipLoadbalancing`. There is no `/domain/name` API.

## Layout

Files mirror the API paths an adapter reads:

- `me.json` — `GET /me` (identity: subsidiary, currency, connection test)
- `services-run-a.json`, `services-run-b.json` — `GET /services` id lists
- `services/{serviceId}.json` — `GET /services/{serviceId}` expanded service
- `service-renew/{serviceId}.json` — `GET /service/{serviceId}/renew` possible order combinations (fallback only)
- `catalog/{catalogName}.json` — `GET /order/catalog/formatted/{catalogName}` (`order.catalog.Catalog`, last-resort estimate)

## Redaction rules

No credential material, no account identity: keys and person names are
`[redacted]`, service names are `*-synthetic-*`, ids are invented, and
contact fields carry `[redacted]`. The redaction test
(`tests/Feature/Domain/Providers/Ovh/OvhFixturesTest.php`) fails if a
future edit reintroduces secret-shaped values.
