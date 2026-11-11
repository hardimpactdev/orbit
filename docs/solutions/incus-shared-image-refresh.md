---
title: "Keep stock container images independent of Incus refresh"
description: "Publish an owned stock image instead of sharing an upstream image fingerprint across projects."
covers:
  - swamp/ansible/library/orbit_incus_images.py
  - swamp/ansible/tasks/task-container-host-prepare.yml
  - swamp/ansible/tasks/instance-image.yml
---

## Why the copied image fails

Incus 7.0.2 stores image archives once per fingerprint. A copied upstream image can have records in both `default` and an Orbit project. Auto-refresh processes those records separately; replacement in one project can leave another record pointing to missing files. Enabling auto-update on both copies does not make the refresh atomic.

The container-host phase publishes its own stock image from a stopped temporary container. Publication hashes the exported contents into an independent fingerprint with no update source. The phase refuses publication that returns the upstream fingerprint, an updating image or a missing archive. It never repairs another project's record.

## Reconcile the stock image

Before inspecting archives or deleting an image, the phase reads the daemon PID from `/1.0`, reads that process's `INCUS_DIR` and `INCUS_SOCKET`, and checks that the socket reports the same daemon. Unreadable state, a mismatched daemon or an inaccessible image directory fails the phase. The client's environment does not select the archive directory.

An absent alias, a copied upstream record or a missing owned archive requires publication. The temporary container is `orbit-stock-build`; publication uses `orbit-stock-candidate`. The image gets `user.orbit.stock-build` at creation, before alias assignment, so an interrupted publication still leaves ownership evidence. The selected alias moves only after the new archive and fingerprint pass their checks. Incus updates an existing alias in one database transaction.

The old selected image gets `orbit-stock-retiring` before promotion. Every converge reconciles that marker, the staging alias, marked unaliased publications, the temporary container and owned upstream cache records. If promotion never happened, cleanup preserves the selected image and removes only the retirement marker. Failed cleanup fails the phase; the next converge retries from recorded ownership without publishing another image unnecessarily. A healthy repeat reports zero changes.

Cleanup removes only this project's owned records. It preserves unrelated images and the prepared `orbit-instance` image. Publishing a prepared image clears the inherited `user.orbit.stock-build` mark. Cleanup may delete an unaliased marked image even when an instance names its fingerprint in `volatile.base_image`; Incus permits that deletion, and ZFS retains an origin with dependent clones in its deleted namespace.

The shared Node admission lock excludes overlapping Node convergence, role work and enrollment. The image module adds no lock, retry loop or analyzer exception. The existing clone probe and prepared-image build tasks keep their behavior.

## Contract and proof limits

The pinned Incus 7.0.2 source shows [image publication and creation-time properties](https://github.com/lxc/incus/blob/v7.0.2/cmd/incusd/images.go#L466-L568), [non-atomic refresh across projects](https://github.com/lxc/incus/blob/v7.0.2/cmd/incusd/images.go#L2126-L2166), [transactional alias replacement](https://github.com/lxc/incus/blob/v7.0.2/cmd/incusd/images.go#L4141-L4166), and [ZFS origin retention](https://github.com/lxc/incus/blob/v7.0.2/internal/server/storage/drivers/driver_zfs_volumes.go#L1705-L1729).

Fixtures prove the emitted requests, ownership recovery, refusal paths and unchanged repeats. They cannot prove real archive deletion, fingerprint isolation after an upstream refresh or ZFS clone survival. Those claims need a separately authorized live proof. This change starts no live refresh.

If upstream initialization reuses a dangling cache retained by another project, reconciliation fails. It does not delete that record or claim a repair. The archive check proves file presence, not integrity.
