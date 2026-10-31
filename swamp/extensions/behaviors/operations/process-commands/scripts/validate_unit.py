"""Validate one staged unit without publishing or changing its runtime."""

import process_common as common
from process_common import Refused, main
from unit_files import ANALYZE, staged, unit_input


def validate(value):
    path, _content = staged(value, unit_input(value))
    if common.command([ANALYZE, "verify", str(path)]).returncode:
        raise Refused("process.candidate_invalid")
    return {"ready": True}


if __name__ == "__main__":
    main(validate)
