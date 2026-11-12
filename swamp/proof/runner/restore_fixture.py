"""A bounded fixture for the disposable nested fleet; no live fleet entrypoint."""
import json
import shlex
import uuid

import fleet as fleet_module


def guest(run, mode, slug, **values):
    command = ['runuser', '-u', 'orbit', '--', 'php',
               fleet_module.NODE_CHECKOUT + '/tools/swamp-proof-guest/instance-removal.php']
    result = run.fleet.sh('gateway', shlex.join(command),
                          stdin_text=json.dumps({'mode': mode, 'slug': slug, **values}))
    if result.returncode:
        raise RuntimeError('nested removal fixture failed: ' + result.stderr[-500:])
    return json.loads(result.stdout)


def must(run, node, command, stdin=None, timeout=60):
    result = run.fleet.sh(node, command, timeout=timeout, stdin_text=stdin)
    if result.returncode:
        raise RuntimeError('owned removal fixture command failed: ' + result.stderr[-500:])
    return result.stdout.strip()


def sql(run, node, container, statement):
    command = ['docker', 'exec', '-i', container, 'psql', '-X', '-qAt',
               '-U', 'postgres', '-d', 'postgres', '-v', 'ON_ERROR_STOP=1']
    return must(run, node, shlex.join(command), stdin=statement)


def call(run, words, wanted='succeeded'):
    result = run.cli(words)
    if not isinstance(result['json'], dict) or result['json'].get('status') != wanted:
        raise RuntimeError(f'fixture CLI {words[0]} did not end {wanted}')
    return result['json']


def catalog(run, node, container, plan):
    # Names come only from this owned fixture's captured database plan.
    names = ','.join("'" + name.replace("'", "''") + "'" for name in plan['databases'])
    users = ','.join("'" + name.replace("'", "''") + "'" for name in plan['users'])
    return json.loads(sql(run, node, container,
        "SELECT json_build_object('databases', (SELECT json_agg(json_build_object("
        "'name',datname,'oid',oid::bigint,'owner',pg_get_userbyid(datdba),'allow',datallowconn) "
        f"ORDER BY datname) FROM pg_database WHERE datname IN ({names})), "
        "'users',(SELECT json_agg(json_build_object('name',rolname,'oid',oid::bigint) "
        f"ORDER BY rolname) FROM pg_roles WHERE rolname IN ({users})));\n"))


