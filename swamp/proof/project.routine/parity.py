"""Legacy project:maintenance:routine contract (F9): the run selected one routine group request for the
admitted Project and this week, and the Gateway admitted exactly that group from the run's result."""
import json
import os
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
result = operation['result']


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


project = operation['input']['project']['id']  # Launch, id 32: the Project with a source-owned maintenance configuration
check(result['project_id'] == project, 'the result names the admitted Project')
check(result['key'].startswith(f'{project}-routine-'), 'the routine key is this Project and week')
check(len(result['requests']) == 1 and result['requests'][0]['maintenance']['mode'] == 'routine', 'one routine group request')
check(result['blocked'] == [], 'nothing blocked the routine')
check(len(result['admitted_group_ids']) == 1, 'the Gateway admitted the one group from the result')
print('parity: legacy project:maintenance:routine contract holds')
