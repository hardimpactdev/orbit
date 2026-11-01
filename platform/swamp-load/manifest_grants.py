"""One manifest-policy step; the existing loader owns locks, health and halting."""
import hashlib
import json
import os
import re
import subprocess

PRINCIPAL = 'user:orbit-gateway'
SUBJECT = {'kind': 'user', 'name': 'orbit-gateway'}


def scopes(policy):
    if policy['version'] != 2 or policy['principal'] != PRINCIPAL:
        raise ValueError('unsupported_manifest')
    rows = []
    for kind, entries, name, actions in [
        ('workflow', policy['workflows'], 'workflow', ['run', 'read']),
        ('vault', policy['vaults'], 'vault', ['write', 'read']),
    ]:
        for entry in entries:
            expected = actions
            if kind == 'workflow' and 'publishes_inputs' in entry:
                if entry['publishes_inputs'] is not True:
                    raise ValueError('invalid_schedule_producer')
                expected = ['run', 'read', 'write']
            elif 'publishes_inputs' in entry:
                raise ValueError('invalid_schedule_producer')
            if entry['actions'] != expected or not re.fullmatch(r'[a-z][a-z0-9_-]*', entry[name]):
                raise ValueError('invalid_exact_scope')
            row = {'subject': SUBJECT, 'effect': 'allow', 'actions': expected,
                   'resource': {'kind': kind, 'pattern': entry[name]}}
            if kind == 'vault':
                key = entry['key_pattern']
                if not key.startswith('^') or not key.endswith('$') or '\n' in key or '\r' in key:
                    raise ValueError('invalid_key_pattern')
                re.compile(key)
                row['condition'] = "key.matches('" + key.replace('\\', '\\\\').replace("'", "\\'") + "')"
            rows.append(row)
    identities = [json.dumps([r['resource'], r.get('condition')], sort_keys=True) for r in rows]
    if not rows or len(set(identities)) != len(rows):
        raise ValueError('duplicate_or_empty_manifest')
    return rows


def missing(grants, scope):
    found = [g for g in grants if g.get('subject') == scope['subject']
             and g.get('resource') == scope['resource'] and g.get('state', 'active') == 'active'
             and (g.get('condition') or None) == scope.get('condition')]
    actions = []
    for grant in found:
        if (grant.get('effect') != 'allow' or grant.get('methods') or not grant.get('actions')
                or not set(grant['actions']).issubset(scope['actions'])):
            raise ValueError('existing_exact_scope_conflict')
        actions.extend(grant['actions'])
    if len(actions) != len(set(actions)):
        raise ValueError('overlapping_exact_scope_conflict')
    return found, [a for a in scope['actions'] if a not in actions]


def save(path, data):
    temporary = path.with_suffix('.tmp')
    with temporary.open('w') as stream:
        os.chmod(temporary, 0o600)
        json.dump(data, stream)
        stream.flush()
        os.fsync(stream.fileno())
    temporary.replace(path)


def reconcile(raw, revision, directory, native):
    """Apply only missing declared actions; never resume uncertain mutations."""
    if not re.fullmatch(r'[a-f0-9]{40}', revision):
        raise ValueError('invalid_source_revision')
    requested = scopes(json.loads(raw))
    digest = hashlib.sha256(raw).hexdigest()
    checkpoint = directory / (revision + '.json')
    prior = json.loads(checkpoint.read_text()) if checkpoint.exists() else None
    if prior and (prior['state'] != 'applied' or prior['manifest_sha256'] != digest):
        raise ValueError('checkpoint_requires_inspection')
    before = native(['access', 'grant', 'list'])
    groups = native(['access', 'group', 'list'])
    if not isinstance(before, list) or not isinstance(groups, list):
        raise TypeError('unreadable_policy_inventory')
    plan = [missing(before, scope) for scope in requested]
    if prior:
        if any(actions for _, actions in plan):
            raise ValueError('applied_policy_changed_requires_inspection')
        return {**prior, 'read_only_recheck': True}
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    backup = directory / (revision + '.before.json')
    if backup.exists():
        raise ValueError('existing_backup_requires_inspection')
    save(backup, {'grants': before, 'groups': groups, 'manifest': json.loads(raw)})
    out = {'state': 'applying', 'manifest_sha256': digest, 'source': revision,
           'backup': str(backup), 'created': [], 'before_count': len(before)}
    save(checkpoint, out)
    try:
        for scope, (_, actions) in zip(requested, plan):
            if not actions:
                continue
            out['pending'] = {**scope, 'actions': actions}
            save(checkpoint, out)
            args = ['access', 'grant', 'create', '--subject', PRINCIPAL, '--allow', ','.join(actions),
                    '--on', scope['resource']['kind'] + ':' + scope['resource']['pattern']]
            if scope.get('condition'):
                args += ['--when', scope['condition']]
            if native(args).get('status') != 'succeeded':
                raise ValueError('grant_create_unconfirmed')
            out['created'].append(out.pop('pending'))
            save(checkpoint, out)
        after = native(['access', 'grant', 'list'])
        if (not isinstance(after, list) or any(g not in after for g in before)
                or len(after) != len(before) + len(out['created'])
                or native(['access', 'group', 'list']) != groups):
            raise ValueError('prior_policy_changed')
        if any(missing(after, scope)[1] for scope in requested):
            raise ValueError('exact_readback_failed')
        out['grants'] = [g for scope in requested for g in missing(after, scope)[0]]
        out['after_count'] = len(after)
        if out['created']:
            out['pending'] = 'access_reload'
            save(checkpoint, out)
            if native(['access', 'reload']).get('success') is not True:
                raise ValueError('access_reload_unconfirmed')
            out.pop('pending')
        out['state'] = 'applied'
        save(checkpoint, out)
        return out
    except Exception:
        out['state'] = 'failed_requires_inspection'
        save(checkpoint, out)
        raise


def native(args, cwd, *, timeout=90):
    env = {'HOME': '/home/orbit', 'PATH': '/home/orbit/.local/bin:/usr/local/bin:/usr/bin:/bin',
           'SWAMP_HOME': '/home/orbit/.swamp', 'SWAMP_CONFIG_DIR': '/home/orbit/.config/swamp',
           'SWAMP_API_KEY_FILE': '/etc/orbit/swamp-issuer/api-key', 'SWAMP_NO_UPDATE_CHECK': '1',
           'SWAMP_NO_TELEMETRY': '1', 'SWAMP_SERVE_TIMEOUT_MS': '60000'}
    result = subprocess.run(['/home/orbit/.local/bin/swamp', *args, '--server', 'ws://127.0.0.1:9418',
                             '--token-file', '/etc/orbit/swamp-issuer/operator.token', '--json'],
                            cwd=cwd, env=env, check=False, capture_output=True, timeout=timeout)
    if result.returncode or len(result.stdout) > 8 * 1024 * 1024:
        raise RuntimeError('native_policy_refused_or_uncertain')
    text = result.stdout.decode()
    values = []
    while text.strip():
        value, end = json.JSONDecoder().raw_decode(text.lstrip())
        values.append(value)
        text = text.lstrip()[end:]
    if not values:
        raise ValueError('empty_native_policy_reply')
    return values[-1]
