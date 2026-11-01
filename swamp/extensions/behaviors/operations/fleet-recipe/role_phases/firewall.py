"""Apply the admission-captured role firewall expansion before pruning."""

import json
import subprocess

from phase_common import inputs


def converge(intent):
    for field in ["desired", "remove"]:
        for argv in intent["firewall"][field]:
            subprocess.run(
                argv, check=True, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL
            )
    return {"ready": True}


if __name__ == "__main__":
    print(json.dumps(converge(inputs()["intent"])))
