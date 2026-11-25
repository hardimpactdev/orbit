#!/usr/bin/env python3
"""Proof: a Laravel default's seed carries a Phpactor index that a fresh development clone reuses, and only an app-dev default gets one.

Run it in a task copy of Orbit, as `agent`, after the nested Gateway serves the checkout and `node:converge` of the nested app-dev
Node has built the runtime folder from the recipe:

    python3 -I tools/proofs/phpactor-seed-index.py PROJECT NODE PROD_NODE

PROJECT is the standing Laravel fixture Project of the inner fleet, NODE its app-dev Node and PROD_NODE the inner app-prod Node.
In order, the script:

1. checks the pinned PHARs in the app-dev runtime folder and that the production runtime folder plan, and the folder of that version
   on the app-prod Node, hold neither PHAR nor a bin link to one;
2. on the default, with vendor/ moved aside and the index removed, runs `instances:php-index` and shows an index built from source;
   then puts vendor/ back and removes that index;
3. deploys the default, so `php_index` builds the full index before `seed` retakes the seed;
4. plants an untracked file in the default, runs `instances:php-index` and shows the file stays and the seed is retaken; a second
   run finds the index unchanged and keeps the seed;
5. creates a clone and shows, before any build in it, the inherited index at the path Phpactor discovers and a lookup from it, then
   the count of a build against a cold build in an empty home (on a third-level fleet Node Incus shifts the clone file by file, so
   every ctime moves and Phpactor reindexes), `textDocument/definition` of App\\Models\\User through `phpactor
   language-server` to the clone's own file, an incremental build that indexes one new class, and whether the planted file is there;
6. shows that the clone and the in-memory variants of the default as non-Laravel, on the app-prod Node and with no active app-dev
   role get no index, and that `instances:php-index` refuses the clone; then destroys the clone and removes the planted file.

The transcript goes to stdout. Exit 0 only when every assertion holds.
"""
import argparse
import datetime
import hashlib
import importlib.util
import json
import os
import re
import shlex
import subprocess
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('lsp_proof', HERE / 'laravel-lsp-definition.py')
lsp = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lsp)
say, step, fail, check, guest, as_agent, as_agent_argv, Server, definition_uris, operation_line = (
    lsp.say, lsp.step, lsp.fail, lsp.check, lsp.guest, lsp.as_agent, lsp.as_agent_argv, lsp.Server, lsp.definition_uris, lsp.operation_line)

CHECKOUT = HERE.parents[1]
RECIPE = CHECKOUT / 'apps/gateway/resources/machine-software/recipe.json'
INSTANCE_PROJECT = lsp.INSTANCE_PROJECT
NESTED_APP_PROD = 'nested-app-prod'
ROOT = '/srv/workspace'
INDEX = f'/home/agent/.cache/phpactor/index/{Path(ROOT).name}-{hashlib.md5(ROOT.encode()).hexdigest()[:6]}'
STAMP = '/home/agent/.cache/orbit-php-index.json'
PROGRESS = re.compile(r'(\d+)/(\d+) \[')
ACTIVE_SECONDS = 900


def orbit(*args, timeout=1500):
    """The copy's CLI against the nested Gateway; a command that follows its Operation prints one line before the JSON."""
    home = os.path.expanduser('~')
    env = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': home, 'ORBIT_HOME': home + '/.orbit', 'COLUMNS': '240'}
    say('$ orbit ' + ' '.join(args))
    done = subprocess.run([*lsp.ORBIT, *args], capture_output=True, text=True, timeout=timeout, env=env, cwd='/', stdin=subprocess.DEVNULL)
    try:
        parsed = json.loads(done.stdout[done.stdout.index('{'):])
    except ValueError:
        fail(f'orbit {args[0]} exit {done.returncode}, no JSON: {(done.stderr or done.stdout).strip()[-400:]}')
    if done.returncode != 0:
        fail(f'orbit {args[0]} exit {done.returncode}: ' + json.dumps({k: parsed.get(k) for k in ('id', 'status', 'failed_step', 'error_code', 'error')}))
    return parsed


