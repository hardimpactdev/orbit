#!/usr/bin/python
"""Publish an independent stock image and reconcile its owned records."""

import json
import os
import re
import stat
from pathlib import Path
from urllib.parse import quote

DOCUMENTATION = r'''
module: orbit_incus_images
short_description: Reconcile an independently published stock container image
version_added: "1.0.0"
description:
  - Publish stock without an upstream update source and reconcile owned leftovers.
  - No installed Ansible module publishes Incus containers and reconciles their image aliases.
options:
  project:
    description: Incus project that owns the stock image.
    type: str
    required: true
  alias:
    description: Selected stock image alias from the admitted host intent.
    type: str
    required: true
  archive_directory:
    description: Image directory after verification of the daemon identity.
    type: path
    required: true
  socket:
    description: Verified local Incus Unix socket for every stock-image command.
    type: path
    required: true
  failure_code:
    description: Public host preparation error code.
    type: str
    required: true
attributes:
  check_mode:
    description: Publication needs live reads and is not run in check mode.
    support: none
author: Orbit
'''


class ImageError(Exception):
    """An image reconciliation that did not reach its required end state."""


class Images:
    def __init__(self, module):
        self.module = module
        self.project = module.params['project']
        self.changed = False

    def run(self, args, *, change=False):
        code, out, err = self.module.run_command(
            ['incus', '--project', self.project, *args],
            environ_update={'INCUS_REMOTE': 'local', 'INCUS_SOCKET': self.module.params['socket']},
        )
        if code:
            raise ImageError(err.strip() or out.strip() or f'Incus exited {code}')
        self.changed |= change
        return out

    def query(self, path, *, method='GET', body=None):
        args = ['query', '--request', method]
        if body is not None:
            args += ['--data', json.dumps(body)]
        path += ('&' if '?' in path else '?') + 'project=' + quote(self.project, safe='')
        out = self.run([*args, '--', path])
        return json.loads(out) if out.strip() else None

    def images(self):
        return json.loads(self.run(['image', 'list', '--format', 'json']))

    @staticmethod
    def named(images, alias):
        return next((image for image in images if any(a['name'] == alias for a in image['aliases'])), None)

    def require_owned(self, properties):
        if properties.get('user.orbit.stock-build') != self.module.params['alias']:
            raise ImageError('cannot prove stock ownership of a reserved resource')

    def builder(self):
        rows = self.query('/1.0/instances?recursion=1')
        build = next((row for row in rows if row['name'] == 'orbit-stock-build'), None)
        if build:
            config = build.get('config', {})
            self.require_owned(config)
            source = config.get('volatile.base_image')
            if not isinstance(source, str) or not re.fullmatch('[0-9a-f]{64}', source):
                raise ImageError('cannot prove stock ownership without the native source fingerprint')
        return build

    def remove_build(self):
        if self.builder():
            self.run(['delete', '--force', '--', 'orbit-stock-build'], change=True)

    def remove_named(self, alias):
        image = self.named(self.images(), alias)
        if image:
            self.require_owned(image.get('properties', {}))
            if len(image['aliases']) > 1:
                self.run(['image', 'alias', 'delete', alias], change=True)
            else:
                self.run(['image', 'delete', image['fingerprint']], change=True)

    def promote(self, alias, fingerprint, images):
        selected = self.named(images, alias)
        target = next(image for image in images if image['fingerprint'] == fingerprint)
        self.require_owned(target.get('properties', {}))
        if selected:
            self.require_owned(selected.get('properties', {}))
        if selected and selected['fingerprint'] == fingerprint:
            return
        path = '/1.0/images/aliases'
        if selected:
            self.query(path + '/' + quote(alias, safe=''), method='PUT', body={'target': fingerprint})
        else:
            self.query(path, method='POST', body={'name': alias, 'target': fingerprint})
        self.changed = True

    def leftovers(self, alias, known_sources=()):
        build = self.builder()
        sources = set(known_sources)
        if build:
            sources.add(build.get('config', {}).get('volatile.base_image'))
        images = self.images()
        for name in [alias, 'orbit-stock-retiring', 'orbit-stock-candidate']:
            image = self.named(images, name)
            if image:
                self.require_owned(image.get('properties', {}))
        self.remove_named('orbit-stock-retiring')
        self.remove_named('orbit-stock-candidate')
        for image in self.images():
            if not image['aliases'] and image.get('properties', {}).get('user.orbit.stock-build') == alias:
                self.require_owned(image['properties'])
                self.run(['image', 'delete', image['fingerprint']], change=True)
        for image in self.images():
            if (image.get('cached') and image.get('update_source') and not image['aliases']
                    and image['fingerprint'] in sources):
                self.run(['image', 'delete', image['fingerprint']], change=True)
        if build:
            self.remove_build()

    @staticmethod
    def archive_exists(path):
        try:
            mode = path.stat().st_mode
        except FileNotFoundError:
            return False
        if not stat.S_ISREG(mode):
            raise ImageError('image archive is not a regular file')
        return True

    def stock(self):
        alias = self.module.params['alias']
        archives = Path(self.module.params['archive_directory'])
        if not archives.is_dir() or not os.access(archives, os.R_OK | os.X_OK):
            raise ImageError('cannot read the image archive directory')
        self.leftovers(alias)
        images = self.images()
        old = self.named(images, alias)
        if old:
            self.require_owned(old.get('properties', {}))
        if not old or old.get('update_source') or not self.archive_exists(archives / old['fingerprint']):
            try:
                self.run(['init', 'images:ubuntu/26.04/cloud', 'orbit-stock-build',
                          '--config', 'security.idmap.isolated=true',
                          '--config', 'user.orbit.stock-build=' + alias], change=True)
                source = self.builder()['config']['volatile.base_image']
                self.run(['publish', 'orbit-stock-build', '--alias', 'orbit-stock-candidate',
                          'user.orbit.stock-build=' + alias], change=True)
                images = self.images()
                built = self.named(images, 'orbit-stock-candidate')
                if (not built or not re.fullmatch('[0-9a-f]{64}', source) or built['fingerprint'] == source
                        or built.get('update_source') or built['auto_update']):
                    raise ImageError('stock publication did not produce an independent image')
                self.require_owned(built.get('properties', {}))
                if not self.archive_exists(archives / built['fingerprint']):
                    raise ImageError('published stock archive is missing')
                if old and old['fingerprint'] != built['fingerprint']:
                    self.promote('orbit-stock-retiring', old['fingerprint'], images)
                self.promote(alias, built['fingerprint'], images)
                self.leftovers(alias, [source])
            finally:
                self.remove_build()


def main():
    from ansible.module_utils.basic import AnsibleModule

    module = AnsibleModule(argument_spec={
        'project': {'type': 'str', 'required': True},
        'alias': {'type': 'str', 'required': True},
        'archive_directory': {'type': 'path', 'required': True},
        'socket': {'type': 'path', 'required': True},
        'failure_code': {'type': 'str', 'required': True},
    })
    images = Images(module)
    try:
        images.stock()
    except (ImageError, OSError, ValueError, KeyError) as error:
        module.fail_json(changed=images.changed, msg=f"{module.params['failure_code']}: {error}")
    module.exit_json(changed=images.changed)


if __name__ == '__main__':
    main()
