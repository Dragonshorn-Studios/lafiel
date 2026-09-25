## Description

Please include a summary of the changes and the related issue/problem being resolved.

Fixes # (issue)

## Type of Change

- [ ] `bug`: Bug fix (non-breaking change which fixes an issue)
- [ ] `feat`: New feature (non-breaking change which adds functionality)
- [ ] `docs`: Documentation updates
- [ ] `refactor`: Code refactoring without behavioral changes
- [ ] `ci` / `chore`: CI workflow or build maintenance updates

## Checklist

- [ ] Code follows the style guidelines of this project (`composer lint`).
- [ ] Static analysis and types pass (`composer types:check`).
- [ ] Tests covering the changes pass locally (`composer test` / `php artisan test`).
- [ ] Domain boundaries in `app/Domain` are respected.
- [ ] No secrets, credentials, or real account data are committed.
- [ ] Observational principle maintained: no write/mutating calls to provider APIs.
