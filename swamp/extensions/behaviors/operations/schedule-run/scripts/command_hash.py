"""Verify the existing command execution copy without retaining or printing its bytes."""

import hashlib
import sys


def validate(value, expected):
    command = value
    try:
        text = command.decode("utf-8")
    except UnicodeDecodeError:
        return False
    return (
        0 < len(text) <= 4096
        and not any(c in text for c in "\0\r\n")
        and hashlib.sha256(command).hexdigest() == expected
    )


if __name__ == "__main__":
    valid = len(sys.argv) == 2 and validate(sys.stdin.buffer.read(16386), sys.argv[1])
    if not valid:
        print('{"error_code":"schedule.artifact_conflict"}')
        raise SystemExit(1)
    print('{"ready":true}')
