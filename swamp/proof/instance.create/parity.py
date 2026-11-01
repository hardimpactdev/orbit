"""Check public create receipts after the full F13 CLI request in app-dev."""
import json
import os
import re
from pathlib import Path

outputs = json.loads(Path(os.environ['PROOF_OUTPUTS']).read_text())
result = json.loads(outputs['create']['finish-result']['stdout'])
assert result['ready'] is True
assert type(result['instance_id']) is int and result['instance_id'] > 0
assert re.fullmatch(r'proof-[a-zA-Z0-9-]+', result['source']['branch'])
assert re.fullmatch(r'[a-f0-9]{40}', result['source']['commit'])
assert result['source']['php_version'] is None
assert result['source']['laravel'] is False
assert result['vp_environment'] is None
assert {'APP_NAME', 'APP_ENV', 'APP_DEBUG'} <= set(result['environment_keys'])
setup = json.loads(outputs['create']['finish-setup']['stdout'])
assert setup['ready'] is True and setup['exitCode'] == 0
assert setup['completed'] == ['verify-public-source']
assert 'error_code' not in result
print(json.dumps({'passed': 4, 'scope': 'source-environment-setup; Route/DB excluded'}))
