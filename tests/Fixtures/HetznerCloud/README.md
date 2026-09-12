# Hetzner Cloud fixtures — SYNTHETIC, redacted

**These captures are synthetic.** They are shaped from the public
Hetzner Cloud API documentation and use invented names and ids; no
real account was read to produce them. They exist so the Hetzner Cloud
adapter tests (issue #17) can run without credentials. Before any
Hetzner estimate is trusted, regenerate this directory from a real
read-only project token spike and confirm the shapes still match.

## What the shapes assume

```
GET /servers|/load_balancers|/primary_ips|/floating_ips|/volumes
  → collection key per resource; meta.pagination.last_page
GET /pricing → currency, vat_rate, pricing.{server_types,
  load_balancer_types, primary_ips, floating_ips, volumes}
  — price_monthly.net / price_per_gb_month.net carry many fractional
    digits (e.g. "4.9900000000"); nets are exact and rounded once
```

Primary IPs and floating IPs may carry `name: null` — the IP itself
is the display name. Volumes are priced per GB times their size.

## The three required cases

| Case | Fixtures |
| --- | --- |
| Ordinary priced resource | `servers.json` (`web-synthetic-01`, cx22 @ fsn1 → 4.99 net) |
| Unknown price | `servers.json` (`db-synthetic-02`, cx32 @ nbg1) has no matching pricing entry in `pricing.json` — the charge must stay unknown, never inferred |
| Missing on the next complete run | lifecycle tests script a run where one server disappears (complete inventory) and assert the estimate charge ends |

Snapshots and images are separately billable but deliberately not in
this fixture set — they are spike items (per-GB and per-server
pricing shapes to be confirmed on a real account).

## Redaction rules

No credential material, no account identity: names are
`*-synthetic-*`, ids are invented, IPs are documentation examples.
The redaction test
(`tests/Feature/Domain/Providers/Hetzner/Cloud/HetznerCloudFixturesTest.php`)
fails if a future edit reintroduces secret-shaped values.
