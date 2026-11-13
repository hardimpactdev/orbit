"""Replace or add only the named keys in a container's .env; every other byte stays.

Runs on the Node (Ansible already needs its Python). argv: Incus project, container, .env path in the container.
stdin: {"lines": {"KEY": "KEY=\"value\""}}, the rendered lines from the Gateway's vault copy. Values never reach argv,
stdout or stderr: the result names keys only. A key whose value holds an unescaped `${` stays as the Project wrote it.
"""
import hashlib
import io
import json
import os
import re
import signal
import subprocess
import sys
import tarfile

INSPECT = 'if [ -L "$1" ]; then printf link; elif [ ! -e "$1" ]; then printf absent; elif [ -f "$1" ]; then printf file; else printf other; fi'
# Stage beside the file, so the rename stays on one filesystem; the same metadata copy as the mailer line.
REPLACE = """set -eu
umask 077
test ! -L "$1" && test -f "$1"
: > "$2"
setfacl -b -- "$2"
chmod 0600 -- "$2"
cat > "$2"
cp --attributes-only --preserve=mode,ownership,timestamps,xattr -- "$1" "$2"
acl=$(getfacl --absolute-names -- "$1")
printf '%s\n' "$acl" | setfacl --set-file=- -- "$2"
"""
BOM = b'\xef\xbb\xbf'
# phpdotenv's trim set for names and comment lines.
TRIM = b' \n\r\t\x00\x0b'
KEY = re.compile(r'[A-Z][A-Z0-9_]*')
# An unescaped `${`: Orbit's own quoting writes `$` as `\\$`, so a written value is never read as a reference.
REFERENCE = re.compile(rb'(?<!\\)\$\{')


class Refused(Exception):
    def __init__(self, detail):
        super().__init__(detail)
        self.detail = detail


def multiline_stop(line, started):
    """phpdotenv Lines::looksLikeMultilineStop: count quotes not after a backslash, once `\\\\` pairs are gone."""
    count = len(re.findall(rb'(?<!\\)"', line.replace(b'\\\\', b'')))
    return count > 1 if started else count >= 1


def multiline_start(line):
    """phpdotenv Lines::looksLikeMultilineStart: a `="` before any `#`, not closed on the same line."""
    pos = line.find(b'="')
    if pos < 0:
        return False
    hash_at = line.find(b'#')
    if 0 <= hash_at < pos:
        return False
    return not multiline_stop(line, True)


def entry_name(entry):
    """phpdotenv EntryParser's name: text before the first `=`, trimmed, without `export ` and outer quotes."""
    if b'=' not in entry:
        return None
    name = entry.split(b'=', 1)[0].strip(TRIM)
    if len(name) > 6 and name[:6] == b'export' and name[6:7].isspace():
        name = name[6:].lstrip(b' \t\n\r\x0b\x0c')
    if len(name) >= 3 and name[:1] == name[-1:] and name[:1] in (b'"', b"'"):
        name = name[1:-1]
    return name.decode('latin-1')


def assignments(lines):
    """Map each key to the (first, last) line indexes of its entries, grouped as phpdotenv groups lines."""
    found = {}
    opened = None
    for index, raw in enumerate(lines):
        line = raw[:-1] if raw.endswith(b'\r') else raw
        if index == 0 and line.startswith(BOM):
            line = line[len(BOM):]
        if opened is None and multiline_start(line):
            opened = (index, line)
            continue
        if opened is not None:
            if not multiline_stop(line, False):
                continue
            start, line = opened
            opened = None
        else:
            start = index
        if line.strip(TRIM)[:1] in (b'', b'#'):
            continue
        name = entry_name(line)
        if name is not None:
            found.setdefault(name, []).append((start, index))
    if opened is not None:
        raise Refused('line %d' % (opened[0] + 1))
    return found


