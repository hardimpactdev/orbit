# Fleet release parents

`fleet.rollout` and `fleet.cli_converge` each start one native Swamp run. The
Gateway records the ordered participants, desired CLI asset, root profile and
frozen observer delivery. It records the result; it does not visit Nodes or
start child Operations.

`operation-fleet-rollout` publishes the CLI and frozen observer on each Node.
`operation-fleet-cli-converge` only installs/configures the CLI. Both call the
canonical `installCli` lower in `cli_install.ts`; current, newer and source
CLIs keep the lower's existing policy. Version observation follows installation.
Observer distribution verifies HTTPS bytes and the admitted SHA-256, atomically
replaces the binary, restarts its service when needed and checks the live process
digest. It invokes no agent action and does not build or choose a new agent.

The native `forEach` has concurrency one. Each placed Node step checks all prior
Node outputs before acting. A refusal or missing output leaves later Nodes
pending. The final step records those bounded outputs and halts on the failed or
uncertain Node. Deferred lock/window outcomes permit later Nodes and leave the
rollout running for catch-up. Shared-role Nodes wait for 03:00–06:00 Amsterdam;
app-dev-only Nodes may run all day. Cancellation is checked before each effect;
a started bounded publication completes before its outcome is recorded.

Fleet maintenance admits all ready Nodes into one parent, retaining its original
idempotency key after a lost reply. Catch-up waits five minutes after a finished
attempt. The Gateway's fleet endpoints require Gateway access, and each result
must name exactly the admitted Nodes in order. Per-Node placement uses the shared
worker runner; a local-only runner refuses a proxy worker. vpn1/usexit18 require
lane 21's SSH proxy transport before an effect can run there.

Focused checks use the pinned native runtime in
`extensions/behaviors/operations/fleet/release_native_test.py`. Its real DAG tests
cover order, CLI-only delivery, refusal, uncertain effects and closed windows;
only HTTP and system effects are replaced by fixtures.
