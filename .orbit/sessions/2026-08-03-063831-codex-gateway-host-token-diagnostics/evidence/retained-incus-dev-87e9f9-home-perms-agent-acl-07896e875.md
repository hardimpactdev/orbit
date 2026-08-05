# Retained Incus runtime proof — home_perms + agent ACL (07896e875)

## Topology

| Field | Value |
| --- | --- |
| Topology id | `dev-87e9f9` |
| Kind | `operator_gateway_app-dev` |
| Feature tip | `07896e875705d1a2f58af43b9e26ae5ce6e9331d` |
| Checkouts | operator/gateway/dev runtime at `/home/orbit/orbit-run` (synced to exact tip) |

## Proof surface

Acceptance surface: **`home_perms` only**. Other posture fields (`sshd`, `sysctl`) were false on this unbaked dev fixture and are excluded.

No `composer test:e2e*` ran.

## Steps and results

1. **Sync** from exact tip succeeded.
2. **`LocalAgentAclEnsure`** required stages all exit code `0`.
3. **getfacl `/home/orbit`** (managed ACL only):

```text
user::rwx
user:agent:--x
group::---
mask::--x
other::---
```

4. Exact-tip **`LocalNodeSecurityPostureProbe::check('orbit')`** → **`home_perms=true`**.
5. Deliberately added **`user:ubuntu:--x`** while retaining agent ACL → getfacl showed both named users → probe **`home_perms=false`**.
6. Removed ubuntu ACL; managed agent ACL restored → probe **`home_perms=true`**.

## Combined runtime result

`passed` — topology `dev-87e9f9` at tip `07896e875705d1a2f58af43b9e26ae5ce6e9331d` proves ACL-aware home hardening accepts only the managed Agent traversal exception and rejects broader named-user ACL.
