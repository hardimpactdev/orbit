# Swamp shape gate

Every pull request and main push runs `Swamp shape`. Existing violations are
listed by file and rule in `config/swamp-shape-baseline.json`. Entries and their
counts may only decrease. Moving code does not transfer an exception. CI also
runs the base revision's checker and dependencies against committed HEAD in
isolated sparse worktrees. That check derives its own shrink-only baseline from
immutable base limits; it does not reinterpret the PR's revised baseline under
old rules. Changing current rules, limits or exclusions cannot admit new debt
under the frozen rules. Actual debt removal and approved classification fixes do
not require stale allowances.

One approved HTTP-only extraction has explicit source accounting: the unchanged
`curlQuote`, `proxyHttpConfig` and `proxyGatewayRequest` declarations move from
`operations/_lib/node-proxy.ts` to `node-proxy-http.ts`. The checker compares all
three declarations with immutable origin `529a72e86d2757cd8f90fedb564e4c69d1879b6d`,
requires the exact two imports, removes the original definitions, and preserves
only the existing automatic-deploy caller and call expression. Extra declarations,
new or indirect callers, changed executable bodies, count/evidence growth and
resurrection after the actual comparison base removed the debt all fail. The
origin must be an ancestor of HEAD. Normally it must also be an ancestor of the
comparison base. For the first history-preserving import only, the verified
unprefixed comparison source may instead precede the origin: it must have the
same repository identity, retained ancestry, and no gate or baseline. The actual
older source still supplies the declarations, caller, findings and derived caps;
the later origin never replaces the comparison base. A prefixed or unrelated
older tree cannot use this path.

The raw baseline still only shrinks. This source proof derives one virtual limit
at the new path for each run; `--write-baseline` never writes that limit into the
ledger. Current findings and workflow coverage still include the retained HTTP
call. JSON metrics report checked-in baseline rows and derived source moves
separately in the JSON report. This is not a general permission
to transfer allowances on rename. The support lower must reach the comparison
base before the extraction, so the frozen checker enforces the same contract.

## Gateway workflow grant intent

The required Gateway boot check compares the loaded `DirectOperation` registry,
including extension contributions, with `config/gateway-workflow-grants.json`.
Each declared workflow needs one exact entry for `user:orbit-gateway` with both
`run` and `read`. Missing actions or workflows fail CI. Wildcards, other
principals or actions, duplicate entries and retired declaration entries fail.
Version 2 also checks each kind's public `execution_copies` declarations against the manifest's `vaults` entries. Each entry names one exact vault, an anchored key pattern, and only `write` plus same-key `read`. Missing, broader, duplicate or retired entries fail. The approved patterns cover positive database intent IDs, Vault-sync and Conn-revoke UUIDv4 keys, and the existing exact bootstrap identity key.

Update the declaration and expected policy together. The check also runs when
only this manifest changes; other Swamp-only changes keep their existing scope.

This manifest is intended source policy, seeded from owner-approved public grant
metadata and reconciled against current declarations. It is not evidence that a
live grant is installed. In particular, historical run-only grants do not prove
read access. Before Gateway uptake, the live grant owner must verify the exact
registered workflow and the installed Gateway principal with native
`access.can-i`. CI reads source files only; it never applies grants, contacts
Swamp access APIs or handles Swamp credentials. Workers retain get/resolve access only for the keys their steps use; the manifest does not install worker grants. Official `access.can-i` exposes the installed key conditions but has no key-context input. The live owner therefore checks that metadata, then proves the family's actual execution-copy push.

## Review checklist

