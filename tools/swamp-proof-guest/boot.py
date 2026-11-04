"""Boot behaviour of the nested proof VMs, as code.

A reset copies, starts and waits for three VMs. Measured on the task VM, six boot problems made a reset slow
(95-99 s) or flaky; with the fixes below the whole fleet is Doctor-clean in about 50 s. Every fix is a small file
this module writes, so a fresh build and a copy of a ready build behave the same. Nothing here is edited by hand
inside a VM.

``apply`` takes an ``execute(argv, input=None)`` callable: Incus on the outer VM for the app Nodes, the guest
fixture helper inside the Gateway.
"""
from pathlib import PurePosixPath

CHECKOUT = '/home/orbit/orbit'
PHP_VERSIONS = ('8.4', '8.5')
PHP_SAPIS = ('fpm', 'cli')
HOSTS_NAMES = 'gateway.orbit swamp.orbit reverb.orbit'
UNITS = '/etc/systemd/system'

# The agent starts after the hub tunnel and waits (at most 60 s) until the Gateway answers realtime discovery with a
# Reverb URL. A null URL makes the agent sleep 60 s before it asks again, so an early start cost a minute.
AGENT = r'''[Unit]
After=wg-quick@orbit.service
Wants=wg-quick@orbit.service

[Service]
# Wait (max 60 s) until the Gateway answers realtime discovery with a Reverb url; a null url makes the agent sleep 60 s.
ExecStartPre=-/usr/bin/bash -c 'end=$((SECONDS+60)); while [ $SECONDS -lt $end ]; do printf "header = \\"Authorization: Bearer %%s\\"\\n" "$(cat /etc/orbit/agent/secret)" | curl -sk -m 2 -K - https://@ADDRESS@/api/v1/agent/realtime 2>/dev/null | grep -q "\\"url\\":\\"" && exit 0; sleep 0.5; done; exit 0'
TimeoutStartSec=90
'''

# The Incus agent rewrites /etc/hosts when a copy starts. This path unit puts the Orbit names back at once.
HOSTS_PATH = '''[Unit]
Description=Watch /etc/hosts for rewrites

[Path]
PathChanged=/etc/hosts
Unit=orbit-hosts.service

[Install]
WantedBy=multi-user.target
'''

HOSTS_SERVICE = r'''[Unit]
Description=Keep the Orbit nested-topology names in /etc/hosts (the Incus agent rewrites the file on a fresh copy)
StartLimitIntervalSec=0

[Service]
Type=oneshot
ExecStart=/usr/bin/bash -c 'grep -q " @NAMES@" /etc/hosts || printf "@ADDRESS@ @NAMES@\\n" >> /etc/hosts'
'''

# Swamp serve starts after the tunnel and Reverb; the worker waits (at most 60 s) until serve listens.
SERVE = '''[Unit]
After=network-online.target wg-quick@orbit.service orbit-websocket.service
Wants=network-online.target

[Service]
Nice=10
RestartSec=2

[Install]
WantedBy=multi-user.target
'''

WORKER = r'''[Unit]
After=orbit-swamp-serve.service
Wants=orbit-swamp-serve.service
StartLimitIntervalSec=0

[Service]
Nice=10
RestartSec=2
ExecStartPre=/usr/bin/bash -c 'end=$((SECONDS+60)); while [ $SECONDS -lt $end ]; do timeout 1 bash -c "exec 3<>/dev/tcp/127.0.0.1/9418" 2>/dev/null && exit 0; sleep 0.5; done; exit 0'

[Install]
WantedBy=multi-user.target
'''

# RequiresMountsFor alone does not hold the unit back: the virtiofs source is mounted after the unit file exists.
# A Gateway service waits (at most 60 s) for the real checkout, and `WorkingDirectory=-` lets it start before then.
CHECKOUT_MOUNT = r'''[Unit]
RequiresMountsFor=@CHECKOUT@

[Service]
WorkingDirectory=-@CHECKOUT@/apps/gateway
ExecStartPre=/usr/bin/bash -c 'end=$((SECONDS+60)); while [ $SECONDS -lt $end ]; do mountpoint -q @CHECKOUT@ && [ -f @CHECKOUT@/apps/gateway/artisan ] && exit 0; sleep 0.2; done; exit 0'
'''

