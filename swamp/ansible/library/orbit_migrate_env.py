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
  proxy_policy:
    description: Denied IPv4 ranges and private DNS domains from the shared Instance proxy policy.
    type: dict
    required: true
author: Orbit
'''

RECOVERY = 'Attach the exact target, correct the .env, or use a non-network driver.'
ASSIGNMENT = re.compile(r'[A-Za-z0-9_.]+\Z')
HOSTNAME = re.compile(r'(?=.{1,253}\Z)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\Z')
DOTENV_TRIM = ' \n\r\t\0\v'
DOTENV_SPACE = ' \n\r\t\v\f'


class DotenvSyntaxError(ValueError):
    """A safe location, without any source text or private value."""

    def __init__(self, line):
        self.line = line
        super().__init__('Malformed or unsupported dotenv syntax.')


def dotenv_entries(text):
    """Assemble double-quoted multiline entries as installed phpdotenv's Lines does."""
    buffered = []
    start = 1
    for number, line in enumerate(re.split(r'\r\n|\n|\r', text), 1):
        position = line.find('="')
        hash_position = line.find('#')
        quotes = len(re.findall(r'(?<!\\)"', line.replace('\\\\', '')))
        begins = not buffered and position != -1 and (hash_position == -1 or hash_position > position) and quotes < 2
        if begins:
            start = number
        if buffered or begins:
            buffered.append(line)
            if quotes < (2 if begins else 1):
                continue
            line = '\n'.join(buffered)
            buffered = []
            number = start
        if line.strip(DOTENV_TRIM) and not line.strip(DOTENV_TRIM).startswith('#'):
            yield number, line
    if buffered:
        raise DotenvSyntaxError(start)


def dotenv_value(raw, line):
    """The installed parser's value states, with variable references left unresolved."""
    state = 'initial'
    output = []
    escapes = {'f': '\f', 'n': '\n', 'r': '\r', 't': '\t', 'v': '\v', '"': '"', '\\': '\\', '$': '$'}
    current_line = line
    for char in raw:
        if state == 'comment':
            pass
        elif state == 'initial':
            if char in ("'", '"'):
                state = 'single' if char == "'" else 'double'
            elif char == '#':
                state = 'comment'
            else:
                output.append(char)
                state = 'unquoted'
        elif state == 'single':
            if char == "'":
                state = 'whitespace'
            else:
                output.append(char)
        elif state == 'double':
            if char == '"':
                state = 'whitespace'
            elif char == '\\':
                state = 'escape'
            else:
                output.append(char)
        elif state == 'escape':
            if char not in escapes:
                raise DotenvSyntaxError(current_line)
            output.append(escapes[char])
            state = 'double'
        elif state == 'unquoted':
            if char == '#':
                state = 'comment'
            elif char in DOTENV_SPACE:
                state = 'whitespace'
            else:
                output.append(char)
        elif state == 'whitespace':
            if char == '#':
                state = 'comment'
            elif char not in DOTENV_SPACE:
                raise DotenvSyntaxError(current_line)
        if char == '\n':
            current_line += 1
    if state in ('single', 'double', 'escape'):
        raise DotenvSyntaxError(line)
    return ''.join(output)


def dotenv(text):
    """Parse supported phpdotenv syntax without expanding private variable references."""
    values = {}
    for line, entry in dotenv_entries(text):
        parts = entry.split('=', 1)
        key = parts[0].strip(DOTENV_TRIM) if len(parts) == 2 else parts[0]
        if key.startswith('export') and len(key) > 6 and key[6] in DOTENV_SPACE:
            key = key[6:].lstrip(DOTENV_SPACE)
        if len(key) >= 3 and key[0] in ("'", '"') and key[-1] == key[0]:
            key = key[1:-1]
        if not ASSIGNMENT.fullmatch(key):
            raise DotenvSyntaxError(line)
        values[key] = dotenv_value(parts[1].strip(DOTENV_TRIM), line) if len(parts) == 2 else None
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


def database_driver(values, stem):
    """Infer a shared default port only when every selected database connection agrees."""
    driver = values.get(stem + '_CONNECTION')
    selected = {driver} if driver != 'sqlite' else set()
    independent = {'QUEUE_CONNECTION', 'BROADCAST_CONNECTION'}
    non_database = {'sync', 'database', 'redis', 'sqs', 'beanstalkd', 'null', 'log', 'pusher',
                    'ably', 'reverb', 'mercure', 'deferred', 'background'}
    for name, value in values.items():
        if name == stem + '_CONNECTION' or name.startswith('REDIS_'):
            continue
        if not name.endswith(('_CONNECTION', '_DATABASE')) or name == stem + '_DATABASE':
            continue
        if name.endswith('_DATABASE') and name[:-9] + '_CONNECTION' in values:
            continue
        if name in independent and value in non_database:
            continue
        if value not in ('sqlite', '', 'null', '(null)'):
            selected.add(value)
    if not selected:
        return 'sqlite' if driver == 'sqlite' else None
    if len(selected) == 1 and next(iter(selected)) in ('mysql', 'mariadb', 'pgsql', 'sqlsrv', 'redis'):
        return next(iter(selected))
    return None


def listener_or_browser_key(values, key):
    """Documented bind settings and browser publisher settings are not Node service destinations."""
    if key[:-5] + '_CONNECTION' in values:
        return False
    return key in {'SERVER_HOST', 'REVERB_SERVER_HOST', 'PI_SERVER_HOST', 'VITE_PUSHER_HOST', 'VITE_REVERB_HOST'}


def http_endpoint(values, key):
    """Only publisher conventions or an explicit HTTP scheme establish proxy transport."""
    stem = key[:-5]
    if any(stem == family or stem.endswith('_' + family) for family in ('DB', 'REDIS', 'MAIL', 'MEMCACHED')):
        return False
    if values.get(stem + '_CONNECTION') in ('mysql', 'mariadb', 'pgsql', 'sqlsrv', 'redis', 'sqlite', 'smtp', 'memcached'):
        return False
    return stem in ('PUSHER', 'REVERB') or values.get(stem + '_SCHEME') in ('http', 'https')


def proxy_permits(host, policy):
    """Apply the existing proxy's supplied deny ranges/domains; never invent a second range list."""
    if not policy:
        return False
    try:
        address = ipaddress.ip_address(host)
        return address.version == 4 and not any(address in ipaddress.ip_network(cidr) for cidr in policy['private_v4'])
    except ValueError:
        return not any(host == domain or host.endswith('.' + domain) for domain in policy['private_domains'])


def connection(values, key):
    """Whether the key is used, its port, and why uncertain driver use is checked conservatively."""
    stem = key[:-5]
    driver_reason = ''
    default = None
    standard = next((family for family in ('DB', 'MAIL', 'REDIS', 'MEMCACHED')
                     if stem == family or stem.endswith('_' + family)), None)
    if standard == 'DB' or (standard is None and stem + '_CONNECTION' in values):
        driver = database_driver(values, stem)
        if driver == 'sqlite':
            return False, None, ''
        ports = {'mysql': 3306, 'mariadb': 3306, 'pgsql': 5432, 'sqlsrv': 1433, 'redis': 6379}
        default = ports.get(driver)
        if driver not in ports:
            driver_reason = 'driver use is unknown or selected connection ports are ambiguous, so an explicit port is required; '
    elif standard == 'MAIL':
        driver = values.get(stem + '_MAILER')
        if driver in ('log', 'array', 'sendmail'):
            return False, None, ''
        if stem + '_PORT' not in values:
            driver_reason = 'an explicit mail port is required because the app configuration may override its default; '
        if driver != 'smtp':
            driver_reason += 'mailer use is unknown, so it is checked conservatively; '
    elif standard == 'REDIS':
        default = 6379
        driver_reason = 'the .env cannot prove Redis is unused by broadcasting, Horizon, scaling or other app consumers; '
    elif standard == 'MEMCACHED':
        default = 11211
    elif stem not in ('PUSHER', 'REVERB') and stem + '_PORT' not in values:
        return False, None, ''
    port = port_number(values.get(stem + '_PORT', str(default) if default is not None else None))
    return True, port, driver_reason


def env_files(checkout):
    """Follow copied app directories, including symlinks, once per inode to avoid cycles."""
    if not os.path.isdir(checkout):
        raise OSError(0, 'Cannot inspect the checkout directory.', checkout)

    def refuse_unreadable(error):
        raise OSError(0, 'Cannot inspect every copied directory.', error.filename or checkout) from error

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


def inspect_environment(checkout, targets, node_addresses, proxy_policy=None):
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
    refusals = []
    try:
        paths = list(env_files(checkout))
    except OSError as error:
        relative = os.path.relpath(error.filename or checkout, checkout)
        return [f'{relative}: cannot inspect copied environments safely. {RECOVERY}']
    for path in paths:
        relative = os.path.relpath(path, checkout)
        try:
            with open(path, encoding='utf-8-sig') as handle:
                values = dotenv(handle.read())
        except DotenvSyntaxError as error:
            refusals.append(f'{relative}:{error.line}: malformed or unsupported dotenv syntax; no values are shown. {RECOVERY}')
            continue
        except (OSError, UnicodeError):
            refusals.append(f'{relative}: cannot read .env safely. {RECOVERY}')
            continue
        for key, value in values.items():
            if not key.endswith('_HOST') or listener_or_browser_key(values, key):
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
                except ValueError:
                    host_only = host == 'localhost'
                if host_only or host in local:
                    reason = 'the host belongs to the Node or container loopback and has no exact attached target'
                elif http_endpoint(values, key):
                    if not proxy_permits(host, proxy_policy):
                        reason = 'the HTTP endpoint is refused by the Instance proxy policy or that policy is unavailable'
                else:
                    reason = 'the direct service has no exact endpoint admitted by the container access plan'
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
        'proxy_policy': {'type': 'dict', 'required': True},
    }, supports_check_mode=True)
    try:
        refusals = inspect_environment(**module.params)
    except ValueError:
        module.fail_json(msg='Cannot inspect the copied .env service endpoints safely.')
    module.exit_json(changed=False, refusals=refusals)


if __name__ == '__main__':
    main()