- Before adding a mechanism, check [Swamp core](https://swamp-club.com/manual) and the [official extensions](https://github.com/swamp-club/swamp-extensions), including ssh, vault, datastore, git, container-image, cfgmgmt and workflows. Use the provided feature and its defaults when it fits. A PR that adds a mechanism must name what it checked and explain why it does not fit.
- Reject custom wrappers, transports, supervisors, protocols or channels for work Swamp already provides. For example, use official `@swamp/ssh` instead of a custom SSH runner.
- Keep the design proportionate. Orbit Nodes are trusted; do not add custody, placement or identity hardening beyond Swamp defaults for hypothetical edge cases.

These are review items. The scanner does not decide whether a custom mechanism
has a justified business purpose. The binding [Swamp shape guide](swamp-shape.md)
owns the execution contract.

## Automated checks

The gate implements Nick's migration policy using these manual references:

- [Workflows](https://swamp-club.com/manual/reference/workflows): placed steps,
  guards, allowFailure, resume, concurrency, queueTimeout and manual approval
  replace application polling, waits and locks.
- [Behavior definitions](https://swamp-club.com/manual/reference/behavior-definitions):
  `command/shell` runs an action script; TypeScript reads intent and records typed
  results. Distinct commands are evidence for the multi-action heuristic, not a
  proof of the business meaning of an action.
- [Vaults](https://swamp-club.com/manual/reference/vaults): resolve private values
  with `vault.get`. Command steps use whole `task.inputs.env` values; the official
  SSH extension also accepts its native secret inputs described below. Argv,
  shell concatenation and workflow inputs cannot carry them. An explicitly
  closed empty `execution_secrets` object carries no values. A dynamic descriptor
  map uses `type: object` with `additionalProperties` set to a closed `{vault,key}`
  descriptor: both fields are required strings, `vault` has an explicit enum,
  and `key` has a pattern. The map needs no fixed field list or cardinality cap;
  every value still carries only public reference names. Loose values and secret
  bytes remain forbidden. SourceControl's
  `repository_identity` is the established public host/org/repo identifier; this
  exact field name is public, unlike SSH or bootstrap identity material.
- The official cfgmgmt `nodeIdentityFile` connection input is a public file path,
  not key content. A workflow schema can declare this exact field as
  `type: string`; no locator annotation or rename is needed. Package
  `2026.10.27.1` documents it in the
  [official connection schema](https://github.com/swamp-club/swamp-extensions/blob/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/cfgmgmt/extensions/models/file.ts).
  This classification changes only workflow input schema checks. Vault
  placement, secret environment sources and argv checks still apply.
- [Data](https://swamp-club.com/manual/reference/data): outputs are persisted.
  Do not write private material to resources, files or logs.

The official `@swamp/ssh` package `2026.10.23.1` (model version `2026.10.20.1`)
accepts a whole `vault.get(...)` value at `globalArguments.transport.identityContent`
and `globalArguments.hosts[].transport.identityContent`. Inline workflow tasks
using this exact type can supply the same fields through `task.globalArgs`.
These are model global arguments, not per-method transport overrides. The
[official source](https://github.com/swamp-club/swamp-extensions/tree/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh)
declares the sensitive fields in `_lib/schemas.ts`; its README describes native
key delivery and redaction. Use the extension's defaults. The gate grants no
generic extension or field-name exemption: raw input values, concatenation,
`identityFile`, other method inputs, argv and other extension types remain outside this
recognition. Existing command-env checks remain in place.

Official `@swamp/ssh` `exec` also accepts one whole `vault.get(...)` expression at
`task.inputs.stdin`. This applies only to workflow `behavior` tasks with
`behaviorType: '@swamp/ssh'` or `model_method` tasks with
`modelType: '@swamp/ssh'`, and literal `methodName: exec`. The effective placement
must select Node 209 with `target: orbit-node-209` or labels `{node: '209'}`.
Use target-only placement for new workflows. The checks accept a single target
or node label, including inherited placement. Matching dual selectors remain
accepted during the existing workflow migration; conflicting selectors fail.
Placement fields inherit independently through workflow, job and step; a step
override or an empty labels map takes precedence for that field. The existing closed
`execution_secrets` descriptor schema still applies. The official
[`ExecArgsSchema`](https://github.com/swamp-club/swamp-extensions/blob/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh/extensions/models/_lib/schemas.ts)
provides native stdin; `runExec` in `_lib/operations.ts` passes it to the process
and records only `hasStdin`. No `AcceptEnv`, sudo `env_keep` or family delivery
channel is needed. Public stdin remains valid. Other methods, extension types,
fields, nested stdin values, concatenation and argv/script interpolation remain
outside this recognition. Node 209 hosts the worker; official SSH reaches the
target Node with the approved identity, pinned host keys and sudo when needed.
Whole vault values in official `exec`/`script` `task.inputs.env` use this same
209 placement scope; existing core command-env checks stay in place. This does
not configure SSH server env forwarding. The pinned official `script` method
uses stdin for its public script body and has no separate stdin argument. Use
`exec` to run a shipped script when it needs a vault value on stdin; private
values do not belong in argv or the script body.

Each embedded operational program has a 20-line threshold before grandfathering.
Independent short strings are not combined to cross it. Script metrics count each
contributing source literal once, including when a wrapper passes it to Python.
The scanner resolves TypeScript literals, templates, tagged templates,
concatenation, constant aliases and joins. It inspects nested subprocesses and
reports evidence for multi-command behavior files. Custom sleeps, timer helpers,
polling, retries and lock calls are counted separately.

The approved Route private DNS grace is the sole sleep exception. It matches only
`workflows/workflow-route-dns-grace.yaml`, job `grace`, step
`wait-private-dns-ttl`, placed with step labels `{node: '209'}` or target
`orbit-node-209` (or both with matching values), and the exact `command/shell`
behavior `route-dns-grace.execute` with the sole input
`run: /usr/bin/sleep 31`. Inherited targets still apply; a foreign target cannot
qualify through 209 labels. The workflow contains one job and one step. This covers
the existing 30-second private DNS TTL after replacement publication and before
old Caddy sites, certificate scopes and LAN allowances retire, including restore.
Core and the official workflows extension provide no native delay. The binding
guide's **Approved exception: Route private DNS grace** records the approval.
Other files, steps, tasks, durations, flags, wrappers and commands remain findings;
the grandfather baseline does not grow. The Route owner owns phase ordering.

Agent calls are checked
against an explicit observer/action inventory; dynamic agent targets are unknown,
not passing. The agent rule examines executable command positions in shell and
Python subprocess calls, including sudo, shell and SSH wrappers. Service units
such as `orbit-agent.service`, artifact paths passed to stat/copy/digest tools and
configuration metadata are not agent invocations. Actual executable calls still
use the observer/action inventory; unresolved commands remain unknown. Python
bindings follow execution order within a lexical scope. Outer captures require
an immutable binding, including writes through loops, destructuring and context
managers. Later assignments cannot replace the command evaluated at an earlier
subprocess call.

Guide rule 6 permits host-tool transaction locks. The Python AST checker accepts
only proven file descriptors for the canonical UFW, observer self-update,
WireGuard peer config and documents cleanup lock paths. It follows literal paths, Path joins, open/fdopen
and with bindings, and accepts exclusive acquisition (optionally nonblocking)
or release. Dynamic paths, other files, unrelated descriptors, sleeps and retry
loops remain findings. Naming a tool in a comment grants no exception. Python
heredocs use this AST rule once rather than a second shell-token lock count.

The parameterized PHP/FPM transaction also qualifies when its tracked sibling
scripts prove the complete source contract. The Host constructor fixes the root
to `/run/lock` and root uid/gid to zero. The Runtime constructor validates the
account with `[a-z_][a-z0-9_-]{0,31}` before an action uses it. The checker follows
the constructor tuple through the action dispatcher, rejects untyped callers and
rebinding, and recognizes the exact `orbit-php-<user>.lock` acquisition sequence:
`O_NOFOLLOW`, root-owned regular file, mode 0600, exclusive flock and close in
finally. It preserves coordination with existing PHP deployment writers. A path
prefix alone, missing sibling source, changed custody checks or an unknown call
chain grants no exception; no grandfather entry is added.

Rule F can prove a fixed external HTTP origin before a dynamic path suffix.
The literal prefix must contain a complete authority and its trailing slash;
unknown origins, partial authorities, reassigned bindings and URL joins that
could replace the authority remain unresolved. Gateway metadata in a public
provider input does not make a fixed external API request a Gateway callback.

Static native child workflows resolve by an unambiguous local workflow name or id.
The coverage graph follows each child and its behavior and script dependencies.
Missing, dynamic or ambiguous children stay unknown; a child with accepted debt
does not make its parent clean.

Workflow metrics separate clean, baseline-accepted and unknown workflows. A clean
workflow has known steps and no findings in its own source or reachable local
behavior dependencies. Static coverage is not a runtime canary. Each run emits
versioned JSON with its commit and units, plus a `SHAPE-GATE` summary on one line.
The `Swamp shape gate` job emits this fixed format for the daily report:

```text
SHAPE-GATE workflows_passing=N/TOTAL grandfathered=M embedded_lines=X custom_loops=Y gateway_client_files=Z
```

`workflows_passing` counts statically clean workflows, not runtime validation.
`grandfathered` counts actual file/rule entries, including proved source moves.
`embedded_lines` counts decoded script lines by source literal. `custom_loops`
counts syntactic control sites. `gateway_client_files` counts distinct files with
a `gateway-http` finding, including callers that reach a local Gateway client.
The JSON report retains coverage categories, raw baseline counts and source moves.

Nick permits exactly two Gateway execution primitives from steps: GitHub
installation-token mint and certificate signing (CSR plus operation id to a public
bundle). The gate allows only these exact POST route templates:

- `/api/v1/direct-operations/{operation}/github-token`
- `/api/v1/direct-operations/{operation}/certificates/sign`

Root confirmed both route contracts on 2026-10-31. The operation segment must be a
positive numeric id. The native Python request fixture proves
its numeric check precedes the request and rejects reassignment after that check. It does not exempt
a client, module, endpoint prefix or file. Other Gateway HTTP calls from steps,
including failure reporters, remain forbidden. The `gateway-http` rule covers the client and its aliases and imports;
workflow coverage follows local dependencies, so helper modules and failure jobs
remain part of the audit. A registered step that reaches a Gateway helper has
its own file/rule finding, so new steps cannot inherit a historical exception.
Intent arrives as non-secret workflow inputs, vault
names and key names identify secrets, and step outputs carry results.

Shared public Operation schemas and cancellation evidence live in
`extensions/behaviors/operations/_lib/direct-input.ts`. Metadata and data-only
helpers import that module directly. Importing `direct.ts` also reaches the
retained Gateway client and therefore keeps that dependency in the shape audit.

The required jobs are `Swamp shape gate`, `Swamp workflow validate` and
`Swamp Deno tests`. The reusable workflow splits every Operations test file
across ten parallel jobs with Deno's native `--shard` and directory flags.
Four general shards exclude the database effect directories (`local-sql-effects/`,
`read-effects/`, `create-effects/`, `write-effects/`, `server-create-effects/`)
and `process-commands/`. Each excluded directory has its own job. The database
engine cases use separate native test modules, so `--parallel` runs their
unchanged effects concurrently. New files enter the general shards automatically,
or their assigned directory job.
Each shard keeps `--parallel -A` and `DENO_JOBS=4`, runs the same
prerequisite checks and receives no validation credentials. The pinned official
`setup-deno` action caches downloaded modules and packages; Deno still performs
its native type checking. All shards run even when one fails. The single
`Swamp Deno tests` aggregate always runs on `ubuntu-26.04` and succeeds only
when the complete matrix succeeds; failed,
cancelled or skipped shards fail
the aggregate. The root `Required checks` continues to require this reusable
workflow.

The reusable workflow accepts `working-directory` and
`base-ref`. Its bytes can be copied to Orbit's root `.github/workflows/` and called
with `working-directory: swamp`; lane24 adds it to `Required checks`. Every
workflow must validate on the checksum-verified runtime pin. All Operations Deno
tests run with four workers per shard, including disposable database effect tests.

Native assertion expressions and guards use Swamp CEL, including grouping,
`has()` and collection macros such as `all()`. Workflow validation owns CEL
syntax; the Node placement lint does not impose a separate expression subset.
Assertions still require a string expression, static messages and matching
Node placement.

Legacy Gateway HTTP over proxy Nodes 1/18 lives in
`operations/_lib/node-proxy-http.ts`. Its retained caller uses pinned SSH curl
on the effect Node with the caller's CA file and timeout. Command lowers import
`operations/_lib/node-proxy.ts`, which contains no Gateway HTTP client. This
separation grants no new use of the legacy client or admission on proxy Nodes.

Nick approved the dedicated `orbit-monorepo-ci` collective token on 2026-10-31,
with `extensions:read` scope. Only the native workflow-validation step receives
`SWAMP_API_KEY` and `SWAMP_SIGNIN_TOKEN`, on pull requests and main pushes. Shape
and Deno jobs receive neither. The caller maps both secrets explicitly, never
through inherited secrets. Pull-request-target events cannot receive them.

The helper downloads the public checksum-verified official artifact and starts
it with fresh HOME, XDG and Swamp configuration directories. Its environment
allowlist admits only these two approved credentials. Official e459's startup
account gate precedes local schema loading and verifies through
`GET /api/whoami`. Native validation is required: unavailable authentication and
schema, checksum or download failures fail the job. There is no import exception.

The gate prints current debt separately from base comparison. Retained historical
agent actions are grandfathered; only growing counts or new action evidence fail.
Unknown agent targets remain unknown findings. A fresh PR also cannot reuse a
stale allowance after the base source reduced its debt. The baseline must match
current findings, so removals reduce the checked-in list in the same PR.

For the history-preserving monorepo import only, the caller may pass the recorded
Swamp source SHA when the actual comparison base has no `swamp/.swamp.yaml`.
This applies to the import PR and its first main push. The checker accepts the
unprefixed source tree only when that commit is an ancestor and its repository
identity matches. Subsequent runs compare the actual Orbit base under `swamp/`.
Lane24 records the import provenance and selects this one-time base.

The scanner reads tracked files. Stage the imported inventory and baseline before
a local import check. When the preserved source predates the gate, the checker
derives initial limits from that source; a gate-source comparison base is not
needed. The import fixtures cover both the PR and its first main push.

The only Gateway wait exception is in the Orbit release runner: it may wait up
to ten minutes for Swamp serve health before failing Caddy publication. It does
not allow Swamp-step polling or Gateway wait/retry machinery elsewhere.

The TS checker owns the endpoint policy and passes it to the Python AST helper.
Native shell URLs use the same policy. The signer fixture is copied from lane0c
model `orbit-certificate-signing-http`; altered methods, paths, unchecked ids and
extra callbacks are rejected.

Python command provenance uses lexical scope and statement order. Global or
nonlocal declarations, walrus assignments and deleted bindings prevent a claim
that a captured command is fixed. Unresolved executable control remains unknown
and cannot gain a clean classification.

The Node-worker lint recognizes the exact official `@swamp/ssh`, `@swamp/cfgmgmt/file`, `@swamp/cfgmgmt/directory`, `@swamp/cfgmgmt/systemd` and `@swamp/cfgmgmt/firewall` types. The systemd model provides native `apply` and `restart` used by publication workflows. This is not an extension-namespace wildcard; other unknown types still fail.

The official firewall model (`@swamp/cfgmgmt` package `2026.10.27.1`) supplies `apply` for Node-local port/protocol/source tuples with its defaults. Family workflows keep fixed209 placement and public official connection inputs. Recognition adds no destination/comment semantics or family SSH adapter; native validation owns the model schema.
