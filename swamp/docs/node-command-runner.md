# Placed Node commands

Follow the binding [Swamp shape guide](swamp-shape.md). Run each operational
action as a placed `command/shell` step. Keep script text in `run:` or a file
delivered through Swamp's supported dispatch path, never in a TypeScript string.

Pass full non-secret intent as workflow inputs. Steps resolve vault key names
with `vault.get`; the command receives secrets through the native environment
mapping. A tool reads a secret from stdin or a config file, never argv. Stdin is
a tool interface, not a private-material transport from the Gateway.

Use native timeout, cancellation, guards and resume. Do not build another
subprocess supervisor or Gateway client. The pilot settles script-file delivery
and the exact vault-to-environment syntax before new conversions use them.

See [worker placement](node-workers.md) and
[app accounts](instance-sudo-steps.md).
