# Task VM model credentials and Pi

Task VM provisioning, enrollment, runtime preparation and destruction use one
native Swamp DAG per parent. Model-key registration and revocation, Pi delivery
and Pi installation are steps of those parents. They do not create child
Operations or invoke agent action commands. The frozen agent stays unchanged.

The Gateway admits the VM, its model-router origin, runtime user and Pi digest.
It supplies existing credentials and the pinned artifact through the shared
private-material boundary. Each field has an admitted owner and read placement.
Model-router management and anchor keys stay in the control step; the runtime
step receives only the VM's own model key and Pi token. No credential goes in
workflow arguments, outputs, logs or command arguments.

The native key step uses CLIProxyAPI's fixed management and model endpoints. It
retains the permanent anchor, verifies that anonymous access is refused, and
verifies the VM key after registration or revocation. Repeating an issue or
revoke succeeds when the desired state already holds. It never replaces or
deletes the anchor. The parent owns placement, serialization and retry. A
cancelled or superseded intent fails its guard before another request.

Revocation writes the freshly observed list without the VM key using `PUT`. It
verifies that the anchor and every unrelated entry stay unchanged. A `PATCH` to
an empty value would leave a tombstone, so it is not a removal operation. Keys
never appear in URL query parameters. All model-key mutations for one router,
including teardown, must share the parent's serialization boundary.

The shared behavior is `@nckrtl/task-vm-model-keys`. Parents call its `issue` or
`revoke` method with `writes: true` and the stable behavior name
`task-vm-model-router-<model_router_node_id>`. Swamp holds that instance's
native method lock across the complete read, mutation and verification. Never
put the VM or run ID in this behavior's name. The run ID belongs in its public
result resource name, `issue-<run_id>` or `revoke-<run_id>`.

The method accepts only the parent Operation and run identity. It reads the
admitted top-level `task_vm_id`, `model_origin`, `model_router_node_id` and
`gateway_node_id`. It checks the stable behavior name and local Gateway worker
identity before reading credentials. The same binding must hold at each guard.
Methods neither admit another Operation nor project the parent result.

Pi delivery downloads the parent's admitted `pi_artifact_file`, verifies its
SHA-256 digest and sends bounded chunks to the local native Pi receiver. The
receiver publishes the verified cache file atomically. The next native Pi step
installs that binary at the admitted account's `.local/bin/pi-server` with mode
0755. Private runtime files use mode 0600. Existing account, ownership, symlink
and digest checks stay in the canonical `pi_native.ts` implementation. Secret
bytes travel on stdin; the command source is code-owned.

The shared Gateway private-material transport and native Pi implementation are
used directly. There is no second secret store, transfer protocol, retry loop or
agent compatibility path. Tests exercise the real HTTP boundary, redaction,
anchor protection, interrupted transfers and local file publication.
