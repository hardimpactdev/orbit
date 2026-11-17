# Rust review: `orbit-agent` (apps/agent), slice B1 of ADR 0267

Working material for ADR 0267 P9, slice B1. It is not documentation: the later B1 fix slices consume it and delete it when the
observer joins `[lints] workspace = true`.

`orbit-agent` is the observer: the outbound-only service that reports facts, Process states and logs to the Gateway, plus
the frozen agent commands that SSH or the Gateway start. It is an application with a library target (`src/lib.rs`) that only
its own binary and tests use, so this review applies the application rules: no API-evolution findings, and `missing_docs`,
`missing_errors_doc`, `exhaustive_enums` and `print_stderr` are noted only as lint noise. Edition 2021, no `rust-version` at review time. A later commit set 1.81, which made clippy report 25 `incompatible_msrv` errors; the B1 fix slice raised it to 1.89, the lowest version that clears them.

The code is in good shape for its size (69k lines with tests). Plain clippy (`-D warnings`, all targets) is clean, every
test passes, every `unsafe` block has a reason nearby, and almost every `unwrap()` outside tests sits on a value that the
line before has proved. The one thing to fix now: five `unsafe` blocks share a `// SAFETY:` comment with a neighbour
instead of carrying their own. Fixed in the commit that follows this report.

