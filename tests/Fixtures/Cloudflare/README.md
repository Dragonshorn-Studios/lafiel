# Cloudflare fixtures — SYNTHETIC, redacted

**These captures are synthetic.** They are shaped from the public
Cloudflare API v4 documentation and use invented zone names, account
names, and subscription ids; no real account was read to produce them.
They exist so the Cloudflare adapter tests (issue #15) can run without
credentials. Before any Cloudflare number is trusted, regenerate this
directory from a real read-only account spike (a token with Billing
Read and Zone Read) and confirm the response shapes still match — the
subscription payload shape in particular is an assumption of this
ticket, not a documented contract.

## Assumed subscription shape (pending spike)

```
rate_plan.public_name, rate_plan.currency, rate_plan.components[]
  — components with a numeric `price` are the fixed part; metered
    components carry none
frequency: monthly | quarterly | annually | yearly
state: Paid | Free map to facts; anything else is skipped with a warning
current_period.start / current_period.end
```

## The three required cases

| Case | Fixtures |
| --- | --- |
| Ordinary priced subscription | `account-subscriptions.json` (`sub-workers-synthetic-01`, 5.00 USD monthly) and `zone-subscriptions-a.json` (`sub-pro-synthetic-01`, 20.00 USD monthly) |
| Unknown price | `account-subscriptions.json` (`sub-unknown-price-synthetic-02` — no price component; the charge must stay unknown, never inferred) |
| Missing on the next complete run | `sub-pro-synthetic-01` is present in `zone-subscriptions-a.json` (run A) and the zone reports nothing in run B (empty result) — both complete subscription observations |

`zone-subscriptions-b.json` additionally prices the free zone at 0.00 —
a known zero (free plan) is a price, not an absence, and must never be
confused with the unknown-price case above.

## Layout

Files mirror the API v4 paths the adapter reads (raw envelopes,
`success`/`result`/`result_info`):

- `token-verify.json` — `GET /user/tokens/verify` (connection test)
- `accounts.json` — `GET /accounts`
- `zones.json` — `GET /zones`
- `account-subscriptions.json` — `GET /accounts/{id}/subscriptions`
- `zone-subscriptions-a.json`, `zone-subscriptions-b.json` — `GET /zones/{id}/subscriptions` (a paid zone and a free zone)

## Redaction rules

No credential material, no account identity: zone names are
`*-synthetic.*`, ids are invented (`*-synthetic-*`), and no token
values appear. The redaction test
(`tests/Feature/Domain/Providers/Cloudflare/CloudflareFixturesTest.php`)
fails if a future edit reintroduces secret-shaped values.
