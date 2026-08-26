# App development setup defaults retained Incus proof

- Candidate: `02c4b873a14f03045a2cec2a0c661829c1638a91`
- Topology: `dev-11337b`
- Kind: `operator_gateway_app-dev_app-prod`
- Provider and host: Incus on `beast`
- Proof terminal: tmux `feat-app-development-setup-defaults:proof-1`
- Runtime checkout: `/home/orbit/orbit-run`

## Candidate binding

The operator and gateway runtime files matched the candidate bytes:

```text
f99d56f7623445aa54999db8fa3ccd02a5c012002ad1f43642e7b98704ef693b  apps/gateway/app/Tools/BunTool.php
3acf1e382bd70b6d775e870c0f38611078e7052fee94569d0b401870c4cfd75b  apps/gateway/app/Actions/Apps/CopyAppDevelopmentSetupSteps.php
cbb78c0719716a97fe8f504a4c3797eebfeb8df77645943a70078ccd5a9aae61  apps/gateway/app/Http/Controllers/Api/AppDevelopmentSetupStepController.php
6188a8a93a70a0b575fae2a3a21e536c2e4df273bf1efbe3d88ecd47e41494c2  apps/cli/app/Commands/App/AppDevelopmentSetupStepRemoveCommand.php
```

The same hashes were observed under `/home/orbit/orbit-run` on the retained
operator or gateway VM. The runtime overlay intentionally has no `.git`
directory, so file hashes bind the executed checkout to the candidate.

## Bun role prerequisite

The prepared topology bakes role intent before it overlays the feature
checkout. The candidate role baselines were therefore refreshed after the
overlay, and Orbit doctor restored the newly required Bun tool from gateway
intent. The normal installer placed Bun under the managed `orbit` user on both
roles:

```text
app-dev-1:  Bun 1.4.0, /usr/local/bin/bun -> /home/orbit/.bun/bin/bun
app-prod-1: Bun 1.4.0, /usr/local/bin/bun -> /home/orbit/.bun/bin/bun
```

## Fitta lifecycle

The proof used tracked files from Beast Fitta commit
`18cfd03586e086b6eb43197aedbe1d26424f4ab0`. Git archives excluded `.env`,
vendor, node modules, and other untracked state.

Fitta first had only a production instance. Eight app development defaults
were then added through the candidate CLI. Creating `fitta.development` on
`app-dev-1` copied the commands in stable order with independent identities:

```text
app defaults:       count=8 ids=74,75,76,77,78,79,80,81 orders=1..8
development steps: count=8 ids=9,10,11,12,13,14,15,16 orders=1..8
production steps:  count=0
```

Updating app default `81` to a future-only build command left copied instance
step `16` as `bun run build`. Restoring the default did not change the instance
row. Re-registering the existing development instance also left its count at
eight. A temporary ninth default was created and removed to prove the complete
CRUD surface; the final app default order returned to `1..8`.

After review correction, invalid `--after=999999` returned
`validation_failed` with `meta.field=after`. Temporary default `82` was then
added, moved before default `81`, updated, and removed with force. The remove
payload retained `app=fitta`. The final app and instance row counts and orders
were unchanged.

`instance:setup fitta.development --stream-json` completed all eight copied
steps. Observed state:

```text
APP_URL=https://fitta.test
APP_KEY_SET=yes
SQLITE=yes
CLIENT_BUILD=yes
SSR_BUILD=yes
LOCKED_DEPS=yes
migrations=ran
```

The prepared node carried FrankenPHP image tag `1-php8.5-bookworm` while the
candidate expected tag `2-php8.5-bookworm`. The identical prepared image was
given the candidate tag inside this disposable fixture. Orbit then registered
and started the development runtime. The candidate route served the Fitta HTML
response through node Caddy:

```text
status=200 peer=10.6.0.4 content_type=text/html; charset=utf-8
```

The operator did not trust the disposable node-local Caddy CA, so the route
probe used `curl --insecure` with an explicit `--resolve` binding. This proves
the application route and response, not external certificate trust.

## Repository gates

- Focused gateway tests: 133 passed, 864 assertions.
- Focused CLI tests: 215 passed, 474 assertions.
- Docs lint, scoped Mago, Rector, OpenAPI/SDK classification, and secret scan passed.
- `composer quality-check` passed at the exact clean candidate in 135 seconds.
- Artifact: `.orbit/quality-gates/quality-check-2026-08-26T091946Z-75f72383a2de.json`.
- No `composer test:e2e*` command was run.
