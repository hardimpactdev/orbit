# Quality gates

Use the smallest verification command that proves the change while developing.
Escalate only when the change crosses a wider contract boundary.

## Default gates

Run these gates while developing unless the change needs a narrower file or
filter first.

```bash
bin/orbit-gateway-pest --compact
bin/orbit-gateway-vendor-bin pint --dirty --format agent
composer docs-lint
```

Run `composer quality-check` before handing off a change that should be broadly
safe. That gate fans out docs linting, PHPStan, Rector dry-run, Pint, and the
default Pest suite across each app and package.

## E2E gates

Run `composer test:e2e` when behavior touches the integrated prepared topology.
Use `composer test:e2e:docker` for Docker-eligible feature tests and
`composer test:e2e:incus` for VM-feature behavior.

Use `composer test:e2e:provision` only when topology preparation, installer
behavior, image shape, `node:new`, WireGuard provisioning, or other VM setup
behavior changes. It runs the single Incus superset provision gate.

There is no standing live-node verification lane. Persistent gateway, operator,
and app nodes are diagnostic targets only.
