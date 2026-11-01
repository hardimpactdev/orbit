"""Bootstrap one Gateway-owned Shark guest over the existing operator SSH identity."""
import json
import re
import shlex
import subprocess
import uuid
from datetime import UTC, datetime

from swamp_proof import ROOT, ProofError, bind


def command(argv, source=None, timeout=60):
    try:
        result = subprocess.run(argv, input=source, capture_output=True, timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise ProofError('Proof bootstrap command unavailable or timed out.') from None
    if result.returncode:
        raise ProofError('Proof bootstrap command failed; diagnostics remain on the disposable VM.')
    return result.stdout


class Guest:
    def __init__(self, guest, host):
        record = guest['record']
        if record.get('host_node_id') != 13 or not re.fullmatch(r'tvm-[1-9][0-9]*', record['name']):
            raise ProofError('Guest bootstrap requires its normal Shark allocation.')
        self.name = record['name']
        self.host = host

    def call(self, argv, source=None, timeout=60):
        remote = ['incus', 'exec', '--project', 'orbit-tasks', self.name, '--', *argv]
        return command(['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
                        '-o', 'ConnectTimeout=15', self.host, shlex.join(remote)], source, timeout)

    def import_image(self, image):
        # Export by fingerprint on Shark, push into this owned VM, then remove host temp files.
        script = '''import json,pathlib,subprocess,sys,tempfile
x=json.load(sys.stdin)
def run(*args):return subprocess.run(args,check=True,capture_output=True).stdout
rows=json.loads(run('incus','image','list',x['fingerprint'],'--project','orbit-tasks','--format','json'))
assert len(rows)==1 and rows[0]['fingerprint']==x['fingerprint'] and rows[0]['type']=='container'
with tempfile.TemporaryDirectory(prefix='swamp-proof-image-') as tmp:
    prefix=str(pathlib.Path(tmp)/'image')
    run('incus','image','export',x['fingerprint'],prefix,'--project','orbit-tasks')
    # Incus file-prefix export names split rootfs <prefix>.root; metadata is first.
    files=sorted(pathlib.Path(tmp).iterdir(),key=lambda p:p.name.endswith('.root'))
    assert len(files) in (1,2)
    for path in files:
        run('incus','file','push',str(path),x['vm']+'/opt/orbit-proof/'+path.name,'--project','orbit-tasks')
    print(json.dumps(['/opt/orbit-proof/'+p.name for p in files]))
'''
        raw = command(['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=15',
                       self.host, 'python3 -c ' + shlex.quote(script)],
                      json.dumps({'fingerprint': image['fingerprint'], 'vm': self.name}).encode(), timeout=600)
        return json.loads(raw)

    def driver(self, action, payload, timeout=60):
        raw = self.call(['python3', '/opt/orbit-proof/repo/tools/swamp_proof_guest.py', action],
                        json.dumps(payload).encode(), timeout)
        try:
            result = json.loads(raw)
        except ValueError:
            raise ProofError('Proof guest returned no public JSON result.') from None
        if 'error' in result and action == 'prepare':
            raise ProofError(result['error'])
        return result


def pins(root=ROOT):
    source = (root / 'swamp/extensions/behaviors/node-worker/config.ts').read_text()
    match = re.search(r'export const Swamp = \{\s*version: "([\w.-]+)",\s*sha256: "([a-f0-9]{64})",\s*bytes: (\d+)', source)
    if not match:
        raise ProofError('Canonical Swamp runtime pin is missing.')
    version, sha256, size = match.groups()
    if f'swampVersion: {version}' not in (root / 'swamp/.swamp.yaml').read_text():
        raise ProofError('Repository and worker runtime pins disagree.')
    extensions = json.loads((root / 'swamp/extensions/models/upstream_extensions.json').read_text())
    for name, record in extensions.items():
        if not name.startswith('@swamp/') or not record.get('version') or not re.fullmatch('[a-f0-9]{64}', record.get('checksum', '')):
            raise ProofError('An official extension pin is missing.')
    return {'version': version, 'sha256': sha256, 'bytes': int(size), 'extensions': extensions}


def archive():
    paths = ['swamp', 'tools/swamp_proof.py', 'tools/swamp_proof_guest.py']
    if command(['git', '-C', str(ROOT), 'status', '--porcelain', '--', *paths]).strip():
        raise ProofError('Commit the proof source and guest driver before proving it.')
    commit = command(['git', '-C', str(ROOT), 'rev-parse', 'HEAD']).decode().strip()
    bundle = command(['git', '-C', str(ROOT), 'archive', '--format=tar', 'HEAD', *paths])
    return commit, bundle


def execute(control, fixtures, topology, receipt, save):
    commit, bundle = archive()
    pin = pins()
    receipt.update(swamp_commit=commit, swamp_version=pin['version'], source_loaded=False)
    save()
    host = control['host']
    guest = Guest(control, f'{host["user"]}@{host["wireguard_ip"]}')
    guest.call(['mkdir', '-p', '/opt/orbit-proof/repo'])
    guest.call(['tar', '-xpf', '-', '-C', '/opt/orbit-proof/repo'], bundle)
    files = guest.import_image(topology['image']) if topology['image']['fingerprint'] else []
    ready = guest.driver('prepare', {'pin': pin, 'topology': topology, 'image_files': files}, timeout=900)
    receipt['source_loaded'] = True
    save()
    target = ready['target']
    context = {'proof.operation_id': control['record']['provision_operation_id']}
    context.update({'proof.control.' + key: value for key, value in {
        'node_id': control['record']['node_id'], 'identity_file': ready['identity_file'],
        'public_key': ready['public_key']}.items()})
    context.update({'proof.target.' + key: value for key, value in target.items()})
    # Container identity is proof-local, never a manually registered Gateway Node.
    context['proof.target.node_id'] = control['record']['node_id']
    requests = [{'fixture': fixture, 'envelope': bind(fixture['envelope'], dict(context, **{'proof.start_tag': str(uuid.uuid4())}), datetime.now(UTC))}
                for fixture in fixtures]
    result = guest.driver('run', {'proofs': requests, 'swamp_commit': commit},
                          timeout=sum(fixture['budget_seconds'] + 180 for fixture in fixtures))
    if 'error' in result:
        receipt.update(result)
        save()
        raise ProofError(result['error'])
    if result.get('swamp_commit') != commit or result.get('swamp_version') != pin['version']:
        raise ProofError('Control source/runtime receipt differs from the admitted proof source.')
    receipt.update(result)
    save()
    return result
