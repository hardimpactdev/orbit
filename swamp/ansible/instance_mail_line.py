"""The exact mailer-line rule. Node Python is an Ansible prerequisite; guests need only sh/coreutils."""
import json
import re
import subprocess
import sys

# These are fixed programs, never caller-supplied shell. No file content enters argv or output.
INSPECT = 'if [ -L "$1" ]; then printf link; elif [ ! -e "$1" ]; then printf absent; elif [ -f "$1" ]; then printf file; else printf other; fi'
REPLACE = """set -eu
test ! -L "$1" && test -f "$1"
tmp=$(mktemp "${1}.orbit-mail.XXXXXX")
trap 'rm -f -- "$tmp"' EXIT HUP INT TERM
cat > "$tmp"
chown --reference="$1" "$tmp"
chmod --reference="$1" "$tmp"
mv -f -- "$tmp" "$1"
"""
ASSIGNMENT = re.compile(rb"^[ \t]*(?:export[ \t]+)?[\"']?MAIL_MAILER[\"']?[ \t]*=")


def apply(prefix, path):
    def command(argv, data=None):
        return subprocess.run(argv, input=data, capture_output=True, check=True).stdout

    kind = command(prefix + ['sh', '-c', INSPECT, 'sh', path])
    if kind == b'absent':
        return {'status': 'not_present'}
    if kind != b'file':
        return {'status': 'left_as_written', 'lines': []}
    # incus exec preserves every byte on stdout. It does not print the bytes to Ansible.
    data = command(prefix + ['cat', '--', path])
    lines = data.split(b'\n')
    assignments = [i for i, line in enumerate(lines)
                   if ASSIGNMENT.match(line.removeprefix(b'\xef\xbb\xbf') if i == 0 else line)]
    if not assignments:
        return {'status': 'not_present'}
    numbers = [i + 1 for i in assignments]
    if len(assignments) != 1:
        return {'status': 'left_as_written', 'lines': numbers}
    index = assignments[0]
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
    command(prefix + ['sh', '-c', REPLACE, 'sh', path], b'\n'.join(lines))
    return {'status': 'changed', 'lines': numbers}


def main():
    try:
        project, container = sys.argv[1:]
        result = apply(['incus', '--project', project, 'exec', container, '--'], '/srv/workspace/.env')
    except (OSError, ValueError, subprocess.SubprocessError):
        print(json.dumps({'error_code': 'env.configuration_invalid'}))
        return 1
    print(json.dumps(result))
    return 0


if __name__ == '__main__':
    sys.exit(main())
