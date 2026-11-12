"""The exact mailer-line rule. Node Python is an Ansible prerequisite; guests need only sh/coreutils."""
import json
import signal
import subprocess
import sys

# These are fixed programs, never caller-supplied shell. No file content enters argv or output.
INSPECT = 'if [ -L "$1" ]; then printf link; elif [ ! -e "$1" ]; then printf absent; elif [ -f "$1" ]; then printf file; else printf other; fi'
# One reserved staging name, also removable through the Node's file API while the guest is stopped.
TEMPORARY = '/srv/workspace/.env.orbit-mail'
KEY = b'MAIL_MAILER'
REPLACE = """set -eu
umask 077
test ! -L "$1" && test -f "$1"
cat > "$2"
chown --reference="$1" "$2"
chmod --reference="$1" "$2"
mv -f -- "$2" "$1"
"""


def apply(prefix, path, remove):
    def command(argv, data=None):
        return subprocess.run(argv, input=data, capture_output=True, check=True).stdout

    try:
        # Entry cleanup precedes even the .env existence/type read. Incus file access works offline.
        command(remove)
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
        # A conservative guard, not a dotenv parser. Any earlier multiline quote opener makes the match unsafe.
        for earlier in lines[:index]:
            if b'=' not in earlier:
                continue
            counts = {34: 0, 39: 0}
            escaped = False
            for byte in earlier.split(b'=', 1)[1]:
                if escaped:
                    escaped = False
                elif byte == 92:
                    escaped = True
                elif byte in counts:
                    counts[byte] += 1
            if any(count % 2 for count in counts.values()):
                return {'status': 'left_as_written', 'lines': numbers}
        if body == b'MAIL_MAILER=smtp':
            return {'status': 'already_smtp'}
        if body != b'MAIL_MAILER=log':
            return {'status': 'left_as_written', 'lines': numbers}
        lines[index] = b'MAIL_MAILER=smtp' + line[len(body):]
        command(prefix + ['sh', '-c', REPLACE, 'sh', path, TEMPORARY], b'\n'.join(lines))
        return {'status': 'changed', 'lines': numbers}
    finally:
        # This runs on the Node, outside the guest killed by a forced container stop.
        command(remove)


def interrupted(_signum, _frame):
    raise InterruptedError


def main():
    for name in [signal.SIGTERM, signal.SIGHUP, signal.SIGINT]:
        signal.signal(name, interrupted)
    try:
        project, container = sys.argv[1:]
        result = apply(['incus', '--project', project, 'exec', container, '--'], '/srv/workspace/.env',
                       ['incus', '--project', project, 'file', 'delete', '--force', container + TEMPORARY])
    except (OSError, ValueError, subprocess.SubprocessError):
        print(json.dumps({'error_code': 'env.configuration_invalid'}))
        return 1
    print(json.dumps(result))
    return 0


if __name__ == '__main__':
    sys.exit(main())
