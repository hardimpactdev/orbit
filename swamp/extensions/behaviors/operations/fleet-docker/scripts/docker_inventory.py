"""Private Docker inventory and package policy. Native history owns orchestration."""
import copy
import http.client
import json
import os
import re
import shutil
import socket
import stat
import subprocess
import tempfile
import tomllib
import urllib.parse
from pathlib import Path

JOURNAL = Path('/var/lib/orbit/docker-migration')
DAEMON = Path('/etc/docker/daemon.json')
DROPIN = Path('/etc/systemd/system/docker.service.d/orbit-docker-migration.conf')
APT = Path('/etc/apt')
POLICY = Path('/usr/sbin/policy-rc.d')
CE = ('docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin', 'docker-compose-plugin', 'docker-ce-rootless-extras', 'docker-model-plugin')
CONFIG = {'Hostname', 'Domainname', 'User', 'AttachStdin', 'AttachStdout', 'AttachStderr', 'ExposedPorts', 'Tty', 'OpenStdin', 'StdinOnce', 'Env', 'Cmd', 'Healthcheck', 'ArgsEscaped', 'Image', 'Volumes', 'WorkingDir', 'Entrypoint', 'NetworkDisabled', 'OnBuild', 'Labels', 'StopSignal', 'StopTimeout', 'Shell'}
HOST = {'CpuShares', 'Memory', 'CgroupParent', 'BlkioWeight', 'BlkioWeightDevice', 'BlkioDeviceReadBps', 'BlkioDeviceWriteBps', 'BlkioDeviceReadIOps', 'BlkioDeviceWriteIOps', 'CpuPeriod', 'CpuQuota', 'CpuRealtimePeriod', 'CpuRealtimeRuntime', 'CpusetCpus', 'CpusetMems', 'Devices', 'DeviceCgroupRules', 'DeviceRequests', 'MemoryReservation', 'MemorySwap', 'MemorySwappiness', 'NanoCpus', 'OomKillDisable', 'Init', 'PidsLimit', 'Ulimits', 'CpuCount', 'CpuPercent', 'IOMaximumIOps', 'IOMaximumBandwidth', 'Binds', 'ContainerIDFile', 'LogConfig', 'NetworkMode', 'PortBindings', 'RestartPolicy', 'AutoRemove', 'VolumeDriver', 'VolumesFrom', 'Mounts', 'ConsoleSize', 'Annotations', 'CapAdd', 'CapDrop', 'CgroupnsMode', 'Dns', 'DnsOptions', 'DnsSearch', 'ExtraHosts', 'GroupAdd', 'IpcMode', 'Cgroup', 'Links', 'OomScoreAdj', 'PidMode', 'Privileged', 'PublishAllPorts', 'ReadonlyRootfs', 'SecurityOpt', 'StorageOpt', 'Tmpfs', 'UTSMode', 'UsernsMode', 'ShmSize', 'Sysctls', 'Runtime', 'Isolation', 'MaskedPaths', 'ReadonlyPaths'}
class Refused(Exception):
    def __init__(self, stage): self.stage = stage

def require(condition, stage):
    if not condition:
        raise Refused(stage)

def command(argv, required=True):
    result = subprocess.run(argv, check=False, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                            env={**os.environ, 'LC_ALL': 'C', 'DEBIAN_FRONTEND': 'noninteractive', 'NEEDRESTART_SUSPEND': '1'})
    require(not required or result.returncode == 0, 'remote')
    return result.returncode, result.stdout.decode('utf-8', errors='strict')

class DockerSocket(http.client.HTTPConnection):
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect('/var/run/docker.sock')

def api(method, path, body=None, missing=False):
    conn = DockerSocket('localhost', timeout=120)
    try:
        conn.request(method, ('' if method == 'GET' else '/v1.52') + path,
                     body=None if body is None else json.dumps(body),
                     headers={} if body is None else {'Content-Type':'application/json'})
        response = conn.getresponse()
        if missing and response.status == 404:
            return None
        require(200 <= response.status < 300, 'docker-api')
        data = response.read(64 * 1024 * 1024 + 1)
        require(len(data) <= 64 * 1024 * 1024, 'docker-api')
        return json.loads(data) if data else {}
    except (OSError, ValueError, http.client.HTTPException):
        raise Refused('docker-api') from None
    finally:
        conn.close()

