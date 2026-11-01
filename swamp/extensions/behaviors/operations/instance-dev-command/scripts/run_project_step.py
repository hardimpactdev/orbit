"""Execute an admitted Project command and retain bounded, redacted output tails."""
import hashlib
import json
import os
import re
import stat
import subprocess
import sys
from pathlib import Path

ESCAPES = {'n': '\n', 'r': '\r', '\\': '\\', '"': '"', '$': '$'}


def unescape(value: str) -> str:
    # The canonical writer escapes these five characters in double-quoted values.
    return re.sub(r'\\([nr\\"$])', lambda match: ESCAPES[match[1]], value)


def closing_quote(text: str, quote: str) -> int:
    escaped = False
    for index, character in enumerate(text):
        if escaped:
            escaped = False
        elif character == '\\' and quote == '"':
            escaped = True
        elif character == quote:
            return index
    return -1


def dotenv_secrets(text: str) -> set[str]:
    """Every value phpdotenv could read from .env, read conservatively so no real value is missed."""
    found: set[str] = set()
    lines = re.split(r'\r\n|\n|\r', text)  # phpdotenv splits only on these, not on U+2028 and friends
    index = 0
    while index < len(lines):
        line = lines[index]
        index += 1
        stripped = line.strip()
        if not stripped or stripped.startswith('#'):
            continue
        if '=' not in line:
            found.add(stripped)  # backstop: an unparsed line may still be part of a value
            continue
        value = line.split('=', 1)[1].strip()
        found.add(value)
        if value[:1] in ('"', "'"):
            quote, body = value[0], value[1:]
            while closing_quote(body, quote) < 0 and index < len(lines):
                body += '\n' + lines[index]  # multi-line quoted value, such as a PEM key
                index += 1
            end = closing_quote(body, quote)
            inner = body[:end] if end >= 0 else body
            for item in (inner, unescape(inner) if quote == '"' else inner):
                found.update([item, *item.splitlines(), *re.split(r'\r\n|\n|\r', item)])
        else:
            found.add(re.split(r'\s+#', value, maxsplit=1)[0].strip())  # inline comment on an unquoted value
    found.discard('')
    return found


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
# Only collect strings for redaction. This does not load the Node file into the command's environment.
try:
    descriptor = os.open('.env', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
except FileNotFoundError:
    pass
else:
    with os.fdopen(descriptor, 'r', encoding='utf-8') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_size > 1048576:
            raise SystemExit(1)
        redactions.update(dotenv_secrets(stream.read()))
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


pattern = re.compile(b'|'.join(re.escape(secret) for secret in secrets)) if secrets else None


def redact(text: bytes) -> bytes:
    # One pass never reprocesses a marker that another match inserted.
    return pattern.sub(b'[REDACTED]', text) if pattern else text


def holds_fragment(line: bytes) -> bool:
    for secret in secret_values:
        if len(secret) < FRAGMENT:
            continue
        for offset in range(len(secret) - FRAGMENT + 1):
            if secret[offset:offset + FRAGMENT] in line:
                return True
    return False


# Normalize invalid UTF-8 BEFORE matching: dropping bytes afterwards could join harmless pieces into a secret.
# Redact the whole capture first, then cut the tail, then redact the tail again for matches split at its start.
whole = redact(bytes(captured).decode('utf-8', errors='replace').encode())
tail = redact(whole[-TAIL_LIMIT:])
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
