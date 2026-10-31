# Dependency methods on the authoritative host

Node 209 runs serve and three independent native workers under `orbit`. Two workers advertise `pool=migration`, and one advertises `pool=services-local`; all advertise `node=209`. Each worker uses `--concurrency auto`. The retired Sabre, Shark and Services workers have no active credentials. Gateway executor authorization is `ORBIT_OPERATION_EXECUTOR_WORKER_NODE_IDS=209`; repository-token authorization remains Node 209 only.

The pinned Swamp runtime refuses `context.runModel()` in remote execution. `@nckrtl/native-host-method` lets a worker invoke an allowlisted dependency method through the pinned local Swamp CLI in a separate process. The CLI uses `/home/orbit/apps/orbit-swamp/default`, so nested methods get the real local context and publish to the authoritative datastore. Producer names and exact report versions remain unchanged. Scan diagnostics use a separate `dependency-scanner-<projectId>` model per project to avoid a shared scanner lock.

The adapter accepts fixed actions and validated ordinary inputs. It cannot select an arbitrary executable, checkout, method or environment. JSON travels through literal stdin, with reports suppressed. Its clean environment contains trusted account paths and no inherited GitHub token, remote-server token or proxy headers. The original scanner requests its short-lived, one-repository Gateway token inside the child. Only git and gh receive that token; package-tool environments retain their existing allowlist. Workers receive no standing GitHub credential, vault grant or Node execution key.

Placed group, dependency, smoke and stage coordinators use the internal `invokeNativeModel` function for their reviewed nested model calls. Definition/type, name and method are validated as CLI identifiers; arguments travel through literal stdin. This TypeScript function is not a public behavior action. It uses the same fixed executable, authoritative checkout, clean environment and process-group cleanup. It returns only success; original methods keep their own canonical records. It never routes the nested call back through serve. Public dependency actions retain their fixed allowlist.

Each invocation owns a private temporary directory and a systemd service. Cancellation, normal completion or the action deadline stops its whole cgroup, including `setsid` descendants, before removing the temporary tree. Root package-command services are bound to this native service lifetime; cancelling the CLI cannot leave a separate package service running. Errors expose only fixed native CLI codes, never raw stderr. Original methods publish their normal safe diagnostics and evidence; the adapter adds no duplicate report resource.

This adapter requires the authoritative Node 209 filesystem, binaries and account. Workflows using it must select both `pool=migration` and `node="209"`; a generic migration worker elsewhere is insufficient. It does not enable `@swamp/ssh` or alter deployment extension pins. The historical, unscheduled `dependency-upgrade` still requires its configured read-only Orbit scratch checkout; placement does not create that checkout or grant repository access.

## Focused verification

Run `deno test -A tests/native_host_method_test.ts` for literal input, environment isolation, safe errors and process-group cleanup. Run `SWAMP_BIN=/path/to/pinned/swamp python3 -B -m unittest discover -s tests -p native_host_method_native_test.py -v` for a real isolated serve, worker and nested native method. That fixture proves the remote refusal, successful local nested execution, separate producer PID and canonical data visibility. It makes no live Node changes. Full producer publication and token isolation are covered separately by the Services detection fixture and dependency credential tests.

Package tools on the authoritative host run through the typed local `package.session` agent command as `orbit-swamp-package`, inside a private root/PID/IPC namespace and per-job cgroup. Only manifests and lockfiles cross that boundary. Registry access requires verified cgroup network filters before package code starts. Host worker tokens/cache, SWAMP_HOME, vault keys, issuer credentials, `/home/orbit`, host proc aliases and sudo authority are unavailable. Missing isolation refuses the scan; it never runs as `orbit`. Git/gh acquisition keeps the existing trusted path and short-lived token scope.

Authoritative scans use the workspace’s declared npm or Bun manager, including Vite+ projects. The Vite+ launcher requires Unix sockets that the package boundary deliberately refuses; scans do not run that launcher. Task-VM project tooling keeps its existing manager selection.

## Placement checks

Every repository workflow step must have effective worker placement. Run
`deno run --allow-read bin/lint-workflow-placement.ts`; CI rejects missing placement.
The lint applies Swamp's independent workflow → job → step inheritance for
`target`, `labels` and `platform`. An empty labels map clears inherited labels;
it does not clear an independently inherited target.

Dependency, smoke and stage coordination defaults to `pool=migration,node=209`.
Horizon detection keeps its explicit task worker target and clears the host labels.
Worker queries request full authorized records with the original predicate, then
project fields locally; selected remote queries are unsupported by the pinned runtime.
Nested project refresh, factory and stage calls use the same native child adapter.
Neither path falls back to execution in serve.
