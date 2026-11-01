"""Run one captured fresh-workspace setup command with its documented seed variables."""

import json
import sys

from check_command import command_source
from check_common import command
from project_environment import load_project_environment
from repository_common import checkout, program_environment

if __name__ == "__main__":
    value = json.load(sys.stdin)
    item = json.loads(sys.argv[1])
    root = checkout(value)
    dotenv = load_project_environment(root)
    result = command(
        command_source(item),
        root,
        None,
        seed={"path": "", "commit": ""},
        environment={**dotenv, **program_environment(None)},
        secret_values=dotenv.values(),
    )
    print(json.dumps(result), flush=True)
    sys.exit(result["exit_code"] if result["exit_code"] >= 0 else 255)
