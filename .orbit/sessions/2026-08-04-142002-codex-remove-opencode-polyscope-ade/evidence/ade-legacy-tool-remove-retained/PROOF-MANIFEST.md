# Proof manifest — OpenCode / PolyScope `tool:remove` retained-Incus

Session-local only (gitignored under `.orbit/`). Not archived. Candidate code
HEAD is unchanged; no force-add of ignored evidence; no premature session archive.

## Candidate identity

| Field | Value |
| --- | --- |
| Candidate code HEAD | `03811c9180f8023616ab0013f779b7afa9223de0` |
| Branch | `codex/remove-opencode-polyscope-ade` |
| Worktree | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade` |
| Tracked dirty files at proof time | none |
| New tracked-source commit for evidence | **none** (evidence is intentionally untracked) |

## Deterministic broader gate (already green; not re-run)

No tracked code changed after the accepted quality-check, so quality-check was
**not** re-run for this evidence-only pass.

| Field | Value |
| --- | --- |
| Artifact | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/quality-gates/quality-check-2026-08-04T120234Z-583348d01512.json` |
| Gate | `quality-check` (`composer quality-check`) |
| Exit | `0` |
| Bound commit | `03811c9180f8023616ab0013f779b7afa9223de0` |
| Dirty | `false` |
| Window | `2026-08-04T12:01:32Z` → `2026-08-04T12:02:34Z` (62s) |

## Retained topology (kept alive for independent review)

| Field | Value |
| --- | --- |
| Topology id | `dev-779aaa` |
| Kind | `operator_gateway_app-dev` |
| Provider | `incus` |
| Host | `beast` |
| Inspected role / node | role `dev` / node `app-dev-1` (WireGuard `10.6.0.4`) |
| Instances | `orbit-e2e-dev-779aaa-operator`, `orbit-e2e-dev-779aaa-gateway`, `orbit-e2e-dev-779aaa-dev` |
| Checkouts | `/home/orbit/orbit-run` (operator, gateway, dev) |
| Source path on host | `/tmp/orbit-e2e-sources/codex-remove-opencode-polyscope-ade-incus-44565d6bb030/retained/dev-779aaa` |
| Acquire command | `composer e2e:incus -- --start --topology=operator_gateway_app-dev --checkout-roles=operator,gateway,app-dev --json` |
| Release (when review done) | `composer e2e:incus -- --stop --id=dev-779aaa` |
| Status at manifest write | all three instances **RUNNING** |

**Do not run** `composer test:e2e*`. Lane used: documented retained Incus helper only.

## Exact commands exercised

From operator VM (`orbit-e2e-dev-779aaa-operator`), cwd `/home/orbit/orbit-run`:

```bash
orbit tool:remove opencode-cli --node=app-dev-1 --force --json
orbit tool:remove polyscope-server --node=app-dev-1 --force --json
```

### Seed intent (exact Orbit-owned residue only)

On `orbit-e2e-dev-779aaa-dev` (host residue):

- unit: `/etc/systemd/system/opencode-server.service`
- home/binary: `/home/agent/.opencode/` incl. `/home/agent/.opencode/bin/opencode`
- process: agent-owned `opencode serve` stub
- unit: `/etc/systemd/system/polyscope-server.service`
- binary: `/home/agent/.local/bin/polyscope-server`
- process: agent-owned `polyscope-server` stub

On gateway DB (process + tool intent for `app-dev-1`):

- process `opencode-server` / tool `opencode-cli` / runtime `systemd`
- process `polyscope-server` / tool `polyscope-server` / runtime `systemd`
- `NodeTool` rows `opencode-cli`, `polyscope-server`

### Deliberate non-owned sentinel (must survive)

- `/home/agent/orbit-ade-proof-nonowned-sentinel.txt`
- `/home/agent/.local/bin/user-managed-sentinel-bin`

## Results

| Check | Result |
| --- | --- |
| `tool:remove opencode-cli` | success; `legacy_runtime_cleanup=true`; process `opencode-server` removed; `tool_row_removed=true` |
| `tool:remove polyscope-server` | success; `legacy_runtime_cleanup=true`; process `polyscope-server` removed; `tool_row_removed=true` |
| OpenCode home / unit / process | absent (fail-closed post-absence) |
| PolyScope binary / unit / process | absent (fail-closed post-absence) |
| Non-owned sentinel + user binary | **survived** |
| Gateway registry | no remaining `opencode-*` / `polyscope-*` process or tool rows; `valkey` + baseline tools remain |

## Absolute artifact paths

Base:

`/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/`

| File | Absolute path |
| --- | --- |
| This manifest | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/PROOF-MANIFEST.md` |
| Topology start (full JSON) | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/00-topology-start-full.json` |
| Topology summary | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/topology-start.json` |
| Node list | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/01-node-list.json` |
| Gateway schema/process seed context | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/02-gateway-schema.txt` |
| Host residue seed | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/03-seed-host-residue.txt` |
| Gateway process/tool intent seed | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/04-seed-gateway-intent.json` |
| Pre-remove process/tool inventory | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/05-pre-remove-inventory.json` |
| `tool:remove opencode-cli` JSON | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/06-tool-remove-opencode-cli.json` |
| `tool:remove polyscope-server` JSON | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/07-tool-remove-polyscope-server.json` |
| Host post-absence + sentinel | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/08-post-absence-host.txt` |
| Post-remove process/tool inventory | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/09-post-remove-inventory.json` |
| Gateway registry clean check | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/10-post-absence-gateway-registry.json` |
| Feature tip recorded at proof | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/feature-tip-at-proof.txt` |
| Completed at (UTC) | `/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/evidence/ade-legacy-tool-remove-retained/completed-at-utc.txt` |

Loop (session-local, untracked):

`/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/loop.md`

Quality-check (session-local, untracked):

`/Users/nckrtl/orbit/.worktrees/codex-remove-opencode-polyscope-ade/.orbit/quality-gates/quality-check-2026-08-04T120234Z-583348d01512.json`

## Reviewer re-check (optional; topology retained)

```bash
# Host absence + sentinel
ssh beast "incus exec orbit-e2e-dev-779aaa-dev -- bash -lc '
  test ! -e /home/agent/.opencode
  test ! -e /home/agent/.local/bin/polyscope-server
  test -f /home/agent/orbit-ade-proof-nonowned-sentinel.txt
  test -f /home/agent/.local/bin/user-managed-sentinel-bin
  echo RECHECK_OK
'"

# Operator inventory
ssh beast "incus exec orbit-e2e-dev-779aaa-operator -- sudo -u orbit bash -lc '
  cd /home/orbit/orbit-run && orbit process:list --node=app-dev-1 --json && orbit tool:list --node=app-dev-1 --json
'"
```

## Out of scope for this pass

- Land / merge / compact archive
- Force-add of ignored `.orbit/**`
- `composer test:e2e*`
- Re-run of `composer quality-check` (no tracked code delta after green artifact)
