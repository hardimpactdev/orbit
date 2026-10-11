# Pi server

`apps/pi-server` is a Bun service that runs Pi agent sessions on a Node for the Gateway's `pi` driver. Read the [Pi server reference](../../docs/reference/pi-server.md) before you change it, and update that page in the same change.

- Keep every setting available as a flag. Orbit's systemd Processes pass only arguments.
- Read the token only from `--token-file` or the environment, never from a flag value, because arguments are visible to other users.
- Require the bearer token on every route. Return errors as `{ "error": { "code", "message", "details" } }` with a dotted code, as the Gateway does.
- Never allow a Claude model or `login anthropic` ([why](../../docs/reference/pi-server.md#claude-is-unavailable-for-task-agents)).
- Write large tool output to a file inside `.git`, and keep the workspace ACL on it ([why](../../docs/reference/pi-server.md#large-tool-output-goes-to-a-file)).
- Do not send provider credentials to the Gateway.

## Checks

From this directory, run:

```bash
bun install --frozen-lockfile
bun run check
bun run test
bun run build
```

Run the tests on Linux; [test worker file access](../../docs/reference/pi-server.md#test-worker-file-access) lists the tools they need. Root `composer check` runs these checks when a change touches this directory, and CI runs them on every pull request.