def quote(value): return urllib.parse.quote(value, safe='')
def inspect(reference, missing=False): return api('GET', '/containers/' + quote(reference.lstrip('/')) + '/json', missing=missing)
def managed(c): return (c.get('Config', {}).get('Labels') or {}).get('orbit.managed') == 'true'
def real_directory(raw, failure):
    require(isinstance(raw, str) and os.path.isabs(raw), failure)
    path = Path(raw).resolve(strict=True)
    require(path != Path('/') and path.is_dir(), failure)
    return str(path)

def private_path(path, directory, parent=False):
    meta = path.lstat()
    require((stat.S_ISDIR(meta.st_mode) if directory else stat.S_ISREG(meta.st_mode)) and
            meta.st_uid == os.geteuid() and not stat.S_IMODE(meta.st_mode) & (0o022 if parent else 0o077), 'journal-permissions')

def journal(create):
    if not JOURNAL.exists() and not JOURNAL.is_symlink():
        if not create:
            return
        JOURNAL.mkdir(mode=0o700, parents=True)
    private_path(JOURNAL.parent, True, True)
    private_path(JOURNAL, True)
    if (JOURNAL/'inventory.json').exists() or (JOURNAL/'inventory.json').is_symlink():
        private_path(JOURNAL/'inventory.json', False)

