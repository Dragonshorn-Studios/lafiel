# Stack of PRs: #2 → #3 → #5 → #4

Issue #4 depends on #5, so #5 joins the stack between #3 and #4. Base is freshly merged main (the #1 app skeleton: Laravel 13, Fortify, Livewire 4 + Flux, Pest 5, Larastan, Pint, CI on Postgres 18/PHP 8.5, sqlite for local tests).

**Step 0 — sync:** `git pull --ff-only` (blocked in plan mode; first act on approval). Verify baseline `vendor/bin/pest` is green before branching.

**Branch order (each stacked on the previous):**

| # | Branch | Issue | Base |
|---|--------|-------|------|
| 1 | `feat/first-administrator` | #2 | main |
| 2 | `feat/canonical-schema` | #3 | feat/first-administrator |
| 3 | `feat/cost-projection` | #5 | feat/canonical-schema |
| 4 | `feat/manual-cost-slice` | #4 | feat/cost-projection |

Open each with `gh pr create --base <previous branch>`; after a PR merges, retarget the next one to main. No GitHub comments/replies (per `.ai/rules`).

---

## PR 1 — #2 First administrator and install state

- **Auth stripping** (per your choice): remove `Features::registration()`, `resetPasswords()`, `emailVerification()` from `config/fortify.php`; drop their Fortify view registrations and the `register`/`forgot-password`/`reset-password`/`verify-email` pages; delete `CreateNewUser`/`ResetUserPassword` Fortify actions. Replace `RegistrationTest`/`EmailVerificationTest`/`PasswordResetTest` with a test asserting the routes are gone (route removal is the new behavior, so the suite stays honest).
- **Exactly-once admin, owned by the DB:** migration adding a unique expression index on `users` allowing at most one row ever (portable sqlite/Postgres). This is the race backstop.
- **Install state:** `EnsureInstalled` middleware — zero users ⇒ `/`, `/login`, everything guest-visible redirects to `/setup`; admin exists ⇒ `/setup` redirects to `/login`. Logged-out visitors then see login only.
- **`App\Actions\Setup\CreateAdministrator`:** acquires `Cache::lock('setup')`, re-checks emptiness inside the lock, creates the verified admin (Password::defaults enforced), treats unique-constraint violation as "already set up". Idempotent by construction.
- **`pages/setup` Livewire SFC** (bare, Flux inputs) at `/setup`; on success auto-login.
- **Tests:** setup page shown when empty; admin created once then `/setup` blocked; race — second create under a held lock fails cleanly; direct second-user insert hits the DB constraint; registration/reset/verify routes 404; unauthenticated mutation gets 419/redirect (CSRF + auth).

## PR 2 — #3 Canonical schema, enums, and Money

- **Domain layout** `app/Domain/{Providers,Inventory,Costs,Sync,History,Support}` (models, enums, VOs; Laravel providers stay in `app/Providers`).
- **Migrations** (portable sqlite + Postgres) for: `provider_accounts`, `provider_capability_states` (UNIQUE `(provider_account_id, capability_key)`), `provider_credentials` (encrypted payload, schema version, fingerprint), `services` (nullable provider_account_id for manual services; partial UNIQUE `(provider_account_id, external_id)`; lifecycle fields incl. `missing_complete_runs`, `lifecycle_state`), `cost_items` (UNIQUE `identity_key`, indexed `logical_charge_key`, `amount_minor` BIGINT nullable, `currency` char(3), all five independent enum dimensions + `is_manual_override`, `valid_from`/`valid_to`, `observed_at`, source ref), `cost_item_services` (coverage, UNIQUE pair), `renewals` (FK to cost item, no amount/currency duplication, `renews_at`, `auto_renew`), `sync_runs` (trigger, status, phase timestamps, counts, sanitized summary), `cost_snapshots` (totals, completeness counters, `calculation_version`, `fx_used`, breakdown/checksum). Enum columns get CHECK constraints + string-backed PHP enums (TitleCase cases).
- **`App\Domain\Support\Money`:** integer minor units + ISO-4217 code, immutable, no floats; `Period` enum (`Monthly/Quarterly/Annual/OneTime/Unknown`) with exact rational monthly/annual equivalents (÷3, ÷12) and half-even rounding applied only to the presented total (int/BCMath math).
- **Models + factories** for every table; credentials `encrypted` cast + `Hidden`, plus tests proving ciphertext at rest and no credentials in serialization/logs.
- **Tests:** constraint ownership (duplicate `(provider_account_id, external_id)` and duplicate `identity_key` rejected), period conversions, deterministic half-even rounding, credential redaction.

## PR 3 — #5 Cost projection and aggregation

- **`App\Domain\Costs\Projection\CostProjector`** + `ProjectionResult`/breakline DTOs + `ProjectionFormatter`; `config/costs.php` (freshness window, display currency PLN).
- **Rules:** aggregate active cost items (never service rows); a charge covering N services counts once; evidence precedence per `logical_charge_key` — invoice actual > usage actual > subscription/renewal quote > manual, actual replaces quote (never added), manual wins only via `is_manual_override`; date-bounded inclusion via `valid_from`/`valid_to`; unknown amounts excluded from the known sum and counted; monthly unchanged, quarterly ÷3, annual ÷12, one-time kept out of recurring; one round of half-even at the presented total; per-currency totals with **no silent FX**; counters for unknown / estimate / stale (non-manual evidence past freshness) / shared_unallocated.
- **Tests** (frozen `CarbonImmutable::setTestNow`): shared charge counted once, precedence replacement, unknown counting, single rounding, stale/estimate/shared_unallocated counters, one-time exclusion, deterministic output, allowed display forms (`187.42 PLN/mo`, `~…`, `+ 2 unknown`, `fixed + metered usage pending`).

## PR 4 — #4 Manual cost vertical slice

- **Bare Livewire pages** (Flux + semantic tokens, no shell — #6 is out of scope): `/costs` list + create/edit, renewals list, history list; Overview totals on the existing dashboard rendered **only** through `CostProjector` (no second calculator).
- **Form fields:** vendor, name, category, amount-or-unknown, currency, period, renewal date, auto-renew, start, end, URL, notes. Manual service row (`provider_account_id` null) + cost item (`source_kind=manual`, `evidence_state=manual`, charge kind from period) + optional `renewals` row.
- **History semantics:** price change closes the current cost item (`valid_to`) and opens a new one with a fresh `identity_key` under the same `logical_charge_key`; ending an item sets its end date — it leaves future projections and stays in history; no hard deletes.
- **Overlay mechanism:** optional service link on the form writing `cost_item_services` — the same relation a future API overlay uses.
- **Tests:** auth-gated CRUD; created cost reaches Overview via the projection; price change preserves history and switches the projection on the change date; ended item leaves future totals and remains in history; unknown amount increments counters only; renewal listed; covered service doesn't double-count.

---

**Per-PR loop:** implement → `vendor/bin/pint --dirty` → narrow `vendor/bin/pest` → full `vendor/bin/pest` + `phpstan analyse` → push → `gh pr create` (stacked base) → report and move to the next branch. CI (Postgres) gates every PR; migrations are written to pass on both sqlite and Postgres from the start.