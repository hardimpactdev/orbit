#!/usr/bin/env python3
"""Proof: laravel-lsp finds a named view's Blade file in a fresh clone of a Laravel default, with nothing set up per clone.

Run it in a task copy of Orbit, as `agent`, after the nested Gateway serves the checkout and `node:converge` of the nested
app-dev Node has built the runtime folder from the recipe:

    python3 -I tools/proofs/laravel-lsp-definition.py PROJECT NODE [--keep]

PROJECT is the standing Laravel fixture Project of the inner fleet and NODE its app-dev Node. The script deploys the
Project's default, so its seed is current, creates a disposable clone with the copy's CLI and waits until it is active.
In the clone, as `agent` in a login shell, it asks `laravel-lsp-root` for the application folder and starts `laravel-lsp`
there over stdio. It sends initialize and initialized, opens the PHP file that calls `view('<name>')`, and asks for the
definition at the name. It asserts that the server answers with the clone's own Blade file, a file of the clone at the
artisan root, not the default's checkout. Then it shuts the server down and destroys the clone (unless --keep).

The transcript goes to stdout; every line that the reviewer needs is in it. Exit 0 only when every assertion holds.
"""
import argparse
import datetime
import hashlib
import json
import os
import re
import shlex
import subprocess
import sys
import threading
import time
from pathlib import Path

CHECKOUT = Path(__file__).resolve().parents[2]
RECIPE = CHECKOUT / 'apps/gateway/resources/machine-software/recipe.json'
ORBIT = ['php', str(CHECKOUT / 'apps/cli/orbit')]
NESTED_APP_DEV = 'nested-app-dev'       # the Incus guest of the inner app-dev Node
INSTANCE_PROJECT = 'orbit-instances'    # the Incus project of the Instance containers on that Node
VIEW_CALL = re.compile(r"view\('([a-z0-9_.\-]+)'\)")
ACTIVE_SECONDS = 900
DEFINITION_SECONDS = 120


def say(text=''):
    print(text, flush=True)


def step(title):
    say()
    say(f'## {title}')


def fail(text):
    say(f'FAIL: {text}')
    raise SystemExit(1)


def check(condition, text):
    say(('PASS: ' if condition else 'FAIL: ') + text)
    if not condition:
        raise SystemExit(1)


def orbit(*args, timeout=1500):
    """The copy's CLI against the nested Gateway, as the proof runner calls it (swamp/proof/runner/fleet.py)."""
    home = os.path.expanduser('~')
    env = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': home, 'ORBIT_HOME': home + '/.orbit', 'COLUMNS': '240'}
    say('$ orbit ' + ' '.join(args))
    done = subprocess.run([*ORBIT, *args], capture_output=True, text=True, timeout=timeout, env=env, cwd='/', stdin=subprocess.DEVNULL)
    try:
        parsed = json.loads(done.stdout)
    except ValueError:
        fail(f'orbit {args[0]} exit {done.returncode}, no JSON: {(done.stderr or done.stdout).strip()[-400:]}')
    if done.returncode != 0:
        fail(f'orbit {args[0]} exit {done.returncode}: ' + json.dumps({k: parsed.get(k) for k in ('id', 'status', 'failed_step', 'error_code', 'error', 'code')}))
    return parsed


def operation_line(operation):
    return json.dumps({k: operation.get(k) for k in ('id', 'kind', 'target_type', 'target_id', 'status', 'failed_step', 'error_code')})


def guest(*argv, timeout=120, stdin_text=None):
    """A program as root on the inner app-dev Node."""
    return subprocess.run(['sudo', 'incus', 'exec', NESTED_APP_DEV, '--', *argv], capture_output=True, text=True, timeout=timeout,
                          input=stdin_text, stdin=None if stdin_text is not None else subprocess.DEVNULL)


def as_agent_argv(container, script):
    """`script` as `agent` in a login shell of the Instance container: the user and PATH a client of the workspace has."""
    return ['sudo', 'incus', 'exec', NESTED_APP_DEV, '--', 'incus', 'exec', '--project', INSTANCE_PROJECT, container, '--',
            'runuser', '-l', 'agent', '-c', script]


def as_agent(container, script, timeout=120):
    say(f'[{container} as agent]$ {script}')
    done = subprocess.run(as_agent_argv(container, script), capture_output=True, text=True, timeout=timeout, stdin=subprocess.DEVNULL)
    for line in (done.stdout + done.stderr).rstrip('\n').splitlines():
        say('  ' + line)
    say(f'  (exit {done.returncode})')
    return done


