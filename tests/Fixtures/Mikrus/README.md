# Mikr.us fixtures — SYNTHETIC, redacted

**These captures are synthetic.** They are shaped from the public
mikr.us API page (https://api.mikr.us/) and from what the community
mikr.us CLI reports; they use invented server names, ips, and dates;
no real account was read to produce them. They exist so the mikr.us
adapter tests (issue #69) can run without credentials. Before any
mikr.us assumption is trusted, regenerate this directory from a real
read-only account spike and confirm the response shapes still match.

## What the shapes assume

```
POST /serwery          (key) → list of servers: name (stable server id),
                              proto, ip, optional virtualization
POST /info             (key, srv) → one server: name, expire, pro,
                              cytrus_expire, storage_expire, uptime
auth: form field `key` on every call; bad key → HTTP 401/403
```

`expire`/`cytrus_expire`/`storage_expire` are assumed to be unix
timestamps and `pro` a flag; the adapter passes them into
`services.metadata` raw under `expiration`/`is_pro`/`cytrus_expiration`/
`storage_expiration` and never interprets them — a shape change shows
up as changed metadata, not as a sync failure.

`/cloud` is deliberately absent: its response shape is unverifiable
without a real account and it is out of the adapter's v1 scope.

The adapter's inventory-only stance rests on there being no billing
endpoint — the public API page documents none (no price, invoice, or
balance surface).
