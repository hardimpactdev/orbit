#!/usr/bin/python
"""Download admitted candidates and publish only a checksum-verified artifact."""
import hashlib
import os
import re
import tempfile
from pathlib import Path

DOCUMENTATION = r'''
module: orbit_artifact
short_description: Fetch an admitted artifact with transport-only fallback
version_added: "1.0.0"
description: Verify cached or downloaded bytes before atomic publication.
options:
  urls:
    description: Ordered HTTPS candidates from the Gateway intent.
    type: list
    elements: str
    required: true
  dest:
    description: Absolute destination path.
    type: path
    required: true
  sha256:
    description: Pinned SHA-256 digest.
    type: str
    required: true
  transport_exit_codes:
    description: Shared curl transport failure policy from the shipped playbook.
    type: list
    elements: int
    required: true
author: Orbit
'''


class ArtifactError(Exception):
    """A failed download or integrity check, with public attempt evidence."""


def digest(path):
    with open(path, 'rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def fetch(module):
    args = module.params
    dest = Path(args['dest'])
    attempts = []
    result = {'changed': False, 'dest': str(dest), 'attempts': attempts, 'published': False}
    try:
        if not dest.is_absolute() or not re.fullmatch('[a-f0-9]{64}', args['sha256']):
            raise ArtifactError('Invalid artifact path or SHA-256 pin')
        if not args['urls'] or any(not url.startswith('https://') for url in args['urls']):
            raise ArtifactError('Artifact candidates must be nonempty HTTPS URLs')
        if dest.exists() and digest(dest) == args['sha256']:
            result['changed'] = (dest.stat().st_mode & 0o777) != 0o644
            if not module.check_mode:
                dest.chmod(0o644)
            return result
        if module.check_mode:
            return {**result, 'changed': True}
        fd, staged = tempfile.mkstemp(prefix=dest.name + '.artifact-', dir=dest.parent)
        try:
            os.close(fd)
            for url in args['urls']:
                code, _, _ = module.run_command([
                    'curl', '--fail', '--silent', '--show-error', '--location',
                    '--proto', '=https', '--proto-redir', '=https', '--connect-timeout', '15',
                    '--max-time', '600', '--max-filesize', '536870912', '--output', staged, '--', url,
                ])
                attempt = {'url': url, 'exit_code': code}
                attempts.append(attempt)
                if code:
                    if code in args['transport_exit_codes']:
                        continue
                    raise ArtifactError('Artifact download failed without a transport error')
                attempt['checksum_valid'] = digest(staged) == args['sha256']
                if not attempt['checksum_valid']:
                    raise ArtifactError('Artifact SHA-256 mismatch')
                os.chmod(staged, 0o644)
                os.replace(staged, dest)
                result.update(changed=True, published=True)
                return result
            raise ArtifactError('All artifact candidates failed in transport')
        finally:
            Path(staged).unlink(missing_ok=True)
    except (ArtifactError, OSError) as error:
        return {**result, 'failed': True, 'msg': str(error)}


def main():
    from ansible.module_utils.basic import AnsibleModule

    module = AnsibleModule(argument_spec={
        'urls': {'type': 'list', 'elements': 'str', 'required': True},
        'dest': {'type': 'path', 'required': True},
        'sha256': {'type': 'str', 'required': True},
        'transport_exit_codes': {'type': 'list', 'elements': 'int', 'required': True},
    }, supports_check_mode=True)
    result = fetch(module)
    if result.pop('failed', False):
        module.fail_json(**result)
    module.exit_json(**result)


if __name__ == '__main__':
    main()
