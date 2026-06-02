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

Run feature E2E before provider provision gates. The prepared-topology lanes
exercise the current source checkout and are the normal behavior signal.
Provider provision commands are final verification for topology preparation,
installer behavior, image shape, `node:new`, WireGuard provisioning, or other
provider setup behavior changes:

```bash
composer test:e2e:provision:docker
composer test:e2e:provision:incus
```

These commands may be run by separate agents in parallel after the relevant
feature lane is green. The aggregate `composer test:e2e:provision` runs both
provider provision commands and is reserved for humans, not agents.

There is no standing live-node verification lane. Persistent gateway, operator,
and app nodes are diagnostic targets only.
