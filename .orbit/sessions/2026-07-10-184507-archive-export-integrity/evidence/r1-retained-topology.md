# R1 Retained Topology Proof

- Result: passed.
- Topology: `dev-b2825c`; kind `operator`; provider `incus`; host `beast`.
- Checkout role: `operator`.
- Inspected instance: `orbit-e2e-dev-b2825c-operator`.
- Runtime checkout: `/home/orbit/orbit-run`.
- Solo terminal: project 4, process `1019` (`archive-export-integrity-topology-proof`), attached interactively before the proof and retained at the VM prompt.
- Local commit under proof: `dc5d1f1f9fe21997aba61b6baec07ff844db2970`.
- Runtime source identity:
  - `bin/orbit-session-archive`: `8dc53ae8b6b4836a79afe1c5a1f4f2d527c99b01f40298d47620aee3296b601c`.
  - `bin/orbit-session-archive-filesystem.php`: `e663b9c18437a45cdedbce02fe8f333c24879b911163b9ded6f9890b9adc4aea`.
  - Both hashes match the local final commit. The VM runtime overlay intentionally has no Git metadata, so file hashes are the checkout identity proof.

## Commands And Results

- `php -l bin/orbit-session-archive`: passed.
- `php -l bin/orbit-session-archive-filesystem.php`: passed.
- A self-contained corpus was created under `/tmp/orbit-r1-topology-proof-1019` with:
  - active `.orbit/loop.md`;
  - evidence file;
  - one valid staged Codex `missing` capture manifest.
- First exact command:
  - `./bin/orbit-session-archive --source-orbit-dir="$proof/worktree/.orbit" --archive-root="$proof/archive-root" --timestamp=2026-07-10-180000 --slug=topology-proof --cwd="$proof/worktree"`
  - Exit: 0.
  - Mode: `created`.
  - Final: `/tmp/orbit-r1-topology-proof-1019/archive-root/2026-07-10-180000-topology-proof`.
  - Evidence file present; staged provider manifest copied byte-for-byte.
  - `./bin/orbit-session-index --sessions-dir="$proof/archive-root" --check`: passed.
  - Transaction residue: 0.
- Refresh exact command:
  - Same command with timestamp `2026-07-10-180001`.
  - Exit: 0.
  - Mode: `refreshed`.
  - Final remained the original `2026-07-10-180000-topology-proof`; no duplicate was minted.
  - Active and archived `loop.md` compare byte-for-byte.
  - Session index check passed.
  - Final session-directory count: 1.
  - Transaction residue: 0.

## Diagnostic Note

One extra diagnostic incorrectly looked for a top-level `agent-sessions/manifest.json` and exited 255. Under staged precedence that file is intentionally absent because the validated provider tree is preserved byte-for-byte; the copied provider manifest comparison and session-index check both passed. This was a discarded diagnostic assumption, not a product or verification failure.

No `composer test:e2e*` command ran.
