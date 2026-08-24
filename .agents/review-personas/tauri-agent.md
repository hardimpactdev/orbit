# Tauri Agent Reviewer

## REQUIRED PROOF

Before reading anything else, run:

```bash
cd <assigned worktree> && pwd && git branch --show-current && git status --short --branch
```

Then print a single `CHECKOUT_PROOF: <pwd> | <branch> | <status summary>` line
before any other output. A report without a `CHECKOUT_PROOF:` line is invalid.

End the report with exactly one machine-parseable final line:

```text
VERDICT: <pass|findings|blocked>
```

- `pass`: no finding blocks acceptance of the reviewed change.
- `findings`: at least one finding must be resolved before acceptance.
- `blocked`: required evidence or context was missing; the review could not
  complete.

Use this reviewer for Orbit Agent macOS UI changes under `apps/macos`,
especially Tauri/Rust tray behavior, native menu layout, and service-status UI.
Also use it for coordinated `apps/agent` service changes when the headless
service contract affects the macOS UI, gateway ping, polling, or typed job
execution.

This is a reviewer persona, not an implementation workflow. It does not replace
`.agents/skills/implementing-features/SKILL.md`,
`.agents/skills/tauri-agent-development/SKILL.md`, focused Cargo checks, or
native UI verification.

## Checklist Helper (non-active)

The one Claude general reviewer may consult this checklist. Never spawn this persona or create a standing lane. The reviewer inspects, captures
evidence, and reports blockers; it does not implement fixes or approve merge.
If the selected reviewer has no provider-session archive support, preserve the
reviewer report itself as the evidence artifact.

## Required Context

Read only the files needed for the Orbit Agent surface under review:

- `AGENTS.md`
- `HARNESS.md`
- `HARNESS_SIGNALS.md`
- `harness-signals/README.md`
- `.agents/skills/tauri-agent-development/SKILL.md`
- `apps/docs/content/generated/monorepo-unit-map.json`
- `apps/docs/content/architecture.md`
- `apps/docs/content/tech-stack.md`
- `apps/docs/content/domains/1_node/node-concepts.md`
- `apps/agent/Cargo.toml`
- `apps/macos/Cargo.toml`
- `apps/macos/tauri.conf.json`
- The changed Rust source and focused Rust tests under `apps/agent/src` or
  `apps/macos/src`
- The raw user-provided screenshots, transcripts, failure text, negative
  examples, and explicit deferrals recorded in `.orbit/loop.md`, the feature
  scratchpad, or the implementation report

When Tauri API details are part of the risk, check current Tauri v2 docs before
judging the implementation. Tauri v2 tray/menu code should match the current
`TrayIconBuilder`, menu builder, menu event, and tray icon event contracts for
the APIs it uses.

## Review Stance

Lead with correctness and native-behavior risks. Treat Cargo checks as evidence
that Rust compiled and tests passed, not proof that macOS tray rendering is
usable. A Tauri Agent change is not ready when Rust behavior, product docs,
quality-gate wiring, host-Mac topology proof, and native macOS behavior
disagree.

## Checklist

### Contract And Scope

- The change keeps headless service code in `apps/agent`, native macOS UI code
  in `apps/macos`, or explicitly owned harness/docs/gate files.
- Product docs, generated unit map source, tests, and implementation agree on
  whether focused Cargo checks are run directly and broad handoff uses
  `composer quality-check`.
- The implementation does not add installer signing/notarization, self-update,
  arbitrary privileged shell execution, approval UI, or SSH/RemoteShell
  replacement behavior unless the active contract explicitly owns it.
- Raw screenshots, transcripts, and negative examples are represented in the
  Done Contract, tests, native verification, or explicit deferrals.
- Non-Markdown `apps/macos` diffs use the implementing Mac as live topology.
  The final packet records `Retained topology proof: passed - host topology
  kind=host-macos; host=...; os=...; command=...; evidence=...`. Retained Incus
  is not accepted as proof for native Tauri/macOS behavior.

### Native Tray And Menu Behavior

- Tray menu rows remain compact and do not introduce unnecessary dropdown
  width.
- Status labels and IP values align predictably; IP values should be visually
  right-aligned when that is the requested menu layout.
- Refresh, Restart, and Quit remain reachable and keep their expected menu
  behavior.
- Menu rendering is verified with Computer Use when visual alignment, click
  behavior, tray dropdown width, or native macOS behavior is part of the
  acceptance surface.
- Evidence comes from the current implementation. Screenshots or native checks
  captured before the latest correction are stale.

### Rust And Tauri

- `apps/macos` Rust code remains typed and small; avoid stringly native-menu
  state when local structs or helpers already express the contract.
- `apps/agent` owns the headless service loop; `apps/macos` may query service
  status but must not own long-running polling or job execution.
- Tauri v2 tray/menu APIs are used consistently for menu items, tray events,
  and menu event handling.
- Local config, gateway ping, job polling, and typed job execution failures are
  handled without silent success states.
- Headless worker behavior stays separate from menu-bar-only behavior.

### Tests And Verification

- Focused `apps/agent` and `apps/macos` tests cover behavior that can be tested
  without a native macOS tray.
- Required Cargo evidence is present:

```bash
cd apps/agent && cargo test
cd apps/agent && cargo fmt -- --check
cd apps/agent && cargo check
cd apps/agent && cargo clippy --all-targets -- -D warnings
cd apps/macos && cargo test
cd apps/macos && cargo fmt -- --check
cd apps/macos && cargo check
cd apps/macos && cargo clippy --all-targets -- -D warnings
```

- Broad handoff evidence includes `composer quality-check` when the diff is not
  docs-only.
- Native `apps/macos` diffs include host-Mac topology evidence captured on the
  Darwin implementation host. If the work ran on non-Darwin, the review is
  blocked until the slice is moved to a Mac host or a Mac-host proof is supplied
  from the implementation machine.
- If native tray rendering changed, Computer Use evidence is present or the
  report names the blocker and why acceptance can proceed without it.
