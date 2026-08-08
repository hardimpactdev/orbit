# Retained-incus runtime proof — PHP snapshot inheritance

Candidate `3f13d088a81ef9579a40517d1c1dd8e74cbd86cb`.

## Topology

| Field | Value |
| --- | --- |
| id | `dev-481460` |
| kind | `operator_gateway_app-dev` |
| provider | `incus` |
| host | `beast` |
| instances | `orbit-e2e-dev-481460-{operator,gateway,dev}` |
| release | `composer e2e:incus -- --stop --id=dev-481460` |

Source was synced to the candidate commit before the decisive runs
(`composer e2e:incus -- --sync --id=dev-481460`).

### Containment

Every command ran through a sentinel that aborts before its payload unless it
is executing on `orbit-e2e-dev-481460-operator` as `orbit`, against a gateway
whose node list is exactly `app-dev-1,gateway,operator-1`. This exists because
an earlier helper had a quoting bug: `ssh` re-parses its argument on the remote
shell, so an unquoted `&&` split the command and ran `orbit` on `beast` — a
real node — against the production gateway. Only read commands executed before
it was caught.

Negative controls, both aborting with the payload unexecuted:

- guard expecting a different hostname, run on the operator VM →
  `CONTAINMENT ABORT: hostname orbit-e2e-dev-481460-operator is not …`
- guard run on `beast` itself (the near-miss host) →
  `CONTAINMENT ABORT: hostname beast is not the fixture operator`

## What this proves

### `php:use --instance` writes one instance, end to end

`orbit php:use 8.3 --instance=snapproof.second` on app `snapproof` (template
8.4, instances `development` and `second` both 8.4):

```json
{"result":{"target":"instance","app":"snapproof","instance":"second",
 "previous":"8.4","version":"8.3",
 "image":"ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.3-bookworm",
 "inherits":false,"changed":true},
 "php":{"app":{"php_version":"8.4"},"instance":{"php_version":"8.3"}}}
```

`meta.warnings` was empty — the removed sibling fan-out warning does not fire.

This hop had never run before this candidate: the fixture carried a single PHP
image, so preflight refused every version as `not_installed`.

### The runtime actually changed

```text
orbit-app-snapproof-development -> PHP 8.4.22 | ORBIT_PHP_VERSION=8.4
orbit-app-snapproof-second      -> PHP 8.3.31 | ORBIT_PHP_VERSION=8.3
```

Two instances of one app on different container images, running different PHP
binaries. Gateway record, image tag, in-container `php -v`, and the environment
variable all agree.

### Changing an app default moves nothing that exists

App default 8.4 → 8.5 (written directly to the app row; no command writes the
template — that surface is out of scope for this slice):

```json
{"apps":{"snapproof":"8.5"},
 "instances":{"development":"8.4","second":"8.3"},
 "workspaces":{"snapfeature":"8.3"}}
```

All three containers kept their own runtimes across the change:
`development` on `1-php8.4`, `second` on `1-php8.3`, and workspace
`snapfeature` on `1-php8.3`.

### New instances copy the current template

With the default at 8.5, `orbit instance:add snapproof.third` produced 8.5 while
`development` stayed 8.4 and `second` stayed 8.3.

### New workspaces copy their owning instance, not the app

`orbit workspace:new snapfeature --instance=snapproof.second` with app template
8.4 and owning instance 8.3 stored `php_version: "8.3"`, `php_inherited: false`.
The Goal clause this covers had no executable coverage before this candidate.

### Read surfaces report the instance, not the template

- `orbit php:list --instance=snapproof.second` human frame:
  `INSTANCE │ snapproof.second (PHP 8.3, app template 8.4)`
- `orbit instance:show snapproof.second --json` → `runtime.php_version` 8.3 with
  image `1-php8.3-bookworm`.

### A defect this run found and closed

`instance:add` stored the new row with an empty `php_version`. Every read
surface reported the right number by resolving through the app, so the gap was
invisible until the app default moved. Observed before the fix: instance
`third` reported 8.5, then reported 8.3 once the app default became 8.3, while
stamped sibling `development` held at 8.4.

