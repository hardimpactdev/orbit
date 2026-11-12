"""The exact mailer-line rule. Node Python is an Ansible prerequisite; guests use sh/coreutils, acl and stock GNU tar."""
import hashlib
import io
import json
import re
import signal
import subprocess
import sys
import tarfile

# These are fixed programs, never caller-supplied shell. No file content enters argv or output.
INSPECT = 'if [ -L "$1" ]; then printf link; elif [ ! -e "$1" ]; then printf absent; elif [ -f "$1" ]; then printf file; else printf other; fi'
# One reserved staging name, also removable through the Node's file API while the guest is stopped.
TEMPORARY = '/srv/workspace/.env.orbit-mail'
KEY = b'MAIL_MAILER'
REPLACE = """set -eu
umask 077
test ! -L "$1" && test -f "$1"
# Remove inherited access grants before staging any private bytes.
: > "$2"
setfacl -b -- "$2"
chmod 0600 -- "$2"
cat > "$2"
cp --attributes-only --preserve=mode,ownership,timestamps,xattr -- "$1" "$2"
acl=$(getfacl --absolute-names -- "$1")
printf '%s\n' "$acl" | setfacl --set-file=- -- "$2"
"""


def prefix_guard_line(lines):
    """Accept only whole, single-line shapes above the candidate; never emulate dotenv quote state."""
    for number, raw in enumerate(lines, 1):
        line = raw[:-1] if raw.endswith(b'\r') else raw
        if b'\r' in line:
            return number
        if number == 1 and line.startswith(b'\xef\xbb\xbf'):
            line = line[3:]
        if re.fullmatch(rb'[ \t]*', line) or re.fullmatch(rb"[ \t]*#[^'\"\\]*", line):
            continue
        assignment = re.fullmatch(rb'[A-Za-z_][A-Za-z0-9_.]*=(.*)', line)
        if assignment is None:
            return number
        value = assignment[1]
        if not any(byte in value for byte in (b"'", b'"', b'\\', b'#')) and not re.search(rb'\s', value):
            continue
        if len(value) >= 2 and value[:1] == value[-1:] and value[:1] in (b"'", b'"'):
            inside = value[1:-1]
            if value[:1] not in inside and b'\\' not in inside:
                # phpdotenv's raw-line grouping also sees this marker inside single quotes.
                if value[:1] != b"'" or b'="' not in inside:
                    continue
        return number
    return None


def apply(prefix, path, remove):
    def command(argv, data=None):
        return subprocess.run(argv, input=data, capture_output=True, check=True).stdout

    def metadata(filename):
        # getfattr is not in the image. GNU tar is an Ubuntu Essential package and
        # exports every xattr (including binary values) in PAX headers. Archive bytes
        # stay in Node memory, never in a file or public output; Node Python is required by Ansible.
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
        # Incus 7: --force does not suppress ENOENT. Accept only this exact response.
        if result.returncode == 1 and result.stdout == b'' and result.stderr == b'Error: file does not exist\n':
            return
        result.check_returncode()

    try:
        # Entry cleanup precedes even the .env existence/type read. Incus file access works offline.
        cleanup()
        kind = command(prefix + ['sh', '-c', INSPECT, 'sh', path])
        if kind == b'absent':
            return {'status': 'not_present'}
        if kind != b'file':
            return {'status': 'left_as_written', 'lines': []}
        # incus exec preserves every byte on stdout. It does not print the bytes to Ansible.
        data = command(prefix + ['cat', '--', path])
        lines = data.split(b'\n')
        count = data.count(KEY)
        if count == 0:
            return {'status': 'not_present'}
        numbers = [i + 1 for i, line in enumerate(lines) for _ in range(line.count(KEY))]
        if count != 1:
            return {'status': 'left_as_written', 'lines': numbers}
        index = numbers[0] - 1
        line = lines[index]
        body = line[:-1] if line.endswith(b'\r') and index < len(lines) - 1 else line
        guard_line = prefix_guard_line(lines[:index])
        if guard_line is not None:
            return {'status': 'left_as_written', 'lines': numbers, 'guard_line': guard_line}
        replacements = {
            b'MAIL_MAILER=log': b'MAIL_MAILER=smtp',
            b'MAIL_MAILER="log"': b'MAIL_MAILER="smtp"',
            b"MAIL_MAILER='log'": b"MAIL_MAILER='smtp'",
        }
        if body in replacements.values():
            return {'status': 'already_smtp'}
        if body not in replacements:
            return {'status': 'left_as_written', 'lines': numbers}
        lines[index] = replacements[body] + line[len(body):]
        command(prefix + ['sh', '-c', REPLACE, 'sh', path, TEMPORARY], b'\n'.join(lines))
        # Fail closed before rename: no mismatch can replace the original inode.
        if metadata(path) != metadata(TEMPORARY):
            raise ValueError
        command(prefix + ['mv', '-f', '--', TEMPORARY, path])
        return {'status': 'changed', 'lines': numbers}
    finally:
        # This runs on the Node, outside the guest killed by a forced container stop.
        cleanup()


def interrupted(_signum, _frame):
    raise InterruptedError


def main():
    for name in [signal.SIGTERM, signal.SIGHUP, signal.SIGINT]:
        signal.signal(name, interrupted)
    try:
        project, container = sys.argv[1:]
        result = apply(['incus', '--project', project, 'exec', container, '--'], '/srv/workspace/.env',
                       ['incus', '--project', project, 'file', 'delete', '--force', container + TEMPORARY])
    except (OSError, ValueError, tarfile.TarError, subprocess.SubprocessError) as error:
        result = {'error_code': 'env.configuration_invalid'}
        # Never forward arbitrary tool output: a tool can echo input or file contents.
        # Only complete, fixed Incus diagnostics are public; everything else stays private.
        if isinstance(error, subprocess.CalledProcessError) and error.stderr in (
                b'Error: Permission denied\n', b'Error: not authorized\n',
                b'Error: The instance is not running\n'):
            result['error_detail'] = error.stderr.decode().rstrip('\n')
        print(json.dumps(result))
        return 1
    print(json.dumps(result))
    return 0


if __name__ == '__main__':
    sys.exit(main())
