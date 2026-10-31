"""Public intent and bounded public results for native certificate commands."""

import json
import os
import sys


def intent():
    value = json.loads(os.environ["ORBIT_CERTIFICATE_INPUT"])
    if not isinstance(value, dict):
        raise ValueError("certificate.intent_invalid")
    return value


def emit(action):
    try:
        value = action()
    except Exception:
        print(json.dumps({"error_code": "certificate.leaf_failed"}))
        sys.exit(1)
    print(json.dumps(value))
