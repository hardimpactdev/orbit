"""An opted-out Project is omitted and its real native selection is recorded, without new work."""
import json
import os
import sqlite3

assert json.load(open(os.environ['PROOF_OPERATION_FILE'])) is None
environment = dict(line.split('=', 1) for line in open('apps/gateway/.env').read().splitlines() if '=' in line)
connection = sqlite3.connect(environment['DB_DATABASE'].strip('"'))
state = json.loads(connection.execute("select value from settings where key = 'swamp.project-maintenance'").fetchone()[0])
assert state['terminal']['run_id'] == os.environ['PROOF_NATIVE_RUN'], state['terminal']
assert state['terminal']['status'] == 'recorded', state['terminal']
assert not state['admissions'], state['admissions']
assert connection.execute("select maintenance from projects where slug = 'maintenance-opt-out-proof'").fetchone()[0] == 0
assert connection.execute("select count(*) from operations where kind in ('project.detect','project.routine','project.observe')").fetchone()[0] == 0
print('The native selection omitted the opt-out Project and the shared subscriber recorded its exact run. No Project operation or GitHub request was needed.')
