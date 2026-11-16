#!/usr/bin/env python3
"""Prove one unpacked static PHP build on the CI runner. See docs/reference/compute-drivers.md#the-static-php-workflow.

  tools/static-php/prove.py build --bin DIR --config DIR  # glibc-only linkage, and the CLI-only extensions in the CLI only
  tools/static-php/prove.py coverage --clover FILE --source PATH         # exit 1 unless the clover report covers lines of PATH

`build` reads the CLI-only extensions from the archive's build.json. It fails unless php, frankenphp and each CLI-only extension
need only glibc, no newer than Ubuntu 26.04's. It then writes the CLI-only php-cli.ini that a container has into the config folder DIR,
and fails unless `php -m` with it lists each CLI-only extension and a page that `frankenphp php-server` serves with it loads none.
`coverage` reads the clover report of a Pest run with that ini: PCOV measured only if a source file the tests run has covered lines.

A manual run from main runs main's copy of this file, not the built branch's, so a branch's change to its own proof is never
what proves it. Only a reviewed ref may run, because the ref's code runs in the same job. This file reads only the unpacked archive
and imports nothing of the ref. `bin/static-php-build check` compares the extensions with the ref's recipe. This file is not part
of the build id.
"""
import argparse
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import tempfile
import time
import xml.etree.ElementTree as ElementTree
import urllib.error
import urllib.request

# The shared libraries of glibc itself, the only ones a binary or a CLI-only extension may need: the containers have nothing else.
GLIBC_LIBRARIES = {'libc.so.6', 'libm.so.6', 'libpthread.so.0', 'libdl.so.2', 'librt.so.1', 'libresolv.so.2', 'libutil.so.1',
                   'ld-linux-x86-64.so.2'}
LOADER = '/lib64/ld-linux-x86-64.so.2'
# Ubuntu 26.04's glibc, which the containers run. It is pinned, not read from the runner with `ldd --version`: the limit is the
# containers' glibc, and a runner image with a newer glibc must not raise it.
GLIBC_CEILING = (2, 43)

# Answers the SAPI that served it and which of the CLI-only extensions it loaded.
SERVED_PROBE = "<?php echo json_encode(['sapi' => PHP_SAPI, 'loaded' => array_values(array_filter(%s, 'extension_loaded'))]);\n"


class ProofError(Exception):
    pass


def linkage_problems(name, readelf):
    """Every way one file needs more than glibc no newer than Ubuntu 26.04's, read from
    `readelf --wide --program-headers --dynamic --version-info`. A static-pie file has no interpreter and needs nothing."""
    found = []
    interpreter = re.search(r'\[Requesting program interpreter: (.+?)\]', readelf)
    if interpreter and interpreter.group(1) != LOADER:
        found.append(f'{name} asks for the program interpreter {interpreter.group(1)}, not {LOADER}.')
    other = sorted(set(re.findall(r'\(NEEDED\)\s+Shared library: \[(.+?)\]', readelf)) - GLIBC_LIBRARIES)
    if other:
        found.append(f'{name} needs {", ".join(other)}; only glibc may stay shared.')
    versions = [tuple(int(part) for part in version.split('.')) for version in re.findall(r'\bGLIBC_(\d+(?:\.\d+)+)\b', readelf)]
    if versions and max(versions) > GLIBC_CEILING:
        found.append(f'{name} needs GLIBC_{".".join(map(str, max(versions)))}, newer than Ubuntu 26.04\'s glibc'
                     f' {".".join(map(str, GLIBC_CEILING))}.')
    return found


