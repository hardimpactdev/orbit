"""Appended to the recipe library; never emit inspect data or remote command output."""
import fcntl
import http.client
import socket
import time
from urllib.parse import quote

JOURNAL = Path('/var/lib/orbit/docker-migration')
DAEMON = Path('/etc/docker/daemon.json')
DROPIN = Path('/etc/systemd/system/docker.service.d/orbit-docker-migration.conf')
CE = ['docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin',
      'docker-compose-plugin', 'docker-ce-rootless-extras']
STEPS = ['inventory', 'stop all containers', 'retire Docker apt sources with backups in /etc/apt',
         'swap CE packages for recipe docker.io, containerd, docker-buildx, docker-compose-v2',
         'verify engine and every image, container and volume',
         'if unreadable: isolate fresh engine, preserve original volume paths, pull and recreate',
         'reconcile Orbit Processes from stored specs', 'report every container state']


class DockerSocket(http.client.HTTPConnection):
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(120)
        self.sock.connect('/var/run/docker.sock')


def api(method, path, body=None, *, missing=False):
    connection = DockerSocket('localhost', timeout=120)
    try:
        # Latest inspect exposes settings that the downgraded API might otherwise hide.
        connection.request(method, ('' if method == 'GET' else '/v1.52') + path,
                           None if body is None else json.dumps(body), {'Content-Type': 'application/json'})
        response = connection.getresponse()
        data = response.read()
        if missing and response.status == 404:
            return None
        if response.status >= 300:
            raise RecipeFailure('docker-api')
        return json.loads(data) if data else {}
    finally:
        connection.close()


def inspect_container(name, *, missing=False):
    return api('GET', '/containers/' + quote(name, safe='') + '/json', missing=missing)


def docker_sources():
    changes = []
    paths = [APT_ETC / 'sources.list', *sorted((APT_ETC / 'sources.list.d').glob('*.list')),
             *sorted((APT_ETC / 'sources.list.d').glob('*.sources'))]
    for path in paths:
        if not path.is_file():
            continue
        content = path.read_text()
        if 'download.docker.com' not in content:
            continue
        if path.is_symlink():
            raise RecipeFailure('apt-source')
        blocks = re.split(r'(\n\s*\n)', content) if path.suffix == '.sources' else content.splitlines(keepends=True)
        keep = []
        for block in blocks:
            active = '\n'.join(line for line in block.splitlines() if not line.lstrip().startswith('#'))
            if 'download.docker.com' not in active:
                keep.append(block)
                continue
            if path.suffix == '.sources':
                fields = deb822_fields(block)
                uris = fields.get('uris', '').split()
                valid = (uris == ['https://download.docker.com/linux/ubuntu']
                         and set(fields.get('types', '').split()) <= {'deb', 'deb-src'}
                         and re.fullmatch(r'[a-z][a-z0-9-]*', fields.get('suites', ''))
                         and fields.get('components') == 'stable')
            else:
                valid = re.fullmatch(r'\s*deb(?:-src)?\s+(?:\[[^]]+\]\s+)?https://download\.docker\.com/linux/ubuntu/?\s+[a-z][a-z0-9-]*\s+stable\s*(?:#.*)?\n?', block)
            if not valid:
                raise RecipeFailure('apt-source')
        after = ''.join(keep).encode()
        if after != content.encode():
            backup_apt_source(path, content.encode(), 'docker', check_only=True)
            changes.append((path, content.encode(), after))
    return changes


