"""Keep the pinned shape tools in a nested-fleet default Instance (docs/reference/development-containers.md#the-pinned-shape-tools).

The fleet refresh runs it as root in the default's container, before the seed snapshot, with the workspace as its one argument:
`python3 -I -c <this file> /srv/workspace`. A copy of the seed then holds the tools at the place where the proof runner and
bin/swamp-shape-gate look for them, so no copy builds them and no copy needs python3-venv.

The workspace's own bin/swamp-shape-gate names and builds the tools from its three pin files, so the names are those of the commit the
refresh checked out, and there is no second copy of a version or of the naming rule. The gate and the proof runner take the tools as
ready when the venv holds `.complete`, so this script writes that marker only when the collections are in place too: it removes the
marker that `tool_path` writes, installs the collections, and writes it again. A failure or a stop in between leaves a venv that no
reader takes as ready, and the runner builds its own tools in its cache.

When the tools of these pins are ready, it only reads them, and removes the tools of other pins when there are any. Otherwise it
installs python3-venv when it is missing, builds the venv with the gate's own `tool_path`, installs the collections into a partial
folder and renames it, unless they are there already, and removes the tools of other pins.

It never fails: its last line on standard output is one JSON object, {"tools": "present" | "built" | "failed", "reason": ...}.
Everything its programs print goes to standard error. A failed step leaves no folder that a reader takes as complete.
"""
import json
import os
import runpy
import shutil
import subprocess
import sys
from pathlib import Path

PACKAGE = 'python3-venv'


def names(workspace):
    """The gate of the workspace, its tools folder, and the venv and collections folders of the workspace's pins."""
    gate = runpy.run_path(str(Path(workspace) / 'bin/swamp-shape-gate'))
    tools = Path(gate['SYSTEM_TOOLS'])
    return gate, tools, tools / gate['venv_name'](), gate['collections_path'](tools)


def collections_ready(collections):
    return (collections / 'ansible_collections').is_dir()


def package_installed():
    read = subprocess.run(['dpkg-query', '--show', '--showformat=${Status}', PACKAGE], capture_output=True, text=True, check=False)
    return read.returncode == 0 and read.stdout.strip() == 'install ok installed'


def install_package():
    # The image deletes the package index, and apt reaches its mirror through the egress proxy that its own setting names.
    # An update timer of the container may hold the apt lock for a while: wait for it up to 120 s instead of failing at once.
    environment = {**os.environ, 'DEBIAN_FRONTEND': 'noninteractive'}
    lock = ['-o', 'DPkg::Lock::Timeout=120']
    subprocess.run(['apt-get', *lock, 'update'], check=True, env=environment)
    subprocess.run(['apt-get', *lock, 'install', '--yes', '--no-install-recommends', '--', PACKAGE], check=True, env=environment)


def build_venv(gate, tools, venv):
    gate['tool_path'](tools)
    # Not ready until the collections are in place: see the module docstring.
    (venv / '.complete').unlink()


def install_collections(workspace, venv, collections):
    if collections_ready(collections):
        return
    partial = collections.with_name(collections.name + '.partial')
    shutil.rmtree(partial, ignore_errors=True)
    shutil.rmtree(collections, ignore_errors=True)
    subprocess.run([str(venv / 'bin/ansible-galaxy'), 'collection', 'install', '-r', str(Path(workspace) / 'swamp/ansible/requirements.yml'),
                    '-p', str(partial)], check=True)
    partial.rename(collections)


def mark_ready(venv):
    (venv / '.complete').write_text('')


def other_pins(tools, venv, collections):
    return [path for path in tools.iterdir() if path.name.startswith(('venv-', 'collections-')) and path not in (venv, collections)]


def remove_other_pins(tools, venv, collections):
    for path in other_pins(tools, venv, collections):
        shutil.rmtree(path)


def run(steps, venv, collections):
    for reason, step in steps:
        try:
            step()
        except Exception as error:  # noqa: BLE001 - the refresh goes on; the reason names the step
            print(f'{reason}: {error}', file=sys.stderr, flush=True)
            return {'tools': 'failed', 'reason': reason, 'venv': venv.name, 'collections': collections.name}
    return None


def keep(workspace):
    try:
        gate, tools, venv, collections = names(workspace)
    except Exception:  # noqa: BLE001 - any failure to read the gate is the one reason `names`
        return {'tools': 'failed', 'reason': 'names'}
    names_of = {'venv': venv.name, 'collections': collections.name}
    if (venv / '.complete').is_file() and collections_ready(collections):
        # A cleanup that failed before is done here, so old tools do not stay in every seed.
        if not tools.is_dir() or not other_pins(tools, venv, collections):
            return {'tools': 'present', 'reason': 'current', **names_of}
        return run((('cleanup', lambda: remove_other_pins(tools, venv, collections)),), venv, collections) or {
            'tools': 'present', 'reason': 'current', **names_of}
    # A reader of /opt/orbit-shape-gate is `agent`, not root.
    os.umask(0o022)
    return run((
        ('package', lambda: None if package_installed() else install_package()),
        ('venv', lambda: build_venv(gate, tools, venv)),
        ('collections', lambda: install_collections(workspace, venv, collections)),
        ('collections', lambda: mark_ready(venv)),
        ('cleanup', lambda: remove_other_pins(tools, venv, collections)),
    ), venv, collections) or {'tools': 'built', 'reason': 'missing', **names_of}


def main(argv):
    # Standard output carries only the result: every program the steps run writes to standard error.
    result_stream = os.fdopen(os.dup(1), 'w')
    sys.stdout.flush()
    os.dup2(2, 1)
    try:
        result = keep(argv[0])
    finally:
        sys.stdout.flush()
    print(json.dumps(result), file=result_stream, flush=True)


if __name__ == '__main__':
    main(sys.argv[1:])
