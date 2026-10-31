# Tool operations

The Gateway validates and records `tool.install`, `tool.update`, `tool.remove`,
`tool.adopt` and `tool.manager_converge` intent. It starts `operation-tool-install-direct` for install and
`operation-tool-direct` for the other writes, then projects bounded native results into manager and Tool records. The sole
Gateway registration is direct-operations.php; no Kit execution declaration,
polled writer or Gateway completion proof remains.

Every write uses the exact admitted orbit-node-N worker and matching node
label, including update, remove, adopt and manager convergence. Swamp runs fixed
system commands on that Node, without agent actions. Install refuses silent
adoption. Adoption observes one package without changing it. Constraints gate
normal candidates; removal checks the exact root and verifies absence without
autoremove. Manager materialization is a native action. Scan reads inventory
without adopting packages or materializing managers.

See [direct Tool intent](tools-direct.md) for placement, privilege, native
recovery and rollout proof. Release-scoped Vite+ environments belong to the
deploy family.

The legacy `operation-tools` discovery workflow, slot models and receipt runner
are retired. Source removal preserves existing run history and data; native
retention remains the cleanup owner. Do not use `swamp workflow delete` to retire
this producer because that also deletes history.
