# Declarative family build contract

`orbit.family/v1` puts the Orbit contract and native Swamp workflow in one
reviewed YAML or JSON spec. This first slice is additive and inactive. No
current family loads these artifacts. The paired Gateway artifact loader checks
release pins and registered names. Shared admission/completion adapters, the
control-worker allowlist and registered typed SSH method still follow before
adoption. The [typed SSH factory](typed-agent.md) is implemented separately and
remains unregistered until those adapters and command contracts land.

The compiler emits `workflow.yaml` and `contract.json` into a new build
directory. It preserves the workflow ID assigned by `swamp workflow create`.
Never invent an ID. The synthetic fixture's ID came from that command on the
pinned runtime; its workflow only asserts `true` and is never installed in the
active workflow tree.

```sh
deno run --lock=family/deno.lock --frozen --allow-read --allow-write=/tmp \
  --allow-env=HOME,SWAMP_CONFIG_DIR,SWAMP_HOME,SWAMP_API_KEY,SWAMP_API_KEY_FILE,SWAMP_SIGNIN_TOKEN \
  --allow-run=/absolute/path/to/swamp bin/compile-family.ts \
  tests/fixtures/family/example.json /tmp/family-build /absolute/path/to/swamp
```

Use Swamp `20261028.205235.0-sha.1602a617`. Schema reads need a Swamp account,
including in CI. The CLI inherits only account/configuration variables. It does
not inherit a remote serve URL or serve token. It refuses an existing output
directory. The release process must install the reviewed pair together and run
native `swamp workflow validate` with the family's actual extension types and
vault definitions before activation. This build does not execute family code.

The complete spec is illustrated in
[`example.json`](../tests/fixtures/family/example.json). Orbit fields are
closed:

- `family`, one `kind`, `actions`, closed JSON Schema `input`, and admitted
  `target.node` and `target.owner` selectors define admission.
- `workflow` is the native Swamp definition, including its ID, jobs, tasks, CEL,
  dependencies and placement. Swamp owns execution, retries and cancellation.
- `commands` contains fixed Orbit-agent names. `steps` binds receipt keys to
  native job/step names, optional command catalogues and read/keyed effects.
- `secrets` contains admitted-state key selectors and one of the three control
  vaults. It holds references. The compiler never resolves secrets. Native vault
  expressions and output vault configuration remain in the native workflow.
- `children` binds another kind to admitted input and Node state. Same-Node work
  uses native steps. A distinct selector still needs runtime cross-Node
  admission.
- `done_when` requires native success and declared receipt keys. A completion
  step cannot allow failure. Runtime receipt presence must also be checked;
  native success alone does not imply an effect happened when a guard skipped
  its step.
- Closed `result.schema` and `result.max_bytes` define public output.
  `projection` and `invariants` name registered domain hooks. `cancel` names the
  cooperative boundary. `health` gives unclaimed/stalled thresholds in seconds.

Unknown fields, invalid schemas, open input/output objects, unbound Node/owner
selectors, unsupported versions, undeclared commands, missing/duplicate step
bindings, invalid dependencies and cycles fail the build. Secrets must use the
vault matching their purpose. Keyed effects must declare a command catalogue.
These checks validate the reviewed contract; they do not sandbox privileged
extension code or prove its domain behavior.

Swamp's own exported workflow schema validates native syntax and supplies native
defaults. The compiler does not maintain another task schema or execute a DAG.
The normalized spec receives a SHA-256 digest. The contract includes that
digest, the native workflow ID/name and its SHA-256 digest. Canonical JSON sorts
object keys and preserves array order. Both repos must pin the same reviewed
contract; the future Gateway consumer must reject spec/run mismatch and unknown
hooks. Generated artifacts count toward family line totals.

The next Gateway slice uses these fields to provide shared admission,
completion, health and secret-reference plumbing, plus reusable contract tests.
Existing Kit I receipts, compensation/cancellation safety and archive custody
remain until the matching shared consumer and a family proof replace their use.
No current Gateway proof or proxy is bypassed by publishing an artifact.

`catalogue` is the SHA-256 of the exact `family/catalogue.json` export. Generate
that file with `bin/export-family-catalogue` from the paired Orbit checkout. It
contains the pinned `apps/agent/contract/commands.json` hash, its command names
and mutation flags, registered Gateway-local command names, closed projection
and invariant names, and implemented native-method bindings. Keep the generated
bytes unchanged. Do not reformat the export. Adding a well-formed name to a
family spec does not register a command or hook.

Every native step needs one Orbit step binding. A command step must use direct
`behavior` type execution with an exact registered `behaviorType`/`methodName`
pair for its transport. Its method input `command` must be a literal equal to
the declaration's single command name. Whole-input CEL, command-name CEL,
existing behavior references, undeclared steps and unused command declarations
are refused. Mutating commands require `effect: keyed` and a method registered
as supporting keyed execution. Non-command steps currently support only native
assertions. Nested workflows and expanded receipt sets need a separate binding
contract before use; they are refused now.

The initial export has `tools.record_intent` as its only projection, no
invariants and no native command methods. This deliberately prevents command
family adoption until the typed method implementation and its Gateway catalogue
registration land together. Tests use a test-only native method registration to
exercise command binding; it is never part of the production export. The
synthetic assertion fixture uses the registered projection name but performs no
Gateway projection or Node calls.

Gateway `OperationFamilyContract::load` consumes only a locally installed
artifact whose SHA-256 matches a code-owned release pin. It requires the exact
installed catalogue digest and resolves projection/invariant/command names
against that release. The expected artifact digest must never come from an
Operation or Swamp run input. The loader does not register or admit a kind. A
run-authority binding and shared admission/completion adapters remain necessary
before adoption. This catalogue is a build contract, not a sandbox for trusted
extension code.

Control vaults belong on Node 209, under `/home/orbit/apps/orbit-swamp/default`.
Their master keys belong under `/home/orbit/.config/swamp/vault-keys/`, outside
the datastore, with separately held encrypted recovery copies. Do not bootstrap
on Services. Live family exports and execution-key installs require the short
plan to the orchestrator first. T1–T5 are approved; T6 mTLS remains deferred.

Native references at the runtime pin:
[workflow primitives](https://github.com/swamp-club/swamp/blob/1602a6172fe5002133a56e8b2da31e90f3fb8b04/design/primitives/workflows.md)
and the schema from `swamp workflow schema get --json`. This uses their native
workflow/DAG and `globalArgs` fields rather than another workflow language.
