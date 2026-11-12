#!/usr/bin/python
"""Ansible entrypoint for the Node-local Caddy publication transaction."""

import grp

from ansible.module_utils.basic import AnsibleModule

from ansible.module_utils.orbit_caddy_publish import PublicationFailure, publish

DOCUMENTATION = r"""
module: orbit_caddy_publish
short_description: Refuse a stale Caddyfile base before publishing on its Node
version_added: "1.0.0"
description:
  - Hold the Node lock through checksum comparison, candidate validation, rename and reload.
options:
  content:
    description: The composed whole Caddyfile.
    type: str
    required: true
  composed_from_sha256:
    description: SHA-256 of the Node file read by the composition.
    type: str
    required: true
author: Orbit
"""
EXAMPLES = ""
RETURN = r"""
published:
  description: Whether the validated candidate replaced the live file.
  type: bool
  returned: success
"""


def main():
    module = AnsibleModule(argument_spec={
        "content": {"type": "str", "required": True},
        "composed_from_sha256": {"type": "str", "required": True},
    })
    try:
        result = publish(module.params["content"], module.params["composed_from_sha256"],
                         module.run_command, gid=grp.getgrnam("caddy").gr_gid)
    except PublicationFailure as error:
        module.fail_json(msg=str(error), error_code=error.code, changed=error.changed, **error.facts)
    except OSError as error:
        module.fail_json(msg=f"caddy.publish_failed: {error}", error_code="caddy.publish_failed")
    else:
        module.exit_json(**result)


if __name__ == "__main__":
    main()
