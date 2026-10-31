"""One placed Instance removal action: verify-source."""

import json
import os
import sys

from identity import Refusal, verify_source

try:
    value = json.loads(os.environ['ORBIT_REMOVAL_MEMBER'])
    result = verify_source(value['member'], value['capture'], value['intent'], allow_quarantine=True)
    print(json.dumps(result))
except (Refusal, OSError, KeyError, ValueError) as error:
    print(json.dumps({'error_code': getattr(error, 'code', 'instance.unsafe_content')}))
    sys.exit(1)
