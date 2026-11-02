"""Execute an admitted Project command and retain bounded, redacted output tails."""
import hashlib
import json
import os
import re
import subprocess
import sys
from pathlib import Path

from project_environment import ProjectEnvironmentRefusal, load_project_environment

value = json.load(sys.stdin)
command = value['command']
if hashlib.sha256(command.encode()).hexdigest() != value['sha256']:
    raise SystemExit(1)
directory = Path(value['directory'])
if directory.is_symlink() or not directory.is_dir() or directory.stat().st_uid != os.geteuid():
    raise SystemExit(1)
os.chdir(directory)
environment = dict(os.environ, ORBIT_INSTANCE_ID=str(value['instance_id']), ORBIT_INSTANCE_NAME=value['instance_name'], ORBIT_SEED_PATH=value['seed_path'], ORBIT_SEED_COMMIT=value['seed_commit'])
redactions = {command}
for key, item in environment.items():
    if re.search(r'TOKEN|SECRET|PASSWORD|CREDENTIAL|(?:^|_)KEY$', key, re.IGNORECASE) and item:
        redactions.add(item)
# Read once through the canonical parser; file values stay out of the process environment.
try:
    values = load_project_environment(directory)
except ProjectEnvironmentRefusal as error:
    # The reader retains unparsed noncomment lines privately; a refused file runs no command.
    redactions.update(error.redaction_targets)
    print(json.dumps({'exitCode': 1, 'stderr': str(error)}))
    raise SystemExit(1) from None
for item in values.values():
    redactions.update([item, *item.splitlines(), *re.split(r'\r\n|\n|\r', item)])
redactions.discard('')
secrets = sorted((item.encode() for item in redactions), key=len, reverse=True)
# Fragment checks cover secret values only, not the command text, which is redacted whole.
secret_values = [item for item in secrets if item != command.encode()]
# Failure output is stderr only (F1). Command stdout is never kept.
CAPTURE_LIMIT = 1048576
TAIL_LIMIT = 4096
FRAGMENT = 8
captured = bytearray()
truncated = False
process = subprocess.Popen(['/bin/bash', '-eu', '-c', command], env=environment,
                           stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
while chunk := os.read(process.stderr.fileno(), 65536):
    captured.extend(chunk)
    if len(captured) > CAPTURE_LIMIT:
        del captured[:-CAPTURE_LIMIT]
        truncated = True
process.stderr.close()
code = process.wait()


def redact(text: bytes) -> bytes:
    # Mark every occurrence, including matches inside or overlapping another match.
    # The mask merges covered ranges without retaining an unbounded list of matches.
    covered = bytearray(len(text))
    for secret in secrets:
        start = text.find(secret)
        while start >= 0:
            covered[start:start + len(secret)] = b'\1' * len(secret)
            start = text.find(secret, start + 1)
    pieces: list[bytes] = []
    cursor = 0
    while (start := covered.find(b'\1', cursor)) >= 0:
        end = covered.find(b'\0', start)
        if end < 0:
            end = len(text)
        pieces.extend((text[cursor:start], b'[REDACTED]'))
        cursor = end
    pieces.append(text[cursor:])
    return b''.join(pieces)


def holds_fragment(line: bytes) -> bool:
    for secret in secret_values:
        if len(secret) < FRAGMENT:
            continue
        for offset in range(len(secret) - FRAGMENT + 1):
            if secret[offset:offset + FRAGMENT] in line:
                return True
    return False


# Normalize invalid UTF-8 BEFORE matching: dropping bytes afterwards could join harmless pieces into a secret.
# Redact the whole capture once. The tail discards its partial first line below;
# reprocessing it would match known values inside generated redaction markers.
whole = redact(bytes(captured).decode('utf-8', errors='replace').encode())
tail = whole[-TAIL_LIMIT:]
lines = tail.split(b'\n')
if truncated or len(whole) > TAIL_LIMIT:
    lines = lines[1:]  # the first line may start mid-secret
kept: list[bytes] = []
used = 0
for line in reversed(lines):
    if holds_fragment(line):
        continue  # dropped, not replaced
    size = len(line) + (1 if kept else 0)
    if used + size > TAIL_LIMIT:
        break  # whole lines only: no byte cut can split a value or a character
    kept.append(line)
    used += size
output = {'exitCode': code, 'stderr': b'\n'.join(reversed(kept)).decode('utf-8')}
print(json.dumps(output))
sys.exit(code)