def readelf(path):
    return subprocess.run(['readelf', '--wide', '--program-headers', '--dynamic', '--version-info', str(path)], check=True,
                          capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout


def cli_ini(modules, cli_extensions):
    """The CLI-only php-cli.ini that a container has: one `extension=` line for each CLI-only extension of the build."""
    return ''.join(f'extension={modules / name}.so\n' for name in cli_extensions)


def cli_problems(listed, cli_extensions):
    """What `php -m` with the CLI ini lacks of the CLI-only extensions."""
    modules = {line.strip().lower() for line in listed.splitlines()}
    missing = [name for name in cli_extensions if name.lower() not in modules]
    return [f'php -m with php-cli.ini lacks {", ".join(missing)}.'] if missing else []


def served_problems(answer):
    """What the served probe shows wrong: it must run under FrankenPHP's own SAPI and load no CLI-only extension."""
    found = []
    if answer['sapi'] != 'frankenphp':
        found.append(f'The served probe ran under the SAPI {answer["sapi"]}, not frankenphp.')
    if answer['loaded']:
        found.append(f'frankenphp php-server loads {", ".join(answer["loaded"])}; only the PHP CLI may.')
    return found


def serve(frankenphp, env, cli_extensions):
    """The answer of the probe page that `frankenphp php-server`, with the environment `env`, serves from an empty root."""
    # The probe is local: no proxy of the environment may answer it.
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    with tempfile.TemporaryDirectory() as root, socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
        probe.close()  # Free the port for the server.
        Path(root, 'index.php').write_text(SERVED_PROBE % json.dumps(cli_extensions))
        server = subprocess.Popen([str(frankenphp), 'php-server', '--root', root, '--listen', f'127.0.0.1:{port}'], cwd=root, env=env,
                                  stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        body = None
        try:
            for _ in range(30):
                try:
                    with opener.open(f'http://127.0.0.1:{port}/', timeout=5) as response:
                        body = response.read().decode()
                    break
                except urllib.error.HTTPError as error:
                    body = f'HTTP {error.code}: {error.read().decode()}'
                    break
                except OSError:
                    if server.poll() is not None:
                        break
                    time.sleep(1)
        finally:
            server.terminate()
            try:
                log = server.communicate(timeout=30)[0]
            except subprocess.TimeoutExpired:
                server.kill()
                log = server.communicate()[0]
    try:
        answer = json.loads(body or '')
    except ValueError:
        answer = None
    if not isinstance(answer, dict) or not isinstance(answer.get('loaded'), list):
        raise ProofError(f'frankenphp php-server did not answer the probe: {body or "no answer"}\n{log}') from None
    return answer


def prove(folder, config):
    """The CI proof of one unpacked build, whose `bin` folder is `folder`."""
    folder = folder.resolve()
    cli_extensions = json.loads((folder.parent / 'build.json').read_text()).get('cli_extensions')
    if not isinstance(cli_extensions, list):
        raise ProofError('build.json names no cli_extensions list: the archive is not a glibc build with CLI-only extensions.')
    modules = folder.parent / 'modules'
    files = [folder / 'php', folder / 'frankenphp', *(modules / f'{name}.so' for name in cli_extensions)]
    problems = [problem for path in files for problem in linkage_problems(path.relative_to(folder.parent), readelf(path))]
    if problems:
        raise ProofError('The static PHP build needs more than glibc:\n  ' + '\n  '.join(problems))
    print('php, frankenphp and the CLI-only extensions need only glibc, no newer than Ubuntu 26.04\'s.')

    # As in a container, the config folder holds php-cli.ini. The CLI reads it (SAPI name cli); the FrankenPHP server
    # (SAPI name frankenphp) never does.
    config = config.resolve()
    config.mkdir(parents=True, exist_ok=True)
    (config / 'php-cli.ini').write_text(cli_ini(modules, cli_extensions))
    env = {**os.environ, 'PHPRC': str(config)}
    listed = subprocess.run([str(folder / 'php'), '-m'], check=True, capture_output=True, text=True, env=env, stdin=subprocess.DEVNULL)
    problems = cli_problems(listed.stdout, cli_extensions) + served_problems(serve(folder / 'frankenphp', env, cli_extensions))
    if problems:
        raise ProofError('Only the PHP CLI may load the CLI-only extensions:\n  ' + '\n  '.join(problems))
    print(f'With {config / "php-cli.ini"}, php -m lists {", ".join(cli_extensions) or "nothing more"},'
          ' and frankenphp php-server loads none of them.')


def coverage_problems(clover, source):
    """Why a clover report does not show that the coverage driver measured `source`, a path that the report's file names end with."""
    try:
        files = [element for element in ElementTree.fromstring(clover).iter('file') if element.get('name', '').endswith('/' + source)]
    except ElementTree.ParseError as error:
        return [f'The clover report is not XML: {error}.']
    if not files:
        return [f'The clover report has no file {source}.']
    metrics = files[0].find('metrics')
    covered = int(metrics.get('coveredstatements', '0')) if metrics is not None else 0
    return [] if covered > 0 else [f'The clover report covers no line of {source}.']


def coverage(clover, source):
    if not clover.is_file():
        raise ProofError(f'Pest wrote no clover report at {clover}: it found no coverage driver.')
    problems = coverage_problems(clover.read_text(), source)
    if problems:
        raise ProofError('Pest did not measure coverage:\n  ' + '\n  '.join(problems))
    print(f'Pest measured coverage of {source}.')


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest='command', required=True)
    build_command = commands.add_parser('build')
    build_command.add_argument('--bin', required=True, type=Path)
    build_command.add_argument('--config', required=True, type=Path)
    coverage_command = commands.add_parser('coverage')
    coverage_command.add_argument('--clover', required=True, type=Path)
    coverage_command.add_argument('--source', required=True)
    args = parser.parse_args()
    try:
        if args.command == 'build':
            prove(args.bin, args.config)
        else:
            coverage(args.clover, args.source)
    except (ProofError, OSError, KeyError, ValueError, subprocess.CalledProcessError) as error:
        print(f'static-php prove: {error}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
