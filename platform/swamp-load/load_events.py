"""Retain a load event when the loader confirms served/disk version state."""
import datetime
import json
import re

SHA = re.compile(r'^[a-f0-9]{40}$')


def confirmed_event(proof):
    mode = proof.get('load_mode')
    if mode not in {'hot_reload', 'restart'}:
        return None
    stamp = proof['version_checked_at']
    at = datetime.datetime.fromisoformat(stamp.replace('Z', '+00:00'))
    assert at.tzinfo is not None, 'load_event_timestamp_requires_zone'
    at = at.astimezone(datetime.timezone.utc)
    sha = proof['loaded_sha']
    assert SHA.fullmatch(sha), 'load_event_requires_full_sha'
    audit = proof['behavior_versions']
    assert isinstance(audit['checked'], int) and isinstance(audit['matched'], int), 'version_audit_missing'
    assert 0 <= audit['matched'] <= audit['checked'], 'version_audit_invalid'
    assert isinstance(audit['mismatches'], list) and isinstance(audit['errors'], list), 'version_audit_missing'
    kind = 'reload' if mode == 'hot_reload' else 'restart'
    event = {'loaded_at': at.isoformat(timespec='microseconds').replace('+00:00', 'Z'),
            'served_sha': sha, 'mode': kind,
            'served_eq_disk': audit['checked'] == audit['matched'] and not audit['mismatches'] and not audit['errors'],
            'event_file': at.strftime('%Y%m%dT%H%M%S%fZ') + '-' + kind + '-' + sha + '.json'}
    if 'swamp_tree_sha' in proof:
        tree = proof['swamp_tree_sha']
        assert isinstance(tree, str) and SHA.fullmatch(tree), 'load_event_requires_full_swamp_tree'
        event['swamp_tree_sha'] = tree
    return event


def retain(L, proof):
    event = confirmed_event(proof)
    if event is None:
        return None
    directory = L.STATE / 'load-events'
    directory.mkdir(mode=0o700, exist_ok=True)
    path = directory / event['event_file']
    if path.exists():
        assert json.loads(path.read_text()) == event, 'load_event_changed'
    else:
        L.atomic(path, event)
    return event