class Server:
    """laravel-lsp over stdio: Content-Length framed JSON-RPC, every message logged to the transcript."""

    def __init__(self, argv):
        self.process = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.next_id, self.messages, self.lock = 1, [], threading.Condition()
        self.stderr = []
        threading.Thread(target=self.read, daemon=True).start()
        threading.Thread(target=self.read_stderr, daemon=True).start()

    def read_stderr(self):
        for line in self.process.stderr:
            self.stderr.append(line.decode(errors='replace').rstrip())

    def read(self):
        stream = self.process.stdout
        while True:
            headers = {}
            while True:
                line = stream.readline()
                if not line:
                    with self.lock:
                        self.messages.append(None)
                        self.lock.notify_all()
                    return
                line = line.decode().strip()
                if not line:
                    break
                name, _, value = line.partition(':')
                headers[name.strip().lower()] = value.strip()
            message = json.loads(stream.read(int(headers['content-length'])))
            say('  <- ' + clip(message))
            if 'method' in message and 'id' in message:
                self.answer(message)
                continue
            with self.lock:
                self.messages.append(message)
                self.lock.notify_all()

    def answer(self, request):
        """A request from the server. This client has no settings to give: an empty configuration, as an editor without settings."""
        method = request['method']
        if method == 'workspace/configuration':
            result = [None for _ in request.get('params', {}).get('items', [])]
        else:
            result = None
        self.write({'jsonrpc': '2.0', 'id': request['id'], 'result': result})

    def write(self, message):
        say('  -> ' + clip(message))
        body = json.dumps(message).encode()
        self.process.stdin.write(b'Content-Length: %d\r\n\r\n' % len(body) + body)
        self.process.stdin.flush()

    def notify(self, method, params):
        self.write({'jsonrpc': '2.0', 'method': method, 'params': params})

    def request(self, method, params, timeout=60):
        identifier, self.next_id = self.next_id, self.next_id + 1
        self.write({'jsonrpc': '2.0', 'id': identifier, 'method': method, 'params': params})
        deadline = time.monotonic() + timeout
        with self.lock:
            while True:
                for message in self.messages:
                    if message is None:
                        fail(f'{method}: the server closed stdout; stderr: ' + ' | '.join(self.stderr[-10:]))
                    if message.get('id') == identifier:
                        if 'error' in message:
                            fail(f'{method}: error {json.dumps(message["error"])}')
                        return message.get('result')
                left = deadline - time.monotonic()
                if left <= 0:
                    fail(f'{method}: no answer in {timeout} seconds')
                self.lock.wait(left)


def clip(message, limit=1200):
    text = json.dumps(message)
    return text if len(text) <= limit else text[:limit] + f'... ({len(text)} bytes)'


