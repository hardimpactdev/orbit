"""Fixed public bootstrap input and bounded error output; no Gateway client."""
import json
import os
import re
import subprocess
import sys


class Refused(Exception):
    """A public machine-readable refusal."""


def intent():
    value = json.loads(os.environ['ORBIT_BOOTSTRAP_INTENT'])
    target = value['target']
    if (value['node_id'] != target['node_id']
            or not isinstance(target['host'], str)
            or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.:-]{0,252}', target['host'])
            or not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', target['user'])
            or not isinstance(target['port'], int) or isinstance(target['port'], bool)
            or not 1 <= target['port'] <= 65535
            or not re.fullmatch(r'SHA256:[A-Za-z0-9+/]{43}', target['fingerprint'])
            or value['platform'] not in ['linux', 'macos']):
        raise Refused('node.bootstrap_intent_invalid')
    return value


def main(action):
    try:
        print(json.dumps(action(), separators=(',', ':')))
    except Refused as error:
        print(json.dumps({'error_code': str(error)}))
        sys.exit(1)
    except (KeyError, TypeError, ValueError, OSError, subprocess.SubprocessError):
        print(json.dumps({'error_code': 'node.bootstrap_channel_uncertain'}))
        sys.exit(1)
