# Ansible input

Swamp expressions have no JSON encoder. Full intents also exceed the 128 KiB limit for one environment string. This behavior writes the non-secret object to a mode `0600` file and locates the playbooks delivered through native `additionalFiles` and `ctx.extensionFile`.

Checked the Swamp workflow inputs and command/shell contracts, and official SSH and cfgmgmt file behaviors. None writes a whole input object as a local JSON file. The behavior executes no Node work. The workflow owns secrets, phase order and completed-run cleanup.

PHP delta publication stays with the shared native publisher. Its three-way composition preserves changes from other admissions; the pinned Ansible modules have no equivalent. Private DNS needs none: the `dns-publish` phase writes the whole rendered state. Ansible still applies the Caddy snapshot, ordinary files, firewall state and exporter pools. Prometheus config uses checksum-guarded `dd` on stdin: atomic host-file replacement would leave its Docker file bind on the old inode.
