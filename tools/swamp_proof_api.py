"""F13 private request bodies: generated on the proof host, never command arguments."""
import json
import re
import secrets
from pathlib import Path

from swamp_proof import ProofError, bind

NAME = re.compile(r'[a-z][a-z0-9_]*\Z')
SECRET = re.compile(r'\$\{secret\.([a-z][a-z0-9_]*)\}')


def validate_private_request(fixture):
    entries = fixture.get('secrets', [])
    if not isinstance(entries, list):
        raise ProofError('secrets must list generated named values.')
    names = set()
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {'name', 'value'} or not isinstance(entry['name'], str) or not NAME.fullmatch(entry['name']) or entry['value'] != 'generate:hex32' or entry['name'] in names:
            raise ProofError('Each secret needs a unique name and generate:hex32 value.')
        names.add(entry['name'])
    request = fixture['request']
    api = request.get('api')
    if api is not None:
        if not isinstance(api, dict) or set(api) != {'method', 'path', 'body'} or api['method'] not in ('POST', 'PUT', 'PATCH', 'DELETE') or not isinstance(api['body'], dict):
            raise ProofError('request.api requires a write method, relative API path and JSON body object.')
        if not isinstance(api['path'], str) or not api['path'].startswith('/api/') or any(char in api['path'] for char in '\r\n?#'):
            raise ProofError('request.api.path must stay under the operator Gateway /api/ origin.')
    elif entries:
        raise ProofError('Generated secrets require request.api.')
    public = {key: value for key, value in fixture.items() if key != 'request'}
    public['request'] = {key: value for key, value in request.items() if key != 'api'}
    if api is not None:
        public['api'] = {key: value for key, value in api.items() if key != 'body'}
    if '${secret.' in json.dumps(public):
        raise ProofError('Secret bindings are allowed only in request.api.body.')
    body = json.dumps(api['body']) if api is not None else ''
    used = set(SECRET.findall(body))
    if used != names or '${secret.' in SECRET.sub('', body):
        raise ProofError('API body must bind exactly its declared generated secrets.')
    # JSON object keys are public schema, never secret substitutions.
    def keys(value):
        if isinstance(value, dict):
            return any('${secret.' in key or keys(item) for key, item in value.items())
        return isinstance(value, list) and any(keys(item) for item in value)
    if api is not None and keys(api['body']):
        raise ProofError('Secret bindings cannot appear in JSON field names.')


def generate(fixture):
    return {entry['name']: secrets.token_hex(32) for entry in fixture.get('secrets', [])}


def private_api(request, context, now, values):
    # Leave secrets out of the public binding context and all normal fixture substitutions.
    def materialize(value):
        if isinstance(value, dict):
            return {key: materialize(item) for key, item in value.items()}
        if isinstance(value, list):
            return [materialize(item) for item in value]
        if isinstance(value, str):
            public = SECRET.sub(lambda match: '[REDACTED:' + match[1] + ']', value)
            result = bind(public, context, now)
            if isinstance(result, str):
                for name, secret in values.items():
                    result = result.replace('[REDACTED:' + name + ']', secret)
            return result
        return value
    # Bind the object, not its serialized JSON, so whole-value capture types are preserved.
    safe = json.loads(SECRET.sub(lambda match: '[REDACTED:' + match[1] + ']', json.dumps(request)))
    safe = bind(safe, context, now)
    return materialize(request['body']), safe


def redact(text, values):
    for name, value in values.items():
        text = text.replace(value, '[REDACTED:' + name + ']')
    return text


def secret_file(folder, values):
    path = Path(folder) / 'secrets.json'
    # The caller's temporary directory is private as well.
    with path.open('x') as stream:
        path.chmod(0o600)
        json.dump(values, stream)
    return path
