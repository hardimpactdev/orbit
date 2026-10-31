# Native Firewall intent

`operation-firewall-direct` runs one admitted `firewall.allow`, `firewall.deny`
or `firewall.remove` root on `orbit-node-ID` with the matching string Node
label. The Gateway records the validated named rule and starts the native run.
It keeps API validation, identity checks and a minimal final projection. Swamp
owns the host effects, cancellation, placement and native history.

The named-rule action in `operations/firewall/local.ts` calls the canonical
`routes/firewall-program.ts` fixed local sudo program. It uses the same
`/run/lock/orbit-firewall.lock` transaction as Route, role and Router writers.
No agent action, digest admission, Gateway primitive or child Operation is used.

A named rule owns only `orbit:node:ID:firewall:NAME`. It checks complete
ownership before changing a rule, preserves unowned comments and refuses
ambiguous or duplicate ownership. Allow rules are prepended. A missing address
family is repaired without deleting the matching survivor. An inactive backend
stays inactive. Removal verifies absence and repeats safely.

The Gateway preserves its public recovery SSH guard, canonical source/port
validation and same-name or opposite-action conflicts. Root success activates or
removes the admitted managed row. A failed root records its safe refusal code.
The projection does not inspect UFW or query step receipts. Duplicate terminal
delivery does not repeat record events.

`firewall.inspect` remains a read-only observation read through the Gateway. It
changes no host state. The frozen agent keeps its published Rust actions until the final observer
strip. Native Firewall workflows execute system commands directly. Shared
observation and Router helpers remain with their owners.

The local tests cover all three root kinds, bound identity, cancellation,
redaction, ownership collisions, inactive state, address-family repair, prepend
order and exact removal. Native harness tests replace only HTTP and the system
boundary. A live dev canary follows release and hot reload.
