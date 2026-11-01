"""F13 real CLI requests and deterministic input comparison; no admission endpoint guessing."""
import json
import re
from datetime import UTC, datetime

from swamp_proof import ProofError, bind, input_mismatches


def json_path(value, path):
    if not isinstance(path, str) or not re.fullmatch(r'\$(?:\.[a-zA-Z_][a-zA-Z0-9_]*|\[[0-9]+\])*', path):
        raise ProofError('Capture paths require an exact JSON object/array path starting at $.')
    try:
        for field, index in re.findall(r'\.([a-zA-Z_][a-zA-Z0-9_]*)|\[([0-9]+)\]', path):
            value = value[field] if field else value[int(index)]
    except (KeyError, IndexError, TypeError):
        raise ProofError('Real CLI output has no value at ' + path) from None
    return value


def cli_argv(template, context, now=None):
    if not isinstance(template, list) or len(template) < 2 or template[0] != 'orbit' or '--json' not in template:
        raise ProofError('F13 requests use the real orbit CLI with --json.')
    argv = bind(template, context, now or datetime.now(UTC))
    if any(type(item) not in (str, int) or item == '' for item in argv):
        raise ProofError('A request binding must produce one public CLI argument.')
    return [str(item) for item in argv]


def prepare(fixture, context, execute):
    """execute runs one real CLI argv on the topology operator and returns its JSON."""
    for request in fixture.get('prepare', []):
        result = execute(cli_argv(request['argv'], context))
        for name, path in request.get('capture', {}).items():
            if not re.fullmatch(r'[a-zA-Z_][a-zA-Z0-9_.]*', name) or 'capture.' + name in context:
                raise ProofError('Capture names must be unique public identifiers.')
            context['capture.' + name] = json_path(result, path)
    return context


def compare_input(operation, fixture, context, now=None):
    expected = bind(fixture['envelope'], context, now or datetime.now(UTC), allow_matchers=True)
    actual = operation['input']
    return {'actual_input': actual, 'input_mismatches': input_mismatches(actual, expected)}


def describe_differences(result):
    return '\n'.join(item['path'] + ': ' + json.dumps(item, sort_keys=True) for item in result['input_mismatches'])


def validate_commands(fixture):
    """Validate shapes without resolving captures that only exist after prepare."""
    request = fixture['request']
    if not isinstance(request, dict) or set(request) not in ({'argv', 'operation'}, {'artisan', 'select'}):
        raise ProofError('request requires argv and its exact Operation JSON path.')
    # Syntax validation does not require a value at the path yet.
    if 'operation' in request and (not isinstance(request['operation'], str) or not re.fullmatch(r'\$(?:\.[a-zA-Z_][a-zA-Z0-9_]*|\[[0-9]+\])*', request['operation'])):
        raise ProofError('Invalid request Operation JSON path.')
    for name in ('prepare', 'before_request'):
        if not isinstance(fixture.get(name, []), list):
            raise ProofError(name + ' must be an ordered list of command objects.')
    if 'artisan' in request:
        if not isinstance(request['artisan'], list) or not request['artisan'] or any(not isinstance(arg, str) or not arg for arg in request['artisan']):
            raise ProofError('Artisan request requires public argv strings.')
        select = request['select']
        if not isinstance(select, dict) or not {'kind', 'count'} <= set(select) or set(select) - {'kind', 'target_type', 'target_id', 'count'} or select['kind'] != fixture['kind'] or type(select['count']) is not int or select['count'] != 1:
            raise ProofError('An Artisan proof must select exactly one Operation of its kind and target.')
    for item in [*fixture.get('prepare', []), *([request] if 'argv' in request else [])]:
        if not isinstance(item, dict) or set(item) - {'argv', 'capture', 'operation'} or not isinstance(item.get('argv'), list) or len(item['argv']) < 2 or item['argv'][0] != 'orbit' or '--json' not in item['argv']:
            raise ProofError('prepare/request commands require the real orbit CLI and --json.')
        if any(not isinstance(arg, str) or not arg for arg in item['argv']):
            raise ProofError('CLI templates require nonempty public argv strings.')
        if not isinstance(item.get('capture', {}), dict):
            raise ProofError('prepare capture must map names to JSON paths.')
    for item in fixture.get('before_request', []):
        if not isinstance(item, dict) or set(item) not in ({'argv'}, {'argv', 'timeout_seconds'}) or not isinstance(item['argv'], list) or not item['argv'] or any(not isinstance(arg, str) or not arg for arg in item['argv']):
            raise ProofError('before_request uses host command objects {argv:[...]}.')

        timeout = item.get('timeout_seconds', 60)
        if type(timeout) is not int or not 1 <= timeout <= 300:
            raise ProofError('before_request timeout_seconds must be 1..300.')
