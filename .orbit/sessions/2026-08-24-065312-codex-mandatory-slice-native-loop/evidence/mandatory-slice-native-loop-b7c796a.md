# Mandatory slice native loop retained-Incus proof

- Candidate: `b7c796a93685f55e094b8ec568e01d4c84f9ac99`
- Venue: `retained-incus`
- Environment: retained topology `dev-006cf2`
- Topology: `operator_gateway_app-dev`
- Source: `/home/nckrtl/orbit/.worktrees/codex-mandatory-slice-native-loop`
- Runtime checkout: `/home/orbit/orbit-run` on `orbit-e2e-dev-006cf2-operator`

## Source identity

The supported retained-topology sync refreshed the operator overlay from the
exact candidate worktree. Host and runtime checkout SHA-256 digests matched:

| Path | SHA-256 |
| --- | --- |
| `HARNESS.md` | `4dcfc4f5ea262210b88c6bdc35d8d5b3ccd0a241794bdd5e15a28ed1fb925e14` |
| `bin/orbit-prepare-worktree` | `663d7d6b4979d72d14163888b65c0bd8dc413fecc7e845fa665560d3d75cd3f9` |
| `bin/orbit-worker-spawn` | `c2270d7f599a433a63ae408cb612e79fe74f957083cc98d5d0dee8b644178bc9` |
| `bin/orbit-loop-contract.php` | `8114dd454d061d8473519a87c766e08d89e52f67457d8c3a448cb8450705204a` |
| `.agents/skills/implementing-features/SKILL.md` | `31dc5d8220ef5c823667dec84393b919e73f0b7b7e0b212a52e8abb1f32c02fa` |

## Retained checks

1. Ran `bin/orbit-prepare-worktree-test` in the refreshed operator checkout.
   It exited 0 and printed `orbit-prepare-worktree tests passed`.
2. Ran a fixture against the refreshed checkout's `bin/orbit-worker-spawn`.
   It supplied an invalid active slice graph and verified the filesystem after
   rejection. It exited 0 and printed
   `worker gate rejected invalid slice graph before registry/log/tmux mutation`.
3. Kept the user-attachable `proof-1` window in the feature tmux session. After
   sync it reported `runtime=/home/orbit/orbit-run`, launcher
   `/home/orbit/orbit-run/apps/cli/orbit`, and the same `HARNESS.md` digest.

## Result

Passed. The exact corrected candidate runs from the refreshed retained Incus
checkout, prepares the mandatory slice loop, carries the canonical FRAME
instruction, and fails closed before worker registry, log, or tmux mutation
when the active slice graph is invalid.

The local Incus daemon used a temporary localhost-only SSH shim because the
configured `beast` SSH alias rejected its local key. The shim refused non-local
targets. The official retained-topology start and sync commands created and
refreshed the fixture.