def record(name, value):
    path = JOURNAL / name
    publish(path, json.dumps(value, sort_keys=True).encode(), 0o600)
    with path.open('rb') as stream:
        os.fsync(stream.fileno())
    descriptor = os.open(JOURNAL, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def daemon_bytes():
    if DAEMON.is_symlink():
        raise RecipeFailure('daemon-config')
    return DAEMON.read_bytes() if DAEMON.exists() else None


# Supported fields: moby/moby docker-v29.1.3/api/swagger.yaml (Ubuntu engine API 1.52).
CONFIG_FIELDS = set(('Hostname Domainname User AttachStdin AttachStdout AttachStderr ExposedPorts Tty OpenStdin '
                     'StdinOnce Env Cmd Healthcheck ArgsEscaped Image Volumes WorkingDir Entrypoint NetworkDisabled '
                     'OnBuild Labels StopSignal StopTimeout Shell').split())
HOST_FIELDS = set(('CpuShares Memory CgroupParent BlkioWeight BlkioWeightDevice BlkioDeviceReadBps '
                   'BlkioDeviceWriteBps BlkioDeviceReadIOps BlkioDeviceWriteIOps CpuPeriod CpuQuota CpuRealtimePeriod '
                   'CpuRealtimeRuntime CpusetCpus CpusetMems Devices DeviceCgroupRules DeviceRequests MemoryReservation '
                   'MemorySwap MemorySwappiness NanoCpus OomKillDisable Init PidsLimit Ulimits CpuCount CpuPercent '
                   'IOMaximumIOps IOMaximumBandwidth Binds ContainerIDFile LogConfig NetworkMode PortBindings RestartPolicy '
                   'AutoRemove VolumeDriver VolumesFrom Mounts ConsoleSize Annotations CapAdd CapDrop CgroupnsMode Dns '
                   'DnsOptions DnsSearch ExtraHosts GroupAdd IpcMode Cgroup Links OomScoreAdj PidMode Privileged '
                   'PublishAllPorts ReadonlyRootfs SecurityOpt StorageOpt Tmpfs UTSMode UsernsMode ShmSize Sysctls Runtime '
                   'Isolation MaskedPaths ReadonlyPaths').split())
ENDPOINT_FIELDS = set(('IPAMConfig Links MacAddress Aliases DriverOpts GwPriority NetworkID EndpointID Gateway IPAddress '
                       'IPPrefixLen IPv6Gateway GlobalIPv6Address GlobalIPv6PrefixLen DNSNames').split())


def supported_fields(value, allowed):
    if any(key not in allowed and item for key, item in (value or {}).items()):
        raise RecipeFailure('newer-engine-configuration')


def validate_process(container, payload):
    labels = container['Config'].get('Labels') or {}
    if labels.get('orbit.managed') != 'true':
        return
    spec = payload['process_specs'].get(labels.get('orbit.process.id'))
    if (not spec or labels.get('orbit.process.spec') != spec['hash']
            or container['Name'].lstrip('/') != spec['name']):
        raise RecipeFailure('orbit-spec-changed')
    if (container['HostConfig'].get('NetworkMode', 'default') not in ('default', 'bridge', '')
            or set(container['NetworkSettings']['Networks']) - {'bridge'}):
        raise RecipeFailure('orbit-network-drift')
    for mount in container.get('Mounts', []):
        source = mount['Name'] if mount['Type'] == 'volume' else mount.get('Source')
        if not any(v.get('source') == source and v.get('target') == mount['Destination'] for v in spec['volumes']):
            raise RecipeFailure('orbit-unrecorded-mount')


def validate_container(container, process_ids):
    config, host = container['Config'], container['HostConfig']
    image = config.get('Image', '')
    if not image or image.startswith('sha256:') or re.fullmatch(r'[a-f0-9]{64}', image):
        raise RecipeFailure('unpullable-image')
    labels = config.get('Labels') or {}
    supported_fields(config, CONFIG_FIELDS)
    supported_fields(host, HOST_FIELDS)
    supported_fields(config.get('Healthcheck'), set('Test Interval Timeout Retries StartPeriod StartInterval'.split()))
    for endpoint in container['NetworkSettings']['Networks'].values():
        supported_fields(endpoint, ENDPOINT_FIELDS)
        supported_fields(endpoint.get('IPAMConfig'), set('IPv4Address IPv6Address LinkLocalIPs'.split()))
        if endpoint.get('Links'):
            raise RecipeFailure('unsupported-container-links')
    for mount in host.get('Mounts') or []:
        supported_fields(mount, set('Target Source Type ReadOnly Consistency BindOptions VolumeOptions ImageOptions TmpfsOptions'.split()))
        supported_fields(mount.get('BindOptions'), set('Propagation NonRecursive CreateMountpoint ReadOnlyNonRecursive ReadOnlyForceRecursive'.split()))
        supported_fields(mount.get('VolumeOptions'), set('NoCopy Labels DriverConfig Subpath'.split()))
        supported_fields((mount.get('VolumeOptions') or {}).get('DriverConfig'), {'Name', 'Options'})
        supported_fields(mount.get('TmpfsOptions'), set('SizeBytes Mode Options'.split()))
    logging = host.get('LogConfig') or {}
    if logging.get('Type') not in (None, '', 'json-file', 'local', 'none', 'journald', 'syslog', 'gelf', 'fluentd', 'awslogs', 'splunk', 'gcplogs'):
        raise RecipeFailure('unsupported-logging-plugin')
    if labels.get('com.docker.swarm.service.id'):
        raise RecipeFailure('swarm')
    if labels.get('orbit.managed') == 'true':
        if labels.get('orbit.container.kind') != 'process' or labels.get('orbit.process.id') not in process_ids:
            raise RecipeFailure('orbit-spec-missing')
    if (container.get('State', {}).get('Paused') or host.get('AutoRemove') or host.get('VolumesFrom') or host.get('Links')
            or any(str(host.get(key, '')).startswith('container:') for key in ('NetworkMode', 'PidMode', 'IpcMode'))):
        raise RecipeFailure('unsupported-container')
    for mount in container.get('Mounts', []):
        if mount['Type'] not in ('bind', 'volume', 'tmpfs'):
            raise RecipeFailure('unsupported-mount')
        if mount['Type'] in ('bind', 'volume') and not Path(mount['Source']).exists():
            raise RecipeFailure('missing-mount')
    if api('GET', '/containers/' + container['Id'] + '/changes'):
        raise RecipeFailure('writable-layer')


def inventory(payload):
    info = api('GET', '/info')
    raw = daemon_bytes()
    config = json.loads(raw) if raw else {}
    if (info['DockerRootDir'] != '/var/lib/docker' or any(key in config for key in ('data-root', 'hosts', 'containerd'))
            or DROPIN.exists()):
        raise RecipeFailure('custom-daemon')
    containers = [inspect_container(item['Id']) for item in api('GET', '/containers/json?all=1')]
    images = [api('GET', '/images/' + item['Id'] + '/json') for item in api('GET', '/images/json?all=1')]
    image_refs = {}
    for container in containers:
        validate_container(container, payload['process_ids'])
        validate_process(container, payload)
        if ((container['Config'].get('Labels') or {}).get('orbit.managed') == 'true'
                and container['HostConfig'].get('Runtime', 'runc') != info.get('DefaultRuntime', 'runc')):
            raise RecipeFailure('orbit-runtime-drift')
        image = next((image for image in images if image['Id'] == container['Image']), None)
        if not image or image.get('Architecture') != payload['architecture'] or image.get('Os') != 'linux':
            raise RecipeFailure('foreign-image-platform')
        references = image.get('RepoDigests') or []
        reference = container['Config']['Image']
        if '@sha256:' in reference:
            references = [reference]
        pinned = next((ref for ref in references if not run(['docker', 'manifest', 'inspect', ref], required=False).returncode), None)
        if pinned is None:
            raise RecipeFailure('unpullable-image')
        image_refs[container['Id']] = pinned
    volumes = api('GET', '/volumes')['Volumes'] or []
    for volume in volumes:
        if volume['Driver'] != 'local' or volume.get('Options') or not Path(volume['Mountpoint']).is_dir():
            raise RecipeFailure('unsupported-volume')
    networks = [api('GET', '/networks/' + item['Id']) for item in api('GET', '/networks')]
    for network in networks:
        if network['Name'] not in ('bridge', 'host', 'none') and (
                network['Driver'] != 'bridge' or network.get('Ingress') or network.get('Scope') != 'local'
                or network.get('ConfigOnly') or (network.get('ConfigFrom') or {}).get('Network')
                or network.get('IPAM', {}).get('Driver', 'default') != 'default'):
            raise RecipeFailure('unsupported-network')
        if network['Name'] not in ('bridge', 'host', 'none'):
            network_request(network)
    return {'recipe': payload['recipe'], 'containers': containers, 'volumes': volumes, 'networks': networks,
            'images': images, 'image_refs': image_refs, 'daemon': None if raw is None else raw.hex(),
            'fallback': False, 'swapped': False, 'prepared': False, 'stopped': False}


def package_arguments(payload, installed):
    return ['docker.io=' + payload['docker_version'], 'containerd=2.2.2-0ubuntu1.1',
            'docker-buildx', 'docker-compose-v2', *[name + '-' for name in CE if name in installed]]


def swap(payload, state):
    # Suppress package-triggered service startup; preserve a pre-existing policy rather than replacing it.
    marker = JOURNAL / 'restart-policy.json'
    policy_content = b'#!/bin/sh\nexit 101\n'
    if marker.exists():
        if POLICY.is_symlink() or (POLICY.exists() and POLICY.read_bytes() != policy_content):
            raise RecipeFailure('restart-policy')
        POLICY.unlink(missing_ok=True)
        marker.unlink()
    if POLICY.exists() or POLICY.is_symlink():
        raise RecipeFailure('restart-policy')
    with temporary_indexes(snapshot_source(payload['snapshot'])) as options:
        arguments = package_arguments(payload, packages())
        base = ['apt-get', 'install', '--no-install-recommends', '--allow-downgrades',
                '-o', 'Dpkg::Options::=--force-confold', *options]
        simulation = run([*base, '--simulate', '--', *arguments]).stdout
        for line in simulation.splitlines():
            if line.startswith('Remv ') and line.split()[1] not in CE:
                raise RecipeFailure('unrelated-package-removal')
        record('restart-policy.json', {'owned': True})
        publish(POLICY, policy_content, 0o755)
        try:
            run([*base, '--yes', '--', *arguments], env=dict(os.environ, DEBIAN_FRONTEND='noninteractive', NEEDRESTART_SUSPEND='1'))
            run(['apt-get', 'check'])
        finally:
            POLICY.unlink()
            marker.unlink()
    installed = packages()
    if (installed.get('docker.io') != payload['docker_version'] or installed.get('containerd') != '2.2.2-0ubuntu1.1'
            or any(name in installed for name in CE) or not all(name in installed for name in ('docker-buildx', 'docker-compose-v2'))):
        raise RecipeFailure('package-verification')
    state['swapped'] = True
    record('inventory.json', state)


def readable(state):
    try:
        run(['systemctl', 'start', 'docker'], timeout=120)
        api('GET', '/info')
        if any(inspect_container(c['Id'], missing=True) is None for c in state['containers']):
            return False
        if any(api('GET', '/images/' + image['Id'] + '/json', missing=True) is None for image in state['images']):
            return False
        if any(api('GET', '/volumes/' + quote(v['Name'], safe=''), missing=True) is None for v in state['volumes']):
            return False
        return True
    except (RecipeFailure, OSError, http.client.HTTPException):
        return False


def network_request(network):
    body = {key: network[key] for key in
            ('Name', 'Driver', 'Internal', 'Attachable', 'EnableIPv4', 'EnableIPv6', 'IPAM', 'Options', 'Labels') if key in network}
    options = dict(network.get('Options') or {})
    bridge = options.get('com.docker.network.bridge.name')
    if not bridge:
        if not re.fullmatch('[a-f0-9]{64}', network['Id']):
            raise RecipeFailure('unsupported-network')
        bridge = 'br-' + network['Id'][:12]
    if not re.fullmatch('[A-Za-z0-9_.-]{1,15}', bridge) or bridge in ('.', '..'):
        raise RecipeFailure('unsupported-network')
    # Stopping dockerd leaves this interface and its connected routes behind. Let Docker
    # reuse/validate it rather than creating a competing bridge with the same subnet.
    body['Options'] = dict(options, **{'com.docker.network.bridge.name': bridge})
    return body


def fresh_engine(state):
    run(['systemctl', 'stop', 'docker.service', 'docker.socket'])
    content = ('[Unit]\nRequires=\nRequires=docker.socket\nWants=\nWants=network-online.target\n'
               '[Service]\nExecStart=\nExecStart=/usr/bin/dockerd --host=fd:// --data-root='
               + str(JOURNAL / 'engine') + '\n').encode()
    if DROPIN.exists() and DROPIN.read_bytes() != content:
        raise RecipeFailure('custom-daemon')
    DROPIN.parent.mkdir(parents=True, exist_ok=True)
    publish(DROPIN, content)
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'start', 'docker'], timeout=120)
    api('GET', '/info')
    for volume in state['volumes']:
        expected = {'type': 'none', 'o': 'bind', 'device': volume['Mountpoint']}
        existing = api('GET', '/volumes/' + quote(volume['Name'], safe=''), missing=True)
        if existing is None:
            api('POST', '/volumes/create', {'Name': volume['Name'], 'Driver': 'local',
                                          'DriverOpts': expected, 'Labels': volume.get('Labels')})
        elif existing.get('Options') != expected:
            raise RecipeFailure('volume-conflict')
    for network in state['networks']:
        if network['Name'] in ('bridge', 'host', 'none'):
            continue
        body = network_request(network)
        existing = api('GET', '/networks/' + quote(network['Name'], safe=''), missing=True)
        if existing is None:
            api('POST', '/networks/create', body)
        elif any(existing.get(key) != value for key, value in body.items()):
            raise RecipeFailure('network-conflict')


