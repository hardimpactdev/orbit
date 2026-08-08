# Retained Incus receipt: convergence restores canonical host ownership

Candidate: `647ba6cb560d02a1269d14683e197698836fb272`
Venue: `retained-incus`
Environment: `dev-fixture`
Topology: `dev-45ca61`, kind `operator_gateway_app-dev`, provider `incus`, host `beast`
Checkout role carrying the candidate: `gateway` at `/home/orbit/orbit-run`

## Containment gate

All fixture commands used the outer-quoted form
`ssh beast "incus exec <instance> -- bash -lc '<script>'"`. Identity asserted
before any mutation:

```text
HOST=orbit-e2e-dev-45ca61-gateway
CHECKOUT_PRESENT
/home/orbit/.config/orbit        orbit:orbit 700
/home/orbit/.config/orbit/config.json  orbit:orbit 600
```

No live-fleet command was used for this proof.

## Baseline

Fixture gateway starts healthy: config root `orbit:orbit 700`, `config.json`
`orbit:orbit 600`. Fixture `orbit` is uid/gid 1002.

## Reproduce the incident

Create the host home view and mis-own the config root, exactly as the live
gateway was found (owned by an unrelated account, owner-only mode):

```text
mkdir -p /mnt/orbit-host/home/orbit && chown orbit:orbit /mnt/orbit-host/home/orbit
chown -R daemon:daemon /home/orbit/.config/orbit

/home/orbit/.config/orbit        daemon:daemon 700
/home/orbit/.config/orbit/config.json  daemon:daemon 600
```

Host CLI as the SSH user is now locked out, reproducing the production symptom
verbatim:

```text
sudo -u orbit env HOME=/home/orbit orbit node:list --json
{"error":{"code":"gateway_unavailable","message":"Gateway URL is not configured.","meta":[]}}
```

## Fail-closed behavior, observed on the node

Running convergence without a resolvable host home view for the config root in
play refuses rather than guessing an owner:

```text
RuntimeException  Unable to resolve host ownership for gateway config root at /mnt/orbit-host/root.
```

(The tinker process resolved `orbit.paths.config_root` to `/root/.config/orbit`,
whose host view was absent. This is the documented fail-closed path.)

## Exercise the repair

```text
ORBIT_HOST_PATH_PREFIX=/mnt/orbit-host php artisan tinker --execute=
  "app(App\Services\Gateway\GatewaySwarmInstaller::class)
     ->bootstrapRuntimeConfig('/home/orbit/.config/orbit');"
CONVERGED
```

## Expected vs observed

Expected: routine convergence restores the config root tree to the canonical
host owner while preserving the documented owner-only modes, and the host Orbit
CLI regains access to its own config.

Observed, after convergence:

```text
/home/orbit/.config/orbit              orbit:orbit 700
/home/orbit/.config/orbit/config.json  orbit:orbit 600
/home/orbit/.config/orbit/.env         orbit:orbit 600

sudo -u orbit env HOME=/home/orbit orbit node:list --json
{"success":{"data":{"nodes":[{"name":"app-dev-1", ... "platform":"u ...
```

Ownership moved `daemon:daemon` -> `orbit:orbit`; mode `700` on the directory
and `600` on the credential files are unchanged; the host CLI reaches the
gateway API again. Result: passed.

## Discriminating proof of the ordering invariant

An earlier revision of this receipt claimed the `.env` observation proved the
ownership repair runs last. **It did not, and that claim was withdrawn.** `.env`
already existed on the fixture and was mis-owned by the reproduce step, and
`File::put` truncates an existing inode in place and preserves ownership, so
`.env` lands `orbit:orbit 600` under either ordering. Asserting
`gateway.sqlite` or `apps.php` the same way would have been equally
non-discriminating, because all three pre-existed.

The discriminator is whether a file is **absent before convergence**, so it must
be created fresh after the point where the repair used to run. Re-run with
`operations-websocket/apps.php` deleted first:

```text
chown -R daemon:daemon /home/orbit/.config/orbit
rm -f /home/orbit/.config/orbit/operations-websocket/apps.php

before:
/home/orbit/.config/orbit   daemon:daemon 700
apps.php                    No such file or directory

after convergence:
/home/orbit/.config/orbit                        orbit:orbit 700
/home/orbit/.config/orbit/operations-websocket/apps.php  orbit:orbit 600
/home/orbit/.config/orbit/gateway.sqlite                 orbit:orbit 600
host CLI node:list -> success
```

`apps.php` is written unconditionally by every convergence. It was absent
beforehand, so it was created during the pass and still landed owned by the
canonical host user — which is only possible with the repair running last.
Under the previous ordering it would have been created after the chown and left
owned by the converging account inside a `0700` tree.

The same invariant is pinned deterministically at unit level by
`GatewaySwarmInstallerTest` "restores canonical host ownership of the config
root during routine runtime convergence", which records whether `.env`,
`gateway.sqlite`, and `apps.php` all exist at the moment the chown is issued.
Moving the repair back to its former position fails that test; it passed at
either position before this guard existed.

## Relationship to the live incident

On the live gateway the same tree was found owned `999:999`
(`caddy:systemd-journal`, an unrelated host account) at mode `0700`, while the
host CLI runs as `orbit` (uid 1001). Every `force_remote_host` operation failed
as `invalid_token` because the CLI could not read `config.json` and therefore
could not reach `/api/internal-executor/token/verify`. That blocked
`caddy.global.ensure` and left `launch.nckrtl.com` without an ingress vhost.

This slice prevents recurrence: ownership is now repaired wherever those
owner-only modes are repaired, so convergence can no longer leave the host CLI
locked out. It does not add Doctor coverage (todo 203), change `invalid_token`
diagnostics (todo 201), or touch any live node.
