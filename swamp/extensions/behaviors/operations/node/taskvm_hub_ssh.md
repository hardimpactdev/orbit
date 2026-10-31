# Same-root TaskVM hub publication

TaskVM enrollment and destruction compose the existing
`@nckrtl/node-wireguard-direct.publish` method on the stable
`wireguard-hub-<hub_id>` model. Its native method lock encloses the fresh
Gateway private-material read and the hub effect. The method checks the current
root identity and cancellation before the private read and again before the
effect. The parent places this step on its admitted active Gateway worker, with
that worker's exact Node label. It does not start a child Operation or another
workflow.

Gateway admission captures
`NodeWireGuardInput::captureForTaskVm(guest, gateway,
excludedGuestId?)` as
immutable top-level `_wireguard`. The six-field snapshot retains the actual hub
owner in `hub_id` and records the Gateway in `executor_node_id`. Enrollment
keeps `excluded_node_id: null`; destruction may exclude only its exact guest.
Every root, guest, hub, and Gateway belongs in `participant_node_ids`.

The parent declares `configuration: node.vpn_configuration`, records
`configuration: {$secret: 'node.vpn_configuration', id: hubId}`, and binds that
field to
`{owner_node_id: hubId, read_node_ids: [gatewayId], write_node_ids: []}`. The
shared base source admits remote publication for `task_vm.destroy` only when its
target TaskVM owns the exact guest Node. Enrollment and provision parents add
their own closed canonical ownership predicate in their family change; this
shared base grants those kinds no authority. Node removal owns its separate
admission binding. Standalone Node roots retain their exact hub-worker boundary
until their owning admission explicitly changes it.

Inside the private callback, the existing VPN source supplies fresh peers and
the existing hub private key. Remote execution also supplies
`connection: {target: {node_id, host, port, user, fingerprint}, identity}` from
the actual hub's pinned SSH endpoint and the existing Gateway SSH key path.
Neither connection custody nor private configuration enters native resources.
The canonical `node-bootstrap/bootstrap_ssh.ts::bootstrapSystem` transports the
fixed native network program over the pinned session, with private input on
stdin. The hub remains workerless. No proxy worker, agent action, custom mutex,
relay endpoint, credential minting, or local execution fallback is involved.

Parents use the shared behavior's `publish` method. A caller of the lower
`convergeWireGuardHub(snapshot, privateValue, localInvoke?, signal?,
{environment, ssh?})`
must supply the bootstrap environment for a remote snapshot. Omitting that
environment refuses before commands; it cannot run the hub program on the
Gateway accidentally. The existing local signature remains valid when
`executor_node_id === hub_id`.

The owning TaskVM parent records its result only after all guest, provider,
network, and Node cleanup stages finish. A hub publication outcome alone does
not prove enrollment or deletion. Native tests use synthetic system/SSH
boundaries with real Gateway HTTP; they do not establish live placement or
network effects.
