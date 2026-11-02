"""instance:rollback contract on the app-prod Node (F9): `current` selects the retained release, the dedicated PHP-FPM
reloaded, and the Gateway recorded the rollback, blocked the
commit it moved away from, and keeps automatic deployment paused."""
import json
import os
import subprocess
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
home = '/home/orbit-app-9001'
moved_away = '2' * 40


def peer(*argv):
    return subprocess.run(['incus', 'exec', 'peer', '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


def gateway(expression):
    return subprocess.run(['runuser', '-u', 'orbit', '--', 'env', 'HOME=/tmp', 'XDG_CONFIG_HOME=/tmp', 'php', 'artisan', 'tinker', '--execute', expression],
                          cwd='apps/gateway', capture_output=True, text=True, check=False).stdout.strip()


check(operation['status'] == 'succeeded', 'the rollback succeeded')
result = operation['result']
check(result['release'] == 'r1' and result['selected_release'] == 'r1' and result['commit'] == '1' * 40,
      'the result names the release, the selection and its commit')
check(peer('readlink', home + '/current').stdout.strip() == 'releases/r1', 'current selects the rolled-back release')
check(peer('stat', '-c', '%U', home + '/current').stdout.strip() == 'orbit-app-9001', 'the production account owns current')
check(peer('test', '-e', '/run/orbit-proof-fpm-reloaded').returncode == 0, 'the dedicated PHP-FPM service reloaded')
check(operation['input']['runtime_definitions'] == {'restart': []}, 'the Instance has no Process to restart')
row = json.loads(gateway(f'echo json_encode(App\\Models\\InstanceDeployment::query()->findOrFail({result["deployment_id"]})->only(["kind","status","release","selected_release","commit","failed_step","error_code"]));'))
check(row == {'kind': 'rollback', 'status': 'succeeded', 'release': 'r1', 'selected_release': 'r1', 'commit': '1' * 40,
              'failed_step': None, 'error_code': None}, f'the Gateway recorded the rollback: {row}')
state = json.loads(gateway('echo json_encode(App\\Models\\Instance::query()->findOrFail(9001)->only(["auto_deploy_state","checkout_path"]));'))
blocked = json.loads(state['auto_deploy_state'] or '{}').get('blocked', []) if isinstance(state['auto_deploy_state'], str) else (state['auto_deploy_state'] or {}).get('blocked', [])
check(blocked == [moved_away], f'the commit the rollback moved away from is blocked: {blocked}')
check(state['checkout_path'] == home + '/releases/r1', 'the Instance records the selected release')
paused = gateway('echo (new App\\Domain\\Instances\\Deployment\\AutomaticDeployHistory(App\\Models\\Instance::query()->findOrFail(9001)))->pausedBy()?->id;')
check(paused == str(result["deployment_id"]), 'automatic deployment stays paused by the rollback')
print('parity: instance:rollback selected r1, reloaded PHP-FPM, blocked the old commit and paused automatic deploys')
