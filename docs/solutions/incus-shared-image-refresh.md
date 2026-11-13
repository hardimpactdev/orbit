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

The phase selects the static local target, verifies the daemon and its archive directory, and binds stock, prepared-image and clone-probe commands to that socket. The client's default remote cannot receive their reads, writes or cleanup.

When the selected stock image is healthy, it has the matching `user.orbit.stock-build` statement, no update source or auto-update, and an archive. That statement permits the healthy check; it never licenses deletion. An unmarked selected image refuses with its full fingerprint and a link to the adoption recipe below.

An absent alias requires publication from a stopped `orbit-stock-build` container. An adopted upstream image can be replaced only while another alias retains its old record. Publication uses `orbit-stock-candidate`; the selected alias moves only after the new fingerprint and archive pass verification. The old image and all upstream caches remain. The phase also keeps each prepared image that it replaces.

Cleanup removes only temporary containers and aliases successfully created in the same invocation. A prior builder, candidate or retirement alias, or an unaliased marked publication, refuses with the exact resource and this recipe. No converge automatically recovers or deletes prior leftovers, even when their properties match. A failed publication can leave an image requiring separately authorized operator cleanup.

Prepared-image builders and clone probes follow the same rule: an existing temporary name refuses instead of being deleted. The [nested-fleet image](/reference/development-containers#the-nested-fleet-image) has its own builder in `orbit-instances-nested`, under the same rule. Publication clears the stock statement from prepared images. F69 supplies Node admission exclusion; there is no module/file lock, analyzer exception or automatic cleanup retry. Drain Operations admitted under old lock keys before rollout.

An Instance host runs the phase once per Instance project: `orbit-instances`, and `orbit-instances-nested` when the Node's [nested-fleet switch](/reference/node-settings#nested-fleet-instance-hosting) is on. Each project gets its own stock image under the same alias. Before the clone probe starts in the nested project, the nested guard checks the probe's effective config and devices against the project's exact profile devices; a `raw.*` key, a privileged container or another device refuses before the start. The normal project's probe keeps its NIC guard unchanged.

## Adopt a pinned legacy stock image

This is an explicit operator procedure, outside ordinary convergence. It does not authorize a live action. Before running it, obtain approval for the exact Node's native server name, local socket, project, stock alias, full 64-character fingerprint and retention alias. The fingerprint must come from known preparation evidence; discovering an alias is not approval to adopt its target. For Instance hosts, the project is `orbit-instances` and the stock alias is `ubuntu-26.04-instance-stock`; task-container values come from that Node's admitted host settings.

Drain existing host-image writers and prevent new admissions throughout adoption and the following convergence. F69 alone does not reserve a standalone operator action. Save the block below as a local Python file and supply the approved values in this order: Node, socket, project, stock alias, fingerprint, retention alias, absolute snapshot filename. The snapshot must not exist. No supplied value is inferred from a live alias.

The recipe verifies all inputs before writing. Project and alias names must match `[A-Za-z0-9][A-Za-z0-9_.-]*`; remote prefixes and slashes are refused. The fingerprint must be exactly 64 lowercase hexadecimal characters. The recipe snapshots the image metadata and aliases, creates the approved retention alias without replacement, and makes exactly one native property update on the full fingerprint. Incus's native `image set-property` merges that property into the existing writable metadata and uses its ETag. A failure stops; it does not retry or perform rollback automatically.

### Adoption recipe

Run this recipe only with the approved tuple and writer drain described above.

```python
import json
import os
import re
import stat
import subprocess
import sys
from pathlib import Path
from urllib.parse import quote

node, socket, project, alias, fingerprint, retention, snapshot = sys.argv[1:]
recipe = '/solutions/incus-shared-image-refresh#adopt-a-pinned-legacy-stock-image'
key = 'user.orbit.stock-build'

def require(condition, resource):
    if not condition:
        raise SystemExit(f'Refused {resource}; see {recipe}')

for field, value in [('retention', retention), ('alias', alias), ('project', project)]:
    require(re.fullmatch('[A-Za-z0-9][A-Za-z0-9_.-]*', value), f'{field} {json.dumps(value)}')
require(re.fullmatch('[0-9a-f]{64}', fingerprint), f'fingerprint {json.dumps(fingerprint)}')
require(node, 'approved Node')
require(retention not in [alias, 'orbit-stock-candidate', 'orbit-stock-retiring'], retention)
require(Path(socket).is_absolute() and Path(socket).is_socket(), socket)
require(Path(snapshot).is_absolute(), snapshot)
env = {**os.environ, 'INCUS_REMOTE': 'local', 'INCUS_SOCKET': socket}

def incus(*args):
    return subprocess.check_output(['incus', '--project', project, *args], env=env, text=True)

def query(path):
    # Incus refuses --project on its raw API command; the path carries the project.
    return json.loads(subprocess.check_output(['incus', 'query', '--', path], env=env, text=True))

def rows():
    return json.loads(incus('image', 'list', '--format', 'json'))

server = query('/1.0')['environment']
require(server['server_name'] == node, f'Node {node}')
pid = server['server_pid']
daemon = dict(item.split(b'=', 1) for item in Path(f'/proc/{pid}/environ').read_bytes().split(b'\x00') if b'=' in item)
directory = daemon.get(b'INCUS_DIR', b'/var/lib/incus').decode() or '/var/lib/incus'
daemon_socket = daemon.get(b'INCUS_SOCKET', (directory + '/unix.socket').encode()).decode()
require(daemon_socket == socket, f'local socket {socket}')
archive = Path(directory) / 'images' / fingerprint
require(archive.exists() and stat.S_ISREG(archive.stat().st_mode), f'archive {fingerprint}')
images = rows()
selected = next((image for image in images if any(a['name'] == alias for a in image['aliases'])), None)
require(selected is not None and selected['fingerprint'] == fingerprint, f'alias {alias} / image {fingerprint}')
require(key not in selected.get('properties', {}), f'image {fingerprint} already has a stock statement')
for image in images:
    for name in ['orbit-stock-candidate', 'orbit-stock-retiring', retention]:
        require(not any(a['name'] == name for a in image['aliases']), f'alias {name} / image {image["fingerprint"]}')
    require(image['aliases'] or image.get('properties', {}).get(key) != alias, f'interrupted image {image["fingerprint"]}')
instances = query('/1.0/instances?recursion=1&project=' + quote(project, safe=''))
for instance in instances:
    require(instance['name'] not in ['orbit-stock-build', 'orbit-instance-build', 'orbit-clone-probe', 'orbit-clone-probe-copy'], f'instance {instance["name"]}')
with open(snapshot, 'x', opener=lambda path, flags: os.open(path, flags, 0o600)) as saved:
    json.dump({'node': node, 'socket': socket, 'project': project, 'alias': alias,
               'fingerprint': fingerprint, 'retention': retention, 'image': selected}, saved, indent=2)
incus('image', 'alias', 'create', retention, fingerprint)
incus('image', 'set-property', fingerprint, key + '=' + alias)
adopted = next(image for image in rows() if image['fingerprint'] == fingerprint)
require(adopted.get('properties') == {**selected.get('properties', {}), key: alias}, f'image {fingerprint} properties')
require(sorted(a['name'] for a in adopted['aliases']) == sorted([a['name'] for a in selected['aliases']] + [retention]), f'image {fingerprint} aliases')
print(f'Adopted image {fingerprint}; metadata saved to {snapshot}. Convergence is a separate action.')
```

After verifying the claim, run the ordinary host convergence under the same writer drain. Verify an independent selected fingerprint, no update source or auto-update, the archive, retained old fingerprint and unrelated aliases, and an unchanged repeat. Keep the retention alias until separately authorized cleanup; this PR never removes it.

For rollback before convergence, under a separately approved writer drain, remove only the added property and retention alias from that exact fingerprint and verify the saved metadata. After replacement, retain the new image, verify the old archive is still usable, restore the original stock alias and metadata from the snapshot, and roll back the software before resuming writers. The new reconciler correctly refuses an unmarked restored record. A missing archive is not repaired by adoption.

## Operator cleanup

A refusal names the resource that needs inspection; it grants no cleanup authority. Obtain separate approval for that exact Node, project and resource before any removal. Confirm the original invocation stopped and preserve unrelated aliases, projects and existing containers. A copied stock statement is not evidence that an image or builder may be deleted. After the approved cleanup, start a new ordinary convergence.

## Contract and proof limits

The pinned Incus 7.0.2 source shows [image publication and creation-time properties](https://github.com/lxc/incus/blob/v7.0.2/cmd/incusd/images.go#L466-L568), [non-atomic refresh across projects](https://github.com/lxc/incus/blob/v7.0.2/cmd/incusd/images.go#L2126-L2166), [transactional alias replacement](https://github.com/lxc/incus/blob/v7.0.2/cmd/incusd/images.go#L4141-L4166), and [ZFS origin retention](https://github.com/lxc/incus/blob/v7.0.2/internal/server/storage/drivers/driver_zfs_volumes.go#L1705-L1729).

The client selects the static local target with `INCUS_REMOTE=local` ([configuration precedence](https://github.com/lxc/incus/blob/v7.0.2/shared/cliconfig/file.go#L152-L186), [local target](https://github.com/lxc/incus/blob/v7.0.2/shared/cliconfig/default.go#L7-L13)); `INCUS_SOCKET` then binds it to the verified socket ([Unix connection](https://github.com/lxc/incus/blob/v7.0.2/client/connection.go#L161-L214)). These settings do not override an explicit remote prefix. The adoption recipe refuses such inputs before any request or snapshot.

Check mode does not prove refusals: the stock module skips in check mode. A refusal precedes any change to the conflicting resource. It does not precede every host change. The bridge's DHCP and DNS firewall tasks run before stock reconciliation, so an image refusal leaves those rules applied. The prior-probe check runs after both. An approved fingerprint that matches the selected alias can still be wrong. The recipe cannot tell it from an intended approval.

Fixtures prove the emitted requests, same-invocation cleanup, refusal paths and unchanged repeats. They cannot prove real archive deletion, fingerprint isolation after an upstream refresh or ZFS clone survival. Those claims need a separately authorized live proof. This change starts no live refresh.

If upstream initialization reuses a dangling cache retained by another project, reconciliation fails. It does not delete that record or claim a repair. The archive check proves file presence, not integrity.
