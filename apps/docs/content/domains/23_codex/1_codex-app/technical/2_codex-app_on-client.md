# Client Context: `orbit codex:app`

[Back to `codex:app` technical contract.](1_codex-app.md)

`codex:app` is a gateway-mediated command. The CLI sends the selected action,
app selector, and target node to the gateway. The gateway authorizes the caller
and applies the config file on the target node over SSH.

The CLI never writes the target node's Codex App config file directly.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI with `codex:app` on the app node and target node | Forward the selected action to the gateway and render the gateway result. |
| Configured CLI without `codex:app` on either required node | Gateway rejects before reading or writing Codex App config. |
| Target node is inactive, hidden, gateway, or not macOS | Gateway rejects before remote shell work. |
| No configured gateway | CLI fails before prompts and side effects. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Codex/CodexAppCommandTest.php` | Client request routing, no-gateway rejection, and gateway pass-through failures. |
| `apps/gateway/tests/Feature/Http/Api/AppCodexControllerTest.php` | Authorization denial, hidden target denial, gateway target denial, unsupported platform denial, and no remote side effects before denial. |
