"""One placed Instance removal action: delete-marker."""

import json
import os
import shutil
import sys

from identity import Refusal, account, directory, identity, info, require

try:
    value = json.loads(os.environ['ORBIT_REMOVAL_MEMBER'])
    member, capture = value['member'], value['capture']
    require(account(member, True) is None and info(capture['source']) is None)
    marker = capture['marker']
    if marker['present'] and info(marker['path']) is not None:
        directory(marker['path'], 0, 0)
        require(identity(marker['path']) == marker['identity'])
        shutil.rmtree(marker['path'])
    print(json.dumps({'instance_id': member['instance_id'], 'marker_absent': True}))
except (Refusal, OSError, KeyError, ValueError) as error:
    print(json.dumps({'error_code': getattr(error, 'code', 'instance.source_finalization_failed')}))
    sys.exit(1)