def gateway(script, timeout=600):
    """`php artisan` on the nested Gateway, as `orbit` in its checkout."""
    say(f'[nested-gateway as orbit]$ {script}')
    done = subprocess.run(['sudo', 'incus', 'exec', 'nested-gateway', '--', 'runuser', '-u', 'orbit', '--', 'sh', '-c',
                           'cd /home/orbit/orbit/apps/gateway && ' + script], capture_output=True, text=True, timeout=timeout, stdin=subprocess.DEVNULL)
    for line in (done.stdout + done.stderr).rstrip('\n').splitlines():
        say('  ' + line)
    say(f'  (exit {done.returncode})')
    return done


def php_index(instance_id):
    """`instances:php-index` and its Operation, followed to the end."""
    done = gateway(f'php artisan instances:php-index {instance_id}')
    started = json.loads(done.stdout.strip().splitlines()[-1])
    if 'operation' not in started:
        fail(f'instances:php-index {instance_id} started no Operation: {json.dumps(started)}')
    deadline = time.monotonic() + 900
    while True:
        operation = orbit('operation:show', str(started['operation']), '--json')
        if operation['status'] in ('succeeded', 'failed', 'cancelled'):
            break
        if time.monotonic() > deadline:
            fail(f'Operation {started["operation"]} still {operation["status"]}')
        time.sleep(5)
    say('php_index Operation: ' + operation_line(operation) + ' steps: ' + json.dumps([s['name'] for s in operation.get('steps', [])]))
    return operation


def seed_snapshot(container, commit):
    rows = guest('incus', 'snapshot', 'list', '--project', INSTANCE_PROJECT, container, '-f', 'csv', '-c', 'nT').stdout.strip().splitlines()
    say(f'{container} snapshots: ' + '; '.join(rows))
    return next((row.split(',', 1)[1] for row in rows if row.startswith(f'seed-{commit},')), None)


def built_items(output):
    """The file count of a `phpactor index:build` run: its last progress line, or 0 for 'No files found'."""
    counts = PROGRESS.findall(output)
    if counts:
        return int(counts[-1][1])
    if 'No files found' in output:
        return 0
    fail('no file count in the build output')


def build(container, home=None, timeout=900):
    """`phpactor index:build` at the root, incremental (no --reset), in agent's home or in an empty one for a cold build."""
    prefix = f'HOME={shlex.quote(home)} ' if home else ''
    say(f'[{container} as agent]$ cd {ROOT} && {prefix}phpactor index:build --no-interaction --no-ansi')
    started = time.monotonic()
    ran = subprocess.run(as_agent_argv(container, f'cd {ROOT} && {prefix}phpactor index:build --no-interaction --no-ansi 2>&1'),
                         capture_output=True, text=True, timeout=timeout, stdin=subprocess.DEVNULL)
    output = ran.stdout.replace('\r', '\n')
    counts = PROGRESS.findall(output)
    tail = [line for line in output.splitlines() if line.strip() and not PROGRESS.search(line)][-3:]
    say(f'  last progress: {counts[-1][0]}/{counts[-1][1]}' if counts else '  no progress line')
    for line in tail:
        say('  ' + line.strip())
    say(f'  (exit {ran.returncode}, {time.monotonic() - started:.1f} s)')
    check(ran.returncode == 0, 'phpactor index:build exited 0')
    return built_items(output)


