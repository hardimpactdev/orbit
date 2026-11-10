#!/usr/bin/python
"""Read copied dotenv files without executing them or returning their private values.

Core Ansible modules do not parse dotenv. A custom read-only module keeps private
file contents on the Node while parsing quoted values, discovering copied apps
and classifying IP endpoints with Python's standard library. A slurp/template
implementation would transport complete private environments to the worker.
"""
import ipaddress
import os
import re

DOCUMENTATION = r'''
module: orbit_migrate_env
short_description: Check copied service endpoints before Instance migration downtime
version_added: "1.0.0"
description: Read checkout dotenv files and refuse service endpoints that cannot be admitted for a container.
options:
  checkout:
    description: Checkout copied by the move.
    type: path
    required: true
  targets:
    description: Exact attached target endpoints recorded for the Instance.
    type: list
    elements: dict
    required: true
  node_addresses:
    description: Addresses belonging to the source Node.
    type: list
    elements: str
    required: true
  fleet_addresses:
    description: Recorded addresses of fleet Nodes.
    type: list
    elements: str
    required: true
  wireguard_ranges:
    description: Configured fleet WireGuard ranges.
    type: list
    elements: str
    required: true
author: Orbit
'''

RECOVERY = 'Attach the exact target, correct the .env, or use a non-network driver.'
ASSIGNMENT = re.compile(r'^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$')
HOSTNAME = re.compile(r'(?=.{1,253}\Z)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\Z')


def dotenv(text):
    """Literal dotenv values only; interpolation stays unresolved and never reads another key."""
    values = {}
    lines = iter(text.splitlines())
    for line in lines:
        match = ASSIGNMENT.match(line)
        if not match:
            continue
        key, raw = match.groups()
        raw = raw.strip()
        if raw[:1] in ('"', "'"):
            quote = raw[0]
            while not re.search(r'(?<!\\)' + quote + r'\s*(?:#.*)?$', raw[1:]):
                continuation = next(lines, None)
                if continuation is None:
                    break
                raw += '\n' + continuation
            quoted = re.fullmatch(quote + r'(.*)(?<!\\)' + quote + r'\s*(?:#.*)?', raw, re.DOTALL)
            value = quoted[1] if quoted else None
        else:
            value = re.split(r'\s+#', raw, maxsplit=1)[0].strip()
        values[key] = value.strip() if value is not None else None
    return values


def canonical_host(value):
    """Return only a host literal that is safe to display, never URL credentials or substitutions."""
    if value is None or '$' in value or '`' in value:
        return None, '[unresolved host]'
    value = value.strip()
    if '%' in value:
        return None, '[invalid host]'
    if value.startswith('[') and value.endswith(']'):
        value = value[1:-1]
    try:
        address = ipaddress.ip_address(value)
        return str(address), str(address)
    except ValueError:
        name = value[:-1] if value.endswith('.') else value
        if HOSTNAME.fullmatch(name):
            return name.lower(), value
    return None, '[invalid host]'


def port_number(value):
    if value is not None and re.fullmatch(r'[0-9]{1,5}', value):
        port = int(value)
        if 1 <= port <= 65535:
            return port
    return None


def connection(values, key):
    """Whether the key is used, its port, and why uncertain driver use is checked conservatively."""
    stem = key[:-5]
    driver_reason = ''
    default = None
    standard = next((family for family in ('DB', 'MAIL', 'REDIS', 'MEMCACHED')
                     if stem == family or stem.endswith('_' + family)), None)
    if standard == 'DB' or (standard is None and stem + '_CONNECTION' in values):
        driver = values.get(stem + '_CONNECTION')
        if driver == 'sqlite':
            return False, None, ''
        ports = {'mysql': 3306, 'mariadb': 3306, 'pgsql': 5432, 'sqlsrv': 1433, 'redis': 6379}
        default = ports.get(driver)
        if driver not in ports:
            driver_reason = 'driver use is unknown, so it is checked conservatively; '
    elif standard == 'MAIL':
        driver = values.get(stem + '_MAILER')
        if driver in ('log', 'array', 'sendmail'):
            return False, None, ''
        default = 25
        if driver != 'smtp':
            driver_reason = 'mailer use is unknown, so it is checked conservatively; '
    elif standard == 'REDIS':
        prefix = stem[:-5]
        store = values.get(prefix + 'CACHE_STORE')
        legacy_store = values.get(prefix + 'CACHE_DRIVER')
        cache_conflict = prefix + 'CACHE_STORE' in values and prefix + 'CACHE_DRIVER' in values and store != legacy_store
        consumers = [values.get(prefix + 'CACHE_STORE', legacy_store),
                     values.get(prefix + 'SESSION_DRIVER'), values.get(prefix + 'QUEUE_CONNECTION')]
        known = ({'array', 'file', 'database', 'null', 'memcached', 'dynamodb', 'octane'},
                 {'array', 'file', 'database', 'cookie', 'null', 'memcached', 'dynamodb'},
                 {'sync', 'database', 'null', 'sqs', 'beanstalkd', 'deferred', 'background'})
        if not cache_conflict and all(driver in choices for driver, choices in zip(consumers, known)):
            return False, None, ''
        default = 6379
        if cache_conflict:
            driver_reason = 'cache driver aliases disagree, so Redis is checked conservatively; '
        elif 'redis' not in consumers:
            driver_reason = 'Redis consumer drivers are unknown, so it is checked conservatively; '
    elif standard == 'MEMCACHED':
        default = 11211
    elif stem + '_PORT' not in values:
        return False, None, ''
    port = port_number(values.get(stem + '_PORT', str(default) if default is not None else None))
    return True, port, driver_reason


