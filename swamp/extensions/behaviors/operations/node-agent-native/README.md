# Agent operations from 209

`node.agent_rekey`, `node.settings` and `node.converge` use one native root
each. Every step runs on 209. Official SSH uses the approved credential identity
file and the target's recorded host keys. The Gateway supplies complete public
intent and projects the terminal result; the workflows make no Gateway callback.

The SSH timeout and agent envelope preserve each frozen command’s existing
execution budget.

The agent source is frozen. Under the explicit Orchestrator boundary decision,
these roots invoke existing agent commands over SSH. Settings separates account
lookup from storage preparation. Converge separates pinned binary staging and
installation, account lookup, captured footprint actions and non-rotating agent
configuration. Caddy and network children reuse their native family workflows.
The agent's existing transaction locks stay inside its commands; no new host
lock, transport or retry mechanism is added.

Rekey uses the unfinished, bound Node convergence intent. `agent.configure`
generates the secret on the Node, where it stays. Only its SHA-256 verifier is
public. A native result returns that verifier; the Gateway stores it at terminal
projection. No vault, worker enrollment, private-output endpoint or agent source
change is needed. Native resume owns uncertain command responses. The original
source Operation retains its independent outcome.

The native tests replace only SSH in disposable local repositories. A separate
fake host directory proves that a generated secret does not appear in Swamp
source, resources or run history. These are source checks, not live rollout or
worker-retirement receipts.
