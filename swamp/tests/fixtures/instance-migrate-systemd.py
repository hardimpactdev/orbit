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
if name == os.environ.get("ORBIT_FIXTURE_FAIL_SYSTEMD_STOP") and module.params["state"] == "stopped":
    module.fail_json(msg="Injected timer stop failure")
before = states.get(name)
after = dict(before or {"state": "stopped", "enabled": False})
if module.params["state"] is not None:
    after["state"] = module.params["state"]
if module.params["enabled"] is not None:
    after["enabled"] = module.params["enabled"]
if name.endswith(".service") and module.params["state"] == "started" and json.loads((path.parent / "state.json").read_text()).get("containers"):
    module.fail_json(msg="A host writer resumed before its failed container was removed")
if module.params["state"] is not None or module.params["enabled"] is not None:
    states[name] = after
    path.write_text(json.dumps(states))
status = {"ActiveState": "active" if after["state"] == "started" else "inactive",
    "UnitFileState": "enabled" if after["enabled"] else "disabled", "LoadState": "loaded", "DropInPaths": ""}
status.update(after.get("status", {}))
module.exit_json(changed=before != after if module.params["state"] is not None or module.params["enabled"] is not None else False,
    status=status)
