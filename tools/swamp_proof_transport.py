"""Bootstrap one Gateway-owned Shark guest over the existing operator SSH identity."""
import json
import re
import shlex
import subprocess
import sys

from swamp_proof import ROOT, ProofError


def command(argv, source=None, timeout=60, public=False):
    try:
        result = subprocess.run(argv, input=source, capture_output=True, timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise ProofError('Proof bootstrap command unavailable or timed out.') from None
    if public and result.stderr:
        sys.stderr.buffer.write(result.stderr)
        sys.stderr.buffer.flush()
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

    def call(self, argv, source=None, timeout=60, public=False):
        remote = ['incus', 'exec', '--project', 'orbit-tasks', self.name, '--', *argv]
        return command(['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
                        '-o', 'ConnectTimeout=15', self.host, shlex.join(remote)], source, timeout, public=public)


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
