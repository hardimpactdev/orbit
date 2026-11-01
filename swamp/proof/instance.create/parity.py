"""Check the full F13 request through normal operator CLI and workload Node reads."""
import json
import os
import re
import shlex
import subprocess
import sys
from pathlib import Path

SETUP_CHECK = '''import json, re, stat, sys
from pathlib import Path
root = Path(sys.argv[1]).resolve(strict=True)
commit = sys.argv[2]
if not re.fullmatch(r"[a-f0-9]{40}", commit):
    raise RuntimeError("proof-setup-commit-invalid")
marker = root / ".orbit-proof-setup"
if not marker.exists() or not stat.S_ISREG(marker.lstat().st_mode):
    raise RuntimeError("proof-setup-marker-missing")
if marker.stat().st_size != 41 or marker.read_text() != commit + "\\n":
    raise RuntimeError("proof-setup-marker-mismatch")
print(json.dumps({"setup_executed": True, "commit": commit}))
'''


DATABASE_CHECK = '''import json, sqlite3, sys
from pathlib import Path
root, source = (Path(value).resolve() for value in sys.argv[1:])
if root == source:
    raise RuntimeError("proof-source-and-target-are-identical")
for base in (root, source):
    if not (base / "database/database.sqlite").is_file():
        raise RuntimeError("proof-database-missing")
with sqlite3.connect((root / "database/database.sqlite").as_uri() + "?mode=ro", uri=True) as db:
    if db.execute("SELECT marker FROM orbit_create_seed").fetchall() != [("owned-source",)]:
        raise RuntimeError("proof-copied-database-invalid")
    if db.execute("SELECT name FROM orbit_create_migrations").fetchall() != [("create-before-setup",)]:
        raise RuntimeError("proof-migration-not-applied")
with sqlite3.connect((source / "database/database.sqlite").as_uri() + "?mode=ro", uri=True) as db:
    if db.execute("SELECT marker FROM orbit_create_seed").fetchall() != [("owned-source",)]:
        raise RuntimeError("proof-source-database-changed")
    if db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='orbit_create_migrations'").fetchall():
        raise RuntimeError("proof-source-database-was-migrated")
print(json.dumps({"copied_database_migrated": True, "source_database_preserved": True}))
'''


def node_json(node, argv):
    response = subprocess.run([*shlex.split(os.environ['PROOF_EXEC']), node,
                               '--argv=' + json.dumps(argv)], check=True, capture_output=True,
                              text=True, timeout=30)
    return json.loads(response.stdout)


def validate(selected, operation, instance, case):
    """Only public terminal fields establish this request's disposition."""
    result = json.loads(selected['stdout'])
    if selected.get('ready') is not True or result.get('ready') is not True:
        raise RuntimeError('proof-create-not-ready')
    if operation.get('status') != 'succeeded' or operation.get('kind') != 'instance.create':
        raise RuntimeError('proof-create-operation-not-succeeded')
    if not (type(result.get('instance_id')) is int and result['instance_id'] > 0 and result['instance_id'] == operation.get('target_id') == instance.get('id')):
        raise RuntimeError('proof-create-target-mismatch')
    if instance.get('status') != 'active':
        raise RuntimeError('proof-create-instance-not-active')
    source = result['source']
    if not re.fullmatch(r'proof-[a-zA-Z0-9-]+', source['branch']) or not re.fullmatch(r'[a-f0-9]{40}', source['commit']):
        raise RuntimeError('proof-create-source-invalid')
    if instance.get('starting_commit') != source['commit']:
        raise RuntimeError('proof-create-source-projection-mismatch')
    if source['php_version'] is not None or source['laravel'] is not False or result['vp_environment'] is not None:
        raise RuntimeError('proof-nonweb-source-profile-mismatch')
    if not {'APP_NAME', 'APP_ENV', 'APP_DEBUG'} <= set(result['environment_keys']):
        raise RuntimeError('proof-create-environment-incomplete')
    expected = ['migrate-owned-database'] if case == 'database-migration' else ['verify-public-source']
    steps = operation['input']['direct_provisioning']['setup']['steps']
    if [step['name'] for step in steps] != expected:
        raise RuntimeError('proof-create-setup-list-mismatch')
    if 'error_code' in result:
        raise RuntimeError('proof-create-has-error')
    return result


def main():
    case = sys.argv[1]
    if case not in ('source-setup', 'database-migration'):
        raise RuntimeError('proof-create-case-unknown')
    selected = json.loads(Path(os.environ['PROOF_OUTPUTS']).read_text())
    operation = node_json('operator', ['orbit', 'operation:show', os.environ['PROOF_OPERATION_ID'], '--json'])
    instance = node_json('operator', ['orbit', 'instance:show', str(operation['target_id']), '--json'])
    result = validate(selected, operation, instance, case)
    if case == 'source-setup':
        checked = node_json('app-dev', ['python3', '-c', SETUP_CHECK, instance['checkout_path'], result['source']['commit']])
        if checked != {'setup_executed': True, 'commit': result['source']['commit']}:
            raise RuntimeError('proof-setup-parity-unconfirmed')
    if case == 'database-migration':
        listing = node_json('operator', ['orbit', 'instance:list', '--json'])
        defaults = [row for row in listing['instances'] if row['project_id'] == instance['project_id'] and row['name'] == 'default']
        if len(defaults) != 1 or defaults[0]['id'] == instance['id']:
            raise RuntimeError('proof-source-default-not-unique')
        checked = node_json('app-dev', ['python3', '-c', DATABASE_CHECK, instance['checkout_path'], defaults[0]['checkout_path']])
        if checked != {'copied_database_migrated': True, 'source_database_preserved': True}:
            raise RuntimeError('proof-database-parity-unconfirmed')
    print(json.dumps({'passed': 4, 'case': case}))


if __name__ == '__main__':
    main()
