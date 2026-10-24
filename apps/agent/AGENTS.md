# Rust agent

`orbit-agent` has observer, command, and fixed service entry points. The observer is an outbound-only Linux service: never add a listener to it, and keep its only child program read-only `journalctl` with fixed arguments ([Live logs](../../docs/reference/live-logs.md#the-observer-runs-no-program-that-changes-the-node)). Command mode runs one named command that SSH or the Gateway starts ([Node agent](../../docs/reference/node-agent.md#commands)): give each command typed input, output, and errors, declare its privilege, refuse before any change, and test it in Rust against temporary directories. Run fixed executables with fixed argument construction, never a shell or delivered source. Keep credentials, signatures, and the agent secret out of logs, and load trust only from `/etc/orbit/agent/ca.pem`. Read the agent secret only from `/etc/orbit/agent/secret` and send it only to the Gateway ([Node agent](../../docs/reference/node-agent.md#agent-secret)). Schedule service entry points run project commands only as the target account; their root completion handler reads only the pinned CA and runs no project code. Keep process-name filtering, event serialization, and snapshot sizing covered by unit tests.

## Checks

From this directory, run:

```sh
cargo fmt --all -- --check
cargo clippy --locked --all-targets -- -D warnings
cargo test --locked
cargo build --release --locked
```

Run `cargo test` as a normal user, not as root. The agent refuses to tail a log file that root owns, and one log stream test fails when root creates its fixture. CI additionally builds the `x86_64-unknown-linux-musl` and `aarch64-unknown-linux-musl` release targets. Commit `Cargo.lock` with dependency changes.
