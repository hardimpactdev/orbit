# `project:new` From An Operator Node

[Back to technical contract](1_project-new.md)

This is the standard caller path for `project:new`. The CLI on an operator identity
gathers input, calls the gateway API, and renders the progress tree or JSON
response.

## Behavior

- **Input Resolution:** Gathers all arguments and options. Validates the slug,
  source-branch shape, and repository-reference format before calling the
  gateway. Authorization is the gateway's responsibility.
- **Gateway Call:** Executes an HTTPS POST request to the gateway's `project:new`
  endpoint. The gateway identifies the WireGuard peer and decides whether the
  request is allowed.
- **Apply:** After the CLI resolves the complete source plan, the gateway
  atomically writes the project and its named first instance locally and
  orchestrates all remote work to that instance's serving node through
  authenticated Agent push over WireGuard.
- **Progress:** The CLI consumes the gateway's progress stream and renders the
  human-facing tree or JSON envelope.

## Connectivity

- Requires WireGuard connectivity to the gateway.
- Requires trust of the gateway's root CA.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | Operator CLI posts to gateway stream endpoint without local workload SSH. |
| `apps/cli/tests/Feature/Commands/App/AppNewStreamCommandTest.php` | Stream request shape and gateway stream consumption for operator-caller paths. |
| `packages/core/tests/SourceControl/GitCloneReferenceTest.php` | Clone-reference safety shared by local and gateway validation. |

There is no gateway-side coverage for this on-client mapping: operator-caller behavior lives in `apps/cli`. Gateway API behavior is mapped in the command contract file.
