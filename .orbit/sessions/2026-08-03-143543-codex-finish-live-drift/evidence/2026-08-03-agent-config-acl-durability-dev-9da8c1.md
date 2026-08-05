# Runtime proof: agent config ACL durability (non-vacuous)

- Date: 2026-08-03
- Feature HEAD: `064642c8888915a3d167c776cb54d624fe240d24`
- Quality artifact: `.orbit/quality-gates/quality-check-2026-08-03T122829Z-0727e896cbc9.json` (`git.commit` match, `dirty=false`)
- Topology: retained `dev-9da8c1`
- Kind: `operator_gateway_agent`
- Host: `beast`
- Agent instance: `orbit-e2e-dev-9da8c1-agent`
- Result: **passed** (non-vacuous)

Earlier file-only ACL proof on the same topology was vacuous for agent open
(directory mask effective-none). This record supersedes that path with
directory + file ACL durability after HEAD `064642c8`.

## Baseline (after one-time restore of prior-candidate damage)

1. Synced exact HEAD `064642c8888915a3d167c776cb54d624fe240d24`.
2. Re-applied directory `u:agent:--x` + file `u:agent:r--`.
3. As agent: `test -e /home/orbit/.config/orbit/config.json` → exit 0.
4. As agent: `head -c1` on config → opening brace `{` (real file content).
5. Baseline config mtime: `12:14:10`.

## Atomic rewrite (`OrbitConfigStore::save`)

Command (inside agent instance, as orbit, real config path):

```bash
# from /home/orbit/orbit-run on orbit-e2e-dev-9da8c1-agent
sudo -n -u orbit -H env ORBIT_CONFIG_PATH=/home/orbit/.config/orbit/config.json \
  /home/orbit/orbit-run/apps/cli/orbit gateway:use default --json
```

- Result: success selected; `config-write-exit=0`
- Config mtime: `12:14:10` → `12:29:35` (atomic rewrite occurred)

## Post-rewrite ACLs (exact)

Directory `/home/orbit/.config/orbit`:

```text
user::rwx
user:agent:--x
group::---
mask::--x
other::---
```

File `/home/orbit/.config/orbit/config.json`:

```text
user::rw-
user:agent:r--
group::---
mask::r--
other::---
```

## Post-rewrite agent open + real CLI read (non-vacuous)

- As agent: `test -e /home/orbit/.config/orbit/config.json` → exit 0
- As agent: `head -c1` on config → opening brace `{`
- As agent:

```bash
/home/agent/.local/bin/orbit gateway:list --json
```

- Result: `active_gateway=default` and the real gateway row present
- `nonvacuous-proof-exit=0`

## Outcome

After `gateway:use` atomic rewrite, directory traversal ACL and file read ACL both remain effective; agent can open the real config and run Orbit CLI against it (not emptySkeleton).
