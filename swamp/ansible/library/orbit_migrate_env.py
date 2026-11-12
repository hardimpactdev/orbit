#!/usr/bin/python
"""Read copied dotenv files without executing them or returning their private values.

Core Ansible modules do not parse dotenv. A custom read-only module keeps private
file contents on the Node while parsing quoted values, selecting named files
and classifying IP endpoints with Python's standard library. A slurp/template
implementation would transport complete private environments to the worker.
"""
import errno
import glob
import ipaddress
import json
import os
import re
import shlex
import socket
import subprocess
import sys
import time

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
  environment_files:
    description: Explicit application and Schedule environment paths; no checkout traversal.
    type: list
    elements: path
    required: true
  unit_files:
    description: Admitted source Process units whose EnvironmentFile directives name additional files.
    type: list
    elements: path
    default: []
  targets:
    description: Exact attached target endpoints recorded for the Instance.
    type: list
    elements: dict
    required: true
  development_mail:
    description: The same admitted endpoint used by the container development mail phase, or null when ineligible.
    type: dict
  node_services:
    description: Admitted Node service key pairs and ports with IPv4 loopback proxies.
    type: list
    elements: dict
    default: []
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
DATABASE_PORTS = {'mysql': 3306, 'mariadb': 3306, 'pgsql': 5432, 'sqlsrv': 1433, 'redis': 6379}


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
        name = value.removesuffix('.')
        if HOSTNAME.fullmatch(name):
            return name.lower(), value
    return None, '[invalid host]'


def port_number(value):
    if value is not None and re.fullmatch(r'[0-9]{1,5}', value):
        port = int(value)
        if 1 <= port <= 65535:
            return port
    return None


def database_drivers(values, stem):
    """Only env-selected network drivers contribute to the endpoint check."""
    driver = values.get(stem + '_CONNECTION')
    selected = {driver} if driver in DATABASE_PORTS else set()
    if stem == 'DB':
        cache = values.get('CACHE_STORE', values.get('CACHE_DRIVER'))
        consumers = ((values.get('SESSION_DRIVER'), ['SESSION_CONNECTION']),
                     (values.get('QUEUE_CONNECTION'), ['DB_QUEUE_CONNECTION']),
                     (cache, ['DB_CACHE_CONNECTION', 'DB_CACHE_LOCK_CONNECTION']))
        for consumer, keys in consumers:
            if consumer == 'database':
                selected.update(connection for key in keys
                                if (connection := values.get(key) or driver) in DATABASE_PORTS)
    return selected


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
    """Env selectors establish use; declarations and unknown app config do not."""
    stem = key[:-5]
    standard = next((family for family in ('DB', 'MAIL', 'REDIS', 'MEMCACHED')
                     if stem == family or stem.endswith('_' + family)), None)
    prefix = stem[:-len(standard)] if standard else ''
    cache = values.get(prefix + 'CACHE_STORE', values.get(prefix + 'CACHE_DRIVER'))
    broadcast = values.get(prefix + 'BROADCAST_CONNECTION', values.get(prefix + 'BROADCAST_DRIVER'))
    selectors = (cache, values.get(prefix + 'SESSION_DRIVER'), values.get(prefix + 'QUEUE_CONNECTION'), broadcast,
                 values.get(prefix + 'DB_CONNECTION'))
    default, reason = None, ''
    if standard == 'DB' or (standard is None and stem + '_CONNECTION' in values):
        drivers = database_drivers(values, stem)
        if not drivers:
            return False, None, ''
        default = DATABASE_PORTS[next(iter(drivers))] if len(drivers) == 1 else None
        if default is None:
            reason = 'selected connection ports are ambiguous, so an explicit port is required; '
    elif standard == 'MAIL':
        if values.get(stem + '_MAILER', values.get(stem + '_DRIVER')) != 'smtp':
            return False, None, ''
        if stem + '_PORT' not in values:
            reason = 'an explicit mail port is required because the app configuration may override its default; '
    elif standard in ('REDIS', 'MEMCACHED'):
        selected = standard.lower() in selectors
        if not selected:
            return False, None, ''
        default = 6379 if standard == 'REDIS' else 11211
    elif stem not in ('PUSHER', 'REVERB') or broadcast != stem.lower():
        return False, None, ''
    port = port_number(values.get(stem + '_PORT', str(default) if default is not None else None))
    return True, port, reason


