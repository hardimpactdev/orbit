"""A real empty policy fire reaches its shared scheduled projection without an Operation."""
import json
import os
import sqlite3

assert json.load(open(os.environ['PROOF_OPERATION_FILE'])) is None
environment = dict(line.split('=', 1) for line in open('apps/gateway/.env').read().splitlines() if '=' in line and not line.startswith('#'))
connection = sqlite3.connect(environment['DB_DATABASE'].strip('\"'))
state = json.loads(connection.execute("select value from settings where key = 'automatic-deploy.fires'").fetchone()[0])
assert state['runs'] == [os.environ['PROOF_NATIVE_RUN']], state
assert connection.execute("select count(*) from operations where kind = 'instance.deploy'").fetchone()[0] == 0
print('One real empty policy fire was recorded through the shared subscriber. No deployment Operation was created; no repository, production Node or model provider was used.')
