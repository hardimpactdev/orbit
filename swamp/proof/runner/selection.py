"""One case-change decision, using exactly the proof runner's loader and expansion."""
import json
import tempfile
from dataclasses import fields, is_dataclass
from pathlib import Path

import spec

VERSION = 1


def canonical(value):
    """Typed JSON equality, independent of mapping order and diagnostic source locations."""
    if is_dataclass(value):
        return {field.name: canonical(getattr(value, field.name)) for field in fields(value)
                if not isinstance(value, spec.Step) or field.name != 'where'}
    if isinstance(value, dict):
        return {key: canonical(item) for key, item in value.items()}
    if isinstance(value, list):
        return [canonical(item) for item in value]
    return value


def encoded(value):
    return json.dumps(canonical(value), sort_keys=True, separators=(',', ':'), allow_nan=False)


def definitions(cases_text, batches_text):
    """The expanded runner definitions, with the setup that runs before each batch."""
    with tempfile.TemporaryDirectory() as directory:
        cases, batches = Path(directory) / 'cases.yaml', Path(directory) / 'batches.yaml'
        cases.write_text(cases_text)
        batches.write_text(batches_text)
        loaded = spec.load(cases, batches)
        raw = spec.read_yaml(cases)
    if not loaded.cases:
        raise spec.SpecError('Selected command has no cases')
    for fixture in (raw.get('fixtures') or {}).values():
        if not isinstance(fixture, dict) or set(fixture) - {'summary', 'params', 'steps'}:
            raise spec.SpecError('Unknown fixture construct')
    settings = {key: canonical(getattr(loaded, key)) for key in ('command', 'target', 'sources', 'baseline', 'spread', 'parallel')}
    result = {}
    for case in loaded.cases:
        batch = loaded.batches[case.batch]
        if set(batch) - {'summary', 'setup'}:
            raise spec.SpecError('Unknown batch construct')
        setup = spec.expand_steps(batch.get('setup') or [], 'setup', 'batch setup', {}, {}, {})
        result[case.id] = {'case': canonical(case), 'settings': settings, 'setup': canonical(setup)}
    return result, raw


def select(before, after, code_changed=False, before_batches=None, after_batches=None, changed_paths=()):
    """None ids means whole file: uncertainty can never become a successful empty selection."""
    current = None
    try:
        current, raw = definitions(after, after_batches)
        if code_changed:
            return {'only': list(current), 'reasons': dict.fromkeys(current, 'command code changed')}
        # Tooling changes do not select commands, but must never narrow their own proof.
        if any(path.startswith(('swamp/proof/runner/', 'apps/gateway/app/Domain/Proof/',
                                'apps/gateway/app/Infrastructure/Proof/'))
               or path in ('swamp/proof/selection.txt', 'bin/ci-build-plan', 'bin/ci-proof-run', '.github/workflows/ci.yml')
               for path in changed_paths):
            return {'only': list(current), 'reasons': dict.fromkeys(current, 'proof tooling changed')}
        previous, old = definitions(before, before_batches) if before is not None else ({}, {'cases': []})
        old_cases = {case['id']: case for case in old['cases']}
        raw_cases = {case['id']: case for case in raw['cases']}
        reasons = {}
        # Group by the source id so changing a variant selects all its siblings.
        for name, case in raw_cases.items():
            ids = [key for key in current if key == name or key.startswith(name + '[')]
            reason = 'added' if name not in old_cases else 'changed' if encoded(case) != encoded(old_cases[name]) else None
            if reason is None:
                changed = [key for key in ids if key not in previous or encoded(current[key]) != encoded(previous[key])]
                if not changed:
                    continue
                if any(key not in previous or encoded(current[key]['settings']) != encoded(previous[key]['settings']) for key in changed):
                    reason = 'file settings changed'
                elif any(encoded(current[key]['setup']) != encoded(previous[key]['setup']) for key in changed):
                    reason = 'batch setup changed'
                elif encoded(raw.get('fixtures')) != encoded(old.get('fixtures')):
                    reason = 'uses changed fixture'
                else:
                    reason = 'uses changed var'
            reasons.update(dict.fromkeys(ids, reason))
        return {'only': list(reasons), 'reasons': reasons}
    except Exception:  # Invalid/unknown definitions must select work, never nothing_to_prove.
        return {'only': list(current) if current else None,
                'reasons': dict.fromkeys(current or [], 'selection uncertain')}
