---
title: "Keep stock container images independent of Incus refresh"
description: "Publish an owned stock image instead of sharing an upstream image fingerprint across Incus projects."
covers:
  - swamp/ansible/library/orbit_incus_images.py
  - swamp/ansible/tasks/task-container-host-prepare.yml
  - swamp/ansible/tasks/instance-image.yml
---

# Keep stock container images independent of Incus refresh

## Why shared upstream images break

Incus 7.0.2 stores image archives once per fingerprint. Copying an upstream image between `default` and an Orbit project lets auto-refresh update each record separately. Replacing one copy can leave another project pointing at a missing archive file, even with auto-update enabled on both.

To prevent this, the container-host phase publishes its own stock image from a stopped `orbit-stock-build` container. That produces an independent fingerprint with no update source and a `user.orbit.stock-build` property.

## Reconcile contract

Instance host convergence runs once for `orbit-instances`. It runs again for `orbit-instances-nested` on CI-only Nodes or Nodes with the [nested-fleet switch](/reference/node-settings#nested-fleet-instance-hosting) enabled:

- Binds stock, prepared-image, and clone-probe calls strictly to `INCUS_REMOTE=local` and the verified `INCUS_SOCKET`.
- Treats a stock image as healthy only with `user.orbit.stock-build`, no update source, and a present archive; unmarked images refuse with their fingerprint.
- Publishes replacements via `orbit-stock-candidate` and moves the alias only after fingerprint and archive checks pass. Old images and caches stay untouched.
- Removes only temporary containers and aliases created in the same invocation; prior leftovers refuse for operator inspection.
- In `orbit-instances-nested`, allows only `security.idmap.isolated=true` plus profile devices on the clone probe, refusing any `raw.*` or `security.privileged` key before start.

## Adopt a pinned legacy stock image

This manual operator procedure requires approval for the exact Node name, local socket, project (`orbit-instances`), stock alias (`ubuntu-26.04-instance-stock`), 64-character hex fingerprint, retention alias, and a new snapshot path. Drain all host-image writers before running and keep them drained through the next convergence.

### Adoption recipe

Save this script locally and pass `<node> <socket> <project> <alias> <fingerprint> <retention> <snapshot>`:

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

After running the recipe, run host convergence under the same writer drain and verify an independent fingerprint, absent update source, present archive, retained old fingerprint, and zero-change repeat. Keep the retention alias until separately approved cleanup.

To roll back before convergence, remove the added `user.orbit.stock-build` property and retention alias from that exact fingerprint. After replacement, restore the stock alias and metadata from the snapshot and roll back software before resuming writers.

## Limits

Keep these verification and ordering boundaries in mind when running the phase:

- The stock module skips in Ansible check mode, so check mode does not prove refusals.
- Bridge DHCP and DNS firewall tasks run before stock reconciliation, so an image refusal leaves those firewall rules applied.
- Archive checks verify file presence, not archive integrity.
- Fixtures verify emitted requests, refusal paths, and repeats; live archive deletion and ZFS clone survival require live verification.
