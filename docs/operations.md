# Operations guide

> Running Lafiel in production: install, first boot, Docker/Coolify, the queue and scheduler, backup and restore, APP_KEY handling, updates, and the known provider-gaps checklist. The data vocabulary lives in the [glossary](glossary.md).

## Install

1. Clone the repository on the target host (or point Coolify at the repo).
2. Copy `.env.production.example` to `.env` next to `docker-compose.yml`.
3. Fill in `APP_KEY` (`php artisan key:generate --show` from any machine with the repo), `APP_URL`, `DB_PASSWORD`.
4. `docker compose up -d --build`.
5. Open `APP_URL` — first boot redirects to the setup screen to create the single local administrator.

The stack is three containers from one image plus PostgreSQL: **web** (nginx + php-fpm), **queue** (`php artisan queue:work`), **scheduler** (`php artisan schedule:work`). Roles are selected by the `ROLE` environment variable.

## First boot

- The web role runs `php artisan migrate --force` once (`RUN_MIGRATIONS=true` on the app service).
- Setup creates the one local administrator. Public registration does not exist.
- After setup, the pipeline liveness checks arm themselves: from that point, a missing scheduler or queue heartbeat turns the health endpoint red.

## Queue and scheduler

- The **scheduler** runs `php artisan schedule:work` and is what fires the daily jobs. If it is dead, daily snapshots and scheduled syncs silently stop — that is why its heartbeat is on the health endpoint.
- The **queue** runs `php artisan queue:work`. Provider syncs are queued jobs; a dead worker means syncs queue but never run.
- Both write a heartbeat every minute (`ops:scheduler-heartbeat`, `ops:queue-heartbeat` in the database cache). A heartbeat older than 5 minutes, or missing after setup, fails the `/up` health endpoint and `lafiel:ops`.

## Health and monitoring

- `GET /up` — 200 when the database and pipeline are healthy; 500 when the database is unreachable, a heartbeat is stale, or stored credentials are unreadable. The compose healthcheck consumes this.
- `php artisan lafiel:ops` — prints every check with severity and detail, exits 1 on critical failures. Wire it into cron or a monitor for alerting.
- Checks: database, scheduler heartbeat, queue heartbeat, credential readability, snapshot age (warning when the latest snapshot is older than 2 days).

## Scheduled jobs

| Schedule | Job | Purpose |
| --- | --- | --- |
| every minute | `lafiel:heartbeat` | scheduler liveness heartbeat |
| every minute | `HeartbeatJob` (queued) | queue liveness heartbeat |
| daily 04:00 | `lafiel:sync --trigger=schedule` | sync every enabled provider account |
| daily 23:40 | `lafiel:snapshot` | daily cost snapshot |

Material input changes (manual cost create/update/end, successful or partial syncs) also capture snapshots immediately; the input checksum dedupes identical runs.

## Backup and restore

**Backup**: dump the database. Everything stateful lives in PostgreSQL (costs, services, credentials, snapshots, users) plus the `.env` (APP_KEY, DB credentials).

```bash
docker compose exec postgres pg_dump -U lafiel lafiel > lafiel-backup-$(date +%F).sql
cp .env lafiel-env-backup-$(date +%F)
```

**Restore**: bring up the stack with the **same APP_KEY** as the backup, then restore the dump.

```bash
docker compose up -d postgres
cat lafiel-backup-2026-09-11.sql | docker compose exec -T postgres psql -U lafiel lafiel
docker compose up -d
```

- The same APP_KEY is **required**: stored provider credentials are encrypted with it, and a different key makes them unreadable (the health endpoint and `lafiel:ops` will say so).
- Snapshots are rows in the dump like everything else; a restore replays history as it was captured.

## APP_KEY

- `APP_KEY` encrypts provider credentials at rest. Generate once with `php artisan key:generate --show`, store it with the backups, and never rotate casually.
- Rotating it does not corrupt the database — it makes existing provider credentials unreadable. The health endpoint reports `credentials unreadable`; replace the credentials on the Providers page to recover.
- Re-entering the same APP_KEY after an accidental rotation restores readability.

## Updates

- `git pull && docker compose up -d --build` — the app role re-runs migrations on boot; credentials and history survive because they live in the PostgreSQL volume.
- Snapshots store their `calculation_version`; updating the app never rewrites captured history (see [architecture](architecture.md), "Snapshot capture and rebuild").

## OVH read-only credentials

Create an OVHcloud API application, then delegate the smallest read-only rights — `GET` on `/me` (identity and connection test), `/services` and `/service/*` (inventory and renewal strategies), `/order/catalog/formatted/*` (fallback pricing), and `/me/bill*` (reserved for the billing sync). Lafiel never calls a mutating OVH endpoint — no create, renew, scale, or delete. See the [OVH rights delegation guide](https://help.ovhcloud.com/csm/de-api-api-rights-delegation?id=kb_article_view&sysparm_article=KB0068603).

## Known provider gaps (v1 checklist)

- **OVH**: adapter proven against synthetic fixtures only — the [real-account spike](https://github.com/Dragonshorn-Studios/lafiel/issues/9) has not run yet. Renewal quotes come from `/service/{serviceId}/renew` and are estimates, never actuals; the public catalog only backfills unpriced services as a marked fallback estimate. No renewal date is trusted yet, so no renewal rows are written (auto-renew flags without a known date are not persisted). No invoice actuals; Public Cloud usage and billing are an explicit unsupported gap, never a known zero (#51).
- **Renewal reconciliation**: a provider stopping to report a renewal date does not yet end the stored renewal row (one-sided writes are deliberate; reconciliation is a future decision).
- **Overdue renewals**: the Overview window shows the next 30 days; renewals past their date leave the window and live only on the Renewals page.
- **FX**: totals are per currency, never converted. A live-FX preview would be a separate view with an explicit, stored rate source.
- **Cloudflare**: adapter shipped (#15) against synthetic fixtures only — the real-account spike (a token with Billing Read and Zone Read) has not run yet, and the subscription payload shape is a documented assumption. Fixed subscriptions are counted at `actual` evidence; metered usage is never included and every Cloudflare run reads partial. No invoice actuals, no usage metering.
- **Contabo**: adapter shipped (#16) against synthetic fixtures only — the real-account spike has not run yet and must confirm the payload shapes and probe for any account-specific billing surface. Every price is unknown until a manual overlay is attached; no invoice actuals.
- **Hetzner Cloud**: adapter shipped (#17) against synthetic fixtures only — the real-account spike (a project token with a read-only role) has not run yet. Catalog prices are net estimates with VAT preserved in the notes; volumes are per-GB × size, rounded once; resources without a pricing entry count as unknown. Snapshots and images are not inventoried yet (spike items). **Hetzner Robot**: deliberately not implemented (#17) — the webservice shape needs a real-account spike first.

## Clearing synced provider data

After a test sync on a live database, **Clear synced data** on the Providers page removes one account's discovered services, provider charges, capability health, and sync history while keeping the connection and credentials. Independent charges entered by hand stay. A queued or running sync blocks the wipe; wait for it to finish. Disconnect does the same wipe and then removes the account.
