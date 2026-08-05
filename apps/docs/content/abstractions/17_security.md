# Security Sections

Security is a cross-family section pattern, not a state family.

Orbit keeps security findings under the family that owns the protected state:

| Owning family | Security issue-key section |
| --- | --- |
| `node` | `node.security.*` for provisioned Linux runtime-user posture, WireGuard-bound SSH, public SSH denial, unattended upgrades, sysctl, and managed home permissions (owner `0700`-equivalent; optional narrow managed Agent traversal ACL `u:agent:--x` with mask; doctor-restorable for weak base mode-only drift without fighting that ACL) |
| `instance` | `instance.security.*` for production app runtime isolation |
| `workspace` | `workspace.security.*` for development workspace runtime isolation |
| `firewall_rule` | `firewall_rule.security.*` only when the firewall family owns protected-rule representation drift |

`doctor --family=security` is intentionally invalid. Operators select the
owning family, then optionally narrow to one exact key:

```bash
orbit doctor --family=node --key=node.security.public_ssh_deny
orbit doctor --family=instance --key=instance.security.runtime_container_isolation
orbit doctor --family=workspace --key=workspace.security.fs_permissions
```

Node-owned security policy applies to every provisioned Linux node unless a
role contract documents a narrower exception. Production and development instance
roles have different runtime surfaces: `app-prod` uses `instance.security.*`
and has no workspace workflow; `app-dev` keeps workspace checks and
uses `workspace.security.*`.

The current sudo model remains broad passwordless sudo for the `orbit`
maintenance user. Least-privilege sudo wrappers are future scope and are not
part of this baseline.
