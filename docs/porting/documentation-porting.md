# Documentation Porting And Pipeline

Command and feature docs missing from this repo must be ported before
rebuilding the matching implementation. Converted command docs follow the
directory/split-file shape used by `docs/commands/1_node/1_node-new`. Each
public command lives in its own numbered command directory with at least a
public command page, canonical technical contract, and output renderer
contracts. Add input-mode, caller-role, and other companion technical files
whenever the command has prompts, non-interactive differences, destructive
consent, topology behavior, or other split ownership.

After structural conversion, run the command-designer semantic check for
each ported command and family doctor file, using
`.agents/skills/command-designer/references/semantic-check.md` plus the
authority docs (`BLUEPRINT.md`, `MISSION.md`, `CONCEPTS.md`,
`BUILDING-BLOCKS.md`, `commands/README.md`). Before considering a family
complete, also run a legacy feature-detail audit: search the old code and
tests for capabilities encoded in implementation support rather than legacy
prose, and document the supported product behavior in the new family
contracts.

## Family review

When a family's read commands (or a deliberate subset that proves the
implementation shape) are ported, add a `family-review` candidate here as a
single-line entry. The pipeline filler turns the entry into a normal worker
todo using
`docs/superpowers/plans/solo-orchestration/references/family-review-todo-template.md`.
The next family's implementation may not start until the review is merged
or explicitly deferred here with a reason.

## Pairing rule

Each command and feature port pairs an in-memory Pest test with an E2E test.
The Solo `E2E-*` todo is bookkeeping, not evidence — the family workstream
entry must cite the test file under `tests/E2E/` and the exact
`composer test:e2e[…]` command that passed. `composer quality-check`
excludes E2E and is not enough.

The lane is chosen per the rubric in
[`testing-infrastructure.md`](testing-infrastructure.md): Docker feature
(default), Incus VM-feature (real systemd/kernel networking), Incus provision
(installer, WireGuard, SSH trust, destructive host mutation), or
`lane=none` with a recorded reason.

## Feature E2E checkout rule

Command-port `e2e-feature` gates must test the branch or worktree that
contains the port. Prepared topology images are reusable baselines, not
feature-code delivery vehicles. The E2E gate acquires the smallest prepared
topology that covers the command, overlays the current checkout into the
disposable clone, and runs `php artisan <command>` from that checkout. Do
not rebuild images, mutate templates, or repoint the steady-state `orbit`
symlink to expose a command under development.

If an E2E lane cannot test the current checkout this way, the gate is not
ready: create an E2E harness todo first, or mark the gate blocked with the
missing checkout-overlay support.

## Sequencing rules

- Do not start new implementation while an active final-review or push
  recovery todo is open.
- Count only open, unblocked, unlocked `worker-ready` todos as dispatchable
  worker capacity. Blocked `worker-ready` todos are planned inventory.
- Count `e2e-ready` todos separately from `worker-ready`; both consume
  pipeline capacity but dispatch through different orchestrator paths.