def env_files(checkout, environment_files=None, unit_files=()):
    """Only the application's files and files explicitly named by admitted source units."""
    if not os.path.isdir(checkout):
        raise OSError(0, 'Cannot inspect the checkout directory.', checkout)
    paths = list(environment_files if environment_files is not None else [os.path.join(checkout, '.env')])
    for unit in unit_files:
        try:
            with open(unit, encoding='utf-8') as handle:
                lines = handle.read().splitlines()
        except FileNotFoundError:
            # The Process preflight owns a missing unit; it names no environment file here.
            continue
        files = []
        directory = ''
        for line in lines:
            key, separator, value = line.strip().partition('=')
            if separator and key in ('EnvironmentFile', 'WorkingDirectory'):
                if not value.strip():
                    if key == 'EnvironmentFile':
                        files = []
                    else:
                        directory = ''
                    continue
                try:
                    # Keep systemd's backslash escapes until token boundaries are known.
                    words = shlex.shlex(value, posix=True)
                    words.whitespace_split = True
                    words.commenters = ''
                    words.escape = ''
                    named = [re.sub(r'\\x([0-9a-fA-F]{2})', lambda match: chr(int(match[1], 16)),
                                    path.removeprefix('-')).replace('%%', '%') for path in words]
                    if key == 'EnvironmentFile':
                        files.extend(named)
                    else:
                        directory = named[0] if named else ''
                except ValueError as error:
                    raise OSError(0, 'Cannot read the named environment paths.', unit) from error
        if os.path.isabs(directory) and os.path.commonpath(
                [os.path.realpath(checkout), os.path.realpath(directory)]) == os.path.realpath(checkout):
            paths.append(os.path.join(directory, '.env'))
        for path in files:
            paths.extend(glob.glob(path) if glob.has_magic(path) else [path])
    for path in dict.fromkeys(paths):
        path = path if os.path.isabs(path) else os.path.join(checkout, path)
        # Application .env and optional EnvironmentFile may legitimately be absent.
        # A broken symlink still exists as a directory entry and must fail the read.
        if os.path.lexists(path):
            yield path



class ProbeError(RuntimeError):
    """A closed diagnostic, never an OS error containing private input."""


PRIVATE_NETWORKS = tuple(ipaddress.ip_network(value) for value in (
    '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16', 'fc00::/7', 'fe80::/10'))
PROBE_SECONDS = 2
PROBE_LIMIT = 20


def private_address(address):
    address = getattr(address, 'ipv4_mapped', None) or address
    return address.is_loopback or any(address.version == network.version and address in network for network in PRIVATE_NETWORKS)


class HostProbes:
    """One bounded, cached host observation per endpoint in this preflight."""

    def __init__(self):
        self.cache = {}

    def answer(self, host, port):
        if host != host.strip():
            return None
        try:
            literal = ipaddress.ip_address(host)
        except ValueError:
            literal = None
            if not HOSTNAME.fullmatch(host.removesuffix('.')):
                return None
        if literal is not None and not private_address(literal):
            return None
        key = (host, port)
        if key in self.cache:
            return self.cache[key]
        if len(self.cache) >= PROBE_LIMIT:
            if literal is None:
                return None  # No more resolution: retain the existing hostname policy.
            raise ProbeError('the host probe limit of 20 unique endpoints was reached')
        deadline = time.monotonic() + PROBE_SECONDS
        self.cache[key] = None
        addresses = [literal] if literal is not None else self.resolve(host)
        if not addresses or any(not private_address(address) for address in addresses):
            return None
        if len(addresses) > 8:
            raise ProbeError('the host has too many addresses to probe safely')
        for index, address in enumerate(addresses):
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise ProbeError('the host probe could not inspect every address within its bound')
            try:
                client = socket.socket(socket.AF_INET6 if address.version == 6 else socket.AF_INET, socket.SOCK_STREAM)
            except OSError as error:
                raise ProbeError('the host probe could not create a TCP socket') from error
            try:
                with client:
                    client.settimeout(min(1, remaining / (len(addresses) - index)))
                    client.connect((str(address), port))
                self.cache[key] = True
                return True
            except TimeoutError:
                continue
            except OSError as error:
                if error.errno in {errno.ECONNREFUSED, errno.ETIMEDOUT, errno.EHOSTUNREACH,
                                   errno.ENETUNREACH, errno.EHOSTDOWN, errno.ENETDOWN}:
                    continue
                raise ProbeError('the host TCP probe failed with socket error ' + str(error.errno)) from error
        self.cache[key] = False
        return False

    @staticmethod
    def resolve(host):
        # getaddrinfo has no timeout API. Isolate only name resolution in a bounded
        # child; no TCP connection is made until every answer is proved private.
        source = """import json,socket,sys
try:
 print(json.dumps(list(dict.fromkeys(row[4][0] for row in socket.getaddrinfo(sys.argv[1], None, type=socket.SOCK_STREAM)))))
except socket.gaierror as error:
 if error.errno not in (socket.EAI_NONAME, socket.EAI_AGAIN, getattr(socket,'EAI_NODATA',socket.EAI_NONAME)):raise
 print('[]')
"""
        try:
            reply = subprocess.run([sys.executable, '-c', source, host], capture_output=True,
                                   text=True, timeout=0.5, check=False)
            if reply.returncode:
                raise ProbeError('the host name could not be resolved safely')
            return [ipaddress.ip_address(value) for value in json.loads(reply.stdout)]
        except (OSError, TypeError, ValueError, subprocess.SubprocessError) as error:
            raise ProbeError('the host name could not be resolved within the probe bound') from error


