"""One Docker migration action per native step; private inventory stays on its host."""
import json
import os
import sys
from pathlib import Path

import docker_inventory as d


def load(data):
    d.journal(False)
    state = json.loads((d.JOURNAL / 'inventory.json').read_bytes())
    d.require(d.re.fullmatch(r'[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}', state.get('inventory_id', '')), 'inventory-version')
    d.require(state['recipe'] == data['recipe'], 'recipe-mismatch')
    d.require(d.same_daemon(state), 'daemon-changed')
    for container in state['containers']:
        d.process(container, data)
    return state


def catalogue(state):
    return {'probe': [c['Id'] for c in state['containers'] if not d.managed(c) and c.get('State', {}).get('Running')][:1], 'container_ids': [c['Id'] for c in state['containers']],
                'unmanaged': [c['Id'] for c in state['containers'] if not d.managed(c)],
                'volumes': [v['Name'] for v in state['volumes']],
                'networks': [n['Name'] for n in state['networks'] if n['Name'] not in ('bridge', 'host', 'none')],
                'images': list(dict.fromkeys([i['Id'] for i in d.archive_images(state)] + list(state['committed'].values()))),
                'tags': [i['Id'] + '\t' + tag for i in state['images'] for tag in i.get('RepoTags') or []],
                'process_ids': sorted({c['Config']['Labels']['orbit.process.id'] for c in state['containers'] if d.managed(c)})}


def inventory(data, mode, run_id):
    release = dict(line.split('=', 1) for line in Path('/etc/os-release').read_text().splitlines() if '=' in line)
    d.require(release.get('ID', '').strip('"') == 'ubuntu' and release.get('VERSION_CODENAME', '').strip('"') == 'resolute', 'platform')
    d.require(d.command(['dpkg', '--print-architecture'])[1].strip() == data['architecture'], 'architecture')
    d.journal(False)
    present = (d.JOURNAL / 'inventory.json').exists()
    d.require(mode != 'restore-policies' or present, 'missing-inventory')
    if present:
        state = load(data)
    else:
        d.require('docker-ce' in d.installed(), 'not-docker-ce')
        state = {**d.collect(data), 'inventory_id': run_id}
    d.require(mode != 'migrate' or state.get('recovery') not in ('active', 'restored'), 'already-restored')
    changes = d.source_changes()
    ancestor = d.JOURNAL
    while not ancestor.exists():
        ancestor = ancestor.parent
    original = d.JOURNAL
    try:
        d.JOURNAL = ancestor
        sufficient = d.space(state)
    finally:
        d.JOURNAL = original
    d.require(sufficient, 'insufficient-space')
    if mode == 'restore-policies':
        d.require('docker-ce' in d.installed() and not d.DROPIN.exists()
                  and d.real_directory(d.api('GET', '/info').get('DockerRootDir'), 'restore-engine') == state['metadata_roots']['docker'], 'restore-engine')
        d.require(all(d.inspect(c['Id'], True) is not None for c in state['containers']), 'restore-container')
        state.update(fallback=False, recovery='active')
        d.record(state)
    if mode != 'plan' and not present:
        d.journal(True)
        d.record(state)
    return dict(healthy=True, planned=mode == 'plan', **catalogue(state),
                plan={'recipe': data['recipe'], 'docker_version': data['docker_version'], 'containerd_version': data['containerd_version'],
                          'resuming': present, 'source_backups': [str(c[3]) for c in changes], 'fallback_data_root': str(d.JOURNAL / 'engine'),
                          'metadata_roots': state['metadata_roots'], 'space': {'sufficient': sufficient}})