def execute(run, args):
    from steps import Infra
    node, alias, phase = args['on'], args['as'], args['phase']
    if node != 'app-dev' or phase not in ('setup', 'restored', 'new-removal'):
        raise Infra('instance_removal fixture needs app-dev and a declared phase')
    try:
        if phase == 'setup':
            slug = 'restore-proof-' + uuid.uuid4().hex[:12]
            path = '/home/orbit/apps/' + slug
            domain = slug + '.restore.test'
            shell = f"""install -d -o orbit -g orbit {path}/public
runuser -u orbit -- sh -c {shlex.quote(
    f"cd {path} && printf '%s' {slug} > public/index.html && git init -q --initial-branch=main"
    " && git add public/index.html && git -c user.name=Fixture -c user.email=fixture@example.test commit -qm fixture"
    f" && git remote add origin https://github.com/example/{slug}.git && git rev-parse HEAD")}"""
            commit = must(run, node, shell)
            instance = guest(run, 'create', slug, node_id=run.fleet.nodes[node]['node_id'], commit=commit)['instance']
            port = must(run, node, "python3 -c 'import socket; s=socket.socket(); s.bind((\"127.0.0.1\",0)); print(s.getsockname()[1])'")
            call(run, ['database:server:create', slug, '--driver=pgsql',
                       '--node=' + str(run.fleet.nodes[node]['node_id']), '--port=' + port])
            call(run, ['database:create', slug, '--server=' + slug, '--instance=' + str(instance), '--test-processes=2'])
            call(run, ['route:create', str(instance), domain])
            before = guest(run, 'inspect', slug)
            container = before['container']
            sql(run, node, container, "ALTER SYSTEM SET wal_level = 'logical';\n")
            must(run, node, shlex.join(['docker', 'restart', container]))
            ready = shlex.join(['docker', 'exec', container, 'pg_isready', '-U', 'postgres'])
            must(run, node, 'timeout 45 sh -c ' + shlex.quote(f'until {ready}; do sleep 0.2; done'), timeout=50)
            database = before['database']
            sql(run, node, container, "\\connect " + database + "\nSELECT pg_create_logical_replication_slot('restore_proof', 'test_decoding');\n")
            must(run, node, shlex.join(['docker', 'exec', '-d', '--user', 'postgres', container,
                'pg_recvlogical', '-U', 'postgres', '-d', database, '-S', 'restore_proof',
                '--start', '-f', '/tmp/restore-proof-slot']))
            # pg_recvlogical owns the active slot before destroy; no after-admission barrier.
            active = ("SELECT count(*) FROM pg_replication_slots "
                      "WHERE slot_name='restore_proof' AND active;")
            check = shlex.join(['docker', 'exec', container, 'psql', '-XqAt', '-U', 'postgres', '-c', active])
            must(run, node, 'timeout 10 sh -c ' + shlex.quote(f'until [ "$({check})" = 1 ]; do sleep 0.1; done'), timeout=15)
            call(run, ['instance:destroy', str(instance), '--yes', '--force'], 'failed')
            failed = guest(run, 'inspect', slug)
            if failed['instance_status'] != 'removing' or failed['removal_status'] != 'failed' or failed['route_status'] != 'retiring':
                raise RuntimeError('fixture destroy did not retain the failed removal')
            run.names.update({alias + '.' + key: value for key, value in {
                'instance': instance, 'slug': slug, 'removal': failed['removal'],
                'domain': domain, 'container': container}.items()})
            run.names[alias + '.before'] = before
            run.names[alias + '.catalog'] = catalog(run, node, container, failed['plan'])
            return []
        slug = run.names[alias + '.slug']
        current = guest(run, 'inspect', slug)
        before = run.names[alias + '.before']
        failures = []
        if phase == 'new-removal':
            if current['removal'] == run.names[alias + '.removal'] or current['removal_status'] != 'failed':
                failures.append('new destroy did not capture a fresh removal identity')
            return failures
        for key in ('instance', 'route', 'targets', 'domain', 'checkout', 'commit'):
            if current[key] != before[key]:
                failures.append('restore changed captured ' + key)
        if not (current['instance_status'] == 'active' and current['route_status'] == 'active'
                and current['published'] and current['abandoned'] and not current['deleted']
                and current['removal'] == run.names[alias + '.removal'] and current['destroy_status'] == 'failed'):
            failures.append('restore did not preserve abandoned history and active records')
        if catalog(run, node, current['container'], current['plan']) != run.names[alias + '.catalog']:
            failures.append('restore changed the captured database or user identities')
        if sql(run, node, current['container'], "SELECT count(*) FROM pg_replication_slots WHERE slot_name='restore_proof' AND active;\n") != '1':
            failures.append('replication slot did not stay active')
        address = run.fleet.nodes[node]['bridge']
        response = must(run, 'gateway', shlex.join(['curl', '--fail', '--silent', '--show-error',
            '--max-time', '10', '--cacert', fleet_module.NESTED_CA,
            '--resolve', f"{current['domain']}:443:{address}", 'https://' + current['domain']]))
        if response != slug:
            failures.append('the same Route did not serve the surviving checkout')
        must(run, node, shlex.join(['test', '-f', current['awake']]))
        must(run, node, 'test ! -e ' + shlex.quote(current['cold']))
        return failures
    except (RuntimeError, ValueError, KeyError) as error:
        raise Infra(str(error)) from error