# PHP reads the checkout as it is: no stale opcode after an edit, and no Laravel config, route or event cache.
OPCACHE = '''opcache.enable=1
opcache.enable_cli=0
opcache.validate_timestamps=1
opcache.revalidate_freq=0
'''
OPCACHE_FILE = '99-orbit-dev-opcache.ini'
LARAVEL_CACHES = ('config.php', 'events.php', 'routes-*.php')

GATEWAY_SERVICES = ('orbit-swamp-serve', 'orbit-node-worker', 'orbit-proof-agent-view', 'orbit-proof-operations')


def render(template, **values):
    for key, value in values.items():
        template = template.replace('@' + key.upper() + '@', value)
    return template


def files(role, address, *, checkout=CHECKOUT):
    """Path to contents for one VM. `role` is `gateway` or `node`; `address` is the Gateway's private address."""
    common = {
        '/etc/cloud/cloud-init.disabled': '',
        f'{UNITS}/orbit-hosts.path': HOSTS_PATH,
        f'{UNITS}/orbit-hosts.service': render(HOSTS_SERVICE, names=HOSTS_NAMES, address=address),
        f'{UNITS}/orbit-agent.service.d/10-after-wireguard.conf': render(AGENT, address=address),
    }
    if role == 'node':
        return common
    if role != 'gateway':
        raise ValueError('Unknown boot role: ' + role)
    mount = render(CHECKOUT_MOUNT, checkout=checkout)
    gateway = {
        f'{UNITS}/orbit-swamp-serve.service.d/10-boot.conf': SERVE,
        f'{UNITS}/orbit-node-worker.service.d/10-boot.conf': WORKER,
        f'{UNITS}/orbit-proof-agent-view.service.d/10-checkout-mount.conf': mount,
        f'{UNITS}/orbit-proof-operations.service.d/10-checkout-mount.conf': mount,
    }
    for version in PHP_VERSIONS:
        for sapi in PHP_SAPIS:
            gateway[f'/etc/php/{version}/{sapi}/conf.d/{OPCACHE_FILE}'] = OPCACHE
    return {**common, **gateway}


def write(execute, path, contents):
    prepare = 'mkdir -p "$(dirname "$1")"'
    if path.startswith('/etc/php/'):
        # A PHP version this image does not carry has no conf.d; skip it instead of creating a stray tree.
        prepare = '[ -d "$(dirname "$1")" ] || exit 0'
    execute(['sh', '-c', f'umask 022; {prepare}; cat > "$1"', 'sh', path], input=contents)


def apply(execute, role, address, *, checkout=CHECKOUT):
    """Write the boot files and enable them. Safe to repeat. Call after the services it adjusts exist."""
    for path, contents in files(role, address, checkout=checkout).items():
        write(execute, path, contents)
    execute(['systemctl', 'daemon-reload'])
    execute(['systemctl', 'enable', '--now', 'orbit-hosts.path'])
    execute(['systemctl', 'start', 'orbit-hosts.service'])
    if role == 'gateway':
        execute(['systemctl', 'enable', *GATEWAY_SERVICES])
        # `opcache.validate_timestamps=1` takes effect when FPM starts again.
        execute(['sh', '-c', 'for v; do if [ -d "/etc/php/$v/fpm" ]; then systemctl try-restart "php$v-fpm"; fi; done', 'sh', *PHP_VERSIONS])
        caches = ' '.join(f'"$1"/bootstrap/cache/{name}' for name in LARAVEL_CACHES)
        execute(['sh', '-c', f'rm -f {caches}', 'sh', str(PurePosixPath(checkout) / 'apps/gateway')])
