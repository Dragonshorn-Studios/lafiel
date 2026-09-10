# Lafiel

Self-hosted infrastructure cost ledger. Lafiel combines provider inventory and billing evidence with manual cost records, projects recurring spend, tracks renewals, and preserves replayable history. It is strictly observational: it reads provider data but never changes infrastructure.

Read [docs/architecture.md](docs/architecture.md) before coding, and [docs/implementation-plan.md](docs/implementation-plan.md) for the issue map. Design guidance lives in [docs/design-system.md](docs/design-system.md), provider integration notes in [docs/integrations.md](docs/integrations.md).

## Stack

- PHP 8.5, Laravel 13, Livewire + Flux, Tailwind (via Vite)
- PostgreSQL in production (local development defaults to SQLite)
- Database queue by default; Redis is optional and not required
- Node.js 22 LTS only to build frontend assets — the Vite dev server never runs in production

## Local development

Requirements: PHP 8.5+, Composer, Node.js 22 LTS.

```sh
composer setup    # composer install, .env + app key, migrations, npm install, npm run build
composer dev      # app server, queue worker, log tail, and Vite dev server
```

A clean checkout boots on SQLite with no services: visiting the app redirects to the login screen. The first administrator is created through registration until the first-run setup flow (#2) lands. Run pending migrations with `php artisan migrate`.

### Check commands

| Command | What it runs |
| --- | --- |
| `composer test` | Pint (formatting), PHPStan (static analysis), and the Pest suite |
| `composer lint` | Auto-fix code style with Pint |
| `composer types:check` | PHPStan only |
| `php artisan test` | Pest suite only |

CI (`.github/workflows/tests.yml`) runs the same checks on PHP 8.5 against PostgreSQL on every push and pull request.

## Production deployment

One production image runs in three roles, selected with the `ROLE` environment variable: `web` (nginx + PHP-FPM), `queue` (`queue:work`), and `scheduler` (`schedule:work`). Assets are built into the image; migrations are run by the web role on start (`RUN_MIGRATIONS=true`).

### Docker Compose

```sh
cp .env.production.example .env   # then set APP_KEY, APP_URL, DB_PASSWORD
docker compose up -d --build
```

The web role listens on `APP_PORT` (default 8080). The stack contains PostgreSQL with a named volume for data; `/health`-style checks use `GET /up`, which returns 200 only when the application and the database are both healthy.

### Coolify

Deploy the repository as a Docker Compose service: point a domain at the `app` service (container port 80) and set `APP_KEY`, `APP_URL`, and `DB_PASSWORD` as environment variables (the compose file interpolates them from the service environment). Set Coolify's health check path to `/up`.

### Operational notes

- Provider credentials are encrypted with `APP_KEY`. Restoring a backup onto an app with a different `APP_KEY` makes stored credentials unreadable — back the key up with the database (see docs/architecture.md, Security).
- Containers log to stderr (`LOG_CHANNEL=stderr`), so `docker logs` / Coolify logs show application output.
- Schedule ticks require exactly one `scheduler` container; `schedule:work` handles that role.