After the fix, on the same fixture: `instance:add snapproof.fourth` stored
`"8.5"`, and moving the app default to 8.3 left `fourth` reporting 8.5. Row
state at that point:

```json
{"apps":{"snapproof":"8.5"},
 "instances":{"development":"8.4","second":"8.3","third":null,"fourth":"8.5"},
 "workspaces":{"snapfeature":"8.3"}}
```

`third` is retained deliberately as the pre-fix artifact: it is the contrast
that shows the empty row, not the stamped one, is what follows the app.

### Adopted workspaces own their version too (second review, F-A)

`orbit workspace:setup --instance=snapproof.second --path=<abs>` adopting a git
worktree that had no row yet, with app template 8.5 and owning instance 8.3,
stored:

```json
{"apps":{"snapproof":"8.5"},
 "workspaces":{"snapfeature":"8.3","adopted":"8.3"}}
```

The adopted row took 8.3 from its owning instance, not 8.5 from the app. Before
the fix this row was stored empty, which made it a brand-new live-inheriting
workspace created after the migration.

Moving the owning instance to 8.4 afterwards left both workspaces on 8.3, so an
adopted workspace is not dragged by its instance either. Re-read at the final
candidate after a source sync, that separation still holds:

```json
{"apps":{"snapproof":"8.5"},
 "instances":{"development":"8.4","second":"8.4","third":null,"fourth":"8.5"},
 "workspaces":{"snapfeature":"8.3","adopted":"8.3"}}
```

Container state at the end of the run, four runtimes under one app:

```text
orbit-app-snapproof-development | 1-php8.4-bookworm
orbit-app-snapproof-second      | 1-php8.3-bookworm
orbit-ws-snapproof-adopted      | 1-php8.3-bookworm
orbit-ws-snapproof-snapfeature  | 1-php8.3-bookworm
```

`php:list --instance=snapproof.development` rendered
`INSTANCE │ snapproof.development (PHP 8.4, app template 8.5)` with
`AVAILABLE IMAGES │ 8.5, 8.4, 8.3`.

### Convergence scope, measured rather than assumed

`php:use 8.3 --instance=snapproof.second` with four instances under one app.
Container identity before and after, on the app-dev node:

```text
before                                          after
development 13:01:57 49f4755796cb  ->  development 13:01:57 49f4755796cb  (php8.5)
fourth      13:02:05 a92149d23433  ->  fourth      13:02:05 a92149d23433  (php8.5)
third       13:02:21 509f8f162db9  ->  third       13:02:21 509f8f162db9  (php8.5)
second      13:02:13 3ab204d1af2f  ->  second      13:03:49 cf516dcaf27d  (php8.3)
```

Only the written instance was recreated. `EnactAppRuntime` does iterate every
Orbit instance of the app (`foreach ($app->instances ...)`), so convergence is
app-wide in scope, but applying is idempotent: a sibling already matching its
rendered state keeps its running container and its own version.

This measurement corrected the documentation twice. The original text claimed
siblings are "not touched", which understated the iteration; the first fix
claimed convergence "re-applies every instance", which overstated the effect.
The contract now states both halves, matching this observation and the code.

## Boundary (do not overclaim)

- **The F5 guard removal was not exercised here.** Removing the app-wide
  workspace precondition from `php:use --instance` matters most for an
  `app-prod` instance gated by a workspace on another node, and this topology
  kind has no `app-prod` node. That change rests on the gateway unit and
  feature suites.
- **App-default writes used the database row, not a command.** No command
  changes an app default after creation; that surface is explicitly out of
  scope for this slice.
- **The fixture image set was placed by hand, and the code that would automate
  it is deliberately not in this candidate.** The base image was topped up
  manually because its rebuild path times out (task #15), and — since topology
  VMs clone from template instances rather than the base — the three images
  were then loaded directly into this topology's dev node (task #16). The
  harness changes that would have baked and verified them were split out of
  this candidate: a scope audit found the topology-builder verification never
  executed on the retained-acquisition path at all, so it was unproven rather
  than merely unexercised. None of the proof above depends on that code; every
  observation was produced through `php:use`, `instance:add`, `workspace:new`,
  `workspace:setup`, and container inspection against manually placed images.
