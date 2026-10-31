import {
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import {
  DockerMigrationProgram,
  dockerMigrationSystem,
} from "./docker_migration_system.ts";

const input = {
  recipe: "recipe-fixture",
  snapshot: "20261031T000000Z",
  architecture: "amd64",
  docker_version: "29.1.3",
  containerd_version: "2.2.2",
  process_ids: ["42"],
  process_states: { "42": "running" },
  process_specs: { "42": { name: "orbit-42", hash: "same-spec", volumes: [] } },
  fallback: false,
};

// Only the OS/Docker command boundary is fake. The fixed transaction performs actual journal,
// metadata, source-backup and policy-file operations inside one disposable filesystem.
const Fixture = String.raw`
workspace = tempfile.TemporaryDirectory(prefix='orbit-docker-system-')
root = Path(workspace.name)
JOURNAL = root/'var/lib/orbit/docker-migration'
DAEMON = root/'etc/docker/daemon.json'
DROPIN = root/'etc/systemd/system/docker.service.d/orbit-docker-migration.conf'
APT = root/'etc/apt'
POLICY = root/'usr/sbin/policy-rc.d'
for directory in (DAEMON.parent,APT/'sources.list.d',POLICY.parent,root/'docker',root/'containerd',root/'volume/_data'):
    directory.mkdir(parents=True,exist_ok=True)
DAEMON.write_text(json.dumps({'data-root':str(root/'docker'),'runtimes':{'nvidia':{'path':'nvidia-container-runtime'}}}))
original_daemon = DAEMON.read_bytes()
(root/'volume/_data/database').write_text('irreplaceable-volume-data')
(root/'docker/volumes').mkdir()
(root/'docker/volumes/metadata.db').write_text('volume-metadata')
(root/'docker/overlay2').mkdir()
(root/'docker/overlay2/payload').write_text('never-copy-overlay-payload')
(root/'containerd/io.containerd.metadata.v1.bolt').mkdir()
(root/'containerd/io.containerd.metadata.v1.bolt/meta.db').write_text('containerd-metadata')
source_path = APT/'sources.list.d/docker.list'
source_path.write_text('deb [signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu resolute stable\n')
original_source = source_path.read_bytes()
commands, requests = [], []
fail_command = None
fail_api = None
incompatible = False
changed = False
free_bytes = 100*1024**3
swap_count = 0
simulation = 'Remv docker-ce [ce-version]\nRemv containerd.io [ce-version]\n'
packages = {'docker-ce':'ce-version','containerd.io':'ce-version','nvidia-container-toolkit':'safe'}
images = [{'Id':'sha256:'+64*'a','Architecture':'amd64','Os':'linux','Size':1024,'RepoDigests':['fixture@sha256:'+64*'a'],'RepoTags':['fixture:latest']}]
containers = []
for id,name,orbit,running in [('abc','foreign',False,True),('stopped','foreign-stopped',False,False),('owned','orbit-42',True,True)]:
    containers.append({'Id':id,'Name':'/'+name,'Image':images[0]['Id'],
                       'Config':{'Image':'fixture:latest','Env':['PRIVATE_MIGRATION_SENTINEL=never-return-this'],
                                 'Labels':{'orbit.managed':'true','orbit.container.kind':'process','orbit.process.id':'42','orbit.process.spec':'same-spec'} if orbit else {}},
                       'HostConfig':{'RestartPolicy':{'Name':'always','MaximumRetryCount':5},'Runtime':'runc'},
                       'Mounts':[], 'NetworkSettings':{'Networks':{}},
                       'State':{'Running':running,'Status':'running' if running else 'exited'}})
volumes = [{'Name':'volume','Driver':'local','Options':{},'Mountpoint':str(root/'volume/_data'),'Labels':{}}]
networks = [{'Name':'bridge','Id':64*'0','Driver':'bridge','Scope':'local'},
            {'Name':'custom','Id':64*'b','Driver':'bridge','Scope':'local','IPAM':{'Driver':'default'},'Options':{}}]
info = {'DockerRootDir':str(root/'docker'),'Driver':'overlay2','DefaultRuntime':'runc','LoggingDriver':'json-file','CgroupDriver':'systemd'}
fresh_mode = False
service = True

def find(reference):
    return next((c for c in containers if c['Id'] == reference or c['Name'].lstrip('/') == reference.lstrip('/')),None)

def fixture_command(argv,required=True,timeout=3600):
    global packages,swap_count,service,fresh_mode
    commands.append(argv)
    rendered = ' '.join(argv)
    if fail_command and fail_command in rendered:
        if required: raise Refused('remote')
        return (1,'')
    if argv[:2] == ['dpkg','--print-architecture']: return (0,'amd64\n')
    if argv[0] == 'dpkg-query': return (0,''.join(name+'\t'+version+'\tinstall ok installed\n' for name,version in packages.items()))
    if argv[:2] == ['containerd','config']: return (0,'root = '+json.dumps(str(root/'containerd')))
    if argv[:2] == ['systemctl','show']:
        return (0,'loaded\n' if '--property=LoadState' in argv else '')
    if argv[:2] == ['systemctl','stop']: service = False
    if argv[:2] == ['systemctl','start']:
        service = True
        if DROPIN.exists() and not fresh_mode:
            fresh_mode = True; containers.clear(); volumes.clear(); networks[:] = [n for n in networks if n['Name'] == 'bridge']
    if argv[0] == 'apt-get' and '--simulate' in argv: return (0,simulation)
    if argv[:2] == ['apt-get','install'] and '--yes' in argv:
        assert POLICY.read_bytes() == b'#!/bin/sh\nexit 101\n'
        swap_count += 1
        packages = {'docker.io':data['docker_version'],'containerd':data['containerd_version'],'docker-buildx':'1','docker-compose-v2':'1','nvidia-container-toolkit':'safe'}
    if argv[:2] == ['dpkg','--compare-versions']: return (0,'')
    if argv[:2] == ['docker','save']: Path(argv[argv.index('--output')+1]).write_bytes(b'private-local-image')
    if argv[:2] == ['docker','commit']: return (0,'sha256:'+64*'c')
    if argv[:2] == ['docker','stop']:
        current = find(argv[-1]); current['State'] = {'Running':False,'Status':'exited'}
    if argv[:2] == ['docker','start']:
        current = find(argv[-1]); current['State'] = {'Running':True,'Status':'running'}
    return (0,'')

def fixture_api(method,path,body=None,missing=False):
    requests.append((method,path,copy.deepcopy(body)))
    if fail_api and fail_api in path: raise Refused('docker-api')
    path = urllib.parse.unquote(path)
    if not service:
        if method == 'GET' and '/containers/' in path and missing: return None
        raise Refused('docker-api')
    if path == '/info': return copy.deepcopy(info)
    if path == '/containers/json?all=1': return [{'Id':c['Id']} for c in containers]
    if path == '/images/json?all=1': return copy.deepcopy(images)
    if path.startswith('/images/'):
        if incompatible and swap_count and not fresh_mode: return None
        return copy.deepcopy(next((i for i in images if i['Id'] in path or 'fixture' in path),images[0]))
    if path == '/volumes': return {'Volumes':copy.deepcopy(volumes)}
    if path.startswith('/volumes/'):
        if path == '/volumes/create':
            volumes.append({'Name':body['Name'],'Options':body['DriverOpts']}); return {}
        return copy.deepcopy(next((v for v in volumes if v['Name'] == path.split('/')[-1]),None))
    if path == '/networks': return copy.deepcopy(networks)
    if path.startswith('/networks/'):
        if path == '/networks/create': networks.append(copy.deepcopy(body)); return {}
        return copy.deepcopy(next((n for n in networks if n['Name'] == path.split('/')[-1] or n.get('Id') == path.split('/')[-1]),None))
    if path.startswith('/containers/create'):
        name = urllib.parse.parse_qs(urllib.parse.urlsplit(path).query)['name'][0]
        config = {k:copy.deepcopy(v) for k,v in body.items() if k not in ('HostConfig','NetworkingConfig')}
        containers.append({'Id':'new-'+name,'Name':'/'+name,'Image':body['Image'] if body['Image'].startswith('sha256:') else images[0]['Id'],
                           'Config':config,'HostConfig':copy.deepcopy(body['HostConfig']),'State':{'Running':False,'Status':'exited'}}); return {}
    if path.startswith('/containers/'):
        reference = path.split('/')[2]; current = find(reference)
        if path.endswith('/json?size=1'): return {'SizeRootFs':1024,'SizeRw':64}
        if path.endswith('/changes'): return [{'Kind':1,'Path':'/write'}] if changed else []
        if current is None:
            if missing: return None
            raise Refused('docker-api')
        if path.endswith('/update'): current['HostConfig']['RestartPolicy'] = copy.deepcopy(body['RestartPolicy']); return {}
        return copy.deepcopy(current)
    raise AssertionError((method,path,body))

command,api = fixture_command,fixture_api
real_space = space
def space(state):
    if free_bytes == 0: return False
    return real_space(state)

def check_data():
    assert DAEMON.read_bytes() == original_daemon
    assert (root/'volume/_data/database').read_text() == 'irreplaceable-volume-data'

def load(): return json.loads((JOURNAL/'inventory.json').read_bytes())
def safe(result):
    encoded = json.dumps(result)
    assert 'PRIVATE_MIGRATION_SENTINEL' not in encoded and 'never-return-this' not in encoded
    return result
`;

async function python(body: string): Promise<unknown> {
  const source = `${DockerMigrationProgram}\ndata = json.loads(${
    JSON.stringify(JSON.stringify(input))
  })\n${Fixture}\n${body}\n`;
  const result = await new Deno.Command("/usr/bin/python3", {
    args: ["-"],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writing = (async () => {
    const writer = result.stdin.getWriter();
    await writer.write(new TextEncoder().encode(source));
    await writer.close();
  })();
  const [output] = await Promise.all([result.output(), writing]);
  if (!output.success) throw new Error(new TextDecoder().decode(output.stderr));
  return JSON.parse(new TextDecoder().decode(output.stdout));
}

Deno.test("Docker migration validates ownership before invoking any system command", async () => {
  let calls = 0;
  const invoke = () => {
    calls++;
    throw new Error("must not run");
  };
  await assertRejects(() =>
    dockerMigrationSystem("prepare", {
      ...input,
      process_specs: {
        ...input.process_specs,
        "43": input.process_specs["42"],
      },
    }, invoke)
  );
  await assertRejects(() =>
    dockerMigrationSystem(
      "prepare",
      { ...input, snapshot: "bad; source" },
      invoke,
    )
  );
  await assertRejects(() =>
    dockerMigrationSystem(
      "prepare",
      { ...input, architecture: "arm64" },
      invoke,
    )
  );
  assertEquals(calls, 0);
});

Deno.test("Docker migration fixed program uses stdin and preserves lost-result uncertainty", async () => {
  await assertRejects(
    () =>
      dockerMigrationSystem("prepare", input, (command) => {
        assertEquals(command.command, "/usr/bin/sudo");
        assertEquals(command.args, ["-n", "/usr/bin/python3", "-"]);
        assertEquals(command.timeoutMs, 3600000);
        assertStringIncludes(command.stdin!, "recipe-fixture");
        return Promise.resolve({
          success: false,
          stdout: "PRIVATE_MIGRATION_SENTINEL",
        });
      }),
    Error,
    "no confirmed outcome",
  );
  await assertRejects(
    () =>
      dockerMigrationSystem("prepare", input, () =>
        Promise.resolve({
          success: true,
          stdout: JSON.stringify({ ok: true, inspect: "secret" }),
        })),
    Error,
    "invalid bounded result",
  );
});

Deno.test("Docker migration success is repeatable, private and retains config, volume data and offline metadata", async () => {
  assertEquals(
    await python(`
first = safe(transaction('prepare',data)); assert first['ok'], first
assert swap_count == 1
assert (APT/'docker.list.orbit-docker-backup').read_bytes() == original_source
assert not source_path.exists()
assert (JOURNAL/'metadata/docker/volumes/metadata.db').read_text() == 'volume-metadata'
assert (JOURNAL/'metadata/containerd/io.containerd.metadata.v1.bolt/meta.db').read_text() == 'containerd-metadata'
assert not (JOURNAL/'metadata/docker/overlay2').exists()
assert stat.S_IMODE((JOURNAL/'inventory.json').stat().st_mode) == 0o600
assert stat.S_IMODE(JOURNAL.stat().st_mode) == 0o700
assert 'PRIVATE_MIGRATION_SENTINEL' in (JOURNAL/'inventory.json').read_text()
assert all('Env' not in c['Config'] for c in load()['containers'] if managed(c))
second = safe(transaction('prepare',data)); assert second['ok'] and swap_count == 1
assert find('foreign')['State']['Running'] and not find('foreign-stopped')['State']['Running']
check_data(); print(json.dumps(first))
`),
    {
      ok: true,
      fallback: false,
      containers: [{ name: "foreign", state: "running", health: null }, {
        name: "foreign-stopped",
        state: "exited",
        health: null,
      }, { name: "orbit-42", state: "exited", health: null }],
      process_ids: ["42"],
    },
  );
});

Deno.test("Docker migration refuses unsafe ownership and unrelated package plans", async () => {
  for (
    const [setup, expected] of [
      [
        "containers[2]['Config']['Labels']['orbit.process.spec']='changed'",
        "orbit-spec-changed",
      ],
      [
        "containers[2]['Config']['Labels']['orbit.process.id']='999'",
        "orbit-spec-missing",
      ],
      [
        "containers[2]['HostConfig']['NetworkMode']='foreign'",
        "orbit-network-drift",
      ],
      [
        "containers[0]['HostConfig']['AutoRemove']=True",
        "unsupported-container",
      ],
      [
        "containers[0]['Config']['NewerEngineField']=True",
        "newer-engine-configuration",
      ],
      ["volumes[0]['Driver']='foreign-plugin'", "unsupported-volume"],
      [
        "simulation='Remv nvidia-container-toolkit [safe]\\n'",
        "unrelated-package-removal",
      ],
      [
        "simulation='Inst unrelated [3] (2 source)\\n'",
        "unrelated-package-downgrade",
      ],
    ]
  ) {
    const actual = await python(`
${setup}
try:
    result = transaction('prepare',data)
    actual = result.get('stage')
except Refused as error: actual = error.stage
assert swap_count == 0
check_data(); print(json.dumps(actual))
`);
    assertEquals(actual, expected);
  }
});

Deno.test("Docker migration disk refusal stops before policy changes", async () => {
  assertEquals(
    await python(`
free_bytes = 0
result = safe(transaction('prepare',data))
assert result['stage'] == 'insufficient-space', result
assert not any(method == 'POST' for method,_,_ in requests)
assert not any(argv[:2] == ['docker','stop'] for argv in commands)
assert all(c['HostConfig']['RestartPolicy']['Name'] == 'always' for c in containers)
check_data(); print(json.dumps(result['stage']))
`),
    "insufficient-space",
  );
});

Deno.test("Docker migration archive failure leaves stopped state and resumes the same shutdown commit once", async () => {
  assertEquals(
    await python(`
changed=True
fail_command='docker save'
# A pullable original image means only the shutdown commit needs an archive.
first = safe(transaction('prepare',data)); assert first['stage']=='preserve-shutdown-layers', first
assert load()['stopped'] and not load()['swapped'] and not any(c['State']['Running'] for c in containers)
assert len([a for a in commands if a[:2] == ['docker','commit']]) == 1
fail_command=None
second = safe(transaction('prepare',data)); assert second['ok'], second
assert len([a for a in commands if a[:2] == ['docker','commit']]) == 2
# There are two foreign containers. Each is committed once; the first was not committed twice.
assert len([a for a in commands if a[:2] == ['docker','commit'] and a[-1] == 'abc']) == 1
check_data(); print(json.dumps(second['ok']))
`),
    true,
  );
});

Deno.test("Docker migration interrupted package swap repairs dpkg before retry without replacing inventory", async () => {
  assertEquals(
    await python(`
fail_command='apt-get install --no-install-recommends --allow-downgrades'
# Fail only the effect, not the preceding simulation.
original_command=command
def command(argv, required=True, timeout=3600):
    global fail_command
    if argv[0]=='apt-get' and argv[1]=='install' and '--yes' in argv and fail_command:
        commands.append(argv); raise Refused('remote')
    saved=fail_command; fail_command=None
    try: return original_command(argv,required,timeout)
    finally: fail_command=saved
first=safe(transaction('prepare',data)); assert first['stage']=='swapping', first
assert load()['swapping'] and not POLICY.exists()
original_inventory=copy.deepcopy(load()['containers'])
fail_command=None
second=safe(transaction('prepare',data)); assert second['ok'],second
assert load()['containers']==original_inventory
assert any(a==['dpkg','--configure','-a'] for a in commands)
assert any(a[:3]==['apt-get','-f','install'] and '--simulate' in a for a in commands)
check_data(); print(json.dumps(second['ok']))
`),
    true,
  );
});

Deno.test("Docker fallback is explicit, preserves original backing and restores custom bridge identity", async () => {
  assertEquals(
    await python(`
incompatible=True
first=safe(transaction('prepare',data)); assert first['stage']=='fallback-required',first
assert not DROPIN.exists() and swap_count==1
assert all(c['HostConfig']['RestartPolicy']['Name']=='no' for c in containers)
data['fallback']=True
second=safe(transaction('prepare',data)); assert second['ok'] and second['fallback'],second
assert swap_count==1
assert any(a==['systemctl','mask','containerd.service'] for a in commands)
assert next(v for v in volumes if v['Name']=='volume')['Options']['device']==str(root/'volume/_data')
assert next(n for n in networks if n['Name']=='custom')['Options']['com.docker.network.bridge.name']=='br-'+12*'b'
assert 'data-root' not in json.loads((JOURNAL/'daemon.json').read_text())
third=safe(transaction('prepare',data)); assert third['ok'] and swap_count==1
check_data(); print(json.dumps(second['fallback']))
`),
    true,
  );
});

Deno.test("Docker policy recovery requires original engine and all original containers before restoring state", async () => {
  assertEquals(
    await python(`
first=safe(transaction('prepare',data)); assert first['ok']
refusal=safe(transaction('restore-policies',data)); assert refusal['stage']=='restore-engine'
packages={'docker-ce':'ce-version'}
saved=containers.pop()
refusal=safe(transaction('restore-policies',data)); assert refusal['stage']=='restore-container'
containers.append(saved)
second=safe(transaction('restore-policies',data)); assert second['ok'],second
assert find('foreign')['State']['Running'] and not find('foreign-stopped')['State']['Running']
assert all(c['HostConfig']['RestartPolicy']=={'Name':'always','MaximumRetryCount':5} for c in containers)
assert safe(transaction('restore-policies',data))['ok']
assert transaction('prepare',data)['stage']=='already-restored'
check_data(); print(json.dumps(load()['stage']))
`),
    "restored",
  );
});

Deno.test("Docker policy disable failure restores original policies and never swaps", async () => {
  assertEquals(
    await python(`
original_api=api
failed=False
def api(method,path,body=None,missing=False):
    global failed
    if not failed and method=='POST' and path.endswith('/update'):
        failed=True; raise Refused('docker-api')
    return original_api(method,path,body,missing)
result=safe(transaction('prepare',data)); assert not result['ok'] and swap_count==0
assert all(c['HostConfig']['RestartPolicy']=={'Name':'always','MaximumRetryCount':5} for c in containers)
assert find('foreign')['State']['Running'] and not find('foreign-stopped')['State']['Running']
check_data(); print(json.dumps(result['stage']))
`),
    "docker-api",
  );
});

Deno.test("Docker recovery journal refuses recipe/spec/config drift and public or symlink custody", async () => {
  for (
    const [setup, stage] of [
      ["data['recipe']='foreign'", "recipe-mismatch"],
      ["data['process_specs']['42']['hash']='changed'", "orbit-spec-changed"],
      ["DAEMON.write_text('{}')", "daemon-changed"],
      ["os.chmod(JOURNAL/'inventory.json',0o644)", "journal-permissions"],
      [
        "(JOURNAL/'inventory.json').rename(JOURNAL/'original.json'); (JOURNAL/'inventory.json').symlink_to(JOURNAL/'original.json')",
        "journal-permissions",
      ],
    ]
  ) {
    assertEquals(
      await python(`
assert transaction('prepare',data)['ok']
${setup}
before=len(commands)
try:
    result=transaction('prepare',data); actual=result.get('stage')
except Refused as error: actual=error.stage
assert len(commands)==before
print(json.dumps(actual))
`),
      stage,
    );
  }
});

Deno.test("Docker failed stop restores only originally running containers and policies without swapping", async () => {
  assertEquals(
    await python(`
fail_command='docker stop --time 60 abc'
result=safe(transaction('prepare',data))
assert not result['ok'] and swap_count==0,result
assert find('foreign')['State']['Running'] and not find('foreign-stopped')['State']['Running']
assert all(c['HostConfig']['RestartPolicy']=={'Name':'always','MaximumRetryCount':5} for c in containers)
assert load()['stage']=='post-stop-refusal'
check_data(); print(json.dumps(result['ok']))
`),
    false,
  );
});

Deno.test("Docker source and restart policy conflicts preserve foreign files", async () => {
  for (
    const [setup, stage] of [
      [
        "(APT/'docker.list.orbit-docker-backup').write_text('foreign')",
        "apt-source",
      ],
      [
        "source_path.write_text('deb https://download.docker.com/linux/ubuntu resolute stable main\\n')",
        "apt-source",
      ],
      ["POLICY.write_text('foreign-policy')", "restart-policy"],
    ]
  ) {
    assertEquals(
      await python(`
${setup}
try:
    result=transaction('prepare',data); actual=result.get('stage')
except Refused as error: actual=error.stage
assert swap_count==0
if POLICY.exists(): assert POLICY.read_text()=='foreign-policy'
check_data(); print(json.dumps(actual))
`),
      stage,
    );
  }
});

Deno.test("Docker fallback resume refuses occupied volume/network/container artifacts", async () => {
  for (
    const [setup, stage] of [
      ["volumes[0]['Options']['device']='foreign'", "volume-conflict"],
      [
        "next(n for n in networks if n['Name']=='custom')['Options']['com.docker.network.bridge.name']='foreign'",
        "network-conflict",
      ],
      [
        "find('foreign')['Config']['Env']=['FOREIGN=do-not-adopt']",
        "container-conflict",
      ],
    ]
  ) {
    assertEquals(
      await python(`
incompatible=True; data['fallback']=True
assert transaction('prepare',data)['ok']
saved=load(); saved['prepared']=False; record(saved)
${setup}
result=safe(transaction('prepare',data))
assert not result['ok'] and swap_count==1,result
check_data(); print(json.dumps(result['stage']))
`),
      stage,
    );
  }
});

Deno.test("Docker native public API executes the fixed program and validates bounded success", async () => {
  const result = await dockerMigrationSystem(
    "prepare",
    input,
    async (request) => {
      const main = request.stdin!.lastIndexOf("\nmain(");
      const source = request.stdin!.slice(0, main).replace(
        "Path('/etc/os-release')",
        "(root/'os-release')",
      ) +
        `\ndata=json.loads(${
          JSON.stringify(JSON.stringify(input))
        })\n${Fixture}\nLOCK=root/'migration.lock'\n(root/'os-release').write_text('ID=ubuntu\\nVERSION_CODENAME=resolute\\n')\n` +
        request.stdin!.slice(main);
      const child = new Deno.Command("/usr/bin/python3", {
        args: ["-"],
        stdin: "piped",
        stdout: "piped",
        stderr: "piped",
      }).spawn();
      const writing = (async () => {
        const writer = child.stdin.getWriter();
        await writer.write(new TextEncoder().encode(source));
        await writer.close();
      })();
      const [output] = await Promise.all([child.output(), writing]);
      if (!output.success) {
        throw new Error(
          new TextDecoder().decode(output.stderr),
        );
      }
      return {
        success: output.success,
        stdout: new TextDecoder().decode(output.stdout),
      };
    },
  );
  assertEquals(result.ok, true);
  assertEquals(result.process_ids, ["42"]);
});
