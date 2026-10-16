# Rust agent

`orbit-agent` is an outbound-only Linux service. Keep credentials, signatures, and the agent secret out of logs, never add listeners or command execution on anyone's behalf (the only program the agent runs is read-only `journalctl` with fixed arguments, see [Live logs](../../docs/reference/live-logs.md#the-agent-runs-no-program-that-changes-the-node)), and load trust only from `/etc/orbit/agent/ca.pem`. Read the agent secret only from `/etc/orbit/agent/secret` and send it only to the Gateway ([Node agent](../../docs/reference/node-agent.md#agent-secret)). Keep process-name filtering, event serialization, and snapshot sizing covered by unit tests.

## Checks

From this directory, run:

```sh
cargo fmt --all -- --check
cargo clippy --locked --all-targets -- -D warnings
cargo test --locked
cargo build --release --locked
```

Run `cargo test` as a normal user, not as root. The agent refuses to tail a log file that root owns, and one log stream test fails when root creates its fixture. CI additionally builds the `x86_64-unknown-linux-musl` and `aarch64-unknown-linux-musl` release targets. Commit `Cargo.lock` with dependency changes.
