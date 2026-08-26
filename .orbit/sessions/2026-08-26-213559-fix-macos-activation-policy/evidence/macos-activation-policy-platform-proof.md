# macOS activation-policy platform proof

- candidate: `a716d1eea10463ed4f73ae70e02f973fb9c13b14`
- venue: `host-macos`
- environment: `nick.local`
- host: `nick.local`
- os: `Darwin 27.0 (26A5421a)`
- linux-host: `Beast` at `192.168.6.20`

The exact candidate adds only `#[cfg(target_os = "macos")]` to the existing
Accessory activation-policy call in `apps/macos/src/main.rs`.

On the implementing Mac, these commands passed from `apps/macos`:

```text
cargo fmt -- --check
cargo check
cargo test
cargo clippy --all-targets -- -D warnings

test result: 43 passed; 0 failed
test result: 25 passed; 0 failed
```

On Beast/Linux, the same candidate passed:

```text
cargo check
cargo test
cargo clippy --all-targets -- -D warnings

test result: 43 passed; 0 failed
test result: 25 passed; 0 failed
```

Expected: macOS compiles and executes the existing Accessory activation-policy
call, while non-macOS targets exclude the platform-only Tauri API.

Observed: Darwin compiled the call and passed all focused checks. Linux compiled
the same source and passed all focused checks after the platform guard. The
repository quality gate also passed all 51 subgates on the exact candidate.

Result: passed.
