"""One placed Instance removal action: quarantine."""

import json
import os
import sys

from identity import (
    Refusal,
    identity,
    info,
    quarantine_path,
    require,
    verify_source,
)

try:
    value = json.loads(os.environ['ORBIT_REMOVAL_MEMBER'])
    member, capture, intent = value['member'], value['capture'], value['intent']
    verify_source(member, capture, intent, allow_quarantine=True)
    source = capture['source']
    target = quarantine_path(member, capture, intent['removal_id'])
    if info(source) is not None:
        os.rename(source, target)
    if info(target) is not None:
        require(identity(target) == capture['source_identity'])
    print(json.dumps({'instance_id': member['instance_id'], 'quarantined': info(target) is not None}))
except (Refusal, OSError, KeyError, ValueError) as error:
    print(json.dumps({'error_code': getattr(error, 'code', 'instance.source_finalization_failed')}))
    sys.exit(1)
