# Native role firewall action

`roleFirewallSteps(name, inputExpr, fullGlobals, dependency?, guard?)` runs one
canonical `FirewallRules` transaction through official SSH on Node209. The parent
supplies the admitted public rules, approved identity and full target host keys.
The target host is named `target`. The final named step returns `{ready:true}`
only after the existing policy verifies every required tuple.

The action preserves destination, interface, family, exact comment ownership,
restoration and pruning semantics from `FirewallProgram`. It adds no new firewall
policy, transport, callback or retry. Its existing UFW transaction lock protects
the host tool. The official cfgmgmt firewall schema does not express the interface
and destination required by `orbit:wireguard-members`.

Optional plans belong in an admission job. Their native fan-out lists must be
available at the start of the effects job. A guard does not protect an absent
input-only field from eager evaluation.