def recreate(container, image_ref):
    name = container['Name'].lstrip('/')
    body = dict(container['Config'], HostConfig=dict(container['HostConfig']))
    body['Labels'] = dict(body.get('Labels') or {}, **{'orbit.docker.migration': container['Id']})
    # The old engine's anonymous volume names must not silently become new empty volumes.
    binds = list(body['HostConfig'].get('Binds') or [])
    mounts = [dict(mount) for mount in body['HostConfig'].get('Mounts') or []]
    for mount in mounts:
        if mount['Type'] == 'volume' and not mount.get('Source'):
            mount['Source'] = next(m['Name'] for m in container['Mounts'] if m['Destination'] == mount['Target'])
    if mounts:
        body['HostConfig']['Mounts'] = mounts
    destinations = {item.split(':')[1] for item in binds} | {m['Target'] for m in mounts}
    for mount in container.get('Mounts', []):
        if mount['Type'] == 'volume' and mount['Destination'] not in destinations:
            binds.append(mount['Name'] + ':' + mount['Destination'] + (':rw' if mount['RW'] else ':ro'))
    body['HostConfig']['Binds'] = binds
    endpoints = {}
    for network_name, endpoint in container['NetworkSettings']['Networks'].items():
        endpoints[network_name] = {key: endpoint[key] for key in ('IPAMConfig', 'Aliases', 'DriverOpts', 'MacAddress', 'GwPriority') if endpoint.get(key) is not None}
    body['NetworkingConfig'] = {'EndpointsConfig': endpoints}
    existing = inspect_container(name, missing=True)
    if existing is not None:
        config = {key: value for key, value in body.items() if key not in ('HostConfig', 'NetworkingConfig')}
        if (existing['Image'] != container['Image'] or existing['Config'] != config
                or any(existing['HostConfig'].get(key) != value for key, value in body['HostConfig'].items())):
            raise RecipeFailure('container-conflict')
        return
    run(['docker', 'pull', image_ref], timeout=600)
    if api('GET', '/images/' + quote(image_ref, safe='') + '/json')['Id'] != container['Image']:
        raise RecipeFailure('image-version')
    if '@' not in container['Config']['Image']:
        run(['docker', 'tag', image_ref, container['Config']['Image']])
    api('POST', '/containers/create?name=' + quote(name, safe=''), body)


