"""Candidate loader audit slice: static current-source inventory; no Swamp discovery.

Use with the existing metadata reader and audit(), then coverage() with the
served model.type.search names. This does not load, repair or compile extensions.
"""
import hashlib
import json
import pathlib
import re

BUILTINS = {'command/shell'}
FACTORIES = {'nativeEffect', 'provisioningRoot', 'nativeProvision', 'projectConfig'}
ENTRY = re.compile(r'export\s+(?:const|let)\s+(?:behavior|model)\b')
LITERAL = re.compile(r'\bversion\s*:\s*[\'"]([^\'"]+)[\'"]')
TYPE = re.compile(r'\btype\s*:\s*[\'"](@[\w./-]+)[\'"]')
PROJECT = re.compile(r'\bprojectConfig\(\s*[\'"](@[\w./-]+)[\'"]')
IMPORT = re.compile(r'import\s*\{([^}]+)\}\s*from\s*[\'"]([^\'"]+)[\'"]', re.DOTALL)


def version(path):
    text = path.read_text()
    versions = set(LITERAL.findall(text))
    if not versions:
        for symbol in re.findall(r'\bversion\s*:\s*([A-Za-z_$][\w$]*)', text):
            versions.update(re.findall(r'\bconst\s+' + re.escape(symbol) + r'\s*=\s*[\'"]([^\'"]+)[\'"]', text))
    if len(versions) == 1:
        return next(iter(versions)), path
    # These existing factories own the versions of their exported wrappers.
    inherited = []
    for symbols, relative in IMPORT.findall(text):
        for factory in FACTORIES:
            if re.search(r'\b' + factory + r'\b', symbols) and re.search(r'\b' + factory + r'\s*\(', text) and relative.startswith('.'):
                helper = (path.parent / relative).resolve()
                matches = set(LITERAL.findall(helper.read_text()))
                if len(matches) == 1:
                    inherited.append((next(iter(matches)), helper))
    if len(inherited) == 1:
        return inherited[0]
    raise ValueError('disk_version_not_statically_unique')


def inventory(app, changed=None):
    """Full audit: current public entries plus manifest-listed official models.

    Catalog rows and their old absolute paths are not source authority. `changed`
    is accepted for the existing call site; completeness uses the full inventory.
    """
    app = pathlib.Path(app).resolve()
    entries = {}
    for base in [app / 'extensions/behaviors', app / 'extensions/models']:
        if not base.is_dir():
            continue
        for path in base.rglob('*.ts'):
            if path.name.endswith(('_test.ts', '.test.ts')) or 'fixtures' in path.relative_to(base).parts:
                continue
            text = path.read_text()
            if ENTRY.search(text):
                for name in set(TYPE.findall(text) + PROJECT.findall(text)):
                    entries[name] = path
    manifest = app / 'extensions/models/upstream_extensions.json'
    if manifest.is_file():
        for extension in json.loads(manifest.read_text()).values():
            for relative in extension.get('files', []):
                path = app / relative
                if path.suffix != '.ts' or '/models/' not in relative:
                    continue
                if not path.is_file():
                    raise ValueError('official_source_missing:' + relative)
                names = set(TYPE.findall(path.read_text()))
                if len(names) == 1:
                    entries[next(iter(names))] = path
    rows = []
    for name, path in sorted(entries.items()):
        row = {'type': name, 'source': str(path), 'source_sha256': hashlib.sha256(path.read_bytes()).hexdigest()}
        try:
            expected, source = version(path)
            row.update(expected=expected, version_source=str(source),
                       version_source_sha256=hashlib.sha256(source.read_bytes()).hexdigest(),
                       version_basis='current source declaration or imported factory')
        except ValueError as error:
            row['error'] = str(error)
        rows.append(row)
    return rows


def coverage(rows, served_types):
    expected = {row['type'] for row in rows}
    served = set(served_types)
    return {'builtin_exclusions': sorted(served & BUILTINS),
            'served_without_source': sorted(served - expected - BUILTINS),
            'source_not_served': sorted(expected - served)}
