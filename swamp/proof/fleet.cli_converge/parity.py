"""Check that the native child replaced an unrecognized CLI with the admitted release and wrote the root profile."""
import json
import os
import subprocess

release = json.load(open(os.path.join(os.path.dirname(__file__), '..', 'fleet.cli_converge', 'cli-release.json')))
version = f"0.{release['number']}.0"
operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
assert operation['status'] == 'succeeded', operation.get('error_code')
node = operation['result']['nodes'][0]
assert node['outcome'] == 'converged' and node['cli_version'] == version and node['error_code'] is None, node


def target(*argv):
    return subprocess.check_output(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], text=True).strip()


assert target('readlink', '/usr/local/bin/orbit') == f'orbit-{version}'
assert target('stat', '-c', '%U:%G %a', f'/usr/local/bin/orbit-{version}') == 'root:root 755'
assert target('runuser', '-u', 'orbit', '--', '/usr/local/bin/orbit', '--version').splitlines()[0] == f'Orbit {version}'
assert target('stat', '-c', '%U:%G %a', '/root/.orbit/config.json') == 'root:root 600'
print(f'parity: unrecognized CLI replaced by Orbit {version}; profile root:root 0600')