def definition_uris(result):
    """The target URIs of a definition answer: a Location, a list of Locations, or a list of LocationLinks."""
    if result is None:
        return []
    items = result if isinstance(result, list) else [result]
    return [item.get('uri') or item.get('targetUri') for item in items]


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('project', type=int)
    parser.add_argument('node', type=int)
    parser.add_argument('--keep', action='store_true', help='leave the clone for inspection')
    args = parser.parse_args()

    say('# laravel-lsp go-to-definition proof')
    say('started: ' + datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds'))
    say('checkout commit: ' + subprocess.run(['git', '-C', str(CHECKOUT), 'rev-parse', 'HEAD'], capture_output=True, text=True).stdout.strip())

    step('The pinned laravel/lsp in the recipe and in the runtime folder of the app-dev Node')
    pin = json.loads(RECIPE.read_text())['instances']['runtime']['laravel-lsp']
    say('recipe instances.runtime.laravel-lsp: ' + json.dumps(pin))
    link = guest('readlink', '-f', '/opt/orbit-runtime/current/bin/laravel-lsp').stdout.strip()
    say('runtime folder laravel-lsp: ' + link)
    digest = guest('sha256sum', link).stdout.split()[0] if link else ''
    say('sha256: ' + digest)
    check(digest == pin['artifacts']['amd64']['sha256'], 'the runtime folder holds the PHAR the recipe pins, by its sha256')

    step('Prepare the default')
    project = orbit('project:show', str(args.project), '--json')
    say(f'Project {project["id"]} {project["slug"]}: {project["repository_url"]}, default branch {project["default_branch"]}, root {project["root"]}')
    defaults = [item for item in orbit('instance:list', '--json')['instances']
                if item.get('project_id') == args.project and item.get('node_id') == args.node and item.get('name') == 'default']
    if len(defaults) != 1:
        fail(f'no one default Instance of Project {args.project} on Node {args.node}')
    default = orbit('instance:show', str(defaults[0]['id']), '--json')
    say(f'default Instance {default["id"]}, checkout {default["checkout_path"]}')
    deployed = orbit('instance:deploy', str(default['id']), '--json')
    say('deploy Operation: ' + operation_line(deployed))
    check(deployed['status'] == 'succeeded', f'the default deploy succeeded at commit {deployed["result"]["commit"]}')
    default_container = f'instance-{default["id"]}'
    snapshots = guest('incus', 'snapshot', 'list', '--project', INSTANCE_PROJECT, default_container, '-f', 'csv', '-c', 'nT').stdout.strip()
    say('default snapshots: ' + snapshots.replace('\n', '; '))
    check(f'seed-{deployed["result"]["commit"]}' in snapshots, 'the default holds the seed of the deployed commit')
    indexed = as_agent(default_container, 'find / -xdev -iname "*laravel*lsp*" -not -path "/proc/*" 2>/dev/null; true')
    check(indexed.stdout.strip() == '', 'the default holds no laravel-lsp install or index of its own: the runtime folder serves it')

    step('Create a fresh disposable clone')
    name = 'lsp-proof-' + datetime.datetime.now(datetime.timezone.utc).strftime('%m%d%H%M%S')
    created = orbit('instance:create', str(args.project), str(args.node), name, '--json')
    say('create Operation: ' + operation_line(created))
    clone_id = created.get('target_id') or created.get('result', {}).get('instance_id')
    completion = created.get('result', {}).get('completion_operation_id')
    say(f'clone Instance {clone_id}, completion Operation {completion}')
    deadline = time.monotonic() + ACTIVE_SECONDS
    while True:
        clone = orbit('instance:show', str(clone_id), '--json')
        if clone['status'] == 'active':
            break
        if clone['status'] in ('failed', 'removed') or time.monotonic() > deadline:
            fail(f'clone {clone_id} is {clone["status"]}, not active')
        time.sleep(5)
    if completion:
        say('completion Operation: ' + operation_line(orbit('operation:show', str(completion), '--json')))
    check(clone['status'] == 'active', f'clone {clone_id} ({clone["name"]}) is active on branch {clone.get("selected_branch")} at {clone.get("starting_commit")}')
    container = f'instance-{clone_id}'

    try:
        prove(container, clone, default)
    finally:
        if args.keep:
            say(f'kept clone {clone_id}')
        else:
            step('Destroy the clone')
            destroyed = orbit('instance:destroy', str(clone_id), '--yes', '--force', '--json')
            say('destroy Operation: ' + operation_line(destroyed))
            gone = guest('incus', 'info', '--project', INSTANCE_PROJECT, container)
            check(destroyed['status'] == 'succeeded' and gone.returncode != 0, f'clone {clone_id} and its container {container} are gone')
    say()
    say('RESULT: PASS')


def prove(container, clone, default):
    step('Nothing set up in the clone: no per-clone install, no editor settings, no running server')
    found = as_agent(container, 'command -v laravel-lsp laravel-lsp-root')
    check(found.stdout.splitlines() == ['/opt/orbit-runtime/current/bin/laravel-lsp', '/opt/orbit-runtime/current/bin/laravel-lsp-root'],
          'laravel-lsp and laravel-lsp-root come from the shared runtime folder on agent\'s PATH')
    installed = as_agent(container, 'find / -xdev -iname "*laravel*lsp*" -not -path "/proc/*" 2>/dev/null; true')
    check(installed.stdout.strip() == '', 'the clone holds no laravel-lsp install or index of its own')
    editors = as_agent(container, 'ls -d /srv/workspace/.zed /srv/workspace/.vscode /srv/workspace/.idea ~/.config/zed 2>/dev/null; true')
    check(editors.stdout.strip() == '', 'the clone holds no editor settings')
    running = as_agent(container, 'ps -u agent -o pid=,args= | grep "/[l]aravel-lsp$" || echo "no laravel-lsp process"')
    check(running.stdout.strip() == 'no laravel-lsp process', 'no laravel-lsp server runs before a client starts one')

    step('The application root and the versions the server runs with')
    application = as_agent(container, "sed -n 's/^\\(ORBIT_INSTANCE_ID\\|ORBIT_APPLICATION\\)=/&/p' /etc/orbit/instance")
    root = as_agent(container, 'cd /srv/workspace && laravel-lsp-root').stdout.strip()
    check(root != '' and as_agent(container, f'test -f {shlex.quote(root)}/artisan').returncode == 0, f'laravel-lsp-root names {root}, a folder with artisan')
    versions = as_agent(container, f'cd {shlex.quote(root)} && laravel-lsp --version && command -v php && php -r "echo PHP_VERSION, PHP_EOL;" '
                        '&& php artisan --version && test -f vendor/autoload.php && echo "vendor/autoload.php present"').stdout
    check('Laravel LSP v' in versions and 'vendor/autoload.php present' in versions, 'laravel-lsp and the Project\'s PHP and vendor/ answer at the root')

    step('A PHP file that calls view() with a real named view')
    calls = as_agent(container, f'cd {shlex.quote(root)} && grep -rn --include=*.php "view(\'" routes app | head -5').stdout
    match = re.match(r'([^:]+):(\d+):(.*)$', calls.splitlines()[0]) if calls.strip() else None
    if not match:
        fail('no PHP file under routes or app calls view(\'<name>\')')
    relative, line_number, line = match[1], int(match[2]), match[3]
    view = VIEW_CALL.search(line)
    if not view:
        fail(f'{relative}:{line_number} has no literal view name')
    blade = f'{root}/resources/views/{view[1].replace(".", "/")}.blade.php'
    check(as_agent(container, f'test -f {shlex.quote(blade)}').returncode == 0, f'the view {view[1]!r} is the real file {blade} in the clone')
    php_path = f'{root}/{relative}'
    text = subprocess.run(as_agent_argv(container, f'cat {shlex.quote(php_path)}'), capture_output=True, text=True, timeout=60).stdout
    character = line.index(view[1]) + len(view[1]) // 2
    say(f'definition position: {relative} line {line_number} character {character} (inside {view[1]!r})')

    step('laravel-lsp over stdio at the root, as agent in the clone')
    say(f'[{container} as agent]$ cd {root} && exec laravel-lsp')
    server = Server(as_agent_argv(container, f'cd {shlex.quote(root)} && exec laravel-lsp'))
    root_uri = 'file://' + root
    initialized = server.request('initialize', {
        'processId': None, 'clientInfo': {'name': 'orbit-laravel-lsp-proof'}, 'rootUri': root_uri, 'rootPath': root,
        'workspaceFolders': [{'uri': root_uri, 'name': Path(root).name}],
        'capabilities': {'textDocument': {'definition': {'linkSupport': True}, 'synchronization': {'didSave': True}},
                         'workspace': {'configuration': True, 'workspaceFolders': True}}})
    check(isinstance(initialized, dict) and initialized.get('capabilities', {}).get('definitionProvider') not in (None, False),
          'initialize answered with a definition provider: ' + json.dumps(initialized.get('serverInfo')))
    server.notify('initialized', {})
    uri = 'file://' + php_path
    server.notify('textDocument/didOpen', {'textDocument': {'uri': uri, 'languageId': 'php', 'version': 1, 'text': text}})

    # The server loads the application's views on demand after the open; ask again until it answers, within a bound.
    deadline, uris = time.monotonic() + DEFINITION_SECONDS, []
    while not uris:
        uris = definition_uris(server.request('textDocument/definition', {'textDocument': {'uri': uri},
                                                                           'position': {'line': line_number - 1, 'character': character}}))
        if not uris:
            if time.monotonic() > deadline:
                fail(f'no definition in {DEFINITION_SECONDS} seconds')
            time.sleep(2)
    say('definition URIs: ' + json.dumps(uris))
    check(uris == ['file://' + blade], f'the definition is the clone\'s own Blade file, file://{blade}')
    check(as_agent(container, f'test -f {shlex.quote(blade)} && head -n 2 {shlex.quote(blade)}').returncode == 0,
          'the URI names a file that exists in the clone')

    step('The clone, not the default: the server and its file on the app-dev Node')
    # Inside every container the workspace is /srv/workspace, so the Node tells the clone from the default: the server's cgroup is the clone's
    # container, and the URI's file is under the clone's checkout, not the default's.
    processes = guest('sh', '-c', 'for p in $(pgrep -f "/[l]aravel-lsp$"); do echo "$p $(cat /proc/$p/cgroup)"; done').stdout.strip()
    say('laravel-lsp processes on the Node (pid, cgroup): ' + (processes or 'none'))
    check(processes != '' and all(f'orbit-instances_{container}/' in line for line in processes.splitlines()),
          f'the only laravel-lsp process on the Node runs in the clone\'s container {container}')
    relative_blade = blade.removeprefix(root.rstrip('/') + '/')
    clone_file = clone['checkout_path'].rstrip('/') + root.removeprefix('/srv/workspace') + '/' + relative_blade
    default_file = default['checkout_path'].rstrip('/') + root.removeprefix('/srv/workspace') + '/' + relative_blade
    say(f'the URI on the Node: {clone_file}; the default\'s file: {default_file}')
    check(clone_file != default_file and guest('test', '-f', clone_file).returncode == 0,
          'the URI\'s file is the clone\'s own copy under its checkout, not the default\'s path')

    step('Shut the server down')
    server.request('shutdown', None)
    server.notify('exit', None)
    exited = server.process.wait(timeout=30)
    say(f'server exit status: {exited}')
    if server.stderr:
        say('server stderr (last lines): ' + ' | '.join(server.stderr[-5:]))
    after = as_agent(container, 'ps -u agent -o pid=,args= | grep "/[l]aravel-lsp$" || echo "no laravel-lsp process"')
    check(exited == 0 and 'no laravel-lsp process' in after.stdout, 'the server exited 0 and left no process behind')


if __name__ == '__main__':
    main()