def public_report(state):
    containers = []
    for old in state['containers']:
        try:
            current = inspect_container(old['Name'], missing=True)
            status = current['State']['Status'] if current else 'missing'
        except (RecipeFailure, OSError, http.client.HTTPException):
            current, status = None, 'unreadable'
        health = current['State'].get('Health', {}).get('Status') if current else None
        containers.append({'name': old['Name'].lstrip('/'), 'state': status, 'health': health})
    return {'fallback': state['fallback'], 'containers': containers,
            'process_ids': sorted({c['Config']['Labels']['orbit.process.id'] for c in state['containers']
                                   if (c['Config'].get('Labels') or {}).get('orbit.managed') == 'true'})}


def migrate(payload, mode):
    path = JOURNAL / 'inventory.json'
    state = json_file(path) if path.exists() else None
    if state and state['recipe'] != payload['recipe']:
        raise RecipeFailure('recipe-mismatch')
    if state is None:
        if 'docker-ce' not in packages():
            raise RecipeFailure('not-docker-ce')
        state = inventory(payload)
    for container in state['containers']:
        validate_process(container, payload)
    sources = docker_sources()
    if mode == 'plan':
        return {'steps': STEPS, 'containers': [c['Name'].lstrip('/') for c in state['containers']],
                'recreate': [{'name': c['Name'].lstrip('/'), 'image': c['Config']['Image'], 'pull': state['image_refs'][c['Id']],
                              'runtime': c['HostConfig'].get('Runtime', 'runc'),
                              'source': 'stored Process spec' if (c['Config'].get('Labels') or {}).get('orbit.managed') == 'true' else 'inventory',
                              'restart': c['State']['Running']} for c in state['containers']],
                'fallback_data_root': str(JOURNAL / 'engine'), 'fallback_systemd_dropin': str(DROPIN),
                'volume_backings': [{'name': v['Name'], 'path': v['Mountpoint']} for v in state['volumes']],
                'packages': package_arguments(payload, packages()),
                'source_backups': [str(APT_ETC / (p.name + '.orbit-docker-backup')) for p, _, _ in sources],
                'resuming': path.exists(), 'daemon_json': 'unchanged', 'volume_data': 'never deleted'}
    if daemon_bytes() != (None if state['daemon'] is None else bytes.fromhex(state['daemon'])):
        raise RecipeFailure('daemon-changed')
    if not path.exists():
        record('inventory.json', state)
    if mode == 'report':
        report = public_report(state)
        deadline = time.monotonic() + 120
        while any(c['health'] == 'starting' for c in report['containers']) and time.monotonic() < deadline:
            time.sleep(2)
            report = public_report(state)
        report['healthy'] = True
        for old, current in zip(state['containers'], report['containers'], strict=True):
            process_id = (old['Config'].get('Labels') or {}).get('orbit.process.id')
            expected_running = payload['process_states'].get(process_id) == 'running' if process_id else old['State']['Running']
            if (current['state'] in ('missing', 'unreadable') or expected_running != (current['state'] == 'running')
                    or (expected_running and current['health'] in ('starting', 'unhealthy'))):
                report['healthy'] = False
        return report
    if not state['prepared']:
        # Before every resumed transaction stop anything that may have auto-started.
        try:
            current = api('GET', '/containers/json?all=1')
        except (RecipeFailure, OSError, http.client.HTTPException):
            # A previous attempt may have stopped the service or failed inside apt.
            if not state['stopped']:
                run(['systemctl', 'start', 'docker'], timeout=120)
                current = api('GET', '/containers/json?all=1')
            else:
                current = []
        known = {c['Id'] for c in state['containers']}
        if not state['fallback'] and any(c['Id'] not in known for c in current):
            raise RecipeFailure('inventory-drift')
        for container in current:
            run(['docker', 'stop', '--time', '60', container['Id']], timeout=90)
        if not state['fallback']:
            for container in current:
                # Shutdown itself can write to the layer. Never discover that only after downgrading.
                if api('GET', '/containers/' + container['Id'] + '/changes'):
                    raise RecipeFailure('writable-layer')
        state['stopped'] = True
        record('inventory.json', state)
        run(['systemctl', 'stop', 'docker.service', 'docker.socket', 'containerd.service'])
        for source, before, after in sources:
            backup_apt_source(source, before, 'docker')
            if after.strip():
                publish(source, after)
            else:
                source.unlink()
        if not state['swapped']:
            swap(payload, state)
        if not state['fallback'] and not readable(state):
            # Persist the choice before touching service configuration, so interruptions cannot reuse old metadata.
            state['fallback'] = True
            record('inventory.json', state)
        if state['fallback']:
            fresh_engine(state)
            for container in state['containers']:
                if (container['Config'].get('Labels') or {}).get('orbit.managed') != 'true':
                    recreate(container, state['image_refs'][container['Id']])
        for container in state['containers']:
            if (container['Config'].get('Labels') or {}).get('orbit.managed') != 'true' and container['State']['Running']:
                run(['docker', 'start', container['Name'].lstrip('/')])
        state['prepared'] = True
        record('inventory.json', state)
    return public_report(state)


