# Orbit Pi server

The Pi server runs [Pi](https://github.com/earendil-works/pi) coding-agent sessions on a task VM through Pi Durable 1.4.0. The Gateway's `pi` task driver creates sessions, starts turns, and reads transcripts through its HTTP API. One server runs per task VM, as a systemd Process, and every request needs its bearer token.

The [Pi server reference](../../docs/reference/pi-server.md) covers its flags, sign-in, installation on a task VM, API, and limits.

## Run it locally

```bash
bun install
PI_SERVER_TOKEN=$(openssl rand -hex 32) bun dev/serve-faux.ts /path/to/workspaces 3790
```

`dev/serve-faux.ts` serves the real API with a scripted model, so it needs no provider sign-in. Each turn runs one `bash` command in the session's workspace and then answers. The server listens on `127.0.0.1` at the given port, `3790` by default, and accepts workspaces only under the given path.

`bun run start -- --host=127.0.0.1 --token-file=PATH` starts the real server. It needs a provider sign-in, which `bun run start -- login openai-codex` creates in `~/.pi/agent`.

## Layout

- `src/main.ts`: the entry point. `login` signs in to a provider, and anything else starts the server.
- `src/config.ts`: flags and environment variables.
- `src/http.ts`: routes, bearer-token checks, the error envelope, and the event stream.
- `src/registry.ts`, `src/state.ts`, `src/store.ts`: one Harness and SQLite database per session, thread states, and bootstrap records.
- `src/workspace-tools.ts`, `src/tool-output.ts`, `src/workspace-tmpdir.ts`: the agent tools, large tool output files, and the per-workspace `TMPDIR`.
- `src/search-docs.ts`: the `search_docs` tool, which asks a Laravel app's Boost server.
- `tests`: Vitest tests. `tests/support.ts` holds the shared fixtures.

The engine resumes unfinished turns after restart and deduplicates sends by Durable `requestId`. It owns provider retries (1, 2, 4, 8 minutes; four retries) and reports crash-loop parking as failure. There is no JSONL import or coding-agent JavaScript extension loading.

`src/system-prompt.ts` is pinned to pi-coding-agent 1.4.0, `dist/core/system-prompt.js` (MIT notice in `LICENSE.pi`). The builder has no public export; fixed-input parity tests compare it with the installed upstream implementation.

## Checks

```bash
bun run check
bun run test
bun run build
```

Run the tests on Linux. [Test worker file access](../../docs/reference/pi-server.md#test-worker-file-access) lists the tools they need.

`bun run build` writes `dist/pi-server` for this machine. `bun run build:linux` writes the `x86_64` Linux binary that Nodes run.
