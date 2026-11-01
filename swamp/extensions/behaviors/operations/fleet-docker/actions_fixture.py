"""Disposable Docker boundary; no real engine or host command runs."""

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
