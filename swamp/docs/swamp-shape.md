# The Swamp shape: how every Orbit operation must look

Read this before you port, write or review any Orbit operation in Swamp. This is Orbit's sole owner for machine execution, placement, secrets, Gateway callbacks and result transport. Skills and integration references route here; they do not add execution rules. It overrides the generic Swamp-managed AGENTS preamble and older per-family contracts, including blanket "no shell" rules. The [Swamp manual](https://swamp-club.com/manual) owns native mechanics (Workflows, Behavior Definitions, Vaults, Data, Remote Execution); [docs/cli/README.md](../../docs/cli/README.md) owns chart design.

Decided by Nick, 2026-10-31.

## The objective

Swamp does all operational work, the way Swamp is designed to be used, with Swamp defaults. The Gateway keeps state, the API/CLI/MCP, validation and intent records, and it projects results. orbit-agent only observes. If your code makes Swamp a thin wrapper around a hidden program, it is wrong, even if it is green.

## Use what Swamp provides first (Nick, 2026-10-31)

Before you build any mechanism, check Swamp core (https://swamp-club.com/manual) and the official extensions (https://github.com/swamp-club/swamp-extensions: ssh, vault, datastore, git, container-image, cfgmgmt, workflows, and others). If Swamp or an official extension does it, use that, with its defaults. Don't build custom wrappers, transports, supervisors, protocols or channels.
- Don't over-engineer for edge cases. All Orbit Nodes are trusted, so extra custody, placement or identity hardening beyond Swamp's defaults is not needed.
- Example: steps on vpn1 and usexit18 use the official `@swamp/ssh` extension (exec/script methods, pinned hostKeys, the approved 209 key, sudo where root is needed) from a normal 209 control worker. They don't use a custom SSH runner.
- A PR that adds a mechanism must say which core feature or official extension it checked, and why that doesn't fit.

## The shape

1. **The Gateway starts a run with the full intent as inputs.**
   - StartSwampRun passes every non-secret field the workflow needs as workflow inputs. Swamp records them in run history.
   - Secrets are never inputs. Pass the vault name and key name, and resolve them in the step.
   - Swamp refuses a run whose inputs hold null for a property the inputs schema declares, whatever the schema form (`{}`, `nullable`, `anyOf`); only a `default` lets null through. Declare an intent that can hold null as an opaque `type: object`, as `operation-instance-destroy` and `operation-instance-unregister` do.
   - Steps do not call back to the Gateway to read intent or report progress. There is no binding wait.
2. **The Gateway records the result from the run.** Steps return outputs. The Gateway projects the terminal result of the run. It does not re-validate it.
3. **Ansible does every action on a machine; Swamp orchestrates.**
   - Decided by Nick, 2026-11-07. Before that, an Ansible phase was the approved exception (2026-11-03); it is now the default. Run each logical phase as one placed `command/shell` step that runs one tagged part of a repository playbook on the Swamp Node, against the intent file. The playbook reads, changes and checks the machine in one run. A read, a plan or an end check is a task in the playbook, not a Swamp step. The chart shows one step per phase.
   - Swamp keeps what is not machine work: the logical phases and their order (`dependsOn`, `guard`, `forEach`, `allowFailure`), the two Gateway calls a step may make (mint and sign), external API calls (GitHub), cancellation, and the `input`, result and `cleanup` steps of the run folder.
   - A native step that acts on a machine (`@swamp/ssh exec`, an official `cfgmgmt` behavior) is the exception. The PR names the measured numbers that justify it: Swamp steps, end-to-end time and per-phase time of both forms. Several reads and changes on one machine, and any work that repeats per Node, belong in a phase.
   - Use a typed TypeScript behavior only for a typed, schema-validated result that a file cannot print. A plan of a machine change is not one: it is a pure Jinja template in the playbook with its own template test (see the [porting guide](../../docs/swamp/porting.md)).
   - A single command is not an automatic native exception. Work that must not run twice uses a native guard around the phase. A native cfgmgmt check must report `compliant`; successful execution alone can still report drift.
   - A script or custom Ansible module needs a named gap in core and official extensions, explained in the PR. Never hide a custom transport in it or copy scripts to a Node.
   - A change to the phase sequence needs a written reason in the PR. The [Ansible phase contract](#ansible-phase-contract) below owns the playbook rules.
4. **Scripts are visible and linted.**
   - The script text lives in the workflow or the behavior definition (`run: |`), or in a file that Swamp ships to the worker, never inside a TypeScript string.
   - CI runs shellcheck (and ruff for Python) on all of it. Playbooks live in `swamp/ansible/` and pass `ansible-lint` in the `production` profile; the gate lists each playbook. Their Jinja templates and expressions have template tests in `swamp/tests/` that check them against fixed text: a Deno `*_test.ts`, or a Python test that the gate names in its `ANSIBLE_TESTS` list. The gate runs both.
5. **Secrets come from Swamp vaults.**
   - The Gateway stays the source of truth. It pushes the execution copy with the native authenticated `vault.put` WebSocket request when a value changes. The value is in the frame payload, never argv; the shared Gateway helper launches no CLI.
   - Steps read `${{ vault.get('<vault>', '<key>') }}`. In `command/shell`, Swamp passes it as an environment variable. The approved Node209 worker SSH key instead uses official `@swamp/ssh` `identityFile` through native `LoadCredential`; worker-wide read is approved.
   - Scripts pass a secret to a tool by stdin or a config file, never as an argument, because `ps` shows arguments to every user on the Node.
   - Root-of-trust keys stay only in the Gateway: the GitHub App, Orbit CA and executor signing keys. The only Gateway calls a step may make are minting a short-lived GitHub token, signing a CSR for an Operation, and signing a fresh executor order for a bound Operation ([ADR 0266](../../docs/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor.md#fallback-one-implementation-on-both-paths)). The worker supplies only the binding, not the action, Node or target. The phase sends the order to the fixed relay on stdin and exposes only the result. Never copy a signing key into a Swamp vault.
6. **Use native control flow, not your own.**
   - **Ordering:** `dependsOn` with `condition`.
   - **Fan-out:** `forEach`.
   - **Skip when already done:** `guard`.
   - **Tolerated failure:** `allowFailure`.
   - **Recovery:** `swamp workflow resume`; no hand-written retry loop.
   - **Transient external errors:** in an Ansible phase, the tool's own retry: the task keywords `retries`, `until` and `delay`, or a module's own option such as `update_cache_retries` of `apt`. Never a shell loop or a script of ours. The Note states the bounds.
   - **Waiting for a state to arrive:** the same task keywords, inside a literal `timeout`: for example a wait for an Operation or Doctor of a nested Gateway, one check per `incus exec` (`swamp/ansible/task-vm-fleet-wait.yml`). The task stops at once when the guest it asks is not running. (Decided by the orchestrator, for Nick's review, 2026-11-06.)
   - **Parallelism:** `concurrency`. It sets how many items of one `forEach` run at a time, nothing more. Up to Swamp `20261103.000636.0-sha.db3f6373` the smallest `concurrency` of a level also ran every other step of that level one at a time; from `20261114.210101.0-sha.537e03d1` it does not (swamp-club#4357). So a step that must wait for a sibling names it in `dependsOn`, usually with `condition: {type: always}` so that its guard still sees a sibling's failure. `tests/workflow_engine_order_test.ts` fails on a workflow whose order would differ between the two rules, with every list full and with each list, each two lists and all lists empty (both engines drop an empty `forEach` from the graph, so its dependents can move to another level).
   - **A list that can be empty:** an empty `forEach` is a `skipped` step from Swamp `20261114.210101.0-sha.537e03d1`; before, its dependents saw no status at all, so `skipped` and `or(succeeded, skipped)` never ran them and `not(skipped)` did. A step that must run after a list that may be empty depends on it with `condition: {type: not, condition: {type: failed}}`, which both read alike, and also depends on that list's own predecessors: dependents of an empty `forEach` do not wait for them. `tests/workflow_engine_semantics_native_test.ts` runs the affected workflows on the engine.
   - **Levels:** Swamp runs the jobs of a workflow, and the steps of each job, in the depth levels of their `dependsOn` graph (`execution_service.ts` and `topological_sort_service.ts` of the pinned release). A level starts only when every job or step of the level before it has ended, whatever its own dependencies, and guards never move a step to another level. Lines that must overlap start at the same depth, or run in jobs of one level.
   - **A run cut by a serve restart:** every workflow sets `autoContinue: false`, and so does the workflow of each native Schedule. From Swamp `20261114.210101.0-sha.537e03d1`, serve continues an interrupted run at boot unless the workflow opts out. Orbit's steps are not all safe to run twice, so a cut run stays interrupted, and the Gateway's reconcile decides. `swamp/bin/source_test.ts` checks every workflow file, and `NativeScheduleWorkflowTest` checks the Schedule's workflow.
   - **Waiting for a worker:** set `queueTimeout` on every step per PLAN F8 and the measured kind budget.
   - **Waiting for a person:** `manual_approval`.
   - **Child work:** `type: workflow`.

   No `setTimeout` sleeps, no `while (true)` polling, no lock files when one of these fits. A host-level lock that a tool itself needs, such as apt or ufw, is allowed. Name it in the step.

   Note (sub-orchestrator A, 2026-11-01): a phase script may poll an external system until it reaches a state, such as an external image becoming ready. The poll runs under coreutils `timeout <phase budget>` at a fixed interval. It exits 0 on the target state and nonzero with a readable F1 error on a terminal bad state; the projector renders a timeout (OD2). The PR states the interval and budget, which count toward the kind's F8 budget. This keeps the legacy sequence (F9). Retrying a failed action with a loop of ours, waiting on the Gateway or Orbit state, and waits outside a phase script (TypeScript behaviors, step loops, Gateway code) stay forbidden. An Ansible phase may wait for a service it just started. It may also retry a task that fails on a transient external error, such as a 503 from `snapshot.ubuntu.com` for a package or an index (decided by the orchestrator, for Nick's review, 2026-11-06). The retry is the tool's own: `retries`, `until` and a fixed `delay` on the task, or a module option such as `update_cache_retries`, which waits by the module's own backoff up to its maximum delay. All of it runs inside a phase command that starts with a literal `timeout`. The PR states the retries and the delay, or the module's retries and maximum delay. Any other failed action is not retried: a failed task fails the phase, and recovery is `swamp workflow resume` or a new run. A shell loop or a script of ours never retries.

   The gate accepts a literal positive timeout as the first word of the step command (`timeout N …`). Every invocation of a polling file must be bounded. On exit 124 from a step whose command starts with a literal `timeout N`, the shared F1 projector renders `<step> timed out after <N>s` (OD2; lane 16's projector, pending on main). Scripts print nothing for it, and the gate rejects shell wrappers and epilogues. The script uses a fixed positive literal interval shorter than the timeout; one fixed wait follows the same rule. Python polls return on success and raise a readable error on a bad terminal state. Their HTTPS GET must have a fixed external origin. The shell and Python checks exclude Orbit domains, Gateway names, local names and IP literals. Python `requests` and `httpx` responses need an unconditional `raise_for_status()` before reading state; `urllib.request.urlopen` raises HTTP errors itself. A provider wrapper must prove the same read and failure handling from its source, with no exception suppression. The checker follows imports into sibling modules in the same shipped scripts directory. It grants no DNS-read allowance. Shell polls use `set -e`, a failing HTTP GET, a state `case` and a fixed sleep. Request failures propagate; they are not retried. Script paths may come from a typed metadata step's `ctx.extensionFile` asset. The gate parses these files without running them.
7. **Placement on every step.**
   - Each step declares `target` or `labels`, and serve only relays (`--remote-only`).
   - Use one effective selector, not both `target` and `labels`. Ansible control steps use `labels: {role: swamp}` on the Swamp worker; that is not a blanket selector for every workflow. Keep run-folder producers and consumers on the same worker.
   - A per-Node worker acts only on its own Node.
   - vpn1 and usexit18 run their steps through the SSH proxy workers on 209.
8. **No agent actions.** Do not call orbit-agent action commands from a step. Implement the action as steps. The agent is frozen and will be stripped to an observer.
9. **Swamp defaults.** Use the manual and CLI defaults for retention, GC, concurrency and tokens; `queueTimeout` follows rule 6. Any deviation needs a measured reason, recorded in PLAN.md.
10. **A task VM holds only placeholder keys** (Nick, 2026-11-07). [Credentials in task VMs](/reference/tasks#credentials-in-task-vms) states the pattern.
    - A guard proxy outside the VM, on the Node that holds the real key, accepts the placeholder only from the task VM WireGuard network and only on the paths the VM needs. It answers 403 to anything else, and swaps in the real key upstream.
    - The real key never leaves its Node and the Gateway. Each further key is one more placeholder-to-real pair in a guard.
    - The one exception: short-lived minted tokens that never stay on the VM, such as GitHub App tokens.
    - First use: the model router guard, `ai_router.task_guard`, on `services` at `10.44.0.3:8318`.
    - Containers on agent Nodes reach the same guard through a forwarder with no key on their Node, `ai_router.model_forwarder` ([ADR 0252](/decisions/0252-reach-the-model-router-from-containers-through-a-node-forwarder)).

## Ansible phase contract

Playbooks live under `swamp/ansible/`. Use built-in modules first, then pinned collections; use `command` for a tool without a module and declare `changed_when`. Each tagged phase reads, changes and checks its end state in one invocation. Refuse before the first dependent change, with a public error code in `assert.fail_msg`. Reload only on change with a handler, validate replacement configuration, and require zero changes on a second completed phase run. A pure decision beyond a simple `when` belongs in a small Jinja template with fixed-input tests, not a TypeScript planner.

The workflow's run-folder sequence is:

1. `@nckrtl/ansible-input` writes the whole admitted intent as a 0600 run-scoped file and resolves shipped playbooks from manifest assets. Swamp expressions have no JSON encoder; environment values must be strings.
2. Optional secret jobs read the declared vault into a 0600 file. Guard the job on descriptor presence: `vault.get` arguments can be evaluated in both arms of a conditional expression. The input builder writes inventory, vars and `known_hosts` using admitted accounts and pinned host keys, then removes the staging secret file. Never scan keys or disable host-key checking. Set `no_log: true` on private tasks.
3. Each phase is one placed `command/shell` carrier invoking `timeout <literal seconds> ansible-playbook --tags <phase>`, with the run's inventory, vars and pinned `known_hosts`. Mark machine-changing steps `writes: true`; a read-only check is not a writer. Work on several Nodes uses one parallel play or parallel child workflows with separate run folders.
4. Project results as described below. Cleanup waits for every producer and consumer of the run folder to settle, including failed and skipped optional jobs. A bare `completed` dependency excludes skipped jobs on the pinned runtime; use conditions covering both. Cleanup removes private vars and partial staging after success or failure, not only after a successful copy. Preserve the original failure and expose incomplete cleanup.

Pin Ansible, collections and filter libraries in `requirements.*`. Every playbook, task, template and helper asset belongs in the manifest's `additionalFiles`. See [Ansible phase lessons](../../docs/solutions/ansible-phase-lessons.md) for loader, delegation and fixture pitfalls.

## Result transport

The Gateway uses the shared terminal event/history reader and the family's declared selector. Families add no callbacks, history readers, timers or pollers. [Swamp command steps](../../docs/reference/swamp-command-steps.md) documents the helper API, native captures and transport recovery.

- **Native results:** when native run status and producer captures contain the public result, declare `terminal_result` with `native => true` and project that data directly. Capture producer JSON rather than relying on the step summary's truncated stdout. Do not add a reporting step to reconstruct native status or diagnostics.
- **Ansible result files:** Ansible's stdout is a log, not the public JSON contract. When structured phase results are needed, the playbook writes public `result.json` on success and in failure rescue, then fails the phase on failure. A bounded run-folder result step may print that file after the phase settles, including failure. This is result transport, not another machine phase or a callback; it must not turn a failed run into success. Keep its selector synchronized with the Gateway declaration.
- **Private output:** SSH records stdout and stderr before later shaping. Capture private command output in protected files, transfer privately, apply the [shared output filter](../../docs/reference/tasks.md#keep-command-output-out-of-run-history), then delete the files. Never print a secret or its encoding into history. Keep vault values small; public intent is not a secret envelope.
- **Terminal failures:** retain completed producer observations when cleanup fails, while keeping the Operation failed. An exact native terminal within budget survives Gateway expiry; a failed projection leaves expiry armed. Missing, active or mismatched history is not a result and authorizes no retry or resubmission.

## Checks on every PR

- `swamp workflow validate` for every workflow, on the pinned runtime.
- `ansible-lint` for every playbook, and the template tests in `swamp/tests/`: the Deno `*_test.ts` files and the Python files in the gate's `ANSIBLE_TESTS` list.
- `deno test` for `extensions/behaviors/operations`.
- The placement lint.
- The Swamp-shape gate. It fails on:
  - scripts embedded in TypeScript;
  - many-action behaviors;
  - custom waits, retries or polling of our own (a tool's native retry is allowed, see rule 6);
  - secrets not from `vault.get`;
  - step callbacks to the Gateway for intent;
  - agent action calls.

- Review checklist: does Swamp core or an official extension (swamp-club/swamp-extensions) already provide this mechanism? If yes, the PR uses it with its defaults; a custom wrapper, transport, supervisor, protocol or channel is a defect.

Existing violations are on a grandfather list that may only shrink.

The gate follows static model references by name or UUID into their model definitions and type dependencies. A child workflow choice with two literal names is checked through both source graphs. Arbitrary dynamic references, missing or ambiguous targets, and models without a supported static type remain unknown.

### Bridge writer transaction contract

The Python shape policy proves the bridge writer's complete transaction structure. Admission, refresh and withdrawal enter the same context manager. It opens `writer.lock` once, attempts `LOCK_EX | LOCK_NB` once, and refuses immediately with `instance.bridge_busy` when another writer owns it. The busy path changes no endpoint record, ACL or health fact. The open file context keeps the descriptor until the record reads, ACL changes, confirmed record updates and success or failure health write have finished, and closes it on every exit. The lock inode is never replaced or removed.

This is a structural contract, not a pathname allowance. The checker verifies the context manager, dispatcher, entry point, protected bindings and calls together. Destructive file operations are denied everywhere in the writer except inside one exact `state_file` helper. That helper takes the state root, a fixed state kind and a validated endpoint identity. It constructs each destination leaf itself; callers cannot supply a leaf path or callback. It can unlink those leaves or atomically replace them from a newly created temporary file, so it cannot remove a directory or the `writer.lock` inode. Helper aliases, altered helper bodies, arbitrary temporary-directory cleanup and external mutation commands fail the contract. The checker does not infer path values through loops, matches or helper arguments. The ACL transport also has a fixed body. The import check uses the whole Python inventory, including package and relative imports from other directories. Missing acquisition, early release, mutation on busy, wait loops and destructive calls in any control-flow form fail both the current checker and a checker frozen from the policy commit. A policy change must land before a caller relies on it; the frozen-base check keeps using its comparison commit's policy and baseline.

## Open points settled by the spike

The following sections record earlier capability proofs, not current workflow templates. Use the execution, Ansible and result contracts above when writing an operation.

The current template uses official `@swamp/ssh` package `2026.10.23.1` (model `2026.10.20.1`), checked at upstream commit `d6f7afdcd6d04a63fdbc6ed56c403832770f67a6`. The hand-built SSH launcher, key scanner and common Python module have been deleted. Official `exec` observes platform/architecture; `collect-host-public-key` observes the public key; one pure TypeScript step shapes their outputs. Stored public host keys use the official pinned policy. Missing pins fail native validation; the probe does not select trust on first use. The extension owns its default key agent and connection handling.

The original capability answers below were proved with synthetic data on a dev worker on Beast, using official Swamp `20261031.063736.0-sha.e459d219`; the pin is now `20261114.210101.0-sha.537e03d1`. Serve and worker were separate processes joined through the native remote-only WebSocket dispatch. The SSH executable in the fixture was harmless; this proves native delivery and transport, not a live bootstrap or logical Node 1/18 proxy effect. No secret value was printed.

### 1. A remote command step can run a repository script file

This is the historical script-delivery capability proof, not permission to rebuild an SSH transport. A behavior manifest lists each script and its imports under `additionalFiles`. Native dispatch fingerprints and ships them to the worker cache. A public metadata method resolves `ctx.extensionFile("scripts/probe_host.py")` and records the path with `ctx.writeResource`. The next placed `command/shell` step executes that path. Keep metadata and execution on the same worker. Inline `run:` is not the only supported form.

Proof: native run `4ee4cd98-89c2-43de-8103-0d3746a19306`. The script path was inside the actual worker cache; the built-in shell ran it and retained public outputs. Asset helper modules arrived in the same bundle. Evidence: `evidence/lane11-vault-script-pilot-20261031/remote-script-history.json`.

The original proof did not cover logical Nodes 1/18. Their replacement uses official `@swamp/ssh` from a normal 209 control worker, as the canonical rule above states. The custom proxy runner and its earlier receipt are superseded; they are not a dependency or template.

### 2. A whole vault expression maps to a named environment variable

For `command/shell`, a whole `vault.get` expression belongs in `task.inputs.env`. For the approved Node209 worker SSH key, use `task.globalArgs.transport.identityFile` supplied through native `LoadCredential`. The vault-backed bootstrap pilot below uses `identityContent` for its Gateway-sourced copy:

```yaml
task:
  type: behavior
  behaviorType: '@swamp/ssh'
  behaviorName: bootstrap-ssh
  methodName: exec
  globalArgs:
    name: bootstrap-ssh
    transport:
      kind: ssh
      user: orbit
      identityContent: '${{ vault.get(inputs.execution_secrets.bootstrap_identity.vault, inputs.execution_secrets.bootstrap_identity.key) }}'
    hosts:
      - {name: target, address: '${{ inputs.intent.bootstrap.target.host }}'}
  inputs: {hosts: 'name:target', argv: [uname, -sm]}
```

`globalArgs` belongs inside `task` and supplies the complete globals map. The descriptor contains only `{vault, key}`. With its defaults the official extension loads identity content through stdin into a temporary SSH agent, handles cleanup and records public outputs. Orbit adds no key-file wrapper, environment channel or agent supervisor. Its documented default file fallback remains an upstream implementation detail.

Use the shared Gateway helper `App\Infrastructure\Swamp\SwampCommandStep::executionCopy(vault, key, value)` to obtain the descriptor. It sends the native `vault.put` frame over the authenticated serve WebSocket and retains only a synchronization digest. The request is `{type: "vault.put", id, payload: {vaultName, key, value, force: true}}`. Official e459 defines it in `src/serve/protocol.ts:170` and accepts it in `handleVaultPut` (`src/serve/handlers/vault_handlers.ts:331`). The value is in the frame, never argv; no CLI or upstream patch is needed.

The actual Gateway14 PHP helper wrote a synthetic value to an isolated official-e459 server on Beast: request `4542eeb7453ee8016d19fa9240f201ea`. Readback matched. During the request, 140 Gateway process command lines contained zero value matches, and scratch files contained no plaintext value. Evidence: `vault-frame-gateway-argv.json`; a separate Beast proof is `vault-frame-official-e459.json`, in the same evidence directory. The helper source is [Orbit2038](https://github.com/hardimpactdev/orbit/pull/2038).

Nick approved Gateway execution-input puts at 14:47Z and explicitly accepted the official e459 read exposure tracked in Lab #4226: the Gateway already holds the source secrets. Lane16 owns application through the normal backed-up grants path. The pilot grant names `user:orbit-gateway`, action `write`, resource `vault:orbit-control-inputs`, and condition `key == "bootstrap-identity-node-14"`; no wildcard or new token is needed. Official e459 also admits delete and annotate on that key. Those are separate write-action consequences, not the read bug; `methods: [put]` does not narrow them because the handler supplies no method name. The grant receipt must report the actual scope rather than claim put-only isolation. Workers receive only get/resolve for their exact step keys. No permission, production vault, binary or root-of-trust custody changed in the synthetic proofs. The authority decision is settled; grant application remains lane16-owned. Native `vault.get` checks the run-trigger principal with action `read`; the accepted raw-read exposure does not supply that runtime permission. The orchestrator also approved a separate Gateway `read` grant on this exact pilot key. Lane16 applies and verifies that grant; worker read permission alone does not prove Gateway-triggered resolution. Actual runtime resolution remains part of the live proof.

### 3. The value is absent from shell argv, but child argv can expose it

Native dev-worker run `996e4603-e6b0-42c6-b8a2-e997731a9385` checked `/proc`. With whole-value environment delivery, neither shell nor script argv contained the synthetic vault value. An intentionally unsafe child invocation did expose it in the child's command line. The environment itself was readable through `/proc`; environment transport is not isolation from the worker user. Evidence: `evidence/lane11-vault-script-pilot-20261031/argv-probe.json`.

Never expand secrets into `run:`, command arguments or shell tracing. Scripts pass them to tools through stdin or protected config files.

### 4. A terminal event leads to one native history request

The start stream publishes `event.kind = started` with `event.runId`. Official e459 also supports an active-run attachment on a second authenticated socket:

```json
{"type":"run.attach","id":"follow","payload":{"runId":"<run-id>","afterSeq":0}}
```

The response is `run.attached`, followed by sequenced `event` frames on the same request id. A terminal frame has `event.kind = completed` or `cancelled` and `event.run`. A `completed` event can carry `event.run.status = failed`; it is not a success signal. The run metadata does not inline step outputs.

After the terminal event, send one request:

```json
{"type":"workflow.history.get","id":"result","payload":{"workflowIdOrName":"<run-id>"}}
```

The response has type `workflow.history.get` and the full run under `payload.data`. Select the named job and step in `jobs[].steps[]`; built-in shell output is in `outputs.stdout`, with `exitCode`, `executedAt`, `command`, `durationMs` and `stderr`. A result step prints one public JSON object. The shared `SwampCommandStep::projectTerminal(Operation, event.run, {job, step})` reads that history once, decodes the selected stdout and calls the existing family projector. Full-input opt-in is `workflow_inputs: true`. The family projector returns the outer `{intent, execution_secrets}` envelope; the shared builder writes authoritative IDs last. The separate fixed selector is `terminal_result: {job: bootstrap, step: probe-host}` for this pilot; it does not opt into full inputs. No family Gateway client, callback, poller or resubmission is needed.

Real official-e459 runs `2ebfa2a6-bb7e-4ad2-8a5b-867095984e93` and `2491206d-0531-442a-b5a5-60b988e989f6` proved success and failure after closing the start socket, attaching a second socket, and making exactly one history request per terminal event. A bad fingerprint failed the public scan and skipped the private probe. Raw sanitized frames and requests are in `terminal-attach-spike-succeeded.json` and `terminal-attach-spike-failed.json` beside the other evidence.

Attachment is active-run only. The native registry removes completed runs; it is not durable replay after completion. The shared event-connection owner must preserve the terminal event and handle this lifecycle. Do not invent `workflow.run.attach`, assume late replay, or add family polling. After an explicit late-attach miss, the reconciler passes the already-bound ID without a status to the same `SwampCommandStep::projectTerminal`. It makes one history request and projects only if history has the original identity and an actual terminal status; active, missing, transport or authorization failure causes no write. If an event status is supplied, it must match exactly. This recovers a result without fabricating status or starting a second reader. The shared subscriber is merged in [Orbit2041](https://github.com/hardimpactdev/orbit/pull/2041), commit `1fc90c146d1302a2e4fdfb0ed3135af6c34ffd26`. The existing observer multiplexes native attachments and asks `ReconcileSwampOperations` for a pass when a run ends; families add no listener. Real TLS tests cover all four terminal statuses, 1.1 MiB terminal metadata and 2.2 MiB history, within the shared 8 MiB native socket bound. Actual Gateway release and observer source uptake were verified at 14:32:13Z against that commit. The later live bootstrap run and its terminal Operation result are recorded in the Template section.

## Historical pilot

The standalone `node.bootstrap_probe` scaffold is removed because it had no production admission caller. `node:add` owns enrollment; its shared observation decoder remains. The following is historical evidence, not a current port template. The live Gateway14 → Node209 run is `84a86453-d659-4b9c-a753-381d3ef7e315`, bound to Operation `6174`. All three native steps succeeded. The existing observer projected `probed: true`, `architecture: x86_64` and the expected public SSH host identity. The Operation succeeded at sequence 4 with no error after normal Gateway release 830 restarted the observer at 17:45:05Z. Its finished time preserves the native completion at 17:25:02Z. No native replay or manual projector call was needed.

- [Workflow](https://github.com/hardimpactdev/orbit/blob/193a8c4a7f/swamp/workflows/workflow-operation-node-bootstrap-probe.yaml): full intent, vault references, native dependencies, official SSH methods and the fixed terminal result.
- [Official SSH](https://github.com/swamp-club/swamp-extensions/tree/d6f7afdcd6d04a63fdbc6ed56c403832770f67a6/ssh): package `2026.10.23.1`, normal registry pull and defaults. No vendored or patched transport.
- [Pure result decoder](https://github.com/hardimpactdev/orbit/blob/193a8c4a7f/swamp/extensions/behaviors/operations/node-bootstrap/probe_result.ts): platform/architecture decisions and public JSON only; no process or Gateway client.
- [Shared helper](https://github.com/hardimpactdev/orbit/blob/193a8c4a7f/apps/gateway/app/Infrastructure/Swamp/SwampCommandStep.php): native vault copy and one terminal history read.
- [Gateway declaration](https://github.com/hardimpactdev/orbit/blob/193a8c4a7f/apps/gateway/app/Domain/Nodes/bootstrap-direct-operations.php) and [projector](https://github.com/hardimpactdev/orbit/blob/193a8c4a7f/apps/gateway/app/Domain/Nodes/NodeBootstrapProjection.php): immutable intent and database projection.
- [Result decoder unit tests](https://github.com/hardimpactdev/orbit/blob/193a8c4a7f/swamp/extensions/behaviors/operations/node-bootstrap/probe_result_test.ts): Orbit's platform, architecture and public-output logic. The recorded topology receipt above proves official SSH execution; Swamp's engine and transport are not retested in a custom harness.

Core native inputs, vault resolution, method output records, `run.attach` and `workflow.history.get` already fit. The official vault extensions provide external backends; this pilot uses core `local_encryption`. Official `@swamp/cfgmgmt/node.gather` was checked: its schema accepts only `sshIdentityFile`, not `identityContent`, and gathers additional OS/package details. Official SSH methods fit this vault-backed read-only probe without another transport. Every mechanism PR records its own core/extension check.

Shared vault copies are merged in [Orbit2038](https://github.com/hardimpactdev/orbit/pull/2038); [Orbit2041](https://github.com/hardimpactdev/orbit/pull/2041) provides the shared subscriber and projection handler. The exact-key Gateway write/read and worker read grants were applied and installed-metadata policy checked at 15:02:36Z. The shared history reader uses the existing Gateway read credential, as fixed in [Orbit2071](https://github.com/hardimpactdev/orbit/pull/2071). The live run proves execution-copy resolution through the official SSH methods. Its terminal Operation projection is recorded below.

Source is [Orbit pilot 2003](https://github.com/hardimpactdev/orbit/pull/2003). The former custom SSH prototype is superseded. Preserve rule 5: GitHub App and Orbit CA private keys stay only in the Gateway. The only step-to-Gateway primitives are scoped token minting and CSR-plus-Operation certificate signing.

## Pilot comparison

The comparison uses imported baseline `a116589c527f55343626040f6effc8c7f5d8b746`, counting complete pilot-touched production files including public host-key capture. It excludes tests, documentation, shared generic contracts and the unmodified official registry package. Shared bootstrap lowers remain for other kinds. The earlier custom prototype is not the template.

| Measure | Before | Official template |
| --- | --- | --- |
| Touched production lines | 427 | 602 |
| Probe workflow lines | 44 | 117 |
| Custom Python SSH/scanner production lines | Not applicable | 0 |
| Native steps | Opaque `bootstrap/apply` | `inspect-platform`, `inspect-host-key`, `probe-host` |
| Step outputs | No public outputs on apply | Official SSH stdout/exit/timing and host key; public result JSON |
| Gateway calls from probe steps | Intent/private reads and result callback | None |
| Gateway projection | Family callback | Shared terminal event plus one history read |

For the same file scope, the superseded custom prototype had 659 production lines; the official version has 602. The pre-conversion history is `27d27930-753c-48c6-b00e-f715bc1220e3`. The historical script-delivery run `4ee4cd98-89c2-43de-8103-0d3746a19306` proves that capability only. Official-extension run `7bca63f5-a123-4733-8e3a-a9a4e5f02770` passed the pinned-key fixture; a missing-pin run fails native validation and skips the decoder. Current evidence is `official-ssh-native-history.json`; source counts are `source-comparison-official-ssh.json`, under `evidence/lane11-vault-script-pilot-20261031/`. Live native run `84a86453-d659-4b9c-a753-381d3ef7e315` started at 17:24:48.204Z and completed at 17:25:02.105Z. Its history shows `inspect-platform`, `inspect-host-key` and `probe-host` succeeded as separate steps. Operation `6174` contains their projected public JSON; the existing Node account remains `orbit`. Normal Gateway release 830 recovered the result through the existing observer. The final public API read confirmed success, sequence 4 and no error.

## Scheduled producers and results

Families publish captured non-secret schedule input through `SwampSchedulePublisher::publish(workflowId, schedule, inputs)`. The helper resolves the declared workflow name and sends the official `workflow.trigger.set` request, including nested `inputs`, then checks its acknowledgment. The helper sends a definition (workflow, schedule and inputs) once: a definition that serve already confirmed is not sent again, because each frame rewrites `serve.yaml` and registers the schedule again. The configured credential needs `write` on that workflow; publication failure does not authorize a grant change or an alternate caller. The family owns capture, revision, cadence and publication after committed intent changes. Trigger inputs are small baseline values, not a data feed: a schedule whose data follows Gateway state publishes none of it. It only ticks, and the family reads its own tables when it projects the fire (`maintenance-projects`).

A scheduled root that has no original Operation uses `app/Domain/<Family>/scheduled-results.php`: its fixed workflow UUID maps to `workflow`, `terminal_result` and a `ScheduledResultProjection`. `context_workflow` is optional for producers that publish `trigger.inputs` directly. The existing shared commits-feed reader calls `activate()` and, at terminal, `applyScheduled(workflowId, run, result)`. The family keeps durable replay markers and uses its normal validated admission actions for downstream work. It never fabricates an original run binding. Steps do not POST admissions, and families add no observer or history poller. The row-based `scheduled_result` contract remains for observations of already admitted Operations.

Native schedule input publishers declare `publishes_inputs: true` on the exact standalone scheduled-result workflow. Its intended workflow grant contains run, read and the write action required by `workflow.trigger.set`. Other workflow and context grants stay run/read; no admin or blanket write authority is inferred.

## Real engine checks

Run `bin/swamp-shape-gate` for the Ansible checks with its pinned tools and collections. Module argument validation covers all playbooks and task files under `swamp/ansible`, including blocks and handlers. Controller-only actions have no `AnsibleModule` argument spec. Templated values use valid stand-ins; value-dependent requirements and conditional `omit` presence still need runtime proof.

The command-output table in `swamp/tests/fixtures/command-output/cases.yaml` selects production expressions by file, task and field. Real Ansible/Jinja evaluates them against recorded nft and dpkg output and sanitized format samples. The inventory uses Jinja's syntax tree to find regex filters, native `search`/`match`/`regex` tests, and `select`/`reject` forms. Every regex expression must have cases or an explicit reason why it does not parse command output. Add samples when a parser changes; do not copy a production regex into the test. Counter cases include rising, equal and falling counts. Package cases include installed, held, removed and absent packages.

The pinned Swamp CLI's `workflow validate` type-checks every CEL expression under `jobs`: `forEach.in`, guards, assertions and task inputs. It refuses an unknown function or a bad overload. Runtime data stays `dyn`, so it cannot see a value of the wrong type at run time, such as a wrong field in a step's result. Native workflow validation and real runtime proof therefore still cover different failure classes.
