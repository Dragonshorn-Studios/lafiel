# OVH fixtures — SYNTHETIC, redacted

**These captures are synthetic.** They are shaped from the public OVHcloud
API documentation and use invented service names and ids; no real account
was read to produce them. They exist so the OVH adapter tests (issues #9,
#10, #11) can run without credentials. Before any OVH estimate is trusted,
regenerate this directory from a real read-only account spike (see
`docs/integrations.md`, "Required real-account spike") and confirm the
shapes still match.

## The three required cases

| Case | Fixtures |
| --- | --- |
| Ordinary priced service | `service/vps-synthetic-01.json` + `catalog/vps-eu.json` (a matching plan with prices) |
| Unknown price | `service/domain-zone-synthetic-01.json` + `catalog/domain-eu.json` (no matching plan — price must stay unknown, never inferred) |
| Missing on the next complete run | `ip-synthetic-01` appears in `service-run-a.json` but not in `service-run-b.json` (both complete runs) |

## Layout

Files mirror the API paths an adapter reads:

- `me.json` — `GET /me` (connection test)
- `service-run-a.json`, `service-run-b.json` — `GET /service` listings (complete inventory runs)
- `service/{serviceName}.json` — `GET /service/{serviceName}`
- `catalog/{catalogName}.json` — `GET /order/catalog/formatted/{catalogName}` (renewal pricing)

## Redaction rules

No credential material, no account identity: keys and person names are
`[redacted]`, service names are `*-synthetic-*`, ids are invented, and
contact fields carry `[redacted]`. The redaction test
(`tests/Feature/Domain/Providers/Ovh/OvhFixturesTest.php`) fails if a
future edit reintroduces secret-shaped values.
