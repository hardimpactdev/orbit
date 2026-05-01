# `app:new` on Control Node

[Back to technical contract](1_app-new.md)

This is the standard caller path for `app:new`. The control node gathers input,
calls the gateway API, and renders the progress tree or JSON response.

## Behavior

- **Input Resolution:** Gathers all arguments and options. Performs local
  validation (slug regex, length) before calling the gateway.
- **Gateway Call:** Executes an HTTPS POST request to the gateway's `app:new`
  endpoint.
- **Enactment:** The gateway orchestrates all remote work (SSH to app node)
  and local work (SQLite write).
- **Progress:** The control node consumes the gateway's progress stream and
  renders the human-facing tree or JSON envelope.

## Connectivity

- Requires WireGuard connectivity to the gateway.
- Requires trust of the gateway's root CA.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Apps/AppNewOnControlNodeContractTest.php` | Control-caller behavior for `app:new`: input gathering, gateway HTTPS POST forwarding, gateway-driven SSH enactment routing, progress-stream consumption for human and JSON renderers, missing-gateway failure shape, and absence of direct app-node SSH from the control caller. |
| `tests/E2E/Ephemeral/AppNewControlForwardingTest.php` | Real-environment smoke coverage proving `app:new` invoked from a control node forwards to the gateway over WireGuard and produces the expected JSON envelope without writing durable state locally. |
