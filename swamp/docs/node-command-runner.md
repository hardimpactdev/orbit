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

Node input contracts and local actions import public schemas and the
cancellation type from `operations/_lib/direct-input.ts`. These imports keep
input validation independent of the Gateway execution client.

The Node footprint parent resolves protected agent configuration data before
calling the shared configuration lower. The lower takes that data directly and
returns only the public change or refusal result.

The role parent also resolves runtime data inside its existing private-data
boundary. The shared WebSocket installer receives that data directly and
returns a public completion or refusal result.

See [worker placement](node-workers.md) and
[app accounts](instance-sudo-steps.md).
