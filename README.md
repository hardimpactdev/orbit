# Orbit

Clean Laravel 13 rebuild of Orbit.

Orbit is a command-first environment control plane for Laravel development and
hosting workflows. The current baseline is intentionally small: a node registry,
local updates, registry-backed `update:all`, and a real-node smoke path.

## Current Commands

```bash
php artisan node:register gateway --role=gateway --host=10.6.0.2 --ssh-user=gateway --orbit-path=/home/gateway/orbit --local
php artisan node:register mini --role=control --host=10.6.0.8 --ssh-user=nckrtl --orbit-path=/Users/nckrtl/orbit
php artisan node:register beast --role=app --host=10.6.0.7 --ssh-user=nckrtl --orbit-path=/home/nckrtl/orbit

php artisan node:list
php artisan update
php artisan update:all
```

## Development

```bash
composer install
php artisan migrate
php artisan test --compact
```

Useful checks:

```bash
composer quality-check
composer test:e2e
```
