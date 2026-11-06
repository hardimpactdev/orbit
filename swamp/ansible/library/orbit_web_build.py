#!/usr/bin/python
"""Extract a web build archive with safe names only and publish it by one rename."""
import os
import shutil
import stat
import zipfile
from pathlib import Path

from ansible.module_utils.basic import AnsibleModule

DOCUMENTATION = r'''
module: orbit_web_build
short_description: Publish a verified web build archive
version_added: "1.0.0"
description:
  - Extracts a zip archive into a partial directory next to the destination, checks every entry, sets the owner, the group and the
    modes, and publishes the directory by one rename. A destination that holds an index.html is left as it is.
options:
  src:
    description: The archive, already checked against its digest.
    type: path
    required: true
  dest:
    description: The published directory.
    type: path
    required: true
  owner:
    description: Owner of every entry.
    type: str
    required: true
  group:
    description: Group of every entry.
    type: str
    required: true
  max_entries:
    description: Most entries the archive may hold.
    type: int
    default: 20000
  max_bytes:
    description: Most uncompressed bytes the archive may hold.
    type: int
    default: 536870912
author: Orbit
'''


class Unsafe(Exception):
    """An archive entry or total that the build may not publish."""


def check(archive, max_entries, max_bytes):
    entries = archive.infolist()
    if len(entries) > max_entries or sum(e.file_size for e in entries) > max_bytes:
        raise Unsafe('too many entries or bytes')
    names = set()
    for entry in entries:
        name = entry.filename
        mode = (entry.external_attr >> 16) & 0o170000
        if (name.startswith('/') or '\\' in name or any(part in ('', '.', '..') for part in name.rstrip('/').split('/'))
                or name in names or entry.flag_bits & 1 or mode not in (0, stat.S_IFREG, stat.S_IFDIR)):
            raise Unsafe('unsafe entry ' + repr(name))
        names.add(name)


def publish(module):
    args = module.params
    dest = Path(args['dest'])
    if (dest / 'index.html').is_file():
        return {'changed': False, 'dest': str(dest)}
    partial = dest.parent / ('.' + dest.name + '.partial')
    if module.check_mode:
        return {'changed': True, 'dest': str(dest)}
    shutil.rmtree(partial, ignore_errors=True)
    # A directory without index.html is a stopped install; it is replaced.
    shutil.rmtree(dest, ignore_errors=True)
    try:
        with zipfile.ZipFile(args['src']) as archive:
            check(archive, args['max_entries'], args['max_bytes'])
            archive.extractall(partial)
        if not (partial / 'index.html').is_file():
            raise Unsafe('no index.html')
    except (Unsafe, zipfile.BadZipFile, OSError) as error:
        shutil.rmtree(partial, ignore_errors=True)
        module.fail_json(msg=f'gateway.release_web_build_invalid: {error}')
    for path in [partial, *partial.rglob('*')]:
        shutil.chown(path, args['owner'], args['group'])
        path.chmod(0o750 if path.is_dir() else 0o640)
    os.rename(partial, dest)
    return {'changed': True, 'dest': str(dest)}


def main():
    module = AnsibleModule(argument_spec={
        'src': {'type': 'path', 'required': True},
        'dest': {'type': 'path', 'required': True},
        'owner': {'type': 'str', 'required': True},
        'group': {'type': 'str', 'required': True},
        'max_entries': {'type': 'int', 'default': 20000},
        'max_bytes': {'type': 'int', 'default': 536870912},
    }, supports_check_mode=True)
    module.exit_json(**publish(module))


if __name__ == '__main__':
    main()
