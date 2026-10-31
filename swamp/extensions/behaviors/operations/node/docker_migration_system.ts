import { z } from "npm:zod@4.4.3";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

const Id = z.string().regex(/^[0-9]+$/);
const Version = z.string().max(256).regex(/^[0-9][0-9A-Za-z.+:~_-]*$/);
export const DockerMigrationInput = z.object({
  recipe: z.string().min(1).max(4096).refine((value) =>
    [...value].every((character) =>
      character.codePointAt(0)! >= 32 && character.codePointAt(0)! !== 127
    )
  ),
  snapshot: z.string().regex(/^[0-9]{8}T[0-9]{6}Z$/),
  architecture: z.literal("amd64"),
  docker_version: Version,
  containerd_version: Version,
  process_ids: z.array(Id).max(10000),
  process_states: z.record(Id, z.enum(["running", "stopped"])),
  process_specs: z.record(
    Id,
    z.object({
      name: z.string().min(1).max(255),
      hash: z.string().min(1).max(512),
      volumes: z.array(
        z.object({ source: z.string(), target: z.string() }).passthrough(),
      ),
    }).strict(),
  ),
  fallback: z.boolean().default(false),
}).strict().superRefine((input, ctx) => {
  if (
    new Set(input.process_ids).size !== input.process_ids.length ||
    [...Object.keys(input.process_states), ...Object.keys(input.process_specs)]
      .some((id) => !input.process_ids.includes(id))
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Process ownership differs from admission",
    });
  }
});
export type DockerMigrationInput = z.infer<typeof DockerMigrationInput>;
const Stage = z.enum([
  "input",
  "platform",
  "architecture",
  "remote",
  "docker-api",
  "space",
  "image-size",
  "journal",
  "journal-permissions",
  "daemon-config",
  "custom-daemon",
  "storage-root",
  "containerd-root",
  "newer-engine-configuration",
  "orbit-spec-changed",
  "orbit-network-drift",
  "orbit-unrecorded-mount",
  "missing-image",
  "unsupported-container-links",
  "unsupported-logging-plugin",
  "swarm",
  "orbit-spec-missing",
  "unsupported-container",
  "unsupported-mount",
  "missing-mount",
  "foreign-image-platform",
  "orbit-runtime-drift",
  "unsupported-volume",
  "unsupported-network",
  "packages",
  "not-docker-ce",
  "missing-inventory",
  "recipe-mismatch",
  "already-restored",
  "apt-source",
  "daemon-changed",
  "inventory-drift",
  "insufficient-space",
  "image-id",
  "archive",
  "commit-image",
  "metadata-backup",
  "daemon-start",
  "restart-policy",
  "apt-indexes",
  "repair-dpkg",
  "apt-plan",
  "unrelated-package-removal",
  "unrelated-package-downgrade",
  "repair-dependencies",
  "apt-simulation",
  "swapping",
  "apt-check",
  "package-verification",
  "stop-services",
  "preserve-shutdown-layers",
  "post-stop-refusal",
  "fallback-required",
  "fresh-engine",
  "load-images",
  "restore-image-tags",
  "recreate",
  "recover-policies",
  "restart-containers",
  "volume-conflict",
  "network-conflict",
  "container-conflict",
  "image-version",
  "restore-engine",
  "restore-container",
]);
const Report = {
  fallback: z.boolean(),
  containers: z.array(
    z.object({
      name: z.string().max(255),
      state: z.string().max(64),
      health: z.string().max(64).nullable(),
    }).strict(),
  ).max(10000),
  process_ids: z.array(Id).max(10000),
};
const Result = z.discriminatedUnion("ok", [
  z.object({ ok: z.literal(true), ...Report }).strict(),
  z.object({
    ok: z.literal(false),
    error_code: z.literal("docker.migration_failed"),
    stage: Stage,
    resumable: z.literal(true),
    healthy: z.literal(false),
    ...Report,
  }).strict(),
]);
export type DockerMigrationResult = z.infer<typeof Result>;