def inspect_environment(checkout, targets, node_addresses, proxy_policy=None, development_mail=None, node_services=None, environment_files=None, unit_files=(), warnings=None):
    """Safe refusal lines, with no environment contents except validated host and numeric port."""
    warnings = [] if warnings is None else warnings
    probes = HostProbes()
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
        paths = list(env_files(checkout, environment_files, unit_files))
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
        # URL configuration overrides HOST/PORT in Laravel. K1a admits key pairs, not URL selectors.
        # Keep URL contents and credentials private; the first cut refuses non-SQLite selectors explicitly.
        for key, value in values.items():
            if (key in ('DB_URL', 'DATABASE_URL') or (key.endswith('_DB_URL') and database_drivers(values, key[:-4]))) and value and not value.startswith('sqlite:'):
                refusals.append(f'{relative}: {key}: a database URL can override the attached host/port keys; '
                                'use the exact attached HOST/PORT keys without a URL override. No URL value is shown.')
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
            elif (value in ('127.0.0.1', 'localhost') and any(
                    key == target['host_env_key'] and port == target['port']
                    and values.get(target['port_env_key']) == str(target['port'])
                    for target in (node_services or []))) or (host, port) in attached:
                continue
            elif (key == 'MAIL_HOST' and value in ('127.0.0.1', 'localhost', '::1')
                  and values.get('MAIL_PORT') in ('1025', '2525')):
                if development_mail is None:
                    reason = ('this Instance has no eligible main Mailpit on its Node, so the container will have no '
                              f'mail forwarder on {display}:{port}')
                elif host == '::1':
                    reason = 'the development mail forwarder listens only on 127.0.0.1, so ::1 cannot reach it'
                else:
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
                if host is not None and port is not None:
                    try:
                        if probes.answer(value, port) is False:
                            warnings.append({'code': 'instance.migrate_env_service_unused',
                                             'message': f'{relative}: {key}={endpoint}:{port}: no TCP service answered on the Node.'})
                            continue
                    except ProbeError as error:
                        reason = str(error)
                refusals.append(f'{relative}: {key}={endpoint}:{port if port is not None else "[unknown port]"}: '
                                f'{driver_reason}{reason}. {RECOVERY}')
    return refusals


def main():
    from ansible.module_utils.basic import AnsibleModule

    module = AnsibleModule(argument_spec={
        'checkout': {'type': 'path', 'required': True},
        'environment_files': {'type': 'list', 'elements': 'path', 'required': True},
        'unit_files': {'type': 'list', 'elements': 'path', 'default': []},
        'targets': {'type': 'list', 'elements': 'dict', 'required': True},
        'node_addresses': {'type': 'list', 'elements': 'str', 'required': True},
        'proxy_policy': {'type': 'dict', 'required': True},
        'development_mail': {'type': 'dict'},
        'node_services': {'type': 'list', 'elements': 'dict', 'default': []},
    }, supports_check_mode=True)
    try:
        warnings = []
        refusals = inspect_environment(**module.params, warnings=warnings)
    except ValueError:
        module.fail_json(msg='Cannot inspect the copied .env service endpoints safely.')
    module.exit_json(changed=False, refusals=refusals, environment_warnings=warnings)


if __name__ == '__main__':
    main()