def edit(data, wanted):
    """The new bytes and the public key lists. `wanted` maps key to its rendered line."""
    if re.search(rb'\r(?!\n)', data):
        # phpdotenv also splits on a bare CR; this rule edits LF and CRLF files only.
        raise Refused('line endings')
    lines = data.split(b'\n')
    found = assignments(lines)
    result = {'written': [], 'added': [], 'kept': [], 'unchanged': []}
    appended = []
    for key in sorted(wanted):
        line = wanted[key].encode()
        spans = found.get(key, [])
        if not spans:
            appended.append(line)
            result['added'].append(key)
            continue
        if any(REFERENCE.search(b'\n'.join(lines[start:end + 1])) for start, end in spans):
            result['kept'].append(key)
            continue
        if any(start != end for start, end in spans):
            raise Refused('line %d' % (spans[0][0] + 1))
        changed = False
        for start, _ in spans:
            ending = b'\r' if lines[start].endswith(b'\r') else b''
            bom = BOM if start == 0 and lines[0].startswith(BOM) else b''
            if lines[start] != bom + line + ending:
                lines[start] = bom + line + ending
                changed = True
        result['written' if changed else 'unchanged'].append(key)
    if appended:
        if lines[-1] != b'':
            lines.append(b'')
        lines[-1:] = appended + [b'']
    return b'\n'.join(lines), result


def apply(prefix, path, temporary, remove, wanted):
    def command(argv, data=None):
        return subprocess.run(argv, input=data, capture_output=True, check=True).stdout

    def metadata(filename):
        archive = command(prefix + ['tar', '--format=pax', '--xattrs', '--xattrs-include=*',
                                    '--numeric-owner', '--atime-preserve=system', '-cf', '-', '--', filename])
        with tarfile.open(fileobj=io.BytesIO(archive), mode='r:') as snapshot:
            members = snapshot.getmembers()
            if len(members) != 1 or not members[0].isreg():
                raise ValueError
            member = members[0]
            attributes = {hashlib.sha256(key.encode('utf-8', 'surrogateescape')).digest():
                          hashlib.sha256(value.encode('utf-8', 'surrogateescape')).digest()
                          for key, value in member.pax_headers.items() if key.startswith('SCHILY.xattr.')}
        acl = command(prefix + ['getfacl', '--absolute-names', '-c', '--', filename])
        return member.uid, member.gid, member.mode, hashlib.sha256(acl).digest(), attributes

    def cleanup():
        result = subprocess.run(remove, capture_output=True, check=False)
        if result.returncode == 1 and result.stdout == b'' and result.stderr == b'Error: file does not exist\n':
            return
        result.check_returncode()

    try:
        cleanup()
        kind = command(prefix + ['sh', '-c', INSPECT, 'sh', path])
        if kind != b'file':
            raise Refused('file ' + kind.decode('ascii', 'replace'))
        data = command(prefix + ['cat', '--', path])
        content, result = edit(data, wanted)
        if content == data:
            return {'status': 'unchanged', **result}
        command(prefix + ['sh', '-c', REPLACE, 'sh', path, temporary], content)
        # Fail closed before the rename: a metadata mismatch never replaces the original inode.
        if metadata(path) != metadata(temporary):
            raise ValueError
        command(prefix + ['mv', '-f', '--', temporary, path])
        return {'status': 'changed', **result}
    finally:
        cleanup()


def interrupted(_signum, _frame):
    raise InterruptedError


def main():
    for name in [signal.SIGTERM, signal.SIGHUP, signal.SIGINT]:
        signal.signal(name, interrupted)
    try:
        project, container, path = sys.argv[1:]
        wanted = json.loads(sys.stdin.buffer.read())['lines']
        if not isinstance(wanted, dict) or not wanted or not all(
                isinstance(key, str) and KEY.fullmatch(key) and isinstance(line, str)
                and line.startswith(key + '=') and '\n' not in line and '\r' not in line for key, line in wanted.items()):
            raise Refused('input')
        temporary = os.path.join(os.path.dirname(path), '.env.orbit-keys')
        result = apply(['incus', '--project', project, 'exec', container, '--'], path, temporary,
                       ['incus', '--project', project, 'file', 'delete', '--force', container + temporary], wanted)
    except Refused as refusal:
        print(json.dumps({'error_code': 'env.configuration_invalid', 'error_detail': refusal.detail}))
        return 1
    except (OSError, ValueError, KeyError, TypeError, tarfile.TarError, subprocess.SubprocessError, InterruptedError) as error:
        result = {'error_code': 'env.configuration_invalid'}
        # Never forward tool output: a tool can echo input or file contents. Only fixed Incus diagnostics are public.
        if isinstance(error, subprocess.CalledProcessError) and error.stderr in (
                b'Error: Permission denied\n', b'Error: not authorized\n', b'Error: The instance is not running\n'):
            result['error_detail'] = error.stderr.decode().rstrip('\n')
        print(json.dumps(result))
        return 1
    print(json.dumps(result))
    return 0


if __name__ == '__main__':
    sys.exit(main())
