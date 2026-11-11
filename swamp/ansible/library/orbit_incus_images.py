#!/usr/bin/python
"""Publish independent stock and refuse resources left by prior invocations."""

import json
import os
import re
import stat
from pathlib import Path
from urllib.parse import quote

DOCUMENTATION = r'''
module: orbit_incus_images
short_description: Publish an independent stock container image
version_added: "1.0.0"
description:
  - Publish stock without an upstream update source; never clean prior resources.
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

    def run(self, args, *, change=False, project_flag=True):
        code, out, err = self.module.run_command(
            ['incus', *(['--project', self.project] if project_flag else []), *args],
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
        # Incus refuses --project on its raw API command; the path carries the project.
        out = self.run([*args, '--', path], project_flag=False)
        return json.loads(out) if out.strip() else None

    def images(self):
        return json.loads(self.run(['image', 'list', '--format', 'json']))

    @staticmethod
    def named(images, alias):
        return next((image for image in images if any(a['name'] == alias for a in image['aliases'])), None)

    def refuse(self, resource, *, adoption=False):
        section = 'adopt-a-pinned-legacy-stock-image' if adoption else 'operator-cleanup'
        raise ImageError(f'{resource} requires an operator; see /solutions/incus-shared-image-refresh#{section}')

    def archive_exists(self, path):
        try:
            mode = path.stat().st_mode
        except FileNotFoundError:
            return False
        if not stat.S_ISREG(mode):
            self.refuse(f'image archive {path} is not a regular file')
        return True

    def stock(self):
        alias = self.module.params['alias']
        archives = Path(self.module.params['archive_directory'])
        if not archives.is_dir() or not os.access(archives, os.R_OK | os.X_OK):
            self.refuse(f'archive directory {archives}')
        instances = self.query('/1.0/instances?recursion=1')
        if any(row['name'] == 'orbit-stock-build' for row in instances):
            self.refuse(f'instance {self.project}/orbit-stock-build')
        images = self.images()
        for name in ['orbit-stock-candidate', 'orbit-stock-retiring']:
            image = self.named(images, name)
            if image:
                self.refuse(f'alias {self.project}/{name}, image {image["fingerprint"]}')
        for image in images:
            if not image['aliases'] and image.get('properties', {}).get('user.orbit.stock-build') == alias:
                self.refuse(f'interrupted image {self.project}/{image["fingerprint"]}')
        old = self.named(images, alias)
        if old:
            if old.get('properties', {}).get('user.orbit.stock-build') != alias:
                self.refuse(f'unmarked selected image {self.project}/{old["fingerprint"]}, alias {alias}', adoption=True)
            if not old.get('update_source') and not old['auto_update'] and self.archive_exists(archives / old['fingerprint']):
                return
            if len(old['aliases']) < 2:
                self.refuse(f'selected image {self.project}/{old["fingerprint"]} needs a retention alias')
        created_build = False
        try:
            self.run(['init', 'images:ubuntu/26.04/cloud', 'orbit-stock-build',
                      '--config', 'security.idmap.isolated=true',
                      '--config', 'user.orbit.stock-build=' + alias], change=True)
            created_build = True
            source = self.query('/1.0/instances/orbit-stock-build')['config']['volatile.base_image']
            if not isinstance(source, str) or not re.fullmatch('[0-9a-f]{64}', source):
                self.refuse(f'instance {self.project}/orbit-stock-build has an invalid source')
            self.run(['publish', 'orbit-stock-build', '--alias', 'orbit-stock-candidate',
                      'user.orbit.stock-build=' + alias], change=True)
            built = self.named(self.images(), 'orbit-stock-candidate')
            if not built:
                self.refuse(f'publication alias {self.project}/orbit-stock-candidate')
            fingerprint = built['fingerprint']
            if (fingerprint in {image['fingerprint'] for image in images} or fingerprint == source
                    or built.get('update_source') or built['auto_update']):
                self.refuse(f'publication image {self.project}/{fingerprint} is not independent')
            if not self.archive_exists(archives / fingerprint):
                self.refuse(f'publication image {self.project}/{fingerprint} archive is missing')
            path = '/1.0/images/aliases'
            if old:
                self.query(path + '/' + quote(alias, safe=''), method='PUT', body={'target': fingerprint})
            else:
                self.query(path, method='POST', body={'name': alias, 'target': fingerprint})
            self.changed = True
            # Native publish succeeded and created this alias in this invocation.
            self.run(['image', 'alias', 'delete', 'orbit-stock-candidate'], change=True)
        except ImageError as error:
            raise ImageError(f'{error}; inspect instance {self.project}/orbit-stock-build '
                             f'and publication {self.project}/orbit-stock-candidate; '
                             'see /solutions/incus-shared-image-refresh#operator-cleanup') from error
        finally:
            # No property or pre-existing name grants cleanup authority.
            if created_build:
                try:
                    self.run(['delete', '--force', '--', 'orbit-stock-build'], change=True)
                except ImageError:
                    self.refuse(f'cleanup of current instance {self.project}/orbit-stock-build')


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
