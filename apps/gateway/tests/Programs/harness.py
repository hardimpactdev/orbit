"""Run remote programs as the Gateway does: `python3 -I -c <bootstrap> <name>` with the assembled program, one NUL
byte, and the JSON input on standard input. The assembly follows RemoteProgramCatalog: the libraries on the header's
`Uses` line, in order, then the program."""
import json
import os
import re
import subprocess
from pathlib import Path

REMOTE = Path(__file__).resolve().parents[2] / 'resources' / 'remote'


def header(text):
    fields = {}
    for line in text.split('\n'):
        if not line.startswith('#'):
            break
        match = re.fullmatch(r'# ([A-Z][a-z]+): (.+)', line)
        if match:
            fields.setdefault(match[1], match[2].strip())
    return fields


def with_newline(text):
    return text if text.endswith('\n') else text + '\n'


def program_path(name):
    area, stem = name.split('.')
    return REMOTE / area / f'{stem}.py'


def assemble(name):
    """The assembled source of a Python program."""
    text = program_path(name).read_text()
    libraries = header(text).get('Uses', '').split()
    return ''.join(with_newline((REMOTE / library).read_text()) for library in libraries) + with_newline(text)


def library(*names):
    """The namespace of the listed libraries, as an assembled program sees it."""
    namespace = {'__name__': 'library'}
    source = ''.join(with_newline((REMOTE / name).read_text()) for name in names)
    exec(compile(source, 'library', 'exec'), namespace)
    return namespace


def execute(name, payload, environment=None, replace=None):
    """Run the program and return the completed process. `replace` maps source text to a fault to inject."""
    source = assemble(name)
    for old, new in (replace or {}).items():
        if old not in source:
            raise AssertionError(f'{name} does not contain {old!r}')
        source = source.replace(old, new)
    return execute_source(name, source, json.dumps({'git_read': {}, **payload}).encode(), environment)


def execute_source(name, source, payload, environment=None, **options):
    """Start `source` through the bootstrap with the raw JSON `payload`, as SshProgramRunner does."""
    return subprocess.run(
        ['python3', '-I', '-c', (REMOTE / 'bootstrap.py').read_text(), name],
        input=source.encode() + b'\0' + payload,
        capture_output=True,
        env=dict(os.environ, **(environment or {})),
        check=False,
        **options,
    )


def result(name, payload, **options):
    """The program's (outcome, data). The program must exit 0 and print one JSON line."""
    completed = execute(name, payload, **options)
    if completed.returncode != 0:
        raise AssertionError(f'{name} exited {completed.returncode}: {completed.stderr.decode()}')
    lines = completed.stdout.decode().split('\n')
    if len(lines) != 2 or lines[1] != '':
        raise AssertionError(f'{name} printed {completed.stdout!r}')
    parsed = json.loads(lines[0])
    if set(parsed) != {'outcome', 'data'} or parsed['outcome'] not in header(program_path(name).read_text())['Outcomes'].split():
        raise AssertionError(f'{name} returned {parsed!r}')
    return parsed['outcome'], parsed['data']
