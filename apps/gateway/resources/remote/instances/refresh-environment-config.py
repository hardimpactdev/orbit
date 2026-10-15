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
        'bash', '-seu', '-c', '''cd -- "$1"
cache="$PWD/bootstrap/cache/config.php"
temporary=$(mktemp -d "$PWD/bootstrap/cache/.orbit-config-XXXXXXXX")
trap 'rm -rf -- "$temporary"' EXIT
export APP_CONFIG_CACHE="$temporary/config.php"
"php$2" artisan config:cache
[ -f "$APP_CONFIG_CACHE" ]
# Boot Artisan against the temporary cache using the application's own autoload context.
"php$2" artisan --version --no-interaction
mv -f -- "$APP_CONFIG_CACHE" "$cache"
''',
        '--', application, payload['version'],
    ]):
        return 'failed', {}
    return 'config_cached', {}


run_program(main)
