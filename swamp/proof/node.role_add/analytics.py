"""Real storage prerequisites and immutable runtime evidence for Analytics."""
import ast
import json
import re
import secrets
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SNAPSHOT = Path('/home/orbit/.orbit/swamp-proof/analytics-runtime.json')


def on(*argv, **kwargs):
    return subprocess.run(['incus', 'exec', 'metrics', '--', *argv], check=True,
                          capture_output=True, text=True, **kwargs).stdout


if sys.argv[1] == 'prepare':
    # The Process owns its mounts before role admission. Reuse the canonical public files.
    source = (ROOT / 'swamp/extensions/behaviors/operations/analytics/clickhouse_files.ts').read_text()
    files = re.findall(r'"([^"\n]+\.xml)":\s*("(?:[^"\\]|\\.)*"|\'(?:[^\'\\]|\\.)*\')', source)
    assert len(files) == 4
    for name, content in files:
        path = '/etc/orbit/analytics/clickhouse/' + name
        on('mkdir', '-p', str(Path(path).parent))
        on('tee', path, input=ast.literal_eval(content))
    print(json.dumps({'secrets': {engine: secrets.token_hex(20) for engine in ['postgres', 'clickhouse']}}))
else:
    identifier = sys.argv[2]
    state = json.loads(on('docker', 'inspect', 'orbit-' + identifier))[0]
    actual = {'id': state['Id'], 'started': state['State']['StartedAt'], 'process_id': identifier}
    assert state['State']['Running']
    if sys.argv[1] == 'verify':
        assert json.loads(SNAPSHOT.read_text()) == actual, 'Analytics converge replaced or restarted Plausible'
    else:
        SNAPSHOT.write_text(json.dumps(actual))