Rules come from BeautifulRust (`skills/rust-review`, `skills/rust-best-practices`, `lints/lints.toml`,
https://github.com/morganlinton/BeautifulRust), which cite the Rust API Guidelines, Microsoft's Rust guidelines and
clippy.

## How the counts were taken

Toolchain: Rust 1.100.0 stable. Counts outside tests come from clippy on the non-test targets only
(`cargo clippy -p orbit-agent -p orbit-agent-contract --lib --bins`), so every `#[cfg(test)]` module, `*_tests.rs` file
and `tests/` file is excluded by the compiler, not by a grep. Locations are deduplicated by their full span, so two calls in one chain count twice.

| Measure (outside tests) | Count | Notes |
| --- | ---: | --- |
| `unwrap()` | **72** | `grep -rn 'unwrap()' src crates` finds 2,744; the other 2,672 are in tests. 0 after `5bcbe7d35` (Should fix 2) |
| `expect(..)` | 29 | every one carries a reason |
| `panic!` | 0 | |
| `unreachable!` | 2 | one without a message |
| `todo!` / `unimplemented!` | 0 | |
| `unsafe` block without its own `// SAFETY:` | 5 | 15 with test code (`recipe/test_process.rs` and test modules) |
| `unsafe` block with more than one unsafe operation | 6 | |
| `#[allow]` without a reason | 5 | there are 5 `#[allow]`s in total, all without a reason |
| `missing_panics_doc` | 8 | |
| Slice or index expressions (`indexing_slicing`) | 226 | not a BeautifulRust lint; reviewed by hand for reachable panics |

With BeautifulRust's `lints.toml` and `clippy.toml` applied to `orbit-agent` (all targets), clippy reports **2,019
warnings** in 70 lints. The largest: `unreachable_pub` 666, `missing_docs` 327, `assert_is_empty` 119 (tests),
`as_conversions` 110, `fn_params_excessive_bools` 76, `unwrap_used` 72, `missing_errors_doc` 52, `panic_in_result_fn`
41, `print_stderr` 37, `clone_on_ref_ptr` 35. So step 3 of the group brief (lints on) waits for the later fix slices.

## Tier counts

| Tier | Findings |
| --- | ---: |
| Must fix | 1 |
| Should fix | 9 (2 and 3 done) |
| Consider | 7 (17 done) |

## Must fix

### 1. Five `unsafe` blocks without their own `// SAFETY:` comment (`src/commands/node_identity.rs:7`, `:10`, `:13`; `src/commands/recipe/interruption.rs:33`, `:34`)

**Rule:** every `unsafe` block gets a `// SAFETY:` comment stating why its invariants hold (rust-best-practices §8;
clippy `undocumented_unsafe_blocks`; rust-review "Correctness and soundness"). ADR 0267 P9: "Each block holds one
operation and a `// SAFETY:` comment."

**Problem:** one comment above `zeroed()` covers `uname` and both `CStr::from_ptr` reads, and one comment covers two
`zeroed()` calls and a block that runs `sigemptyset` and `sigaction`. The code is sound, but a reader cannot check each
block against a stated reason, and the `interruption.rs:34` block holds two operations.

**Fix:** one comment per block, and split the two-operation block:

```rust
// SAFETY: sigaction is plain C data; all-zero bytes are a valid value.
let mut old: libc::sigaction = unsafe { std::mem::zeroed() };
// SAFETY: sa_mask is a live, writable sigset_t inside `action`.
unsafe { libc::sigemptyset(&mut action.sa_mask) };
// SAFETY: both sigactions are initialized, and the handler only writes a lock-free atomic.
if unsafe { libc::sigaction(signal, &action, &mut old) } != 0 {
```

No reachable panic qualified for this tier. Each `unwrap()` and `expect()` outside tests was traced to its input. Each one
is a regex literal, an earlier `is_some`/`contains_key`/`insert`, a path that the command built or validated itself
(`caddy_certificate::scope`, `schedules::valid_id`), a JSON string (always UTF-8), or a fixed table. The ones that are not
obviously safe from the line alone are listed under Should fix 2.

## Should fix

### 2. 72 `unwrap()` calls outside tests (whole crate; full list via `clippy::unwrap_used` on `--lib --bins`)

**Done in `5bcbe7d35`.** No `unwrap()` is left outside tests, and `src/lib.rs`, `src/main.rs` and
`crates/contract/src/lib.rs` deny `clippy::unwrap_used` outside tests, so a new one fails clippy. Regex literals are
`LazyLock` statics. The check-then-unwrap sites became pattern matches. The rest `expect()` their invariant. Two
became error returns because the line cannot prove the value: the Caddy download path under `TMPDIR`, which may not be
UTF-8 (`src/commands/caddy_package.rs`), and the agent target's file name, next to the existing missing-parent error
(`src/commands/agent.rs`).

**Rule:** no `unwrap()` outside tests; `expect("why this cannot fail")` instead (rust-best-practices §5; clippy
`unwrap_used`; ADR 0267 P9 "Errors").

**Problem:** none is reachable from outside input, but the proof is in the caller, not at the call. Groups:

- 35 on regex literals, e.g. `src/commands/peer_dns.rs:23-32`, `src/commands/recipe/types.rs:67-98`,
  `src/commands/php_build.rs:212-213`, `src/commands/node_bootstrap.rs:74-75,182`. Several compile the regex on every call
  (`src/commands/incus/vm.rs:174`, `src/commands/package_cache.rs:59`).
- 17 on `parent()`, `file_name()`, `file_stem()` or `strip_prefix()` of a path the command built: `src/commands/php_fpm.rs:32,41` (two),
  `src/commands/php_build/runtime.rs:16,86,118,206`, `src/commands/recipe/artifacts.rs:215,289`,
  `src/commands/recipe/swamp.rs:34`, `src/commands/gateway_start_hooks.rs:81`, `src/commands/package_cache.rs:295`,
  `src/commands/agent.rs:355`, `src/commands/php_build/packages.rs:398`, `src/commands/recipe/javascript.rs:181`,
  `src/commands/caddy_certificate.rs:98,241`.
- 7 on `to_str()` of a validated path or name: `src/commands/schedules.rs:68-70`, `src/commands/caddy_package.rs:179,238`,
  `src/commands/task_topology.rs:427`, `src/commands/caddy_certificate.rs:241`.
- 5 on `serde_json::to_*` of owned data: `src/commands/package_cache.rs:314`, `src/commands/recipe/store.rs:377`,
  `src/commands/recipe/javascript.rs:324`, `src/commands/guest.rs:50`, `src/commands/incus/vm.rs:250`.
- 8 after an earlier check or on a fixed structure: `src/command/run.rs:139` (check-then-unwrap), `src/commands/metrics_publication.rs:239`,
  `src/commands/schedules.rs:456`, `src/metrics.rs:99,102,137`, `src/commands/package_cache.rs:138,158`.

**Fix:** regex literals become `static NAME: LazyLock<Regex> = LazyLock::new(|| Regex::new(..).expect("literal pattern"))`;
the check-then-unwrap becomes a pattern match; the rest become `expect("<the invariant>")` or `let .. else`:

```rust
Some(Value::Number(number)) => match number.as_u64() { Some(value) => value, None => return invalid() },
```

### 3. Poisoned locks are recovered with `into_inner` (`src/workspace.rs:540`, `src/logs/controller.rs:63`)

**Done in `5bcbe7d35` and "Observer B1: a panicked lock holder ends the observer".** Decision: lock poisoning is
fatal, and systemd restarts the observer. The workspace lock, the log registry lock and the stream queue and token bucket
locks (`src/logs/limits.rs`, which the review missed) `expect()` with the reason the lock is never held across a panic.

`expect()` alone was not fatal: Tokio catches a panic in a spawned task, so the first commit ended only the task.
`run` now keeps the tasks that hold these locks (`watch_workspaces`, `run_controller`) in a `JoinSet`. It races
`orbit_agent::supervise` against the session, so a panic in one ends the run with an error, and the process exits 1. The
log controller re-raises a panic of a stream reader it started. A reader that ends normally stays ended, as before. The
binary keeps `panic = "unwind"`. `abort` would also end the process on a panic in tasks that need not be fatal, such as
the blocking reads whose panics the observer already reports.

Lock order: the registry, then a queue, then the agent bucket. `would_accept` takes the queue and bucket locks one after
the other. `push` nests them to check and take both buckets in one step. Tests prove that a panic ends the run (from a
poisoned workspace lock, and from a stream reader). They also prove that each of the four locks is fatal when poisoned,
and they cover both new error returns.

**Rule:** lock poisoning is fatal; don't recover with `into_inner` (rust-best-practices §5, §7; ADR 0267 P9
"Concurrency").