def wait_active(clone_id):
    deadline = time.monotonic() + ACTIVE_SECONDS
    while True:
        clone = orbit('instance:show', str(clone_id), '--json')
        if clone['status'] == 'active':
            return clone
        if clone['status'] in ('failed', 'removed') or time.monotonic() > deadline:
            fail(f'clone {clone_id} is {clone["status"]}, not active')
        time.sleep(5)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('project', type=int)
    parser.add_argument('node', type=int)
    parser.add_argument('prod_node', type=int)
    args = parser.parse_args()
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%m%d%H%M%S')

    say('# Phpactor seed index proof')
    say('started: ' + datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds'))
    say('checkout commit: ' + subprocess.run(['git', '-C', str(CHECKOUT), 'rev-parse', 'HEAD'], capture_output=True, text=True).stdout.strip())
    say(f'the index path Phpactor derives for {ROOT}: {INDEX}')

    runtimes()

    defaults = [item for item in orbit('instance:list', '--json')['instances']
                if item.get('project_id') == args.project and item.get('node_id') == args.node and item.get('name') == 'default']
    if len(defaults) != 1:
        fail(f'no one default Instance of Project {args.project} on Node {args.node}')
    default = orbit('instance:show', str(defaults[0]['id']), '--json')
    container = f'instance-{default["id"]}'
    say(f'default Instance {default["id"]} ({container}), checkout {default["checkout_path"]}')
    planted = f'{ROOT}/proof-planted-{stamp}.txt'
    clone_id = None
    try:
        source_only(default, container)
        commit = deploy(default, container)
        refresh(default, container, commit, planted)
        name = 'phpactor-proof-' + stamp
        step('Create a fresh clone from the refreshed seed')
        created = orbit('instance:create', str(args.project), str(args.node), name, '--json')
        say('create Operation: ' + operation_line(created))
        clone_id = created.get('target_id') or created.get('result', {}).get('instance_id')
        clone = wait_active(clone_id)
        check(clone['status'] == 'active', f'clone {clone_id} ({name}) is active at {clone.get("starting_commit")}')
        in_clone(f'instance-{clone_id}', clone, default, planted)
        exclusions(default, clone_id, args.prod_node)
    finally:
        step('Clean up')
        if clone_id:
            destroyed = orbit('instance:destroy', str(clone_id), '--yes', '--force', '--json')
            say('destroy Operation: ' + operation_line(destroyed))
            gone = guest('incus', 'info', '--project', INSTANCE_PROJECT, f'instance-{clone_id}')
            check(destroyed['status'] == 'succeeded' and gone.returncode != 0, f'clone {clone_id} and its container are gone')
        as_agent(container, f'rm -f {shlex.quote(planted)}; test -d /home/agent/vendor.proof && mv /home/agent/vendor.proof {ROOT}/vendor; true')
    say()
    say('RESULT: PASS')


def runtimes():
    step('The pinned PHARs: in the app-dev runtime folder, not in the production runtime folder')
    recipe = json.loads(RECIPE.read_text())['instances']['runtime']
    for part, program in (('phpactor', 'phpactor'), ('laravel-lsp', 'laravel-lsp')):
        pin = recipe[part]
        say(f'recipe instances.runtime.{part}: version {pin["version"]}, dev_only {pin.get("dev_only")}, sha256 {pin["artifacts"]["amd64"]["sha256"]}')
        link = guest('readlink', '-f', f'/opt/orbit-runtime/current/bin/{program}').stdout.strip()
        digest = guest('sha256sum', link).stdout.split()[0] if link else ''
        say(f'app-dev runtime bin/{program} -> {link} sha256 {digest}')
        check(pin.get('dev_only') is True and digest == pin['artifacts']['amd64']['sha256'], f'the app-dev runtime holds the pinned {part} by its sha256')

    plan = gateway('php artisan tinker --execute=\'$r = app(App\\Infrastructure\\MachineSoftware\\RecipeLoader::class)->load(); '
                   '$p = App\\Domain\\Instances\\InstanceFolderPlan::for($r->instances, true, "/opt/orbit-production-runtime", production: true)["runtime"]; '
                   'echo json_encode(["version" => $p["version"], "parts" => array_column($p["parts"], "name"), "scripts" => array_keys($p["scripts"])]), PHP_EOL;\'')
    production = json.loads(plan.stdout.strip().splitlines()[-1])
    check(not {'phpactor', 'laravel-lsp'} & set(production['parts']) and 'laravel-lsp-root' not in production['scripts'],
          f'the production runtime plan {production["version"]} has neither part nor laravel-lsp-root: {production["parts"][:8]}..., scripts {production["scripts"]}')
    folder = f'/opt/orbit-production-runtime/{production["version"]}'
    listed = subprocess.run(['sudo', 'incus', 'exec', NESTED_APP_PROD, '--', 'sh', '-c',
                             f'ls {folder}/bin; echo ---; ls {folder}; echo ---; find {folder} -iname "*phpactor*" -o -iname "*laravel-lsp*"'],
                            capture_output=True, text=True, timeout=60).stdout
    bins, parts, found = listed.split('---\n')
    say(f'app-prod {folder}/bin: ' + ' '.join(bins.split()))
    say(f'app-prod {folder}: ' + ' '.join(p for p in parts.split() if not p.startswith('browser-')) + ' (+ browser-*)')
    check(bins.strip() != '' and found.strip() == '' and not {'phpactor', 'laravel-lsp', 'laravel-lsp-root'} & set(bins.split()),
          'the assembled production runtime folder of that version has no PHAR and no bin link to one')
    found_prod = subprocess.run(['sudo', 'incus', 'exec', NESTED_APP_PROD, '--', 'sh', '-c',
                                 'find / -xdev \\( -iname "phpactor*" -o -iname "laravel-lsp*" \\) -not -path "/proc/*" 2>/dev/null | head'],
                                capture_output=True, text=True, timeout=300).stdout.strip()
    check(found_prod == '', 'the app-prod Node holds no phpactor or laravel-lsp file anywhere: no install, no index')


def source_only(default, container):
    step('Source only: the default with vendor/ moved aside and no index')
    check(as_agent(container, f'cd {ROOT} && test -f artisan && test -d vendor && mv vendor /home/agent/vendor.proof && rm -rf {INDEX} {STAMP} && ! test -e vendor').returncode == 0,
          'vendor/ is moved out of the application folder and the index and its fingerprint are gone')
    operation = php_index(default['id'])
    check(operation['status'] == 'succeeded', 'instances:php-index on the default without vendor/ succeeded')
    query = as_agent(container, f'cd {ROOT} && ls {INDEX} && phpactor index:query "App\\Models\\User" --no-ansi | head -3; '
                                f'phpactor index:query "Illuminate\\Foundation\\Auth\\User" --no-ansi | head -2')
    check(f'Path:file://{ROOT}/app/Models/User.php' in query.stdout and 'Path:file:///srv/workspace/vendor' not in query.stdout,
          'the index built from source alone knows App\\Models\\User and holds no vendor/ file')
    items = build(container, home='/tmp/phpactor-proof-cold')
    as_agent(container, 'rm -rf /tmp/phpactor-proof-cold')
    check(0 < items < 1000, f'a cold build of the source alone indexes {items} files')
    check(as_agent(container, f'mv /home/agent/vendor.proof {ROOT}/vendor && rm -rf {INDEX} {STAMP} && test -f {ROOT}/vendor/autoload.php').returncode == 0,
          'vendor/ is back and the source-only index is removed, so the deploy builds the full index')


def deploy(default, container):
    step('Deploy the default: php_index builds the index before seed retakes the seed')
    deployed = orbit('instance:deploy', str(default['id']), '--json')
    say('deploy Operation: ' + operation_line(deployed))
    check(deployed['status'] == 'succeeded', f'the default deploy succeeded at commit {deployed["result"]["commit"]}')
    commit = deployed['result']['commit']
    seed_at = seed_snapshot(container, commit)
    stamp = as_agent(container, f'date -u -r {STAMP} "+%Y/%m/%d %H:%M:%S UTC"; ls {INDEX} | head; cat {STAMP} | head -c 120; echo').stdout.splitlines()[0]
    say(f'fingerprint written {stamp}; seed taken {seed_at}')
    check(seed_at is not None and stamp[:16] <= seed_at, 'the index and its fingerprint exist, written before the seed was taken')
    return commit


def refresh(default, container, commit, planted):
    step('A dirty default: plant an untracked file, then instances:php-index')
    as_agent(container, f'echo "planted by the Phpactor proof" > {shlex.quote(planted)} && cd {ROOT} && git status --short --untracked-files=all | head')
    before = seed_snapshot(container, commit)
    time.sleep(61)  # the seed's time has minutes; one minute later, a retaken seed shows a later time
    operation = php_index(default['id'])
    check(operation['status'] == 'succeeded', 'instances:php-index on the dirty default succeeded')
    after = seed_snapshot(container, commit)
    check(after is not None and after > before, f'the seed is retaken: {before} -> {after}')
    survived = as_agent(container, f'cat {shlex.quote(planted)} && cd {ROOT} && git status --short --untracked-files=all | head')
    check(survived.returncode == 0 and Path(planted).name in survived.stdout, 'the planted file survives on the default, untracked')

    step('An unchanged default: a second instances:php-index starts no build and keeps the seed')
    time.sleep(61)
    again = php_index(default['id'])
    kept = seed_snapshot(container, commit)
    check(again['status'] == 'succeeded' and kept == after, f'the seed stays as it was: {kept}')


def in_clone(container, clone, default, planted):
    step('The inherited index, before any build in the clone')
    found = as_agent(container, f'command -v phpactor && ls -la --time-style=+%FT%T {INDEX} && test -f {STAMP} && echo fingerprint present')
    check('/opt/orbit-runtime/current/bin/phpactor' in found.stdout and 'timestamp.' in found.stdout and 'fingerprint present' in found.stdout,
          f'the clone holds the seed\'s index at {INDEX} and the fingerprint, and phpactor comes from the runtime folder')
    query = as_agent(container, f'cd {ROOT} && phpactor index:query "App\\Models\\User" --no-ansi | head -3')
    check(f'Path:file://{ROOT}/app/Models/User.php' in query.stdout, 'before any build, a fresh Phpactor in the clone answers App\\Models\\User from the inherited index')

    # Phpactor reindexes a file whose ctime is newer than the index. Each container has its own isolated ID map; on a real host the
    # clone mounts the seed idmapped and keeps every ctime, but a third-level fleet Node has no idmapped mounts, so Incus shifts the
    # clone file by file at its first start and every ctime moves (docs/decisions/0250-run-instances-and-task-groups-in-incus-containers.md).
    bases = {name: guest('incus', 'config', 'get', '--project', INSTANCE_PROJECT, name, 'volatile.idmap.base').stdout.strip() for name in (default_container(default), container)}
    say('volatile.idmap.base: ' + json.dumps(bases))
    probe = 'cat /home/agent/.cache/phpactor/index/' + Path(INDEX).name + '/timestamp.v3; echo; stat -c "%Z ctime %Y mtime %n" ' + ROOT + '/app/Models/User.php ' + ROOT + '/vendor/autoload.php'
    for name in (default_container(default), container):
        say(f'[{name}] index timestamp, then ctime and mtime: ' + ' | '.join(guest('incus', 'exec', '--project', INSTANCE_PROJECT, name, '--', 'sh', '-c', probe).stdout.split('\n')))
    warm = build(container)
    cold = build(container, home='/tmp/phpactor-proof-cold')
    as_agent(container, 'rm -rf /tmp/phpactor-proof-cold')
    say(f'RESULT inherited-index build in the clone: {warm} files to index against {cold} cold '
        '(on this third-level fleet Node the shifted clone has new ctimes, so the count is not the real host\'s; see above)')

    step('textDocument/definition of App\\Models\\User through phpactor language-server in the clone')
    relative = 'database/seeders/DatabaseSeeder.php'
    text = subprocess.run(as_agent_argv(container, f'cat {ROOT}/{relative}'), capture_output=True, text=True, timeout=60).stdout
    line_number = next(i for i, line in enumerate(text.splitlines()) if 'User::factory' in line and not line.strip().startswith('//'))
    character = text.splitlines()[line_number].index('User::factory') + 2
    say(f'definition position: {relative} line {line_number + 1} character {character}: {text.splitlines()[line_number].strip()}')
    server = Server(as_agent_argv(container, f'cd {ROOT} && exec phpactor language-server'))
    root_uri = 'file://' + ROOT
    initialized = server.request('initialize', {'processId': None, 'rootUri': root_uri, 'rootPath': ROOT,
                                                'workspaceFolders': [{'uri': root_uri, 'name': 'workspace'}],
                                                'capabilities': {'textDocument': {'definition': {'linkSupport': True}}}}, timeout=120)
    check(bool(initialized.get('capabilities', {}).get('definitionProvider')), 'phpactor language-server answered initialize with a definition provider')
    server.notify('initialized', {})
    uri = f'{root_uri}/{relative}'
    server.notify('textDocument/didOpen', {'textDocument': {'uri': uri, 'languageId': 'php', 'version': 1, 'text': text}})
    uris = definition_uris(server.request('textDocument/definition', {'textDocument': {'uri': uri}, 'position': {'line': line_number, 'character': character}}, timeout=120))
    say('definition URIs: ' + json.dumps(uris))
    check(uris == [f'{root_uri}/app/Models/User.php'], 'the definition is app/Models/User.php in the workspace')
    processes = guest('sh', '-c', 'for p in $(pgrep -f "phpactor[.]phar language-server|phpactor language-server"); do echo "$p $(cat /proc/$p/cgroup)"; done').stdout.strip()
    say('phpactor language-server processes on the Node (pid, cgroup): ' + (processes or 'none'))
    # The match also finds the `incus exec` that carries the stdio, outside every container; only the servers inside one count.
    inside = [line for line in processes.splitlines() if 'lxc.payload.' in line]
    check(inside != [] and all(f'orbit-instances_{container}/' in line for line in inside), f'the server runs in the clone\'s container {container} and in no other')
    clone_file = clone['checkout_path'].rstrip('/') + '/app/Models/User.php'
    check(clone_file != default['checkout_path'].rstrip('/') + '/app/Models/User.php' and guest('test', '-f', clone_file).returncode == 0,
          f'the URI\'s file is the clone\'s own copy on the Node, {clone_file}, not the default\'s')
    server.request('shutdown', None)
    server.notify('exit', None)
    say(f'server exit status: {server.process.wait(timeout=30)}')

    step('Incremental: a new class in the clone, built on demand without --reset')
    widget = f'{ROOT}/app/Proof/ProofWidget.php'
    as_agent(container, f'mkdir -p {ROOT}/app/Proof && printf "%s\\n" "<?php" "" "namespace App\\Proof;" "" "final class ProofWidget {{}}" > {widget} && cat {widget}')
    added = build(container)
    check(0 < added < 100, f'the build indexes only what changed: {added} files')
    query = as_agent(container, f'cd {ROOT} && phpactor index:query "App\\Proof\\ProofWidget" --no-ansi | head -3')
    check(f'Path:file://{widget}' in query.stdout, 'index:query finds the new class at the clone\'s file')

    step('The planted file in the clone')
    has = as_agent(container, f'cat {shlex.quote(planted)}; cd {ROOT} && git status --short --untracked-files=all | head')
    say('RESULT planted file in the clone: ' + ('present' if has.returncode == 0 or 'planted' in has.stdout else 'absent')
        + ' (the seed is a snapshot of the default container as it was, its untracked files included; the clone is made from that snapshot)')


def default_container(default):
    return f'instance-{default["id"]}'


def exclusions(default, clone_id, prod_node):
    step('Exclusions: only an active app-dev Laravel default gets the index')
    refused = gateway(f'php artisan instances:php-index {clone_id}')
    check(refused.returncode != 0 and 'instance.php_index_ineligible' in refused.stdout, 'instances:php-index refuses the clone')
    variants = gateway('php artisan tinker --execute=\'use App\\Domain\\Instances\\PhpSymbolIndex; use App\\Models\\Instance; use App\\Models\\Node; '
                       f'$d = Instance::with("node.roles")->find({default["id"]}); $out = ["default" => PhpSymbolIndex::intent($d), "clone" => PhpSymbolIndex::intent(Instance::find({clone_id}))]; '
                       '$v = $d->replicate(); $v->setRelation("node", $d->node); $v->source_is_laravel = false; $out["non-Laravel"] = PhpSymbolIndex::intent($v); '
                       f'$v = $d->replicate(); $v->node_id = {prod_node}; $v->setRelation("node", Node::with("roles")->find({prod_node})); $out["app-prod Node"] = PhpSymbolIndex::intent($v); '
                       '$v = $d->replicate(); $n = $d->node->replicate(); $n->setRelation("roles", $d->node->roles->map(fn ($r) => tap($r->replicate(), fn ($c) => $c->status = App\\Domain\\Shared\\LifecycleStatus::Provisioning))); '
                       '$v->setRelation("node", $n); $out["app-dev role not active"] = PhpSymbolIndex::intent($v); echo json_encode($out), PHP_EOL;\'')
    out = json.loads(variants.stdout.strip().splitlines()[-1])
    check(out['default'] == {'root': ROOT, 'seconds': 600, 'runtime': '/opt/orbit-runtime/current'}, 'the real default\'s intent has php_index at the application root')
    for name in ('clone', 'non-Laravel', 'app-prod Node', 'app-dev role not active'):
        check(out[name] is None, f'{name}: no php_index')


if __name__ == '__main__':
    main()
