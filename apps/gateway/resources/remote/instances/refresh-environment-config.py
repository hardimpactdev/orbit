# Program: instances.refresh-environment-config
# Uses: lib/program.py
# Outcomes: config_cached config_uncached no_current_release failed
# Timeout: 120
# Input: {"user": production user, "home": persistent production home, "version": selected PHP version, "application": effective application directory under current}
# Result: no data. Rebuild an existing current release config cache as the Instance user, or leave it uncached.
# Application output is discarded because config-cache failures can contain environment values.

import subprocess


def main(payload):
    user = payload['user']
    current = payload['home'] + '/current'
    application = payload['application']
    sudo = ['sudo', '-u', user, '-H']

    def succeeds(arguments):
        return subprocess.run(sudo + arguments, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False).returncode == 0

    if not succeeds(['test', '-e', current]):
        if succeeds(['test', '-L', current]):
            return 'failed', {}
        return 'no_current_release', {}
    if not succeeds(['test', '-d', current]):
        return 'failed', {}
    if not succeeds(['test', '-f', application + '/bootstrap/cache/config.php']):
        return 'config_uncached', {}
    if not succeeds([
        'bash', '-seu', '-c', 'cd -- "$1"; exec "php$2" artisan config:cache',
        '--', application, payload['version'],
    ]):
        return 'failed', {}
    return 'config_cached', {}


run_program(main)