/** Fixed Node-local transaction; inspect/credentials never leave its private journal. */
export async function dockerMigrationSystem(
  action: "prepare" | "restore-policies",
  raw: unknown,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<DockerMigrationResult> {
  if (action !== "prepare" && action !== "restore-policies") {
    throw new Error("Unknown Docker migration phase");
  }
  const input = DockerMigrationInput.parse(raw);
  signal?.throwIfAborted();
  const result = await invoke({
    command: "/usr/bin/sudo",
    args: ["-n", "/usr/bin/python3", "-"],
    stdin: `${DockerMigrationProgram}\nmain(${
      JSON.stringify(action)
    }, json.loads(${JSON.stringify(JSON.stringify(input))}))\n`,
    timeoutMs: 3600000,
    maxOutputBytes: 2 * 1024 * 1024,
  }, signal);
  // A lost subprocess outcome is uncertainty, never an invented successful or refused mutation.
  if (!result.success) {
    throw new Error("Docker migration has no confirmed outcome");
  }
  try {
    return Result.parse(JSON.parse(result.stdout));
  } catch {
    throw new Error("Docker migration returned an invalid bounded result");
  }
}

export const DockerMigrationProgram = String
  .raw`import copy, fcntl, http.client, json, os, re, selectors, shutil, socket, stat, subprocess, tempfile, time, tomllib, urllib.parse
from pathlib import Path
JOURNAL = Path('/var/lib/orbit/docker-migration')
DAEMON = Path('/etc/docker/daemon.json')
DROPIN = Path('/etc/systemd/system/docker.service.d/orbit-docker-migration.conf')
APT = Path('/etc/apt')
POLICY = Path('/usr/sbin/policy-rc.d')
LOCK = Path('/run/lock/orbit-docker-migration.lock')
CE = ('docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin', 'docker-compose-plugin', 'docker-ce-rootless-extras', 'docker-model-plugin')
CONFIG = set('Hostname Domainname User AttachStdin AttachStdout AttachStderr ExposedPorts Tty OpenStdin StdinOnce Env Cmd Healthcheck ArgsEscaped Image Volumes WorkingDir Entrypoint NetworkDisabled OnBuild Labels StopSignal StopTimeout Shell'.split())
HOST = set('CpuShares Memory CgroupParent BlkioWeight BlkioWeightDevice BlkioDeviceReadBps BlkioDeviceWriteBps BlkioDeviceReadIOps BlkioDeviceWriteIOps CpuPeriod CpuQuota CpuRealtimePeriod CpuRealtimeRuntime CpusetCpus CpusetMems Devices DeviceCgroupRules DeviceRequests MemoryReservation MemorySwap MemorySwappiness NanoCpus OomKillDisable Init PidsLimit Ulimits CpuCount CpuPercent IOMaximumIOps IOMaximumBandwidth Binds ContainerIDFile LogConfig NetworkMode PortBindings RestartPolicy AutoRemove VolumeDriver VolumesFrom Mounts ConsoleSize Annotations CapAdd CapDrop CgroupnsMode Dns DnsOptions DnsSearch ExtraHosts GroupAdd IpcMode Cgroup Links OomScoreAdj PidMode Privileged PublishAllPorts ReadonlyRootfs SecurityOpt StorageOpt Tmpfs UTSMode UsernsMode ShmSize Sysctls Runtime Isolation MaskedPaths ReadonlyPaths'.split())
DEADLINE = None
class Refused(Exception):
    def __init__(self, stage): self.stage = stage

def require(condition, stage):
    if not condition: raise Refused(stage)

def remaining(limit=3600):
    left = DEADLINE - time.monotonic() if DEADLINE is not None else limit
    require(left > 0, 'remote')
    return min(limit, left)

def command(argv, required=True, timeout=3600):
    # Drain continuously while enforcing an output cap. Package output and arbitrary inspect stderr never escape.
    with subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                          env={**os.environ, 'LC_ALL':'C', 'DEBIAN_FRONTEND':'noninteractive', 'NEEDRESTART_SUSPEND':'1'}) as child:
        data = bytearray()
        try:
            with selectors.DefaultSelector() as ready:
                ready.register(child.stdout, selectors.EVENT_READ)
                until = time.monotonic() + remaining(timeout)
                while ready.get_map():
                    require(time.monotonic() < until, 'remote')
                    for key, _ in ready.select(min(1, max(0, until - time.monotonic()))):
                        chunk = os.read(key.fd, 65536)
                        if not chunk: ready.unregister(key.fileobj)
                        else:
                            data.extend(chunk)
                            require(len(data) <= 64 * 1024 * 1024, 'remote')
            code = child.wait(timeout=remaining())
        except BaseException:
            child.kill(); child.wait(); raise
    require(not required or code == 0, 'remote')
    return code, bytes(data).decode('utf-8', errors='strict')

class DockerSocket(http.client.HTTPConnection):
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        self.sock.connect('/var/run/docker.sock')

def api(method, path, body=None, missing=False):
    conn = DockerSocket('localhost', timeout=remaining(120))
    try:
        conn.request(method, ('' if method == 'GET' else '/v1.52') + path,
                     body=None if body is None else json.dumps(body),
                     headers={} if body is None else {'Content-Type':'application/json'})
        response = conn.getresponse()
        if missing and response.status == 404: return None
        require(200 <= response.status < 300, 'docker-api')
        data = response.read(64 * 1024 * 1024 + 1)
        require(len(data) <= 64 * 1024 * 1024, 'docker-api')
        return json.loads(data) if data else {}
    except (OSError, ValueError, http.client.HTTPException): raise Refused('docker-api') from None
    finally: conn.close()

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
        if not create: return
        JOURNAL.mkdir(mode=0o700, parents=True)
    private_path(JOURNAL.parent, True, True)
    private_path(JOURNAL, True)
    if (JOURNAL/'inventory.json').exists() or (JOURNAL/'inventory.json').is_symlink(): private_path(JOURNAL/'inventory.json', False)

def atomic(path, content, mode=0o600):
    path = Path(path)
    if path.exists() or path.is_symlink():
        require(stat.S_ISREG(path.lstat().st_mode) and path.lstat().st_uid == os.geteuid(), 'journal-permissions')
    descriptor, temporary = tempfile.mkstemp(prefix='.orbit-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as output:
            os.fchmod(output.fileno(), mode)
            output.write(content); output.flush(); os.fsync(output.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(directory)
        finally: os.close(directory)
    finally:
        if os.path.exists(temporary): os.unlink(temporary)

def record(state): atomic(JOURNAL/'inventory.json', json.dumps(state).encode())
def stage(state, name): state['stage'] = name; record(state)
def daemon_bytes():
    require(not DAEMON.is_symlink(), 'daemon-config')
    return DAEMON.read_bytes() if DAEMON.exists() else None

def same_daemon(state):
    raw = daemon_bytes()
    return (raw.hex() if raw is not None else None) == state['daemon']

def supported(value, names): require(not any(v and k not in names for k,v in (value or {}).items()), 'newer-engine-configuration')
def process(c, data):
    if not managed(c): return
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
    supported(config, CONFIG); supported(host, HOST)
    supported(config.get('Healthcheck'), set('Test Interval Timeout Retries StartPeriod StartInterval'.split()))
    for endpoint in c.get('NetworkSettings', {}).get('Networks', {}).values():
        supported(endpoint, set('IPAMConfig Links MacAddress Aliases DriverOpts GwPriority NetworkID EndpointID Gateway IPAddress IPPrefixLen IPv6Gateway GlobalIPv6Address GlobalIPv6PrefixLen DNSNames'.split()))
        supported(endpoint.get('IPAMConfig'), {'IPv4Address','IPv6Address','LinkLocalIPs'})
        require(not endpoint.get('Links'), 'unsupported-container-links')
    for mount in host.get('Mounts') or []:
        supported(mount, set('Target Source Type ReadOnly Consistency BindOptions VolumeOptions ImageOptions TmpfsOptions'.split()))
        supported(mount.get('BindOptions'), set('Propagation NonRecursive CreateMountpoint ReadOnlyNonRecursive ReadOnlyForceRecursive'.split()))
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
    options['com.docker.network.bridge.name'] = bridge; body['Options'] = options
    return body

def defaults(info): return {k:info.get(k) for k in ('DefaultRuntime','LoggingDriver','CgroupDriver')}
def collect(data):
    info = api('GET','/info'); raw = daemon_bytes(); config = json.loads(raw) if raw else {}
    require('hosts' not in config and 'containerd' not in config and not DROPIN.exists(), 'custom-daemon')
    for unit in ('docker.service','containerd.service'):
        require(not command(['systemctl','show','--property=DropInPaths','--value',unit])[1].strip(), 'custom-daemon')
        fragment = command(['systemctl','show','--property=FragmentPath','--value',unit])[1].strip()
        require(not fragment or fragment in ('/usr/lib/systemd/system/'+unit,'/lib/systemd/system/'+unit), 'custom-daemon')
    docker = real_directory(info.get('DockerRootDir'), 'storage-root')
    require('data-root' not in config or real_directory(config['data-root'],'storage-root') == docker, 'storage-root')
    code, dump = command(['containerd','config','dump'], False)
    require(code == 0, 'containerd-root')
    try: containerd = real_directory(tomllib.loads(dump).get('root'), 'containerd-root')
    except ValueError: raise Refused('containerd-root') from None
    containers = [inspect(c['Id']) for c in api('GET','/containers/json?all=1')]
    images = [api('GET','/images/'+quote(i['Id'])+'/json') for i in api('GET','/images/json?all=1')]
    refs = {}
    for c in containers:
        container(c, data); process(c, data)
        require(not managed(c) or c['HostConfig'].get('Runtime','runc') == info.get('DefaultRuntime','runc'), 'orbit-runtime-drift')
        image = next((i for i in images if i['Id'] == c['Image']), None)
        require(image is not None and image.get('Architecture') == data['architecture'] and image.get('Os') == 'linux', 'foreign-image-platform')
        candidates = [c['Config']['Image']] if '@sha256:' in c['Config']['Image'] else image.get('RepoDigests') or []
        refs[c['Id']] = next((ref for ref in candidates if command(['docker','manifest','inspect',ref], False)[0] == 0), None)
    volumes = api('GET','/volumes').get('Volumes') or []
    for v in volumes: require(v['Driver'] == 'local' and not v.get('Options') and Path(v['Mountpoint']).is_dir(), 'unsupported-volume')
    networks = [api('GET','/networks/'+quote(n['Id'])) for n in api('GET','/networks')]
    for n in networks:
        if n['Name'] not in ('bridge','host','none'):
            require(n['Driver'] == 'bridge' and not n.get('Ingress') and n['Scope'] == 'local' and not n.get('ConfigOnly') and
                    not (n.get('ConfigFrom') or {}).get('Network') and (n.get('IPAM') or {}).get('Driver','default') == 'default', 'unsupported-network')
            network_request(n)
    for c in containers:
        if managed(c): c['Config'].pop('Env', None)
    return dict(recipe=data['recipe'], containers=containers, volumes=volumes, networks=networks, images=images, image_refs=refs,
                driver=info.get('Driver'), metadata_roots={'docker':docker,'containerd':containerd}, defaults=defaults(info), archives={}, committed={},
                stage='inventory', daemon=raw.hex() if raw is not None else None, fallback=False, swapped=False, prepared=False, stopped=False,
                swapping=False, images_preserved=False, metadata_backed_up=False, policies_disabled=False)

def installed():
    output = command(['dpkg-query','-W','-f=' + '$' + '{binary:Package}\\t' + '$' + '{Version}\\t' + '$' + '{Status}\\n'])[1]
    return {parts[0].split(':')[0]:parts[1] for line in output.splitlines() if len(parts := line.split('\t')) == 3 and parts[2] == 'install ok installed'}

def report(state):
    containers = []
    for old in state['containers']:
        try:
            c = inspect(old['Name'], True)
            containers.append(dict(name=old['Name'].lstrip('/'), state=c.get('State',{}).get('Status','') if c else 'missing',
                                   health=((c or {}).get('State',{}).get('Health') or {}).get('Status')))
        except Refused: containers.append(dict(name=old['Name'].lstrip('/'), state='unreadable', health=None))
    return dict(fallback=state['fallback'], containers=containers,
                process_ids=sorted({c['Config']['Labels']['orbit.process.id'] for c in state['containers'] if managed(c)}))

def archive_images(state):
    used = {c['Image'] for c in state['containers'] if not state['image_refs'].get(c['Id'])}
    return [image for image in state['images'] if image['Id'] in used]

def space(state):
    require(all(isinstance(i.get('Size'), int) and i['Size'] >= 0 for i in archive_images(state)), 'image-size')
    image_bytes = sum(i['Size'] for i in archive_images(state) if i['Id'] not in state['archives'])
    commit_bytes = 0
    if not state.get('images_preserved'):
        for c in state['containers']:
            if managed(c): continue
            committed = state['committed'].get(c['Id']+':shutdown')
            if committed in state['archives']: continue
            size = api('GET','/containers/'+quote(c['Id'])+'/json?size=1')
            require(all(isinstance(size.get(k), int) and size[k] >= 0 for k in ('SizeRootFs','SizeRw')), 'image-size')
            commit_bytes += size['SizeRootFs'] + size['SizeRw'] * (1 if committed else 2)
    disk = os.statvfs(JOURNAL)
    return disk.f_bavail * disk.f_frsize >= image_bytes + commit_bytes + 2 * 1024**3

def policies(state, restore):
    for c in state['containers']:
        if state['fallback'] and managed(c): continue
        reference = c['Name'].lstrip('/') if state['fallback'] else c['Id']
        policy = c.get('HostConfig',{}).get('RestartPolicy', {'Name':'no'}) if restore else {'Name':'no'}
        api('POST','/containers/'+quote(reference)+'/update', {'RestartPolicy':policy})

def start():
    for attempt in range(3):
        if command(['systemctl','start','--no-block','docker'], False, 15)[0] == 0:
            for _ in range(10):
                try: api('GET','/info'); return
                except Refused: time.sleep(min(2, remaining()))
        if attempt < 2: command(['systemctl','reset-failed','docker'], False)
    raise Refused('daemon-start')

def stop_loaded():
    units = [unit for unit in ('docker.service','docker.socket','containerd.service')
             if command(['systemctl','show','--property=LoadState','--value',unit], False)[1].strip() == 'loaded']
    if units: command(['systemctl','stop',*units])

def archive(state, image):
    if image in state['archives']: return
    require('/' not in image and '\x00' not in image, 'image-id')
    target = JOURNAL/('image-'+image.replace(':','-')+'.tar')
    temporary = target.with_suffix('.partial')
    require(not temporary.is_symlink(), 'archive')
    command(['docker','save','--output',str(temporary),image])
    os.chmod(temporary,0o600)
    with temporary.open('rb') as f: os.fsync(f.fileno())
    os.replace(temporary,target)
    state['archives'][image] = str(target); record(state)

def preserve(state, shutdown):
    for image in archive_images(state): archive(state,image['Id'])
    for c in state['containers']:
        if managed(c): continue
        key = c['Id'] + (':shutdown' if shutdown else ':running')
        if key not in state['committed'] and api('GET','/containers/'+quote(c['Id'])+'/changes'):
            image = command(['docker','commit',c['Id']])[1].strip()
            require(re.fullmatch('sha256:[0-9a-fA-F]{64}',image), 'commit-image')
            state['committed'][key] = image; record(state)
        if key in state['committed']: archive(state,state['committed'][key])

def restart_original(state):
    state['metadata_backed_up'] = False
    for image in set(state['committed'].values()):
        path = state['archives'].pop(image,None)
        if path: Path(path).unlink(missing_ok=True)
    state['committed'] = {}; state['images_preserved'] = False; record(state)
    start(); policies(state,True)
    for c in state['containers']:
        if c.get('State',{}).get('Running'): command(['docker','start',c['Id']], False)

def backup(state):
    if state.get('metadata_backed_up'): return
    stage(state,'metadata-backup')
    def visit(root, path, target):
        if path.is_symlink() or not path.exists(): return
        relative = path.relative_to(root)
        if '_data' in relative.parts: return
        if path.is_dir():
            for child in path.iterdir(): visit(root,child,target)
        elif path.is_file():
            image = relative.parts[0] == 'image' and any(n in relative.parts for n in ('imagedb','layerdb'))
            if not image and path.name not in ('metadata.db','meta.db','local-kv.db') and not (relative.parts[0] == 'containers' and path.name in ('config.v2.json','hostconfig.json')): return
            destination = target/relative
            destination.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
            with path.open('rb') as source:
                descriptor = os.open(destination,os.O_WRONLY|os.O_CREAT|os.O_TRUNC|os.O_NOFOLLOW,0o600)
                with os.fdopen(descriptor,'wb') as output: shutil.copyfileobj(source,output); output.flush(); os.fsync(output.fileno())
    for name, raw in state['metadata_roots'].items():
        root = Path(raw); target = JOURNAL/'metadata'/name
        for part in ('volumes/metadata.db','network/files/local-kv.db','io.containerd.metadata.v1.bolt/meta.db','containerd/daemon/io.containerd.metadata.v1.bolt/meta.db','image'): visit(root,root/part,target)
        directory = root/'containers'
        if directory.is_dir() and not directory.is_symlink():
            for child in directory.iterdir():
                if child.is_symlink(): continue
                for filename in ('config.v2.json','hostconfig.json'): visit(root,child/filename,target)
    state['metadata_backed_up'] = True; record(state)

def source_changes():
    changes = []
    directory = APT/'sources.list.d'
    paths = [APT/'sources.list'] + sorted(directory.glob('*.sources')) + sorted(directory.glob('*.list'))
    for path in paths:
        if not path.is_file(): continue
        raw = path.read_text()
        if 'download.docker.com' not in raw: continue
        require(not path.is_symlink(), 'apt-source')
        blocks = re.split('(\n\s*\n)',raw) if path.suffix == '.sources' else raw.splitlines(keepends=True)
        after = ''
        for block in blocks:
            active = '\n'.join(line for line in block.splitlines() if not line.lstrip().startswith('#'))
            if 'download.docker.com' not in active: after += block; continue
            if path.suffix == '.sources':
                fields, previous = {}, None
                for line in block.splitlines():
                    if not line.strip() or line.startswith('#'): continue
                    if line[0].isspace():
                        require(previous is not None, 'apt-source'); fields[previous] += ' '+line.strip(); continue
                    require(':' in line,'apt-source'); key,value = line.split(':',1); key = key.lower()
                    require(re.fullmatch('[a-z][a-z0-9-]*',key) and key not in fields,'apt-source')
                    fields[key] = value.strip(); previous = key
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
        if not target.exists(): atomic(target,before.encode(),0o644)
        if after.strip(): atomic(path,after.encode(),0o644)
        else: path.unlink()

def package_guard(simulation):
    for line in simulation.splitlines():
        if line.startswith('Remv '): require(line.split()[1].split(':')[0] in CE,'unrelated-package-removal')
        if line.startswith('Inst '):
            match = re.match(r'Inst (\S+)(?: \[([^]]+)\])? \((\S+)',line)
            require(match is not None,'apt-plan')
            name,old,new = match[1].split(':')[0],match[2],match[3]
            if old and name not in (*CE,'docker.io','containerd'):
                require(command(['dpkg','--compare-versions',new,'lt',old],False)[0] == 1,'unrelated-package-downgrade')

def swap(state,data):
    marker = JOURNAL/'restart-policy.json'; policy_bytes = b'#!/bin/sh\nexit 101\n'
    if marker.exists():
        require(not POLICY.is_symlink() and (not POLICY.exists() or POLICY.read_bytes() == policy_bytes),'restart-policy')
        if POLICY.exists(): POLICY.unlink()
        marker.unlink()
    require(not POLICY.exists() and not POLICY.is_symlink(),'restart-policy')
    with tempfile.TemporaryDirectory(prefix='orbit-docker-') as work:
        source = Path(work)/'ubuntu.sources'
        source.write_text('Types: deb\nURIs: https://snapshot.ubuntu.com/ubuntu/'+data['snapshot']+'/\nSuites: resolute resolute-updates resolute-security\nComponents: main universe restricted multiverse\nSigned-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\nCheck-Valid-Until: no\n')
        options = []
        for option in ('Acquire::Retries=0','Acquire::http::Timeout=15','Acquire::https::Timeout=15','Dir::Etc::sourcelist='+str(source),
                       'Dir::Etc::sourceparts=-','APT::Get::List-Cleanup=false','APT::Update::Error-Mode=any','Acquire::Languages=none',
                       'Acquire::IndexTargets::deb::DEP-11::DefaultEnabled=false','Acquire::IndexTargets::deb::CNF::DefaultEnabled=false'): options += ['-o',option]
        stage(state,'apt-indexes'); command(['apt-get','update',*options])
        packages = installed()
        args = ['docker.io='+data['docker_version'],'containerd='+data['containerd_version'],'docker-buildx','docker-compose-v2'] + [name+'-' for name in CE if name in packages]
        base = ['apt-get','install','--no-install-recommends','--allow-downgrades','-o','Dpkg::Options::=--force-confold']
        atomic(marker,b'{"owned":true}'); atomic(POLICY,policy_bytes,0o755)
        try:
            if state.get('swapping'):
                stage(state,'repair-dpkg'); command(['dpkg','--configure','-a'],False)
                package_guard(command(['apt-get','-f','install',*options,'--simulate'])[1])
                stage(state,'repair-dependencies'); command(['apt-get','-f','install',*options,'--yes']); command(['dpkg','--configure','-a'])
            stage(state,'apt-simulation'); package_guard(command([*base,*options,'--simulate','--',*args])[1])
            state['swapping'] = True; stage(state,'swapping'); command([*base,*options,'--yes','--',*args])
            stage(state,'apt-check'); command(['apt-get','check',*options])
        finally:
            POLICY.unlink(); marker.unlink()
    after = installed()
    require(after.get('docker.io') == data['docker_version'] and after.get('containerd') == data['containerd_version'] and
            not any(name in after for name in CE) and all(name in after for name in ('docker-buildx','docker-compose-v2')), 'package-verification')
    state['swapped'] = True; state['swapping'] = False; stage(state,'swapped')

def readable(state):
    try:
        start(); info = api('GET','/info')
        if real_directory(info.get('DockerRootDir'),'storage-root') != state['metadata_roots']['docker'] or info.get('Driver') != state['driver'] or defaults(info) != state['defaults']: return False
        for c in state['containers']:
            if inspect(c['Id'],True) is None: return False
        for image in state['images']:
            if api('GET','/images/'+quote(image['Id'])+'/json',missing=True) is None: return False
        for volume in state['volumes']:
            if api('GET','/volumes/'+quote(volume['Name']),missing=True) is None: return False
        for c in state['containers']: api('GET','/containers/'+quote(c['Id'])+'/changes')
        c = next((c for c in state['containers'] if not managed(c) and c.get('State',{}).get('Running')),None)
        if c: command(['docker','start',c['Id']]); command(['docker','stop','--time','60',c['Id']])
        return True
    except Refused: return False

def fresh(state):
    stop_loaded(); command(['systemctl','mask','containerd.service'])
    config = json.loads(bytes.fromhex(state['daemon'])) if state['daemon'] else {}
    require(isinstance(config,dict),'daemon-config'); config.pop('data-root',None)
    atomic(JOURNAL/'daemon.json',json.dumps(config).encode())
    content = '[Service]\nExecStart=\nExecStart=/usr/bin/dockerd --host=fd:// --data-root='+str(JOURNAL/'engine')+' --config-file='+str(JOURNAL/'daemon.json')+'\n'
    require(not DROPIN.is_symlink() and (not DROPIN.exists() or DROPIN.read_text() == content),'custom-daemon')
    DROPIN.parent.mkdir(parents=True,exist_ok=True); atomic(DROPIN,content.encode(),0o644)
    command(['systemctl','daemon-reload']); start(); api('GET','/info')
    for v in state['volumes']:
        expected = {'type':'none','o':'bind','device':v['Mountpoint']}
        actual = api('GET','/volumes/'+quote(v['Name']),missing=True)
        if actual is None: api('POST','/volumes/create',{'Name':v['Name'],'Driver':'local','DriverOpts':expected,'Labels':v.get('Labels')})
        else: require(actual.get('Options') == expected,'volume-conflict')
    for n in state['networks']:
        if n['Name'] in ('bridge','host','none'): continue
        body = network_request(n); actual = api('GET','/networks/'+quote(n['Name']),missing=True)
        if actual is None: api('POST','/networks/create',body)
        else: require(all(actual.get(k) == v for k,v in body.items()),'network-conflict')

def effective_host(host,info):
    result = {k:copy.deepcopy(v) for k,v in host.items() if v}
    result['Runtime'] = host.get('Runtime') or info.get('DefaultRuntime') or 'runc'
    result['LogConfig'] = {'Type':(host.get('LogConfig') or {}).get('Type') or info.get('LoggingDriver') or 'json-file','Config':(host.get('LogConfig') or {}).get('Config') or {}}
    result['RestartPolicy'] = {'Name':'no','MaximumRetryCount':0,**(host.get('RestartPolicy') or {})}
    result['NetworkMode'] = host.get('NetworkMode') or 'default'
    return result

def recreate(c,reference):
    name = c['Name'].lstrip('/'); body = copy.deepcopy(c['Config']); body['HostConfig'] = copy.deepcopy(c['HostConfig'])
    body['HostConfig']['RestartPolicy'] = {'Name':'no'}
    body['Labels'] = {**(body.get('Labels') or {}),'orbit.docker.migration':c['Id']}
    binds = body['HostConfig'].get('Binds') or []; mounts = body['HostConfig'].get('Mounts') or []
    for mount in mounts:
        if mount['Type'] == 'volume' and not mount.get('Source'):
            original = next((m for m in c.get('Mounts',[]) if m['Destination'] == mount['Target']),None)
            require(original is not None,'missing-mount'); mount['Source'] = original['Name']
    if mounts: body['HostConfig']['Mounts'] = mounts
    for mount in c.get('Mounts',[]):
        if mount['Type'] == 'volume' and not any(len(b.split(':'))>1 and b.split(':')[1] == mount['Destination'] for b in binds) and not any(m['Target'] == mount['Destination'] for m in mounts):
            binds.append(mount['Name']+':'+mount['Destination']+(':'+('rw' if mount.get('RW') else 'ro')))
    body['HostConfig']['Binds'] = binds
    body['NetworkingConfig'] = {'EndpointsConfig':{name:{k:copy.deepcopy(v) for k,v in endpoint.items() if k in ('IPAMConfig','Aliases','DriverOpts','MacAddress','GwPriority') and v is not None}
                                                  for name,endpoint in c.get('NetworkSettings',{}).get('Networks',{}).items()}}
    recovery = c.get('RecoveryImage',c['Image'])
    if reference is None: body['Image'] = recovery
    existing = inspect(name,True)
    if existing is not None:
        info = api('GET','/info')
        require(existing['Image'] == recovery and all(existing.get('Config',{}).get(k) == v for k,v in body.items() if k not in ('HostConfig','NetworkingConfig') and v not in (None,'',[],{})) and
                effective_host(existing.get('HostConfig',{}),info) == effective_host(body['HostConfig'],info),'container-conflict')
        return
    if reference:
        command(['docker','pull',reference]); require(api('GET','/images/'+quote(reference)+'/json')['Id'] == c['Image'],'image-version')
        if '@' not in c['Config']['Image']: command(['docker','tag',reference,c['Config']['Image']])
    api('POST','/containers/create?name='+quote(name),body)

def prepare(state,data,changes):
    if state['prepared']: return report(state)
    try: current = api('GET','/containers/json?all=1')
    except Refused:
        if state['stopped']: current = []
        else: command(['systemctl','start','docker']); current = api('GET','/containers/json?all=1')
    require(state['fallback'] or all(any(old['Id'] == c['Id'] for old in state['containers']) for c in current),'inventory-drift')
    require(space(state),'insufficient-space')
    if state['stopped']:
        for c in current:
            api('POST','/containers/'+quote(c['Id'])+'/update',{'RestartPolicy':{'Name':'no'}}); command(['docker','stop','--time','60',c['Id']])
    else:
        state['policies_disabled'] = True; stage(state,'disable-policies')
        try: policies(state,False)
        except Refused:
            restart_original(state); state['policies_disabled'] = False; stage(state,'post-stop-refusal'); raise
        stage(state,'stop-containers')
        for c in current:
            try: command(['docker','stop','--time','60',c['Id']])
            except Refused:
                try: preserve(state,False)
                finally: restart_original(state); state['policies_disabled'] = False; stage(state,'post-stop-refusal')
                raise
        state['stopped'] = True; stage(state,'stopped')
    if not state.get('images_preserved'):
        stage(state,'preserve-shutdown-layers'); preserve(state,True); state['images_preserved'] = True; record(state)
    stage(state,'stop-services'); stop_loaded(); backup(state)
    stage(state,'retire-sources'); retire_sources(changes)
    if not state['swapped']: swap(state,data)
    stage(state,'verify-engine')
    if not state['fallback'] and not readable(state):
        require(data['fallback'],'fallback-required'); state['fallback'] = True; stage(state,'fallback')
    if state['fallback']:
        stage(state,'fresh-engine'); fresh(state)
        stage(state,'load-images')
        for path in state['archives'].values(): command(['docker','load','--input',path])
        stage(state,'restore-image-tags')
        for image in state['images']:
            if image['Id'] in state['archives']:
                for tag in image.get('RepoTags') or []: command(['docker','tag',image['Id'],tag])
        stage(state,'recreate')
        for old in state['containers']:
            if managed(old): continue
            c = copy.deepcopy(old)
            image = state['committed'].get(c['Id']+':shutdown') or state['committed'].get(c['Id']+':running')
            reference = None if image else state['image_refs'].get(c['Id'])
            if image: c['RecoveryImage'] = image
            recreate(c,reference)
    stage(state,'restore-policies'); policies(state,True)
    stage(state,'restart-containers')
    for c in state['containers']:
        if not managed(c) and c.get('State',{}).get('Running'): command(['docker','start',c['Name'].lstrip('/')])
    state['prepared'] = True; stage(state,'prepared'); return report(state)

def restore(state):
    require('docker-ce' in installed() and not DROPIN.exists() and same_daemon(state) and
            real_directory(api('GET','/info').get('DockerRootDir'),'restore-engine') == state['metadata_roots']['docker'],'restore-engine')
    current = []
    for old in state['containers']:
        actual = inspect(old['Id'],True)
        require(actual is not None and actual['Name'] == old['Name'],'restore-container'); current.append(actual)
    stage(state,'recover-policies'); original = copy.deepcopy(state); original['fallback'] = False; policies(original,True)
    for old,actual in zip(state['containers'],current):
        if managed(old): continue
        if old.get('State',{}).get('Running'): command(['docker','start',old['Id']])
        elif actual.get('State',{}).get('Running'): command(['docker','stop','--time','60',old['Id']])
    state['fallback'] = False; state['prepared'] = True; stage(state,'restored'); return report(state)

def transaction(action,data):
    journal(True); path = JOURNAL/'inventory.json'
    if path.exists(): state = json.loads(path.read_bytes())
    else:
        require(action != 'restore-policies','missing-inventory')
        require('docker-ce' in installed(),'not-docker-ce'); state = collect(data)
    try:
        require(state['recipe'] == data['recipe'],'recipe-mismatch')
        for c in state['containers']: process(c,data)
        if action == 'restore-policies': result = restore(state)
        else:
            require(state['stage'] not in ('recover-policies','restored'),'already-restored')
            changes = source_changes(); require(same_daemon(state),'daemon-changed')
            if not path.exists(): record(state)
            try: result = prepare(state,data,changes)
            except Refused:
                if state['stopped'] and not state.get('swapping') and not state['swapped'] and state['stage'] != 'preserve-shutdown-layers':
                    restart_original(state); state['stopped'] = False; stage(state,'post-stop-refusal')
                raise
        return {'ok':True,**result}
    except Refused as error:
        stage_name = error.stage
        if stage_name == 'remote':
            stage_name = state['stage'] if state['stage'] in ('apt-indexes','repair-dpkg','repair-dependencies','apt-simulation','swapping','apt-check','stop-services','preserve-shutdown-layers','post-stop-refusal','fresh-engine','load-images','restore-image-tags','recreate','recover-policies','restart-containers') else 'remote'
        return {'ok':False,'error_code':'docker.migration_failed','stage':stage_name,'healthy':False,'resumable':True,**report(state)}

def main(action,data):
    global DEADLINE
    DEADLINE = time.monotonic() + 3590
    try:
        release = dict(line.split('=',1) for line in Path('/etc/os-release').read_text().splitlines() if '=' in line)
        require(release.get('ID','').strip('"') == 'ubuntu' and release.get('VERSION_CODENAME','').strip('"') == 'resolute','platform')
        require(command(['dpkg','--print-architecture'])[1].strip() == data['architecture'],'architecture')
        descriptor = os.open(LOCK,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
        with os.fdopen(descriptor,'w') as lock:
            require(stat.S_ISREG(os.fstat(lock.fileno()).st_mode) and os.fstat(lock.fileno()).st_uid == os.geteuid(),'journal-permissions')
            while True:
                try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB); break
                except BlockingIOError: remaining(); time.sleep(0.1)
            result = transaction(action,data)
    except Refused as error:
        result = {'ok':False,'error_code':'docker.migration_failed','stage':error.stage,'resumable':True,'healthy':False,'fallback':False,'containers':[],'process_ids':[]}
    except (OSError,ValueError,KeyError,TypeError,UnicodeError,http.client.HTTPException,subprocess.SubprocessError):
        # Never expose a traceback, command output, inspect data or private journal values.
        result = {'ok':False,'error_code':'docker.migration_failed','stage':'journal','resumable':True,'healthy':False,'fallback':False,'containers':[],'process_ids':[]}
    print(json.dumps(result))
`;