def migration_main():
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    mode, document = sys.argv[1:]
    payload = json.loads(document)
    try:
        platform_check(payload['architecture'])
        if mode != 'plan':
            JOURNAL.mkdir(parents=True, exist_ok=True, mode=0o700)
        if JOURNAL.exists():
            for path in (JOURNAL, JOURNAL.parent):
                if path.is_symlink() or path.stat().st_uid != 0 or path.stat().st_mode & 0o022:
                    raise RecipeFailure('journal-permissions')
            if JOURNAL.stat().st_mode & 0o077:
                raise RecipeFailure('journal-permissions')
            inventory_path = JOURNAL / 'inventory.json'
            if inventory_path.exists() and (inventory_path.is_symlink() or not inventory_path.is_file()
                    or inventory_path.stat().st_uid != 0 or inventory_path.stat().st_mode & 0o077):
                raise RecipeFailure('journal-permissions')
        if mode == 'plan':
            print(json.dumps(migrate(payload, mode)))
        else:
            with open(JOURNAL / 'lock', 'a') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                print(json.dumps(migrate(payload, mode)))
        return 0
    except Exception as error:
        # Fixed stage names only. Docker and apt errors can include credentials.
        stage = str(error) if isinstance(error, RecipeFailure) and re.fullmatch('[a-z-]+', str(error)) else 'remote'
        print(json.dumps({'failure': 'docker-migration:' + stage, 'resumable': True}))
        return 1


if __name__ == '__main__':
    sys.exit(migration_main())
