"""Read-only deploy/rollback end-state checks inside an F13 topology."""
import argparse
import json
import os
import shlex
import subprocess
from pathlib import Path

TARGET_READ = r'''
import glob,json,pathlib,pwd,subprocess,sys
checkout,user,home=sys.argv[1:4]
path=pathlib.Path(checkout)
def text(name):
 p=path/name
 return p.read_text().strip() if p.is_file() else None
result={'head':subprocess.check_output(['sudo','-n','-u',user,'--','git','-C',checkout,'rev-parse','HEAD'],text=True).strip(),
 'account':text('.proof-deploy-user'),'phases':text('.proof-deploy-phases'),'vp_version':text('.proof-vp-version'),
 'marker_owner':pwd.getpwuid((path/'.proof-deploy-user').stat().st_uid).pw_name}
if home:
 result['current']=str((pathlib.Path(home)/'current').resolve(strict=True))
 units=glob.glob('/etc/systemd/system/orbit-'+user+'-php*-fpm.service')
 result['php_active']=any(subprocess.run(['systemctl','is-active','--quiet',pathlib.Path(p).name],check=False).returncode==0 for p in units)
 result['php_socket']=(pathlib.Path('/run/php')/(user+'.sock')).is_socket()
print(json.dumps(result))
'''


def main():
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument('--variant', choices=['development', 'production', 'rollback'], required=True)
    parser.add_argument('--node', required=True)
    parser.add_argument('--instance', type=int, required=True)
    parser.add_argument('--release')
    parser.add_argument('--vp-bin-775', action='store_true')
    args = parser.parse_args()
    prefix = shlex.split(os.environ['PROOF_EXEC'])

    def execute(node, argv):
        result = subprocess.run(prefix + [node, '--argv=' + json.dumps(argv)],
                                check=False, capture_output=True, text=True, timeout=30)
        if result.returncode:
            raise AssertionError('Topology read failed: ' + argv[0])
        return json.loads(result.stdout)

    def cli(command, identity):
        return execute('operator', ['orbit', command, str(identity), '--json'])

    instance = cli('instance:show', args.instance)
    checks = {}
    if args.variant == 'development':
        node = cli('node:show', instance['node_id'])
        user, home = node['user'], ''
    else:
        user = 'orbit-app-' + str(instance['project_id'])
        home = '/home/' + user
        assert instance['production_user'] == user
        assert instance['production_home'] == home
    observed = execute(args.node, ['sudo', '-n', 'python3', '-I', '-c', TARGET_READ,
                                   instance['checkout_path'], user, home])
    if args.variant == 'development':
        checks['checkout-equals-recorded-seed'] = observed['head'] == instance['seed_commit']
        if args.vp_bin_775:
            helper = Path(__file__).with_name('vp_bin_775.py').read_text()
            store = execute(args.node, ['sudo', '-n', '-u', user, '--', 'python3', '-I',
                                        '-c', helper, '--target-user', user, '--check'])
            checks['existing-vp-bin-remains-775'] = store['bin_mode'] == 0o775
            checks['vp-command-succeeded'] = bool(observed['vp_version'])
    else:
        releases = cli('instance:release:list', args.instance)
        selected = releases['selected_release']
        checks['selected-release-retained'] = selected in releases['releases']
        checks['current-link-matches-selection'] = (
            observed['current'] == home + '/releases/' + selected == instance['checkout_path'])
        checks['dedicated-php-service-active'] = observed['php_active'] and observed['php_socket']
        if args.variant == 'rollback':
            assert args.release, 'A captured retained release is required'
            checks['requested-release-selected'] = selected == args.release
            checks['automatic-policy-paused'] = instance['auto_deploy']['pause'] is not None
    if args.variant != 'rollback':
        checks['project-command-account'] = observed['account'] == user == observed['marker_owner']
        checks['project-command-order'] = observed['phases'] == 'before\nafter'
    print(json.dumps({'passed': sum(checks.values()),
                      'failed': sum(not v for v in checks.values()), 'cases': checks}))
    assert all(checks.values()), 'Topology end-state parity failed'


if __name__ == '__main__':
    main()
