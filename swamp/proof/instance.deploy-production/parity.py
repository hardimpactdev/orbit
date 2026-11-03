"""instance:deploy contract on the app-prod Node (F9): a new release at the frozen commit, `.env` written 0600, the
before and after steps ran as the Instance account, `current` swapped atomically to the new release, the dedicated
PHP-FPM reloaded, cleanup removed only the release no longer kept, and the Gateway recorded the deployment."""
import json
import os
import re
import subprocess
import sys

with open(os.environ['PROOF_OPERATION_FILE']) as file:
    operation = json.load(file)
user = 'orbit-app-9002'
home = '/home/' + user


def peer(*argv):
    return subprocess.run(['incus', 'exec', 'peer', '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


def gateway(expression):
    return subprocess.run(['runuser', '-u', 'orbit', '--', 'env', 'HOME=/tmp', 'XDG_CONFIG_HOME=/tmp', 'php', 'artisan', 'tinker', '--execute', expression],
                          cwd='apps/gateway', capture_output=True, text=True, check=False).stdout.strip()


check(operation['status'] == 'succeeded', 'the deploy succeeded')
result = operation['result']
release = result['release']
commit = result['commit']
check(re.fullmatch(r'\d{14}-op-\d{20}', release or '') is not None, f'the result names the new release: {release}')
check(re.fullmatch(r'[0-9a-f]{40}', commit or '') is not None and result['selected_release'] == release, 'the result names the commit and the selection')
directory = f'{home}/releases/{release}'
check(peer('git', '-c', 'safe.directory=*', '-C', directory, 'rev-parse', 'HEAD').stdout.strip() == commit, 'the release is checked out at the recorded commit')
check(peer('stat', '-c', '%U', directory).stdout.strip() == user, 'the Instance account owns the release')
check(peer('readlink', home + '/current').stdout.strip() == 'releases/' + release, 'current selects the new release')
check(peer('stat', '-c', '%U', home + '/current').stdout.strip() == user, 'the Instance account owns current')
check(peer('test', '-L', home + '/current.next').returncode != 0, 'current.next was renamed over current')
check(peer('stat', '-c', '%a %U', home + '/.env').stdout.strip() == '600 ' + user, '.env is 0600 and owned by the Instance account')
check(peer('grep', '-c', '^APP_KEY=', home + '/.env').stdout.strip() == '1', '.env holds the rendered keys')
check(peer('readlink', directory + '/.env').stdout.strip() == '../../.env', 'the release links its .env to the home')
check(peer('readlink', directory + '/storage').stdout.strip() == home + '/storage', 'the release links the shared storage')
before = peer('cat', home + '/before-ran').stdout.split('\n')
check(before[:3] == [user, commit, directory], f'the before step ran as the Instance account in the new release: {before}')
check(peer('cat', home + '/after-ran').stdout.strip() == 'releases/' + release, 'the after step ran once current selected the new release')
check(peer('test', '-e', '/run/orbit-proof-fpm-reloaded').returncode == 0, 'the dedicated PHP-FPM service reloaded')
check(peer('test', '-e', home + '/releases/r1').returncode != 0, 'cleanup removed the release the home no longer keeps')
check(all(peer('test', '-d', f'{home}/releases/{kept}').returncode == 0 for kept in ('r2', 'r3')), 'cleanup kept the previous selection and the newest other release')
left = peer('sh', '-c', 'ls /tmp | grep -c "^orbit-deploy-operation-" || true').stdout.strip()
check(left == '0', f'the read token and the step files are gone: {left}')
row = json.loads(gateway(f'echo json_encode(App\\Models\\InstanceDeployment::query()->findOrFail({result["deployment_id"]})->only(["kind","status","release","selected_release","commit","failed_step","error_code"]));'))
check(row == {'kind': 'deploy', 'status': 'succeeded', 'release': release, 'selected_release': release, 'commit': commit,
              'failed_step': None, 'error_code': None}, f'the Gateway recorded the deployment: {row}')
state = json.loads(gateway('echo json_encode(App\\Models\\Instance::query()->findOrFail(9002)->only(["checkout_path","environment_owned_keys"]));'))
check(state['checkout_path'] == directory, 'the Instance records the selected release')
check(sorted(state['environment_owned_keys'] or []) == ['APP_ENV', 'APP_KEY'], f'the Instance owns the written keys: {state}')
check('proof-private-deploy-output' not in json.dumps(operation), 'no deploy step output reaches the Operation')
print(f'parity: instance:deploy prepared {release} at {commit[:12]}, wrote .env, ran both steps as {user}, swapped current, reloaded PHP-FPM and removed r1')
