"""Shared input and subprocess boundary for the canonical role phase files."""

import importlib.util
import json
import os
import subprocess
import sys
from pathlib import Path

DIRECTORY = Path(__file__).resolve().parent


def inputs():
    return json.loads(os.environ["ORBIT_ROLE_INPUT"])


def account():
    return json.loads(os.environ["ORBIT_ROLE_ACCOUNT"])


def module(name):
    spec = importlib.util.spec_from_file_location(
        name, DIRECTORY / ("canonical_" + name + ".py")
    )
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


def execute(name, data=None, action=None):
    argv = [sys.executable, "-B", str(DIRECTORY / (name + ".py"))]
    if action is not None:
        argv.append(action)
    result = subprocess.run(
        argv,
        input=json.dumps(data) if data is not None else "",
        text=True,
        stdout=subprocess.PIPE,
        check=True,
    )
    output = json.loads(result.stdout)
    if output.get("error_code"):
        raise ValueError(output["error_code"])
    return output


def control(*args):
    subprocess.run(
        ["/usr/bin/systemctl", *args],
        check=True,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
    )
