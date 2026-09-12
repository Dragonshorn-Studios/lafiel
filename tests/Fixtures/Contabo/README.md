# Contabo fixtures — SYNTHETIC, redacted

**These captures are synthetic.** They are shaped from the public
Contabo API documentation and use invented display names and ids; no
real account was read to produce them. They exist so the Contabo
adapter tests (issue #16) can run without credentials. Before any
Contabo assumption is trusted, regenerate this directory from a real
read-only account spike and confirm the response shapes still match —
including whether any account-specific billing surface exists (the
manual-price assumption rests on there being none).

## What the shapes assume

```
GET /v1/compute/instances        → data[].instanceId, displayName, productType
GET /v1/object-storage/instances → data[].objectStorageId, displayName
pagination: size/page query params; _metadata.totalCount
```

Storage VPS is unsupported by the Compute API and never appears in
these captures; the adapter never sees one.

## The three required cases

| Case | Fixtures |
| --- | --- |
| Ordinary resource | `compute-instances.json` (`web-synthetic-01`) — inventoried, price unknown |
| Unknown price | every resource: Contabo exposes no billing surface, so all charges are unknown by design; `object-storage-instances.json` (`backup-synthetic-01`) is the storage case |
| Missing on the next complete run | lifecycle tests script a run where one instance disappears (complete inventory) and assert the unknown charge ends |

## Redaction rules

No credential material, no account identity: names are
`*-synthetic-*` and ids are invented. The redaction test
(`tests/Feature/Domain/Providers/Contabo/ContaboFixturesTest.php`)
fails if a future edit reintroduces secret-shaped values.
