# Security Sections

Security is a cross-family section pattern, not a state family.

Orbit keeps security findings under the family that owns the protected state:

| Owning family | Security issue-key section |
| --- | --- |
| `node` | `node.security.*` for provisioned Linux host posture, SSH identity, public SSH denial, unattended upgrades, sysctl, and bake-time home permissions |
| `app` | `app.security.*` for production app runtime isolation |
| `workspace` | `workspace.security.*` for development workspace runtime isolation |
| `firewall_rule` | `firewall_rule.security.*` only when the firewall family owns protected-rule representation drift |

`doctor --family=security` is intentionally invalid. Operators select the
owning family, then optionally narrow to one exact key:

```bash
orbit doctor --family=node --key=node.security.host_key.app-1
orbit doctor --family=app --key=app.security.fpm_pool_isolation
orbit doctor --family=workspace --key=workspace.security.fs_permissions
```

Node-owned security policy applies to every provisioned Linux node unless a
role contract documents a narrower exception. App production and development
roles have different runtime surfaces: `app-prod` uses `app.security.*`
and has no workspace workflow; `app-dev` keeps workspace checks and
uses `workspace.security.*`.

The current sudo model remains broad passwordless sudo for the `orbit`
maintenance user. Least-privilege sudo wrappers are future scope and are not
part of this baseline.