**Problem:** the recovery is deliberate and documented (`lock_workspaces` says so, and the log controller relies on it so
that a panicked read does not stop the observer). Changing it changes behaviour, so it is out of the must-fix tier, which
must not. It needs a decision in the B1 fix slice: either `expect("workspace lock poisoned")` and let systemd restart the
observer, or a recorded `#[expect]`-style exception for the observer.

**Fix (if the decision is "fatal"):** `.lock().expect("the workspace lock is never held across a panic")`.

### 4. `#[allow]` without a reason (`src/main.rs:279`, `src/egress.rs:705`, `src/command/supervise.rs:64`, `src/logs/laravel.rs:38`, `src/commands/dev_deploy.rs:75`)

**Rule:** suppressions explain themselves and expire: `#[expect(lint, reason = "...")]` (clippy `allow_attributes`,
`allow_attributes_without_reason`; ADR 0267 P9 "Lints"). Needs `rust-version = "1.81"` or newer.

**Fix:** `#[expect(clippy::too_many_arguments, reason = "...")]`; delete `dead_code` at `dev_deploy.rs:75` if the field is
unused, and `laravel.rs:38` if the casts are no longer platform-dependent.

### 5. `unsafe` blocks with several operations (`src/command/identity.rs:29`, `src/commands/deploy_step.rs:118`, `src/commands/node_identity.rs:28`, `src/commands/php_build.rs:312`, `src/workspace.rs:262`)

**Rule:** one unsafe operation per block (clippy `multiple_unsafe_ops_per_block`; ADR 0267 P9 "Unsafe").

**Problem:** each block has a `// SAFETY:` comment, but it covers a loop or several FFI calls, so it states one reason for
several obligations. `node_identity::addresses` walks `getifaddrs` in one block, and `rustix` does not offer that call.
`identity.rs`, `deploy_step.rs`, `php_build.rs` and `workspace.rs` should be checked against `rustix` first.

**Fix:** split each block, or replace it with the `rustix` call where one exists.

### 6. `as` casts (91 outside tests; 12 `cast_possible_truncation`)

**Rule:** prefer `From` / `TryFrom` (clippy `as_conversions`, `cast_possible_truncation`, `cast_sign_loss`).

**Problem:** none of the truncating casts reviewed can wrap on outside input: they convert constants
(`src/egress.rs:69,73`), a timeout's milliseconds (`src/commands/agent.rs:321`), an uptime float
(`src/metrics.rs:306`), or a bounded length (`src/logs/mod.rs:137`). `src/logs/laravel.rs:352` sizes a buffer from file
offsets and was not traced to a bound. The casts still hide the reasoning.

**Fix:** `u64::try_from(left.as_millis()).unwrap_or(u64::MAX)`, `usize::from(..)`, and `u32::try_from(..).expect(..)`
for the constants.

