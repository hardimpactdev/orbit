# Retained Incus receipt: node Doctor detects an unreadable gateway host CLI config

Candidate: `2350b6d7c4e06853606aa23a10680d92955583af`
Venue: `retained-incus`
Environment: `dev-fixture`
Topology: `dev-c56c84`, kind `operator_gateway_app-dev`, provider `incus`, host `beast`
Checkout role carrying the candidate: `gateway` at `/home/orbit/orbit-run`

## Containment sentinel

Asserted before any mutation, with a guard that refuses on a non-fixture
hostname:

```text
SENTINEL_FIXTURE=ok
SENTINEL_HOST=orbit-e2e-dev-c56c84-gateway
SENTINEL_USER=root
CANDIDATE_PRESENT
```

No live-fleet command was used for this proof.

## Fixture faithfulness — corrected

An earlier revision of this receipt claimed the host view "was created to match
production". **That claim was wrong and is withdrawn.** It used a bare
`mount --bind`, which is read-write, while production mounts the host view
read-only (`/home:/mnt/orbit-host/home:ro` in `GatewaySwarmStackRenderer` and
the same posture in `UpdateRunnerLauncher`). That single missing flag is what
allowed a broken restore to record a passing result.

This run uses a genuinely read-only view, verified rather than assumed:

```text
VIEW_OPTS=ro,relatime
touch /mnt/orbit-host/home/orbit/.config/orbit/.rotest
  -> Read-only file system
CHECKOUT=intact
```

The view binds only `/home/orbit/.config`, not `/home`. Binding `/home` over the
prefix path on this topology kind nests the `orbit-run` source overlay under the
new mount and destroys the checkout — that happened on the previous fixture,
which was released and replaced rather than repaired.

## Detection: three-state observation

```text
HEALTHY-BASELINE
  healthy: findings=0

BREAK: chown -R daemon:daemon /home/orbit/.config/orbit
  broken: findings=1 key=node.gateway_cli_config_unreadable
          path=/home/orbit/.config/orbit actual_owner=1:1 mode=700 requires=traverse
  catalog: disposition=genuine_drift restore_action='restore_node_gateway_cli_config_unreadable'

RESTORE via NodesProbe::reconcile
  after-restore: findings=0
  /home/orbit/.config/orbit              orbit:orbit 700
  /home/orbit/.config/orbit/config.json  orbit:orbit 600
```

## Restore: discriminating check against the read-only view

The reviewer's critical finding was that restore chowned the read-only host view
and so could never succeed in production. Both targets were exercised on this
node, against the real read-only mount:

```text
PRE-FIX-TARGET throws: Failed to repair gateway config root ownership at
  /mnt/orbit-host/home/orbit/.config/orbit: chown: changing ownership of
  '/mnt/orbit-host/home/orbit/.config/orb...

POST-FIX-TARGET: ok
  /home/orbit/.config/orbit  orbit:orbit 700
```

The pre-fix target fails with EROFS exactly as predicted; the shipped target
succeeds. This is what the earlier read-write fixture could not distinguish —
under that mount both targets succeeded.

## Expected vs observed

Expected: node Doctor reports a genuine, restorable finding naming the
unreadable path when the gateway host runtime user loses access to its own CLI
config; restore repairs it through the writable config root and reports nothing
afterwards.

Observed: `0 -> 1 -> 0` findings across healthy, broken, and restored states,
with `disposition=genuine_drift`, owner-only modes `700`/`600` unchanged, and the
restore target proven to be the only one that works against a production-shaped
read-only host view.

Result: passed.

## Coverage boundary

Covered on a real node: family probe emits the drift, the issue catalog
classifies it, `NodesProbe::reconcile` repairs it, and the chown target is
correct against a read-only host view.

Not covered by this run: the HTTP report wrapper that renders Doctor output.
`NodesProbe` is the family probe the report runner consumes and the wrapper is
generic across families, so it stays covered by the existing Doctor report tests.

## Re-proven after main advanced

Process 1488 landed while this slice was held. Current main was merged in
(`Merge branch 'main' into doctor-gateway-cli-config-readable`, no conflicts, no
overlapping files), the fixture checkout was refreshed in place with
`composer e2e:incus -- --sync --id=dev-c56c84 --checkout-roles=gateway`, and the
full scenario plus the differential restore check were re-run at the merged tip
rather than carried over. Sentinel, read-only view posture, and checkout
integrity were re-asserted first:

```text
SENTINEL_FIXTURE=ok
SENTINEL_HOST=orbit-e2e-dev-c56c84-gateway
CHECKOUT=intact
findmnt /mnt/orbit-host/home/orbit/.config -> ro,relatime
```

Both results reproduced unchanged: detection `0 -> 1 -> 0`, and on the read-only
view the pre-fix chown target throws EROFS while the shipped target succeeds.

## Relationship to the live incident

On the live gateway the config root was owned `999:999` at `0700` while the host
CLI ran as `orbit` (uid 1001). Every `force_remote_host` operation failed as
`invalid_token`, `caddy.global.ensure` could not run, and `launch.nckrtl.com` had
no ingress vhost — while `orbit doctor --node=gateway --family=node` reported
**0 issues**. This check closes that blind spot. It does not change
`invalid_token` diagnostics (todo 201) and touches no live node.
