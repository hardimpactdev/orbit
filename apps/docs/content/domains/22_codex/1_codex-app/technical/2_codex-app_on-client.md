# Client Context: `orbit codex:app`

[Back to `codex:app` technical contract.](1_codex-app.md)

`codex:app` is a gateway-mediated command. The CLI sends the selected action,
concrete instance selector, and target node to the gateway. For `add` and
`remove`, the gateway resolves the instance's Orbit serving node and source
path, authorizes that serving node plus the Codex App target node, and applies
the config file through an authenticated Agent-push command.

The CLI never writes the target node's Codex App config file directly.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI with `codex:app` on the selected instance serving node and target node | Forward the selected action to the gateway and render the gateway result. |
| Configured CLI without `codex:app` on either required node | Gateway rejects before reading or writing Codex App config. |
| Bare project or external-driver instance | Gateway rejects before it invents source placement, reads config, or dispatches to the target node. |
| Target node is inactive, hidden, gateway, unmanaged, or not macOS | Gateway rejects before Agent dispatch. |
| No configured gateway | CLI fails before prompts and side effects. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Codex/CodexAppCommandTest.php` | Client request routing, no-gateway rejection, and gateway pass-through failures. |
| `apps/gateway/tests/Feature/Http/Api/CodexAppControllerTest.php` | Authorization denial, hidden target denial, gateway target denial, unsupported platform denial, and no remote side effects before denial. |
