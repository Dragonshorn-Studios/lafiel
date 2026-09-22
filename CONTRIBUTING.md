# Contributing to Lafiel

Thanks for considering a contribution. Lafiel is a small, opinionated codebase — the fastest path to an accepted PR is one that works the way the repo already works.

## Read first

- [docs/architecture.md](docs/architecture.md) — domain model, sync pipeline, and the boundaries between `app/Domain` and the framework shell
- [docs/implementation-plan.md](docs/implementation-plan.md) — the issue map; work is tracked there
- [docs/design-system.md](docs/design-system.md) — UI structure, states, and copy rules
- [docs/integrations.md](docs/integrations.md) — provider adapter contracts and credential policy

## Setup

```sh
composer setup    # composer install, .env + app key, migrations, npm install, npm run build
composer dev      # app server, queue worker, log tail, and Vite dev server
```

Requirements: PHP 8.5+, Composer, Node.js 22 LTS. A clean checkout runs on SQLite; no services needed.

## Before you open a PR

`composer test` must pass — it runs Pint (style), PHPStan (static analysis), and the Pest suite. CI runs the same suite on PHP 8.5 against PostgreSQL, so also pass locally against Postgres if you touch queries, migrations, or anything database-shaped.

| Command | What it runs |
| --- | --- |
| `composer test` | Pint, PHPStan, and the Pest suite |
| `composer lint` | Auto-fix code style with Pint |
| `composer types:check` | PHPStan only |
| `php artisan test` | Pest suite only |

## What we look for

- **Tests carry the behavior.** Feature tests through Livewire components and HTTP boundaries, unit tests for domain rules. Use factories, not hand-built rows. Copy-only and pure layout changes don't need new tests; behavior changes do.
- **Follow the domain boundaries.** Business rules live in `app/Domain`; controllers, jobs, and Livewire pages stay thin. Check sibling files for naming and structure before inventing a new pattern.
- **Commit style:** conventional prefixes (`feat:`, `fix:`, `docs:`, `ci:`), branches named `feat/…`, `fix/…`, `docs/…`. Reference the issue you're closing in the PR body.

## Security and data hygiene

- Never commit secrets, credentials, or real account data — not in code, fixtures, docs, or test payloads. Provider fixtures stay synthetic and redacted (see the READMEs under `tests/Fixtures/`).
- Lafiel is strictly observational: adapters read provider data and never call mutating endpoints. A contribution that adds a write path to a provider API will be rejected.
- Credential material must never reach logs, errors, or serialized output; the redactor owns that guarantee — extend it when you add a field.

## Reporting a vulnerability

Do not open a public issue for a security problem. Email the maintainer instead, or open a private security advisory via GitHub's *Report a vulnerability*.

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
