# Orbit

Orbit is a command-first environment control plane for Laravel development,
hosting workflows, and node orchestration.

The monorepo contains three self-contained applications:

- `apps/gateway` — Laravel 13 gateway/control-plane app.
- `apps/docs` — Laravel docs and Librarian lint app.
- `apps/cli` — local CLI and executor app.

## Development

```bash
composer install
cd apps/gateway && composer install
cd ../docs && composer install
cd ../cli && composer install
```

Useful checks:

```bash
composer test
composer quality-check
composer test:e2e
```
