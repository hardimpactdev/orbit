"""One placed Instance removal action: delete-tree."""

import json
import os
import shutil
import sys

from identity import Refusal, directory, identity, info, quarantine_path, require

try:
    value = json.loads(os.environ['ORBIT_REMOVAL_MEMBER'])
    member, capture, intent = value['member'], value['capture'], value['intent']
    require(info(capture['source']) is None)
    target = quarantine_path(member, capture, intent['removal_id'])
    if info(target) is not None:
        require(identity(target) == capture['source_identity'])
        directory(target, capture['uid'], capture['gid'])
        shutil.rmtree(target)
    print(json.dumps({'instance_id': member['instance_id'], 'source_absent': True}))
except (Refusal, OSError, KeyError, ValueError) as error:
    print(json.dumps({'error_code': getattr(error, 'code', 'instance.source_finalization_failed')}))
    sys.exit(1)
