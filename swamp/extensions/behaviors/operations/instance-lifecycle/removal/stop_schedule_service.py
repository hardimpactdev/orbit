"""One placed Instance removal action: stop-schedule-service."""

import json
import os
import re
import subprocess
import sys

from identity import owned_unit

schedule = os.environ['ORBIT_SCHEDULE_ID']
if re.fullmatch(r'[a-f0-9-]{36}', schedule) is None:
    sys.exit(1)
unit = 'orbit-schedule-' + schedule + '.service'
present = owned_unit(unit, 'X-Orbit-Schedule-ID=' + schedule)
exists = subprocess.run(['systemctl', 'show', '--property=LoadState', '--value', unit],
                        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)
if exists.returncode != 0:
    sys.exit(1)
if not present and exists.stdout.strip() != b'not-found':
    sys.exit(1)
if exists.stdout.strip() != b'not-found':
    subprocess.run(['systemctl', 'stop', '--', unit], check=True,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
print(json.dumps({'schedule_id': schedule, 'service_stopped': True}))
