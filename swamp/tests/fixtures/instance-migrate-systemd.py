"""Stateful host systemd boundary for migration fixtures; never contacts the host service manager."""
import json
import os
from pathlib import Path
from ansible.module_utils.basic import AnsibleModule

module = AnsibleModule(argument_spec={"name": {"type": "str"}, "state": {"type": "str"},
    "enabled": {"type": "bool"}, "daemon_reload": {"type": "bool", "default": False}})
path = Path(os.environ["HOST_FIXTURE"]) / "host-processes.json"
states = json.loads(path.read_text())
name = module.params["name"]
if name is None:
    module.exit_json(changed=False)
before = states.get(name)
after = {"state": module.params["state"], "enabled": module.params["enabled"]}
if after["state"] == "started" and json.loads((path.parent / "state.json").read_text()).get("containers"):
    module.fail_json(msg="A host writer resumed before its failed container was removed")
states[name] = after
path.write_text(json.dumps(states))
module.exit_json(changed=before != after)
