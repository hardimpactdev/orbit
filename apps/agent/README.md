# Orbit Node agent

`orbit-agent` is a Rust service that runs on every managed Linux Node. It reports presence, Process state, task checkout Git state, and live log tails to the Gateway over a Reverb WebSocket. The service only observes: it listens on no port and accepts no remote commands.

The [Node agent reference](../../docs/reference/node-agent.md) covers what it observes, how it connects, how the Gateway installs it, and how a tag releases it.

## Layout

- `src/main.rs`: discovery, the WebSocket connection, systemd and Docker watchers, and the event loop.
- `src/lib.rs`: configuration, the agent secret, frames, retry timing, and the shared types.
- `src/workspace.rs`: Git state of task checkouts.
- `src/logs`: live log streams.
- `tests/`: fixtures for log parsing and redaction.
- `scripts/node-agent-viewer.ts`: joins a Node's presence channel and prints what its agent sends.
- `scripts/build-delivery`: builds the delivery a Gateway pins: the static binaries, `SHA256SUMS`, and `manifest.json`.
- `scripts/check-release-tag.sh`: checks that an `agent-v<commit>` release publishes the tested delivery of that commit.

## Checks

The agent builds and runs only on Linux. From this directory, run:

```sh
cargo fmt --all -- --check
cargo clippy --locked --all-targets -- -D warnings
cargo test --locked
cargo build --release --locked
```

Run `cargo test` as a normal user, not as root. The agent refuses to tail a log file that root owns, and one log stream test fails when root creates its fixture. CI also builds static `x86_64-unknown-linux-musl` and `aarch64-unknown-linux-musl` binaries with `cross`. The agent reads its configuration from `/etc/orbit/agent`, so run it on a Node, not on your workstation.
