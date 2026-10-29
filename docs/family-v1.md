# Declarative family build contract

`orbit.family/v1` puts the Orbit contract and native Swamp workflow in one
reviewed YAML or JSON spec. This first slice is additive and inactive. No
current family loads these artifacts. The Gateway consumer, shared runtime
adapters, control-worker allowlist and typed SSH method follow before adoption.

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

Control vaults belong on Node 209, under `/home/orbit/apps/orbit-swamp/default`.
Their master keys belong under `/home/orbit/.config/swamp/vault-keys/`, outside
the datastore, with separately held encrypted recovery copies. Do not bootstrap
on Services. Live family exports and execution-key installs require the short
plan to the orchestrator first. T1–T5 are approved; T6 mTLS remains deferred.

Native references at the runtime pin:
[workflow primitives](https://github.com/swamp-club/swamp/blob/1602a6172fe5002133a56e8b2da31e90f3fb8b04/design/primitives/workflows.md)
and the schema from `swamp workflow schema get --json`. This uses their native
workflow/DAG and `globalArgs` fields rather than another workflow language.
