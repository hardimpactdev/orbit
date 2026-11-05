"""Write the owned request of one proof reset: the proof root, its owner marker and `request.json`.

`proof-fleet-prepare` runs this over SSH on the task VM, then runs each phase of `phases.py` with PROOF_REQUEST set to the
file it wrote. The generation is the id of that workflow run, so every later step names the same request path.
"""
import json
import os
import re
import sys
from pathlib import Path

BUDGET_SECONDS = 1800.0


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(value, indent=2) + '\n')
    temporary.replace(path)


def write_request(group, generation, candidate, home):
    """The request of a reset from the ready copies, written under the checkout `candidate`."""
    root = home / '.local/state' / ('orbit-proof-' + group)
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    if root.is_symlink() or root.stat().st_uid != os.getuid():
        raise RuntimeError('Proof root must be an owned real directory.')
    marker = {'group': group, 'candidate': str(candidate)}
    owner = root / 'owner.json'
    if owner.exists() and json.loads(owner.read_text()) != marker:
        raise RuntimeError('Proof root belongs to a different candidate or group.')
    if not owner.exists():
        write_json(owner, marker)
    key = home / '.config/swamp/club-api-key'
    if not key.is_file() or key.is_symlink() or key.stat().st_uid != os.getuid() or key.stat().st_mode & 0o077:
        raise RuntimeError('The approved private proof club key file is required.')
    evidence = candidate / '.orbit-artifacts' / group / 'reset' / generation
    evidence.mkdir(parents=True, mode=0o700)
    request = {**marker, 'issue': 'TASK-' + group, 'root': str(root), 'generation': generation, 'evidence': str(evidence),
               'cases': None, 'budget_seconds': BUDGET_SECONDS, 'action': 'reset', 'from_ready': True, 'mode': 'ready', 'key_file': str(key)}
    write_json(evidence / 'request.json', request)
    return evidence / 'request.json'


def main(argv):
    if len(argv) != 3 or not re.fullmatch('[1-9][0-9]*', argv[1]) or not re.fullmatch('[0-9a-f]{32}', argv[2]):
        sys.stderr.write('Usage: request.py GROUP GENERATION\n')
        return 2
    os.umask(0o077)
    print(write_request(argv[1], argv[2], Path(__file__).resolve().parents[3], Path.home()))
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv))
