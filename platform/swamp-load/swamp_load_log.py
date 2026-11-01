"""Append confirmed host events to the requested Beast TSV; never invent times."""
import datetime
import fcntl
import json
import os
import pathlib
import re

PATH = pathlib.Path('/home/nckrtl/swamp-execution/evidence/swamp-loads.tsv')
HEADER = '# loaded_at_utc\tserved_sha\tload_mode\tserved_eq_disk\tswamp_tree_sha\n'


def row(event):
    at = datetime.datetime.fromisoformat(event['loaded_at'].replace('Z', '+00:00'))
    assert at.tzinfo is not None, 'load_log_timestamp_requires_zone'
    stamp = at.astimezone(datetime.timezone.utc).isoformat(timespec='microseconds').replace('+00:00', 'Z')
    sha, mode, equal = event['served_sha'], event['mode'], event['served_eq_disk']
    assert isinstance(sha, str) and re.fullmatch('[a-f0-9]{40}', sha), 'load_log_full_sha_required'
    assert mode in {'reload', 'restart'}, 'load_log_mode_invalid'
    assert type(equal) is bool, 'load_log_equality_requires_boolean'
    tree = event.get('swamp_tree_sha', '')
    assert isinstance(tree, str) and (not tree or re.fullmatch('[a-f0-9]{40}', tree)), 'load_log_full_tree_required'
    return '\t'.join([stamp, sha, mode, str(equal).lower(), tree]) + '\n'


def append(events, path=PATH):
    # Validate the whole batch before writing a partial batch.
    rows = sorted({row(event) for event in events})
    path = pathlib.Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    count = 0
    with os.fdopen(os.open(path, os.O_RDWR | os.O_CREAT, 0o644), 'r+') as stream:
        fcntl.flock(stream, fcntl.LOCK_EX)
        stream.seek(0)
        existing = stream.readlines()
        assert not existing or existing[0].startswith('#'), 'load_log_header_missing'
        historical = []
        for line in existing[1:]:
            if line.startswith('#'):
                historical.append(line)
                continue
            fields = line.rstrip('\n').split('\t')
            assert len(fields) in {4, 5}, 'load_log_historical_schema_invalid'
            historical.append(line.rstrip('\n') + ('\t' if len(fields) == 4 else '') + '\n')
        known = {line for line in historical if not line.startswith('#')}
        additions = []
        for line in rows:
            if line not in known:
                additions.append(line)
                known.add(line)
                count += 1
        output = HEADER + ''.join(historical + additions)
        if output != ''.join(existing):
            stream.seek(0)
            stream.write(output)
            stream.truncate()
        stream.flush()
        os.fsync(stream.fileno())
    return count


def collect(events, cache):
    count = append(events)
    if events:
        cursor = max(event['event_file'] for event in events)
        path = pathlib.Path(cache) / 'load-log-cursor.json'
        tmp = path.with_suffix('.tmp')
        tmp.write_text(json.dumps({'last_event': cursor}) + '\n')
        tmp.replace(path)
    return count
