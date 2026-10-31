"""One placed Instance removal action: delete-account."""

import json
import os
import subprocess
import sys

from identity import Refusal, account, info, quarantine_path, require

try:
    value = json.loads(os.environ['ORBIT_REMOVAL_MEMBER'])
    member, capture, intent = value['member'], value['capture'], value['intent']
    require(info(capture['source']) is None)
    require(info(quarantine_path(member, capture, intent['removal_id'])) is None)
    owner = account(member, True)
    if owner is not None:
        require(owner.pw_uid == capture['uid'] and owner.pw_gid == capture['gid'])
        for entry in os.scandir('/proc'):
            if entry.name.isdecimal():
                try:
                    require(entry.stat().st_uid != owner.pw_uid, 'instance.processes_still_running')
                except FileNotFoundError:
                    pass
        subprocess.run(['/usr/sbin/userdel', '--', member['user']], check=True,
                       stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    print(json.dumps({'instance_id': member['instance_id'], 'account_absent': True}))
except (Refusal, OSError, KeyError, ValueError, subprocess.CalledProcessError) as error:
    print(json.dumps({'error_code': getattr(error, 'code', 'instance.source_finalization_failed')}))
    sys.exit(1)