def atomic(path, content, mode=0o600):
    path = Path(path)
    if path.exists() or path.is_symlink():
        require(stat.S_ISREG(path.lstat().st_mode) and path.lstat().st_uid == os.geteuid(), 'journal-permissions')
    descriptor, temporary = tempfile.mkstemp(prefix='.orbit-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as output:
            os.fchmod(output.fileno(), mode)
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

def record(state): atomic(JOURNAL/'inventory.json', json.dumps(state).encode())
def daemon_bytes():
    require(not DAEMON.is_symlink(), 'daemon-config')
    return DAEMON.read_bytes() if DAEMON.exists() else None

def same_daemon(state):
    raw = daemon_bytes()
    return (raw.hex() if raw is not None else None) == state['daemon']

def supported(value, names): require(not any(v and k not in names for k,v in (value or {}).items()), 'newer-engine-configuration')
def process(c, data):
    if not managed(c):
        return
    labels = c['Config']['Labels']
    spec = data['process_specs'].get(labels.get('orbit.process.id'))
    require(spec is not None and labels.get('orbit.process.spec') == spec['hash'] and c['Name'].lstrip('/') == spec['name'], 'orbit-spec-changed')
    require(c.get('HostConfig', {}).get('NetworkMode', '') in ('','default','bridge') and
            all(n == 'bridge' for n in c.get('NetworkSettings', {}).get('Networks', {})), 'orbit-network-drift')
    for mount in c.get('Mounts', []):
        source = mount.get('Name') if mount['Type'] == 'volume' else mount.get('Source')
        require(any(v.get('source') == source and v.get('target') == mount['Destination'] for v in spec['volumes']), 'orbit-unrecorded-mount')

def container(c, data):
    config, host = c.get('Config', {}), c.get('HostConfig', {})
    labels = config.get('Labels') or {}
    require(config.get('Image'), 'missing-image')
    supported(config, CONFIG)
    supported(host, HOST)
    supported(config.get('Healthcheck'), {'Test', 'Interval', 'Timeout', 'Retries', 'StartPeriod', 'StartInterval'})
    for endpoint in c.get('NetworkSettings', {}).get('Networks', {}).values():
        supported(endpoint, {'IPAMConfig', 'Links', 'MacAddress', 'Aliases', 'DriverOpts', 'GwPriority', 'NetworkID', 'EndpointID', 'Gateway', 'IPAddress', 'IPPrefixLen', 'IPv6Gateway', 'GlobalIPv6Address', 'GlobalIPv6PrefixLen', 'DNSNames'})
        supported(endpoint.get('IPAMConfig'), {'IPv4Address','IPv6Address','LinkLocalIPs'})
        require(not endpoint.get('Links'), 'unsupported-container-links')
    for mount in host.get('Mounts') or []:
        supported(mount, {'Target', 'Source', 'Type', 'ReadOnly', 'Consistency', 'BindOptions', 'VolumeOptions', 'ImageOptions', 'TmpfsOptions'})
        supported(mount.get('BindOptions'), {'Propagation', 'NonRecursive', 'CreateMountpoint', 'ReadOnlyNonRecursive', 'ReadOnlyForceRecursive'})
        supported(mount.get('VolumeOptions'), {'NoCopy','Labels','DriverConfig','Subpath'})
        supported((mount.get('VolumeOptions') or {}).get('DriverConfig'), {'Name','Options'})
        supported(mount.get('TmpfsOptions'), {'SizeBytes','Mode','Options'})
    require((host.get('LogConfig') or {}).get('Type','') in ('','json-file','local','none','journald','syslog','gelf','fluentd','awslogs','splunk','gcplogs'), 'unsupported-logging-plugin')
    require(not labels.get('com.docker.swarm.service.id'), 'swarm')
    require(not managed(c) or (labels.get('orbit.container.kind') == 'process' and labels.get('orbit.process.id') in data['process_ids']), 'orbit-spec-missing')
    require(not c.get('State', {}).get('Paused') and not any(host.get(k) for k in ('AutoRemove','VolumesFrom','Links')) and
            not any((host.get(k) or '').startswith('container:') for k in ('NetworkMode','PidMode','IpcMode')), 'unsupported-container')
    for mount in c.get('Mounts') or []:
        require(mount['Type'] in ('bind','volume','tmpfs'), 'unsupported-mount')
        require(mount['Type'] not in ('bind','volume') or Path(mount['Source']).exists(), 'missing-mount')

def network_request(network):
    body = {k:copy.deepcopy(network[k]) for k in ('Name','Driver','Internal','Attachable','EnableIPv4','EnableIPv6','IPAM','Options','Labels') if k in network}
    options = body.get('Options') or {}
    bridge = options.get('com.docker.network.bridge.name')
    if not bridge:
        require(re.fullmatch('[0-9a-fA-F]{64}', network.get('Id','')), 'unsupported-network')
        bridge = 'br-' + network['Id'][:12]
    require(re.fullmatch('[A-Za-z0-9_.-]{1,15}', bridge) and bridge not in ('.','..'), 'unsupported-network')
    options['com.docker.network.bridge.name'] = bridge
    body['Options'] = options
    return body

def defaults(info): return {k:info.get(k) for k in ('DefaultRuntime','LoggingDriver','CgroupDriver')}
def collect(data):
    info = api('GET','/info')
    raw = daemon_bytes()
    config = json.loads(raw) if raw else {}
    require('hosts' not in config and 'containerd' not in config and not DROPIN.exists(), 'custom-daemon')
    for unit in ('docker.service','containerd.service'):
        require(not command(['systemctl','show','--property=DropInPaths','--value',unit])[1].strip(), 'custom-daemon')
        fragment = command(['systemctl','show','--property=FragmentPath','--value',unit])[1].strip()
        require(not fragment or fragment in ('/usr/lib/systemd/system/'+unit,'/lib/systemd/system/'+unit), 'custom-daemon')
    docker = real_directory(info.get('DockerRootDir'), 'storage-root')
    require('data-root' not in config or real_directory(config['data-root'],'storage-root') == docker, 'storage-root')
    code, dump = command(['containerd','config','dump'], False)
    require(code == 0, 'containerd-root')
    try:
        containerd = real_directory(tomllib.loads(dump).get('root'), 'containerd-root')
    except ValueError:
        raise Refused('containerd-root') from None
    containers = [inspect(c['Id']) for c in api('GET','/containers/json?all=1')]
    images = [api('GET','/images/'+quote(i['Id'])+'/json') for i in api('GET','/images/json?all=1')]
    refs = {}
    for c in containers:
        container(c, data)
        process(c, data)
        require(not managed(c) or c['HostConfig'].get('Runtime','runc') == info.get('DefaultRuntime','runc'), 'orbit-runtime-drift')
        image = next((i for i in images if i['Id'] == c['Image']), None)
        require(image is not None and image.get('Architecture') == data['architecture'] and image.get('Os') == 'linux', 'foreign-image-platform')
        candidates = [c['Config']['Image']] if '@sha256:' in c['Config']['Image'] else image.get('RepoDigests') or []
        refs[c['Id']] = next((ref for ref in candidates if command(['docker','manifest','inspect',ref], False)[0] == 0), None)
    volumes = api('GET','/volumes').get('Volumes') or []
    for v in volumes:
        require(v['Driver'] == 'local' and not v.get('Options') and Path(v['Mountpoint']).is_dir(), 'unsupported-volume')
    networks = [api('GET','/networks/'+quote(n['Id'])) for n in api('GET','/networks')]
    for n in networks:
        if n['Name'] not in ('bridge','host','none'):
            require(n['Driver'] == 'bridge' and not n.get('Ingress') and n['Scope'] == 'local' and not n.get('ConfigOnly') and
                    not (n.get('ConfigFrom') or {}).get('Network') and (n.get('IPAM') or {}).get('Driver','default') == 'default', 'unsupported-network')
            network_request(n)
    for c in containers:
        if managed(c):
            c['Config'].pop('Env', None)
    return {'recipe': data['recipe'], 'containers': containers, 'volumes': volumes, 'networks': networks, 'images': images, 'image_refs': refs,
                'driver': info.get('Driver'), 'metadata_roots': {'docker':docker,'containerd':containerd}, 'defaults': defaults(info), 'archives': {}, 'committed': {},
                'stage': 'inventory', 'daemon': raw.hex() if raw is not None else None, 'fallback': False, 'swapped': False, 'prepared': False, 'stopped': False,
                'swapping': False, 'images_preserved': False, 'metadata_backed_up': False, 'policies_disabled': False}

def installed():
    output = command(['dpkg-query','-W','-f=' + '$' + '{binary:Package}\\t' + '$' + '{Version}\\t' + '$' + '{Status}\\n'])[1]
    return {parts[0].split(':')[0]:parts[1] for line in output.splitlines() if len(parts := line.split('\t')) == 3 and parts[2] == 'install ok installed'}

def report(state):
    containers = []
    for old in state['containers']:
        try:
            c = inspect(old['Name'], True)
            containers.append({'name': old['Name'].lstrip('/'), 'state': c.get('State',{}).get('Status','') if c else 'missing',
                                   'health': ((c or {}).get('State',{}).get('Health') or {}).get('Status')})
        except Refused:
            containers.append({'name': old['Name'].lstrip('/'), 'state': 'unreadable', 'health': None})
    return {'fallback': state['fallback'], 'containers': containers,
                'process_ids': sorted({c['Config']['Labels']['orbit.process.id'] for c in state['containers'] if managed(c)})}

def archive_images(state):
    used = {c['Image'] for c in state['containers'] if not state['image_refs'].get(c['Id'])}
    return [image for image in state['images'] if image['Id'] in used]

def space(state):
    require(all(isinstance(i.get('Size'), int) and i['Size'] >= 0 for i in archive_images(state)), 'image-size')
    image_bytes = sum(i['Size'] for i in archive_images(state) if i['Id'] not in state['archives'])
    commit_bytes = 0
    if not state.get('images_preserved'):
        for c in state['containers']:
            if managed(c):
                continue
            committed = state['committed'].get(c['Id']+':shutdown')
            if committed in state['archives']:
                continue
            size = api('GET','/containers/'+quote(c['Id'])+'/json?size=1')
            require(all(isinstance(size.get(k), int) and size[k] >= 0 for k in ('SizeRootFs','SizeRw')), 'image-size')
            commit_bytes += size['SizeRootFs'] + size['SizeRw'] * (1 if committed else 2)
    disk = os.statvfs(JOURNAL)
    return disk.f_bavail * disk.f_frsize >= image_bytes + commit_bytes + 2 * 1024**3

def archive(state, image):
    if image in state['archives']:
        return
    require('/' not in image and '\x00' not in image, 'image-id')
    target = JOURNAL/('image-'+image.replace(':','-')+'.tar')
    temporary = target.with_suffix('.partial')
    require(not temporary.is_symlink(), 'archive')
    command(['docker','save','--output',str(temporary),image])
    os.chmod(temporary,0o600)
    with temporary.open('rb') as f:
        os.fsync(f.fileno())
    os.replace(temporary,target)
    state['archives'][image] = str(target)
    record(state)

def backup(state):
    if state.get('metadata_backed_up'):
        return
    def visit(root, path, target):
        if path.is_symlink() or not path.exists():
            return
        relative = path.relative_to(root)
        if '_data' in relative.parts:
            return
        if path.is_dir():
            for child in path.iterdir():
                visit(root,child,target)
        elif path.is_file():
            image = relative.parts[0] == 'image' and any(n in relative.parts for n in ('imagedb','layerdb'))
            if not image and path.name not in ('metadata.db','meta.db','local-kv.db') and not (relative.parts[0] == 'containers' and path.name in ('config.v2.json','hostconfig.json')):
                return
            destination = target/relative
            destination.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
            with path.open('rb') as source:
                descriptor = os.open(destination,os.O_WRONLY|os.O_CREAT|os.O_TRUNC|os.O_NOFOLLOW,0o600)
                with os.fdopen(descriptor,'wb') as output:
                    shutil.copyfileobj(source,output)
                    output.flush()
                    os.fsync(output.fileno())
    for name, raw in state['metadata_roots'].items():
        root = Path(raw)
        target = JOURNAL/'metadata'/name
        for part in ('volumes/metadata.db','network/files/local-kv.db','io.containerd.metadata.v1.bolt/meta.db','containerd/daemon/io.containerd.metadata.v1.bolt/meta.db','image'):
            visit(root,root/part,target)
        directory = root/'containers'
        if directory.is_dir() and not directory.is_symlink():
            for child in directory.iterdir():
                if child.is_symlink():
                    continue
                for filename in ('config.v2.json','hostconfig.json'):
                    visit(root,child/filename,target)
    state['metadata_backed_up'] = True
    record(state)

def source_changes():
    changes = []
    directory = APT/'sources.list.d'
    paths = [APT/'sources.list'] + sorted(directory.glob('*.sources')) + sorted(directory.glob('*.list'))
    for path in paths:
        if not path.is_file():
            continue
        raw = path.read_text()
        if 'download.docker.com' not in raw:
            continue
        require(not path.is_symlink(), 'apt-source')
        blocks = re.split('(\n\\s*\n)',raw) if path.suffix == '.sources' else raw.splitlines(keepends=True)
        after = ''
        for block in blocks:
            active = '\n'.join(line for line in block.splitlines() if not line.lstrip().startswith('#'))
            if 'download.docker.com' not in active:
                after += block
                continue
            if path.suffix == '.sources':
                fields, previous = {}, None
                for line in block.splitlines():
                    if not line.strip() or line.startswith('#'):
                        continue
                    if line[0].isspace():
                        require(previous is not None, 'apt-source')
                        fields[previous] += ' '+line.strip()
                        continue
                    require(':' in line,'apt-source')
                    key,value = line.split(':',1)
                    key = key.lower()
                    require(re.fullmatch('[a-z][a-z0-9-]*',key) and key not in fields,'apt-source')
                    fields[key] = value.strip()
                    previous = key
                uris,suites,components,types = (fields.get(k,'').split() for k in ('uris','suites','components','types'))
            else:
                match = re.fullmatch(r'\s*(deb(?:-src)?)\s+(?:\[[^]]+\]\s+)?(\S+)\s+(\S+)\s+([^#]+?)(?:\s*#.*)?\s*',block)
                require(match is not None,'apt-source')
                types,uris,suites,components = [match[1]],[match[2]],[match[3]],match[4].split()
            require(uris and all(uri.rstrip('/') == 'https://download.docker.com/linux/ubuntu' for uri in uris) and
                    len(suites) == 1 and re.fullmatch('[a-z][a-z0-9-]*',suites[0]) and components == ['stable'] and types and all(t in ('deb','deb-src') for t in types),'apt-source')
        if after != raw:
            target = APT/(path.name+'.orbit-docker-backup')
            require(not target.is_symlink() and (not target.exists() or target.is_file() and target.read_text() == raw),'apt-source')
            changes.append((path,raw,after,target))
    return changes

def retire_sources(changes):
    for path,before,_,target in changes:
        require(not path.is_symlink() and path.read_text() == before and not target.is_symlink() and
                (not target.exists() or target.is_file() and target.read_text() == before),'apt-source')
    for path,before,after,target in changes:
        if not target.exists():
            atomic(target,before.encode(),0o644)
        if after.strip():
            atomic(path,after.encode(),0o644)
        else:
            path.unlink()

def package_guard(simulation):
    for line in simulation.splitlines():
        if line.startswith('Remv '):
            require(line.split()[1].split(':')[0] in CE,'unrelated-package-removal')
        if line.startswith('Inst '):
            match = re.match(r'Inst (\S+)(?: \[([^]]+)\])? \((\S+)',line)
            require(match is not None,'apt-plan')
            name,old,new = match[1].split(':')[0],match[2],match[3]
            if old and name not in (*CE,'docker.io','containerd'):
                require(command(['dpkg','--compare-versions',new,'lt',old],False)[0] == 1,'unrelated-package-downgrade')

def swap(state,data):
    marker = JOURNAL/'restart-policy.json'
    policy_bytes = b'#!/bin/sh\nexit 101\n'
    if marker.exists():
        require(not POLICY.is_symlink() and (not POLICY.exists() or POLICY.read_bytes() == policy_bytes),'restart-policy')
        if POLICY.exists():
            POLICY.unlink()
        marker.unlink()
    require(not POLICY.exists() and not POLICY.is_symlink(),'restart-policy')
    with tempfile.TemporaryDirectory(prefix='orbit-docker-') as work:
        source = Path(work)/'ubuntu.sources'
        source.write_text('Types: deb\nURIs: https://snapshot.ubuntu.com/ubuntu/'+data['snapshot']+'/\nSuites: resolute resolute-updates resolute-security\nComponents: main universe restricted multiverse\nSigned-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\nCheck-Valid-Until: no\n')
        options = []
        for option in ('Acquire::Retries=0','Acquire::http::Timeout=15','Acquire::https::Timeout=15','Dir::Etc::sourcelist='+str(source),
                       'Dir::Etc::sourceparts=-','APT::Get::List-Cleanup=false','APT::Update::Error-Mode=any','Acquire::Languages=none',
                       'Acquire::IndexTargets::deb::DEP-11::DefaultEnabled=false','Acquire::IndexTargets::deb::CNF::DefaultEnabled=false'):
                           options += ['-o',option]
        command(['apt-get','update',*options])
        packages = installed()
        args = ['docker.io='+data['docker_version'],'containerd='+data['containerd_version'],'docker-buildx','docker-compose-v2'] + [name+'-' for name in CE if name in packages]
        base = ['apt-get','install','--no-install-recommends','--allow-downgrades','-o','Dpkg::Options::=--force-confold']
        atomic(marker,b'{"owned":true}')
        atomic(POLICY,policy_bytes,0o755)
        try:
            if state.get('swapping'):
                command(['dpkg','--configure','-a'],False)
                package_guard(command(['apt-get','-f','install',*options,'--simulate'])[1])
                command(['apt-get','-f','install',*options,'--yes'])
                command(['dpkg','--configure','-a'])
            package_guard(command([*base,*options,'--simulate','--',*args])[1])
            state['swapping'] = True
            record(state)
            command([*base,*options,'--yes','--',*args])
            command(['apt-get','check',*options])
        finally:
            POLICY.unlink()
            marker.unlink()
    after = installed()
    require(after.get('docker.io') == data['docker_version'] and after.get('containerd') == data['containerd_version'] and
            not any(name in after for name in CE) and all(name in after for name in ('docker-buildx','docker-compose-v2')), 'package-verification')
    state['swapped'] = True
    state['swapping'] = False
    record(state)

def readable(state):
    try:
        info = api('GET','/info')
        if real_directory(info.get('DockerRootDir'),'storage-root') != state['metadata_roots']['docker'] or info.get('Driver') != state['driver'] or defaults(info) != state['defaults']:
            return False
        for c in state['containers']:
            if inspect(c['Id'],True) is None:
                return False
        for image in state['images']:
            if api('GET','/images/'+quote(image['Id'])+'/json',missing=True) is None:
                return False
        for volume in state['volumes']:
            if api('GET','/volumes/'+quote(volume['Name']),missing=True) is None:
                return False
        for c in state['containers']:
            api('GET','/containers/'+quote(c['Id'])+'/changes')
        return True
    except Refused:
        return False

def configure_fallback(state):
    config = json.loads(bytes.fromhex(state['daemon'])) if state['daemon'] else {}
    require(isinstance(config, dict), 'daemon-config')
    config.pop('data-root', None)
    atomic(JOURNAL/'daemon.json', json.dumps(config).encode())
    content = '[Service]\nExecStart=\nExecStart=/usr/bin/dockerd --host=fd:// --data-root='+str(JOURNAL/'engine')+' --config-file='+str(JOURNAL/'daemon.json')+'\n'
    require(not DROPIN.is_symlink() and (not DROPIN.exists() or DROPIN.read_text() == content), 'custom-daemon')
    DROPIN.parent.mkdir(parents=True, exist_ok=True)
    atomic(DROPIN, content.encode(), 0o644)
    state['fallback'] = True
    record(state)

def effective_host(host,info):
    result = {k:copy.deepcopy(v) for k,v in host.items() if v}
    result['Runtime'] = host.get('Runtime') or info.get('DefaultRuntime') or 'runc'
    result['LogConfig'] = {'Type':(host.get('LogConfig') or {}).get('Type') or info.get('LoggingDriver') or 'json-file','Config':(host.get('LogConfig') or {}).get('Config') or {}}
    result['RestartPolicy'] = {'Name':'no','MaximumRetryCount':0,**(host.get('RestartPolicy') or {})}
    result['NetworkMode'] = host.get('NetworkMode') or 'default'
    return result

def recreate(c):
    name = c['Name'].lstrip('/')
    body = copy.deepcopy(c['Config'])
    body['HostConfig'] = copy.deepcopy(c['HostConfig'])
    body['HostConfig']['RestartPolicy'] = {'Name':'no'}
    body['Labels'] = {**(body.get('Labels') or {}),'orbit.docker.migration':c['Id']}
    binds = body['HostConfig'].get('Binds') or []
    mounts = body['HostConfig'].get('Mounts') or []
    for mount in mounts:
        if mount['Type'] == 'volume' and not mount.get('Source'):
            original = next((m for m in c.get('Mounts',[]) if m['Destination'] == mount['Target']),None)
            require(original is not None,'missing-mount')
            mount['Source'] = original['Name']
    if mounts:
        body['HostConfig']['Mounts'] = mounts
    for mount in c.get('Mounts',[]):
        if mount['Type'] == 'volume' and not any(len(b.split(':'))>1 and b.split(':')[1] == mount['Destination'] for b in binds) and not any(m['Target'] == mount['Destination'] for m in mounts):
            binds.append(mount['Name']+':'+mount['Destination']+(':'+('rw' if mount.get('RW') else 'ro')))
    body['HostConfig']['Binds'] = binds
    body['NetworkingConfig'] = {'EndpointsConfig':{name:{k:copy.deepcopy(v) for k,v in endpoint.items() if k in ('IPAMConfig','Aliases','DriverOpts','MacAddress','GwPriority') and v is not None}
                                                  for name,endpoint in c.get('NetworkSettings',{}).get('Networks',{}).items()}}
    recovery = c.get('RecoveryImage',c['Image'])
    body['Image'] = recovery
    existing = inspect(name,True)
    if existing is not None:
        info = api('GET','/info')
        require(existing['Image'] == recovery and all(existing.get('Config',{}).get(k) == v for k,v in body.items() if k not in ('HostConfig','NetworkingConfig') and v not in (None,'',[],{})) and
                effective_host(existing.get('HostConfig',{}),info) == effective_host(body['HostConfig'],info),'container-conflict')
        return
    api('POST','/containers/create?name='+quote(name),body)