def env_files(checkout):
    """Follow copied app directories, including symlinks, once per inode to avoid cycles."""
    if not os.path.isdir(checkout):
        raise OSError('The checkout is not a readable directory.')

    def refuse_unreadable(error):
        raise OSError('Cannot inspect every copied directory.') from error

    visited = set()
    for directory, children, files in os.walk(checkout, followlinks=True, onerror=refuse_unreadable):
        stat = os.stat(directory)
        identity = (stat.st_dev, stat.st_ino)
        if identity in visited:
            children[:] = []
            continue
        visited.add(identity)
        children[:] = sorted(child for child in children if child != '.git')
        if '.env' in files:
            yield os.path.join(directory, '.env')


def inspect_environment(checkout, targets, node_addresses, fleet_addresses, wireguard_ranges):
    """Safe refusal lines, with no environment contents except validated host and numeric port."""
    attached = {(canonical_host(str(target['host']))[0], int(target['port'])) for target in targets}
    local = set()
    for value in node_addresses:
        host = canonical_host(value)[0]
        try:
            address = ipaddress.ip_address(host)
            host = str(getattr(address, 'ipv4_mapped', None) or address)
        except ValueError:
            pass
        local.add(host)
    fleet = {canonical_host(value)[0] for value in fleet_addresses}
    ranges = [ipaddress.ip_network(value, strict=False) for value in wireguard_ranges]
    refusals = []
    for path in env_files(checkout):
        relative = os.path.relpath(path, checkout)
        try:
            with open(path, encoding='utf-8-sig') as handle:
                values = dotenv(handle.read())
        except (OSError, UnicodeError):
            refusals.append(f'{relative}: cannot read .env safely. {RECOVERY}')
            continue
        for key, value in values.items():
            if not key.endswith('_HOST'):
                if value is None:
                    refusals.append(f'{relative}: {key}=[unresolved value]: malformed dotenv quoting prevents a reliable '
                                    f'service check. {RECOVERY}')
                continue
            used, port, driver_reason = connection(values, key)
            if not used:
                continue
            host, display = canonical_host(value)
            reason = None
            if host is None:
                reason = 'the service host is not a resolved literal'
            elif port is None:
                reason = 'the service port is missing or is not a literal port'
            elif (host, port) in attached:
                continue
            else:
                try:
                    address = ipaddress.ip_address(host)
                    address = getattr(address, 'ipv4_mapped', None) or address
                    host_only = address.is_loopback or address.is_unspecified or str(address) in local
                    permitted = any(address.version == network.version and address in network for network in ranges)
                except ValueError:
                    host_only = host == 'localhost'
                    permitted = False
                if host_only or host in local:
                    reason = 'the host belongs to the Node or container loopback and has no exact attached target'
                elif host not in fleet and not permitted:
                    reason = 'the host is outside the known fleet and WireGuard ranges and has no exact attached target'
            if reason:
                endpoint = f'[{display}]' if ':' in display else display
                refusals.append(f'{relative}: {key}={endpoint}:{port if port is not None else "[unknown port]"}: '
                                f'{driver_reason}{reason}. {RECOVERY}')
    return refusals


def main():
    from ansible.module_utils.basic import AnsibleModule

    module = AnsibleModule(argument_spec={
        'checkout': {'type': 'path', 'required': True},
        'targets': {'type': 'list', 'elements': 'dict', 'required': True},
        'node_addresses': {'type': 'list', 'elements': 'str', 'required': True},
        'fleet_addresses': {'type': 'list', 'elements': 'str', 'required': True},
        'wireguard_ranges': {'type': 'list', 'elements': 'str', 'required': True},
    }, supports_check_mode=True)
    try:
        refusals = inspect_environment(**module.params)
    except (OSError, ValueError):
        module.fail_json(msg='Cannot inspect the copied .env service endpoints safely.')
    module.exit_json(changed=False, refusals=refusals)


if __name__ == '__main__':
    main()
