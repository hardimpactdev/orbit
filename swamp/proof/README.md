# Swamp proof

The flow is stages 2 and 3 of the [command development pipeline](../../docs/reference/command-development-pipeline.mdx); the stage charts own the steps. This page documents the code that runs them today.

## What runs today

Not built: the reset. The code that built the nested fleet in a task VM, took the memory snapshot `base` and restored it was removed. Its replacement is rebuilt as Swamp steps, and until it lands a `proof:run` has no reset behind it. The case runner and the cases below stay. To prove a command, follow [verifying-commands](../../.agents/skills/verifying-commands/SKILL.md).

The target nested fleet inside a task VM is a Gateway that also runs Swamp, `app-dev` and `app-prod`, on a real WireGuard network `10.45.0.0/24` with the hub on the nested Gateway. [`selection.txt`](selection.txt) maps changed paths to the commands whose cases a commit needs; [`orbit proof:run`](../../docs/cli/proof/run.mdx) reads it.

Cases are written in `swamp/proof/<family>/<command>.cases.yaml` and run by the shared runner in [`runner/`](runner/runner.py) ([format](../../docs/reference/verification-cases.mdx)). A case calls the real candidate CLI, checks the real effect and prints a verdict. The first file is [`firewall/allow.cases.yaml`](firewall/allow.cases.yaml): a real `firewall:allow` opens real traffic. `orbit proof:run` selects a command with [`selection.txt`](selection.txt) and runs its cases file; a command without one is uncovered.

A cases file may spread its cases over the three Nodes and run them several at a time with `runner batch`; see [Several cases at a time](../../docs/reference/verification-cases.mdx#several-cases-at-a-time).
