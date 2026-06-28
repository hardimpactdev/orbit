# Solo Extension Commands

The Solo command domain documents the Orbit CLI commands exposed by the optional Solo extension. Local extension state controls whether `orbit solo:*` commands appear and run on the node where the CLI is invoked. Gateway extension state controls whether `/api/solo/**` routes execute.

Solo traffic is proxied through Orbit gateway routes and targets a loopback URL on the gateway node. Orbit does not expose Solo ports directly to WireGuard.

## State Ownership

The Solo command domain does not own a state family. It hands off drift checks to existing doctor families:

- `doctor --family=node` verifies node and gateway extension state.
- `doctor --family=process` verifies Orbit-managed process state that may be inspected through Solo process views.
- `doctor --family=tool` verifies installed tool state, including configured gateway tools.