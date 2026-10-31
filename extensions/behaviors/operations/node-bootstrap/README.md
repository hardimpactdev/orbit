# Node bootstrap

The Gateway validates the target, candidate account, SSH endpoint, approved host
fingerprint and published observer artifacts. It records intent and starts one
native root per bootstrap kind. Swamp performs every system action. The agent is
frozen and is never called to perform bootstrap work.

First contact runs on the exact admitted Gateway worker. The target Node is an
immutable participant; it does not need a worker before enrollment. The existing
protected bootstrap identity resolves to its Gateway-local SSH key path inside
the effect callback. Neither key bytes nor identity paths enter public inputs,
results or workflow resources. Every connection scans the host key and accepts
only the admitted fingerprint. Account activation remains with node.add.

`nodeBootstrapActions` is the canonical lower for parent DAGs. `probe` observes
platform, architecture and host identity without downloading or changing a
binary. `install` verifies the pinned published observer before atomic delivery.
`host` installs the admitted Linux prerequisites and managed SSH/sudo access,
then checks the exact candidate account. macOS preserves its existing account
and requires the admitted WireGuard address. No Gateway child Operation is used.

PHP package preparation composes the canonical native PHP installer. It keeps
extra packages and does not restart FPM masters; Instances owns later runtime
publication. Gateway projection records bounded outcomes without receipts or
re-running native validation.
