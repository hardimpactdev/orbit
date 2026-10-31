"""Public intent and bounded public results for native certificate commands."""

import json
import os
import sys

PUBLIC_ERRORS = frozenset(
    {
        "certificate.account_invalid",
        "certificate.home_mismatch",
        "certificate.home_unsafe",
        "certificate.leaf_failed",
        "certificate.presence_mismatch",
        "certificate.signature_invalid",
        "certificate.intent_invalid",
        "app-dev.certificate_in_use",
    }
)


def intent():
    value = json.loads(os.environ["ORBIT_CERTIFICATE_INPUT"])
    if not isinstance(value, dict):
        raise ValueError("certificate.intent_invalid")
    return value


def emit(action):
    try:
        value = action()
    except Exception as error:
        code = str(error)
        print(
            json.dumps(
                {
                    "error_code": code
                    if code in PUBLIC_ERRORS
                    else "certificate.leaf_failed"
                }
            )
        )
        sys.exit(1)
    print(json.dumps(value))
