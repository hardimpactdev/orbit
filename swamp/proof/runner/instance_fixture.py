"""One disposable development-clone fixture. Operation execution always uses the real CLI."""
import json
import os
import runpy
import shlex
import time
import uuid

import fleet

GUEST_HELPER = fleet.NODE_CHECKOUT + '/tools/swamp-proof-guest/instance-fixture.php'


def gateway(run, payload):
    command = ['runuser', '-u', 'orbit', '--', 'php', GUEST_HELPER]
    done = run.fleet.sh('gateway', shlex.join(command), stdin_text=json.dumps(payload))
    if done.returncode:
        # The helper emits only an allowlisted public code and exception class.
        raise fleet.FleetError('Instance fixture Gateway: ' + done.stderr.strip()[-250:])
    try:
        return json.loads(done.stdout)
    except ValueError as error:
        raise fleet.FleetError('Instance fixture Gateway returned no JSON') from error


def prepare(run, args):
    started = time.monotonic()
    slug = 'proof-clone-' + uuid.uuid4().hex
    # Register ownership before any resource-creating CLI call, so a lost response still has cleanup.
    run.instance_owner = slug
    run.instance_resources = {}
    run.names.update({args['as'] + '.slug': slug, args['as'] + '.clone': 'proof-development-clone'})
    try:
        plan = gateway(run, {'mode': 'prepare'})
        variables = run.evidence / 'instance-host.json'
        descriptor = os.open(variables, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, 'w') as output:
            json.dump(plan, output)
        # Use the already pinned controller. No alternate installer, image refresh, fleet build or credentials.
        shape = runpy.run_path(str(fleet.CHECKOUT / 'bin/swamp-shape-gate'))
        tools = shape['default_tools']()
        controller = shape['tool_path'](tools)
        collections = shape['collections_path'](tools)
        done = fleet.Fleet.must(['env', 'ANSIBLE_CONFIG=' + str(fleet.CHECKOUT / 'swamp/ansible/ansible.cfg'),
                                'ANSIBLE_COLLECTIONS_PATH=' + str(collections),
                                str(controller / 'ansible-playbook'), '-i', 'nested-gateway,nested-app-dev,',
                                '-e', '@' + str(variables.resolve()),
                                str(fleet.CHECKOUT / 'swamp/ansible/proof-instance-host.yml')],
                               'cannot prepare the disposable Instance host', timeout=1800)
        run.evidence_text('instance-host.log', done.stdout)
        return []
    except fleet.FleetError as error:
        return [str(error)]
    finally:
        run.timings['instance_host'] = round(time.monotonic() - started, 2)


def resources(run, **extra):
    return gateway(run, {'mode': 'resources', 'fixture': run.instance_owner, **extra})


def runtime(run, args):
    if args['fixture'] != getattr(run, 'instance_owner', None):
        raise fleet.FleetError('Instance runtime check does not own this fixture')
    snapshot = resources(run, instance=args['instance'], **({'operation': args['operation']} if 'operation' in args else {}))
    run.evidence_text('instance-records-' + args['state'] + '.json', json.dumps(snapshot, indent=2))
    rows = {row['id']: row for row in snapshot['instances']}
    member = rows.get(args['instance'])
    failures = []
    if args['state'] == 'running':
        source = rows.get(args['source'])
        operation = snapshot.get('operation') or {}
        runtime = (member or {}).get('runtime') or {}
        seed = (source or {}).get('runtime') or {}
        if ((member or {}).get('status') != 'active' or runtime.get('power_state') != 'running'
                or runtime.get('clone_source_instance_id') != args['source']
                or operation.get('kind') != 'instance.create' or operation.get('status') != 'succeeded'
                or operation.get('target_id') != args['instance']
                or not seed.get('seed_commit') or operation.get('seed_commit') != seed['seed_commit']
                or operation.get('source') != seed.get('container_name') or len((member or {}).get('conn', [])) != 1):
            failures.append('instance_runtime: active clone, seed and registered Conn identity were not proved')
        if member is None or not runtime:
            return failures
        run.instance_resources[member['id']] = member
        payload = {'state': 'running', 'member': member, 'seed_commit': seed.get('seed_commit')}
    elif args['state'] == 'absent':
        if member is not None or snapshot['runtime_exists'] or snapshot['conn_exists']:
            failures.append('instance_runtime: Instance, runtime or Conn record remains after destroy')
        retained = run.instance_resources.get(args['instance'])
        if retained is None:
            return failures + ['instance_runtime: missing pre-destroy resource identity']
        payload = {'state': 'absent', 'member': retained}
    else:
        raise fleet.FleetError('Instance runtime state must be running or absent')
    return failures + inspect(run, payload)


def inspect(run, payload):
    command = ['python3', fleet.NODE_CHECKOUT + '/tools/swamp-proof-guest/instance-runtime.py']
    done = run.fleet.sh('app-dev', shlex.join(command), stdin_text=json.dumps(payload), timeout=90)
    run.evidence_text('instance-live-' + payload['state'] + '-' + str(payload['member']['id']) + '.json', done.stdout)
    if done.returncode:
        raise fleet.FleetError('Instance runtime inspection failed: ' + str(done.returncode))
    document = json.loads(done.stdout)
    return ['instance_runtime: ' + failure for failure in document['failed']]


def cleanup(run):
    if not hasattr(run, 'instance_owner'):
        return
    started = time.monotonic()
    try:
        snapshot = resources(run)
        run.evidence_text('instance-cleanup-before.json', json.dumps(snapshot, indent=2))
        # The clone goes before its default. Discover by the owned Project, never by a successful CLI response alone.
        for row in sorted(snapshot['instances'], key=lambda row: row['name'] == 'default'):
            result = run.cli(['instance:destroy', str(row['id']), '--force'])
            if result['rc'] or (result['json'] or {}).get('status') != 'succeeded':
                document = result['json'] or {}
                raise fleet.FleetError('owned Instance cleanup failed: ' + str(row['id']) + ', '
                                       + str(document.get('failed_step')) + ', ' + str(document.get('error_code')))
            if row.get('runtime'):
                if failed := inspect(run, {'state': 'absent', 'member': row}):
                    raise fleet.FleetError('; '.join(failed))
        after = resources(run)
        if after['instances']:
            raise fleet.FleetError('owned Instances remain after cleanup')
        if after['project'] is not None:
            result = run.cli(['project:destroy', str(after['project']), '--yes'])
            if result['rc']:
                raise fleet.FleetError('owned Project cleanup failed')
        final = resources(run)
        run.evidence_text('instance-cleanup-after.json', json.dumps(final, indent=2))
        if final['project'] is not None:
            raise fleet.FleetError('owned Project remains after cleanup')
    finally:
        run.timings['instance_cleanup'] = round(time.monotonic() - started, 2)