### 7. `bool` parameters (59 outside tests, e.g. `src/command/effects.rs:116,189`, `src/commands/caddy_access.rs:37,341`, `src/commands/ci_runner.rs:281`, `src/commands/dev_deploy.rs:33`)

**Rule:** no `bool` parameters in shared signatures; use a two-variant enum (rust-best-practices §4; clippy
`fn_params_excessive_bools` with `max-fn-params-bools = 0`).

**Fix:** per call family, e.g. `enum Replace { Yes, No }` for the publish helpers' last argument
(`files.publish(.., true)` in `src/commands/recipe/javascript.rs`).

### 8. Cheap `Arc` clones written as `.clone()` (32 outside tests, mostly `src/egress.rs:562-832`, `src/facts.rs`)

**Rule:** write `Arc::clone(&x)` so cheap clones are visible (clippy `clone_on_ref_ptr`).

**Fix:** `Arc::clone(&shared)`.

### 9. Panics inside functions that return `Result` (7 outside tests: `src/command/run.rs:372`, `src/commands/metrics_publication.rs:324`, `src/commands/recipe/swamp.rs:58,79,150,165`)

**Rule:** a function that returns `Result` shouldn't also panic (clippy `panic_in_result_fn`). These are `assert!`s on
invariants in test-support hosts; check each one and move it to the test module or return the error.

### 10. Failure docs on public functions (52 `missing_errors_doc`, 8 `missing_panics_doc`)

**Rule:** document `# Errors` and `# Panics` (rust-best-practices §5; ADR 0267 P9 "Errors"). Lower priority in an
application crate, but the ADR asks for it, and it is what blocks `[lints] workspace = true`.

## Consider

### 11. `unreachable!()` without a message (`src/commands/recipe/javascript.rs:539`)

The match is over a fixed array, so it is truly unreachable. Iterating an enum would remove the arm;
otherwise `unreachable!("tool names come from the fixed list above")`.

### 12. 666 `unreachable_pub` items

`pub` items that the crate does not export from `lib.rs`. `pub(crate)` states the intent. Mechanical; do it in one
commit with `cargo clippy --fix`.

### 13. 327 `missing_docs`

The library target is internal. BeautifulRust marks this lint `[lib]`, so an application may drop it. Decide at
lint-on time whether the observer keeps it.

### 14. `print_stderr` (37) and `wildcard_imports` (9)

`eprintln!` is the command protocol's diagnostic channel (`src/command/mod.rs:103,113`), so keep it with a reason. The
glob imports are `use super::*` in sibling modules (`src/commands/firewall/route.rs:2`, `src/commands/instances.rs:3`);
name the items instead.

### 15. `String` built with `+` (`src/command/registry.rs:851,867`, `src/commands/instances.rs:68`, `src/workspace.rs:777`)

Clippy `string_add`; use `format!` or `push_str`. `src/workspace.rs:777` also has a `redundant_clone`.

### 16. Redundant clones (`src/command/lock.rs:302`, `src/commands/production_home.rs:148`, `src/commands/storage_root.rs:163`, `src/logs/controller.rs:356`)

Clippy `redundant_clone`; drop the clone.

### 17. Regexes compiled per call

**Done in `5bcbe7d35`** with Should fix 2.

See Should fix 2. Moving the regexes into `LazyLock` statics fixes both the `unwrap()` and the repeated compile.

## What's already good

- Plain clippy with `-D warnings` is clean across all targets, and `cargo fmt` is clean.
- Typed command input, output and error enums per command, with one variant per refusal reason
  (`src/command/registry.rs`), already match P9's error rule.
- Every `unsafe` block has a reason nearby, and FFI is kept to `uname`, `getifaddrs`, `sigaction` and a few process
  calls.
- Panics are rare outside tests (0 `panic!`, 29 `expect` all with a reason), and the agent secret is checked once, on
  load, before the `expect` in `src/lib.rs:123` relies on it.

## Lints

The crate has no `[lints]` table. BeautifulRust's `lints/lints.toml` and `lints/clippy.toml` would catch Must fix 1
(`undocumented_unsafe_blocks`), Should fix 2 (`unwrap_used`) and Should fix 4 (`allow_attributes_without_reason`)
automatically. ADR 0267 P9 turns them on for the observer only when clippy is clean under them. That is not true yet
(2,019 warnings), so this slice leaves `Cargo.toml` alone. The executor slice owns the workspace `[workspace.lints]`
table.
