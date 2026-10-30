#!/usr/bin/env python3
"""Build the reviewed serve argv. This module performs no runtime changes."""
import json
from pathlib import Path
import sys


def serve_command(command):
    if not isinstance(command, list) or not command or not all(isinstance(v, str) for v in command):
        raise ValueError('expected an argv array')
    if 'serve' not in command:
        raise ValueError('expected the serve command')
    before, options = command[:command.index('serve') + 1], command[command.index('serve') + 1:]
    retained = []
    index = 0
    while index < len(options):
        value = options[index]
        if value == '--max-concurrent-scheduled-runs':
            if index + 1 >= len(options) or not options[index + 1].isdigit():
                raise ValueError('scheduled concurrency has no numeric value')
            index += 2
            continue
        if value.startswith('--max-concurrent-scheduled-runs=') or value.split('=', 1)[0] in ('--remote-only', '--hot-reload'):
            index += 1
            continue
        retained.append(value)
        index += 1
    if Path(before[0]).name == 'env':
        env_end = next((i for i, v in enumerate(before[1:], 1) if '=' not in v), len(before))
        if env_end < len(before) and before[env_end].startswith('-'):
            raise ValueError('env options require an explicit review')
        before = [before[0], 'SWAMP_REMOTE_ONLY=1', *[v for v in before[1:env_end] if not v.startswith('SWAMP_REMOTE_ONLY=')], *before[env_end:]]
    else:
        before = ['/usr/bin/env', 'SWAMP_REMOTE_ONLY=1', *before]
    config = json.loads((Path(__file__).resolve().parents[1] / 'config/services-workers.json').read_text())
    if config['scheduled_runs'] != 1 or not config['remote_only'] or not config['hot_reload']:
        raise ValueError('serve policy must require remote-only, hot reload and native scheduled default1')
    return [*before, *retained, '--max-concurrent-scheduled-runs', '1', '--remote-only', '--hot-reload']


if __name__ == '__main__':
    print(json.dumps(serve_command(json.load(sys.stdin))))
