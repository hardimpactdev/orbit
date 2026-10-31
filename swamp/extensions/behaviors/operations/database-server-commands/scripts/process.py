"""Bind admitted material for ONE fixed shared Process command file."""

import json
import os
import runpy
import sys

value = json.loads(sys.argv[1])
slot, spec, script, directory, operation, destination, desired, private, scrub = (
    sys.argv[2:]
)
control = {
    "process_id": value["process_id"],
    "runtime": "docker",
    "name": value["container"],
    "vite": None,
    "on_demand": False,
    "slot": slot,
}
if spec:
    control["expected_spec"] = spec
os.environ["ORBIT_PROCESS_CONTROL"] = json.dumps(control)
os.environ["ORBIT_PROCESS_OPERATION"] = operation
os.environ["ORBIT_PROCESS_DESTINATION_SLOT"] = destination
os.environ["ORBIT_PROCESS_DESIRED"] = desired
os.environ["ORBIT_PROCESS_INSTALLATION"] = json.dumps(
    {
        "process_id": value["process_id"],
        "runtime": "docker",
        "name": value["container"],
        "desired": "running",
        "on_demand": False,
        "retired": None,
        "spec": spec,
    }
)
if private == "true":
    material = json.load(sys.stdin)
    definition = dict(
        value["container_input"],
        environment={} if scrub == "true" else (material["environment"] or {}),
    )
    os.environ["ORBIT_PROCESS_MATERIAL"] = json.dumps(
        {"runtime": "docker", "spec": spec, "definition": definition}
    )
sys.path.insert(0, directory)
if script == "create_container.py":
    from create_container import create
    from process_common import main

    probe = {
        "mysql": [
            "CMD",
            "mysqladmin",
            "--protocol=TCP",
            "--host=127.0.0.1",
            "ping",
            "--silent",
        ],
        "pgsql": [
            "CMD",
            "pg_isready",
            "--host=127.0.0.1",
            "--username=postgres",
            "--dbname=postgres",
        ],
        "redis": ["CMD", "valkey-cli", "PING"],
    }[value["driver"]]
    main(
        lambda control: create(
            control,
            {
                "Test": probe,
                "Interval": 1000000000,
                "Timeout": 3000000000,
                "Retries": 120,
            },
        )
    )
else:
    runpy.run_path(directory + "/" + script, run_name="__main__")
