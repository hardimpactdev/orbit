"""One placed Instance removal action: delete-worktree-metadata."""

import json
import os
import shutil
import sys

from identity import (
    Refusal,
    directory,
    identity,
    info,
    quarantine_path,
    read_regular,
    require,
)

try:
    value = json.loads(os.environ['ORBIT_REMOVAL_MEMBER'])
    member, capture, intent = value['member'], value['capture'], value['intent']
    if member['source_layout'] == 'worktree' and capture['git_admin'] is not None:
        require(info(capture['source']) is None)
        quarantine = quarantine_path(member, capture, intent['removal_id'])
        require(info(quarantine) is None)
        common, admin = capture['git_common'], capture['git_admin']
        directory(common, capture['uid'], capture['gid'])
        require(identity(common) == capture['git_common_identity'])
        require(os.path.dirname(admin) == common + '/worktrees')
        if info(admin) is not None:
            directory(admin, capture['uid'], capture['gid'])
            require(identity(admin) == capture['git_admin_identity'])
            require(read_regular(admin + '/gitdir') == (capture['source'] + '/.git\n').encode())
            shutil.rmtree(admin)
    print(json.dumps({'instance_id': member['instance_id'], 'metadata_absent': True}))
except (Refusal, OSError, KeyError, ValueError) as error:
    print(json.dumps({'error_code': getattr(error, 'code', 'instance.source_finalization_failed')}))
    sys.exit(1)