def action(name, data, item=None, mode='migrate'):
    state = load(data)
    container = next((c for c in state['containers'] if c['Id'] == item), None)
    if name in ('disable-policy', 'stop', 'commit', 'restore-policy', 'start', 'recreate'):
        d.require(container is not None, 'inventory-drift')
    reference = container['Name'].lstrip('/') if container and state['fallback'] else item
    if name == 'disable-policy':
        d.api('POST', '/containers/' + d.quote(reference) + '/update', {'RestartPolicy': {'Name': 'no'}})
    elif name == 'stop':
        current = d.inspect(reference)
        if current['State'].get('Running'):
            d.command(['docker', 'stop', '--time', '60', reference])
    elif name == 'commit':
        d.require(not d.managed(container) and not d.inspect(reference)['State'].get('Running'), 'inventory-drift')
        key = item + ':shutdown'
        if key not in state['committed'] and d.api('GET', '/containers/' + d.quote(item) + '/changes'):
            # A fixed local tag makes an acknowledged-lost commit discoverable without a second commit.
            tag = 'orbit-docker-migration:' + state['inventory_id'] + '_' + item
            saved = d.api('GET', '/images/' + d.quote(tag) + '/json', missing=True)
            image = saved['Id'] if saved else d.command(['docker', 'commit', item, tag])[1].strip()
            d.require(d.re.fullmatch('sha256:[0-9a-f]{64}', image), 'commit-image')
            state['committed'][key] = image
            d.record(state)
    elif name == 'archive':
        d.require(item in catalogue(state)['images'], 'image-id')
        d.archive(state, item)
    elif name == 'backup':
        d.backup(state)
    elif name == 'sources':
        d.retire_sources(d.source_changes())
    elif name == 'packages':
        if not state['swapped']:
            d.swap(state, data)
    elif name == 'readiness':
        compatible = d.readable(state)
        d.require(compatible or data['fallback'], 'fallback-required')
        return {'ready': True, 'fallback': not compatible or state['fallback']}
    elif name == 'fallback-config':
        d.configure_fallback(state)
    elif name == 'volume':
        volume = next((v for v in state['volumes'] if v['Name'] == item), None)
        d.require(volume is not None and state['fallback'], 'volume-conflict')
        expected = {'type': 'none', 'o': 'bind', 'device': volume['Mountpoint']}
        actual = d.api('GET', '/volumes/' + d.quote(item), missing=True)
        if actual is None:
            d.api('POST', '/volumes/create', {'Name': item, 'Driver': 'local', 'DriverOpts': expected, 'Labels': volume.get('Labels')})
        else:
            d.require(actual.get('Options') == expected, 'volume-conflict')
    elif name == 'network':
        network = next((n for n in state['networks'] if n['Name'] == item), None)
        d.require(network is not None and state['fallback'], 'network-conflict')
        body = d.network_request(network)
        actual = d.api('GET', '/networks/' + d.quote(item), missing=True)
        if actual is None:
            d.api('POST', '/networks/create', body)
        else:
            d.require(all(actual.get(k) == v for k, v in body.items()), 'network-conflict')
    elif name == 'load':
        d.require(item in state['archives'], 'archive')
        d.command(['docker', 'load', '--input', state['archives'][item]])
    elif name == 'tag':
        d.require(item in catalogue(state)['tags'], 'image-id')
        image, tag = item.split('\t')
        if image in state['archives']:
            d.command(['docker', 'tag', image, tag])
    elif name == 'pull':
        d.require(container is not None and not d.managed(container), 'inventory-drift')
        ref = state['image_refs'].get(item)
        if not state['committed'].get(item + ':shutdown') and ref:
            d.command(['docker', 'pull', ref])
            d.require(d.api('GET', '/images/' + d.quote(ref) + '/json')['Id'] == container['Image'], 'image-version')
    elif name == 'recreate':
        d.require(not d.managed(container), 'orbit-spec-changed')
        view = d.copy.deepcopy(container)
        view['RecoveryImage'] = state['committed'].get(item + ':shutdown', container['Image'])
        d.recreate(view)
    elif name == 'restore-policy':
        if not (state['fallback'] and d.managed(container)):
            d.api('POST', '/containers/' + d.quote(reference) + '/update', {'RestartPolicy': container.get('HostConfig', {}).get('RestartPolicy', {'Name': 'no'})})
    elif name == 'start':
        d.require(not d.managed(container), 'orbit-spec-changed')
        desired = container.get('State', {}).get('Running', False)
        if bool(d.inspect(reference)['State'].get('Running')) != desired:
            d.command(['docker', 'start', reference] if desired else ['docker', 'stop', '--time', '60', reference])
    elif name == 'catalogue':
        return catalogue(state)
    elif name == 'report':
        result = dict(healthy=True, **d.report(state))
        for old, actual in zip(state['containers'], result['containers']):
            running = data['process_states'].get(old['Config']['Labels']['orbit.process.id']) == 'running' if d.managed(old) else old.get('State', {}).get('Running', False)
            if actual['state'] not in (['running'] if running else ['exited', 'created']) or running and actual['health'] not in (None, 'healthy'):
                result['healthy'] = False
        d.require(result['healthy'], 'container-health')
        if mode == 'restore-policies':
            state.update(fallback=False, recovery='restored')
            d.record(state)
        return result
    else:
        raise d.Refused('input')
    return {'ready': True}


if __name__ == '__main__':
    try:
        public = json.loads(os.environ['ORBIT_DOCKER_INPUT'])
        name = sys.argv[1]
        value = inventory(public['migration'], public['mode'], public['run_id']) if name == 'inventory' else action(name, public['migration'], sys.argv[2] if len(sys.argv) > 2 else None, public['mode'])
        print(json.dumps(value, separators=(',', ':')))
    except (d.Refused, OSError, ValueError, KeyError, TypeError, d.http.client.HTTPException, d.subprocess.SubprocessError) as error:
        print(json.dumps({'error_code': 'docker.migration_failed', 'stage': error.stage if isinstance(error, d.Refused) else 'inventory'}))
        sys.exit(1)
