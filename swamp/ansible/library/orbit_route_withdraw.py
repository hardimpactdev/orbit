#!/usr/bin/python
"""Ansible entrypoint for one Node's Route withdrawal transaction."""

import grp

DOCUMENTATION = r"""
module: orbit_route_withdraw
short_description: Withdraw one Route from a Node's shared files under the Node lock
version_added: "1.0.0"
description:
  - Under the Node publication lock, read the Caddyfile, the shared PHP-FPM pools and the private DNS files, merge the captured
    states of the withdrawn Route into them, refuse any change outside the Route, keep the previous generation, test every
    candidate, then commit and reload.
options:
  plan:
    description: The captured states and merge steps that the Swamp composition step wrote.
    type: dict
    required: true
author: Orbit
"""
EXAMPLES = ""
RETURN = r"""
previous:
  description: The previous generation of each replaced file.
  type: list
  returned: success
"""


def main():
    from ansible.module_utils.basic import AnsibleModule

    module = AnsibleModule(argument_spec={"plan": {"type": "dict", "required": True}})
    from ansible.module_utils.orbit_route_withdraw import WithdrawFailure, withdraw

    plan = module.params["plan"]
    try:
        result = withdraw(plan, module.run_command, caddy_gid=grp.getgrnam("caddy").gr_gid if plan.get("caddy") else None)
    except WithdrawFailure as error:
        module.fail_json(msg=str(error), error_code=error.code, changed=error.changed)
    except OSError as error:
        module.fail_json(msg=f"route.withdraw_failed: {error}", error_code="route.withdraw_failed")
    else:
        module.exit_json(**result)


if __name__ == "__main__":
    main()
