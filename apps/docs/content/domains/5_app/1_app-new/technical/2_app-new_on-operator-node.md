# `app:new` From An Operator Node

[Back to technical contract](1_app-new.md)

This is the standard caller path for `app:new`. The CLI on an operator identity
gathers input, calls the gateway API, and renders the progress tree or JSON
response.

## Behavior

- **Input Resolution:** Gathers all arguments and options. Performs only the
  local input shape validation the command's input contract documents (slug
  regex, length) before calling the gateway. Authorization is the gateway's
  responsibility.
- **Gateway Call:** Executes an HTTPS POST request to the gateway's `app:new`
  endpoint. The gateway identifies the WireGuard peer and decides whether the
  request is allowed.
- **Apply:** The gateway writes app configuration locally and orchestrates all
  remote work to the target node over SSH via `RemoteShell`.
- **Progress:** The CLI consumes the gateway's progress stream and renders the
  human-facing tree or JSON envelope.

## Connectivity

- Requires WireGuard connectivity to the gateway.
- Requires trust of the gateway's root CA.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Apps/AppNewOnOperatorNodeContractTest.php` | Operator-caller behavior for `app:new`: input gathering, gateway HTTPS POST forwarding, gateway-driven SSH apply routing, progress-stream consumption for human and JSON renderers, missing-gateway failure shape, and absence of direct workload SSH. |
| `apps/gateway/tests/E2E/Ephemeral/AppNewOperatorForwardingTest.php` | Real-environment smoke coverage proving `app:new` invoked from an operator node forwards to the gateway over WireGuard and produces the expected JSON envelope without writing durable state locally. |
