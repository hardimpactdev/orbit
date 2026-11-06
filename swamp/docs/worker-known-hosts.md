# Worker known-hosts publication

The Gateway records Node SSH host pins. When a persisted pin or WireGuard address changes, it admits `node.worker_known_hosts`, targeting the Swamp Node. The public intent contains the complete recorded WireGuard host-key lines and their content digest. Missing pins are omitted; this workflow never scans a host or accepts a first-contact key. The existing `SwampSshExecutionInput` remains the target and identity supplier.

Official `@swamp/cfgmgmt` version `2026.10.27.1` exposes no host-key or known-hosts connection input. Its documented `accept-new` SSH policy requires the runner's `~/.ssh/known_hosts` to be seeded before the first connection. Its file model is SSH-only, including for localhost. The shared publication therefore uses Swamp core `command/shell`, placed on209, with one local command. Families consume that preseed and add no trust adapter or writer.

The step `Write known hosts` pipes the content into `install -D -m 0600 /dev/stdin /home/$USER/.ssh/known_hosts`. OpenSSH reads the passwd home, not the worker unit's `HOME`, and systemd sets `USER` for the worker. A missing `.ssh` directory is created under the worker's `0077` umask. Each run replaces the file. The Gateway reads the step's status from Swamp's run data and records `known_hosts_ready` with the published digest. The command reads no key, opens no SSH connection and changes no worker unit.

Each persisted pin change admits a publication with the full current pin snapshot. Publications can overlap. Every write is a complete atomic replacement; the last completed replacement wins. The Gateway does not hold changes behind an unfinished run, suppress identical snapshots, or admit another run during terminal projection. An identical pin-record request changes no pin and admits no publication. A failed publication stays failed. HTTP idempotency for the caller remains separate from automatic admission; Swamp owns execution and terminal history.

The Gateway principal `user:orbit-gateway` runs and reads this workflow through its one `*` workflow grant. Publish and validate the native workflow, then take up the Gateway source. A successful terminal publication is the live prerequisite for cfgmgmt consumers. Source merge, an SSH authentication proof, or a configured worker capacity alone does not establish it. Quiet windows and worker maintenance remain separately owned; this source authorizes no immediate host change.

## Proof

The proof records minimal@16, Operation 4 and native run `51113625-08d0-4a59-8fb2-1a6e61a0de5f`. The fixture empties the worker file at mode 0644 before admission; parity checks the exact captured public pins, their digest, owner `orbit`, mode 0600 and Gateway terminal result. The write took 0.946 seconds and the Operation 2.5 seconds. This proves the isolated topology, not production uptake or ordering between overlapping publications.
