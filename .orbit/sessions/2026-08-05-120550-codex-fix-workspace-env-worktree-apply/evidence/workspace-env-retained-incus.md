# Workspace env retained Incus proof

- Candidate: `89322e6f9436dfa35cee08203a30203a6b2a24ce`
- Venue: retained Incus topology `dev-868c8e` (`operator_gateway_app-dev`)
- Roles: `orbit-e2e-dev-868c8e-operator`, `orbit-e2e-dev-868c8e-gateway`, `orbit-e2e-dev-868c8e-dev`
- Runtime checkout: `/home/orbit/orbit-run`
- Registered workspace: `envproof.development` / `react`
- Registered path: `/home/orbit/apps/envproof/.worktrees/react`
- Workspace runtime: `orbit-ws-envproof-react`

Candidate identity was verified after `composer e2e:incus -- --sync --id=dev-868c8e --json`:

```text
gateway WorkspaceEnvApplier.php sha256:
30f0b3fb5164434ffeea613e16b114d36099d9cd0e7c03f2745d76d43e52eaaf

operator LocalEnvFilePath.php sha256:
366c9b231b490210416dfdf8cbcfb079d831704a4258a596b8755814171c356d

operator apps/cli/orbit sha256:
eb19bf3561cf7627029de7e9b105b1460b72dacbf0511adb9004727c4fd4068a
```

`workspace:show` resolved the fixture to the exact managed worktree path and inherited process:

```json
{"workspace":{"name":"react","project":"envproof","instance":"development","node":"app-dev-1","path":"/home/orbit/apps/envproof/.worktrees/react","url":"https://react.envproof.test","lifecycle_status":"active"},"inherited_processes":[{"name":"frankenphp-envproof-react"}]}
```

The fixture env started as a regular file owned by `orbit:orbit`, mode `0640`, with `APP_KEY=fixture` and `UNRELATED=preserved`. The first apply deliberately exposed the phase-specific error contract because the minimal fixture did not yet contain `artisan`:

```json
{"error":{"code":"workspace.env_apply_failed","message":"Saved 'REDIS_HOST' and wrote the workspace env file for 'react', but cache clear or runtime restart failed.","meta":{"workspace":"react","key":"REDIS_HOST","phase":"runtime","stored":true,"env_written":true,"applied":false,"runtime_restarted":false}}}
```

After adding the fixture's no-op `artisan` entry point, the exact apply shape succeeded twice with the same value:

```text
orbit workspace:env set react --instance=envproof.development --key=REDIS_HOST --value=172.29.0.1 --apply --json
```

```json
{"success":{"data":{"scope":"workspace","project":"envproof","instance":"development","workspace":"react","path":"/home/orbit/apps/envproof/.worktrees/react/.env","stored":true,"applied":true,"runtime_restarted":true,"variable":{"key":"REDIS_HOST","value":"172.29.0.1","secret":false},"apply":{"env_path":"/home/orbit/apps/envproof/.worktrees/react/.env","cache_cleared":true,"runtime_outcome":"restarted","env_written":true,"runtime_restarted":true}},"meta":[]}}
```

The second successful apply produced the same env content hash as the first successful apply:

```text
de7a34ca42505529984ba6de985fddf9a485542f1a23a46cb17ffb962afa272b
```

The final env state retained all expected values and permissions:

```text
APP_KEY=fixture
UNRELATED=preserved
REDIS_HOST=172.29.0.1
Access: (0640/-rw-r-----)  Uid: (1002/orbit) Gid: (1002/orbit)
```

The correct workspace container kept the same container identity and received a fresh start on each successful apply:

```text
before successful apply: StartedAt=2026-08-05T09:27:53.478397486Z
after first success:     StartedAt=2026-08-05T09:31:23.600059072Z
after second success:    StartedAt=2026-08-05T09:32:48.830289681Z
container id: 73533026eafe2f5e9d3f50c8fd127be78c42396f224c92cd1ed2154e093d6a7a
final status: running
```

The restarted container served the fixture directly on its retained-topology network:

```text
curl http://172.19.0.5/
envproof-ok
```

After the independent review fix for existing non-regular `.env` targets, the
topology was synced again to candidate `89322e6f9436dfa35cee08203a30203a6b2a24ce`.
The runtime-user writer hash on the operator matched the candidate:

```text
a42d9bfdca4658d288eec887014590424fcf8845526e70053d3d69bc7de49ed3
```

The exact apply command again returned the same successful JSON shown above,
the env hash remained `de7a34ca42505529984ba6de985fddf9a485542f1a23a46cb17ffb962afa272b`,
mode remained `0640`, and the selected workspace runtime restarted.

Result: passed.
