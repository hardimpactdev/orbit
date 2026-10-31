"""One placed Instance removal action: inspect-sources."""

import json
import os
import sys

from identity import Refusal, capture_member, info, require, verify_source

try:
    intent = json.loads(os.environ['ORBIT_REMOVAL_INTENT'])
    prior = intent.get('captured_sources')
    captures = prior if prior is not None else {
        'members': [capture_member(member, intent) for member in intent['members']]
    }
    if prior is not None:
        members = {member['instance_id']: member for member in intent['members']}
        expanded = []
        for row in captures['members']:
            if 'source' not in row:
                # Add filesystem metadata to the original capture, never replace its facts.
                current = capture_member(members[row['instance_id']], intent)
                for field in ['user', 'group', 'source_root', 'source_identity', 'source_commit',
                              'common_repository_path', 'linked_worktree_paths', 'origin', 'worktree_inventory']:
                    require(current[field] == row[field])
                row = {**current, **row}
            expanded.append(row)
        captures = {**captures, 'members': expanded}
    require(len(captures['members']) == len(intent['members']))
    rows = {row['instance_id']: row for row in captures['members']}
    require(len(rows) == len(intent['members']))
    intent = {**intent, 'captured_sources': captures}
    dispositions = []
    for member in intent['members']:
        capture = rows[member['instance_id']]
        verify_source(member, capture, intent, allow_quarantine=True)
        dispositions.append({'instance_id': member['instance_id'],
                             'teardown_required': capture['release'] is not None and info(capture['source']) is not None})
    print(json.dumps({'source_inspection': captures, 'source_dispositions': dispositions}, separators=(',', ':')))
except (Refusal, OSError, KeyError, ValueError) as error:
    print(json.dumps({'error_code': getattr(error, 'code', 'instance.source_identity_invalid')}))
    sys.exit(1)
